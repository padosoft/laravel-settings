# Da 6.x a 7.0

La 7.0 riscrive la cifratura dei valori dei settings. La feature esisteva dalla
6.x tramite `padosoft-settings.encrypted_keys`, ma non era utilizzabile in
produzione. Chi non ha mai popolato `encrypted_keys` non subisce alcun impatto
funzionale: il comportamento dei settings in chiaro e' invariato.

## Perche' la 6.x non era usabile

Nella 6.x la cifratura era implementata in **due punti indipendenti**: il mutator
del Model (`Settings::setValueAttribute`) e `SettingsManager::set()`. Gli hook
`created`/`updated` del Model chiamavano `set()` passando `$model->value`, cioe'
un valore gia' decifrato dall'accessor, che veniva quindi cifrato di nuovo.

Il risultato e' che i due percorsi di scrittura producevano dati incompatibili:

| Percorso di scrittura | Strati nel DB | Lettura a cache calda | Lettura a cache fredda |
|---|---|---|---|
| Eloquent (`create`/`save`) | 1 | corretta | **eccezione**, dopo aver scritto il valore in chiaro in cache |
| `setAndStore()` | 2 | **restituiva il ciphertext** | corretta |

Nessuno dei due era corretto in entrambi gli stati della cache. In aggiunta:

- `get()` a cache fredda popolava Redis con `toJson()`, che applica l'accessor:
  il **valore decifrato finiva in cache**, condivisa fra tutti i processi;
- la validazione girava **dopo** la cifratura, quindi qualunque regola diversa da
  `string` falliva sul ciphertext e la scrittura veniva **persa in silenzio**;
- il cast veniva applicato al ciphertext **prima** della decifratura: con una
  regola numerica il valore veniva distrutto;
- `loadOnStartUp()` scriveva il valore **decifrato** nel log quando la
  validazione falliva;
- `overrideConfig()` propagava il **ciphertext** dentro `config()`;
- una key in allowlist con valore in chiaro nel database faceva restituire
  silenziosamente il `$default` invece di segnalare l'errore.

## Cosa cambia

**Un solo punto di cifratura.** Tutto passa da `Encryption\SettingsCipher`.
Model e Manager lo usano entrambi e non implementano crittografia.

**Lo storage e la cache contengono lo stesso raw.** Il plaintext non viene mai
serializzato sui canali interni. La decifratura avviene solo al confine di
lettura.

**Envelope versionato con binding.** Formato: `pdsenc:v1:<kid>:<payload>`, dove
il payload cifrato contiene la key del setting e il context di deployment. Un
envelope copiato da un altro setting o da un altro ambiente non viene decifrato:
viene rifiutato. Si usa `encryptString()`, quindi nessun `unserialize()`.

**Keyring dedicato.** `PADOSOFT_SETTINGS_ENCRYPTION_KEY` e
`PADOSOFT_SETTINGS_PREVIOUS_ENCRYPTION_KEYS` permettono di ruotare la chiave dei
settings indipendentemente da `APP_KEY`. Senza configurazione si ricade su
`app.key`, come prima.

**Fail closed.** Se una key e' in allowlist ma il valore memorizzato e' in
chiaro, la lettura lancia invece di restituire il default. Una credenziale vuota
che parte verso un provider e' peggio di un errore visibile. Disattivabile con
`padosoft-settings.encryption.strict = false`.

**Redazione.** `toArray()`/`toJson()` restituiscono `[redacted]` al posto di un
valore cifrato, senza nemmeno decifrarlo. I log non contengono piu' valori.

## Breaking change

1. **`SettingsManager::set()` non cifra piu' due volte.** Se un'applicazione
   dipendeva dal doppio strato (non dovrebbe: era un difetto), i dati vanno
   normalizzati con `padosoft-settings:encryption-migrate`.
2. **`toArray()`/`toJson()` redigono i valori cifrati.** Chi li usa per leggere
   il valore deve passare a `$model->value` o a `settings('key')`.
3. **Gli hook del Model chiamano `syncFromModel()` invece di `set()`.** Chi
   avesse esteso il Model sovrascrivendo `booted()` deve aggiornarsi.
4. **`config_override` su una key cifrata viene ignorato** per default, perche'
   propagherebbe il segreto in chiaro dentro `config()` e potenzialmente in
   `bootstrap/cache/config.php`. Si riabilita con
   `padosoft-settings.encryption.allow_config_override = true`.
5. **La lettura di una key in allowlist con valore in chiaro lancia.** Prima
   restituiva il default.
6. **`Settings::getIsValidAttribute()` ora risponde davvero.** Prima restituiva
   `true` per qualunque valore, perche' `validate()` con `$throw = false`
   ritorna `null` invece di lanciare. Inoltre non stampa piu' l'errore con
   `echo`, che finiva nel body della response.

Le eccezioni di cifratura estendono
`Padosoft\Laravel\Settings\Exceptions\DecryptException`: il codice che gia'
cattura quel tipo continua a funzionare.

## Procedura di adozione

```bash
# 1. schema: allarga settings.value a TEXT (VARCHAR(255) non contiene un envelope)
php artisan vendor:publish --provider="Padosoft\Laravel\Settings\ServiceProvider"
php artisan migrate

# 2. chiave dedicata in .env
#    PADOSOFT_SETTINGS_ENCRYPTION_KEY=...
#    PADOSOFT_SETTINGS_ENCRYPTION_CONTEXT=<univoco per ambiente/tenant>

# 3. allowlist in config/padosoft-settings.php -> encrypted_keys

# 4. fotografia dello stato, senza modificare nulla
php artisan padosoft-settings:encryption-audit

# 5. dry-run e poi scrittura
php artisan padosoft-settings:encryption-migrate
php artisan padosoft-settings:encryption-migrate --write

# 6. verifica
php artisan padosoft-settings:encryption-audit
```

Vincoli sulle key cifrate:

- la `validation_rules` deve castare a stringa; una regola numerica romperebbe
  il valore anche con l'ordine corretto delle operazioni;
- `config_override` non e' ammesso (vedi sopra);
- la colonna `settings.value` deve essere `TEXT`.

## Rotazione della chiave

```bash
# 1. la nuova chiave viene distribuita come PREVIOUS su tutte le istanze
# 2. la si promuove a PADOSOFT_SETTINGS_ENCRYPTION_KEY e si rilascia
# 3. re-wrap
php artisan padosoft-settings:encryption-rotate --write
# 4. solo quando l'audit non riporta piu' quel key id, si rimuove la vecchia chiave
php artisan padosoft-settings:encryption-audit --json
```

La rotazione della chiave di cifratura **non** sostituisce la rotazione della
credenziale: se un segreto e' stato esposto, va revocato e riemesso presso il
provider.
