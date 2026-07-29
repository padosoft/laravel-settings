<?php
return [
    'enabled' => false,
    'default_connection' => 'default',
    'local_connection' => null,
    'enable_redis_log_failure' => false,
    'local_expire' => 300,
    /*
    |--------------------------------------------------------------------------
    | Key con valore cifrato a riposo
    |--------------------------------------------------------------------------
    |
    | Elenco delle key il cui valore viene cifrato nel database E in cache.
    | Una key rimossa da questo elenco resta leggibile: gli envelope canonici
    | vengono sempre decifrati, indipendentemente dall'allowlist.
    |
    | Vincoli:
    |  - la validation_rule deve castare a stringa (una regola numerica
    |    distruggerebbe il valore);
    |  - config_override non e' ammesso su una key cifrata, vedi
    |    encryption.allow_config_override.
    |
    */
    'encrypted_keys' => [],

    'encryption' => [
        // Chiave dedicata ai settings. Separata da APP_KEY di proposito: ruotare
        // APP_KEY (sessioni, cookie, signed URL) non deve rendere illeggibili i
        // settings. Se vuota si ricade su app.key.
        'key' => env('PADOSOFT_SETTINGS_ENCRYPTION_KEY'),

        // Chiavi accettate solo in lettura, CSV. Servono a ruotare senza
        // downtime: prima si distribuisce la nuova chiave come previous su tutte
        // le istanze, poi la si promuove a current, infine si re-wrappa.
        'previous_keys' => env('PADOSOFT_SETTINGS_PREVIOUS_ENCRYPTION_KEYS', ''),

        // Contesto di deployment, legato crittograficamente al valore: impedisce
        // che un envelope copiato da un altro ambiente venga decifrato qui.
        // Va reso univoco per tenant/ambiente.
        'context' => env('PADOSOFT_SETTINGS_ENCRYPTION_CONTEXT', ''),

        // Se true il context deve corrispondere sempre, anche quando e' vuoto.
        'require_context' => env('PADOSOFT_SETTINGS_ENCRYPTION_REQUIRE_CONTEXT', false),

        // Fail closed. Se una key e' nell'allowlist ma il valore memorizzato e'
        // in chiaro (tipico di una scrittura via SQL diretto), la lettura lancia
        // invece di restituire il default: una credenziale vuota che parte verso
        // un provider e' peggio di un errore.
        'strict' => env('PADOSOFT_SETTINGS_ENCRYPTION_STRICT', true),

        // config_override su una key cifrata propaga il valore in chiaro dentro
        // config(), e quindi potenzialmente in bootstrap/cache/config.php.
        'allow_config_override' => false,

        'cipher' => 'AES-256-CBC',
    ],

    'cast' => [
        //Esempio
        //'boolean' => ['class' => \Padosoft\Laravel\Settings\CastSettings::class, 'method' => 'boolean'],
        //'booleanFromString' => ['class' => \Padosoft\Laravel\Settings\CastSettings::class]
    ],
];
