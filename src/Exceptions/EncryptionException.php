<?php

namespace Padosoft\Laravel\Settings\Exceptions;

/**
 * Errore della pipeline di cifratura dei settings.
 *
 * Estende DecryptException per retrocompatibilita': il codice che oggi cattura
 * Padosoft\Laravel\Settings\Exceptions\DecryptException continua a funzionare
 * senza modifiche.
 *
 * ATTENZIONE: il messaggio di questa eccezione finisce nei log e nelle pagine di
 * errore. Non deve MAI contenere il valore di un setting, il ciphertext, la
 * chiave di cifratura o la lunghezza del valore (side channel).
 * E' ammesso citare la key del setting e un motivo sintetico.
 */
class EncryptionException extends DecryptException
{
    public static function unknownKeyId(string $settingKey, string $keyId): self
    {
        return new self(sprintf(
            'Settings "%s": nessuna chiave di cifratura corrisponde al key id "%s". Aggiungere la chiave al keyring (padosoft-settings.encryption.previous_keys) prima di leggere il valore.',
            $settingKey,
            $keyId
        ));
    }

    public static function undecryptable(string $settingKey): self
    {
        return new self(sprintf(
            'Settings "%s": valore non decifrabile con nessuna chiave del keyring.',
            $settingKey
        ));
    }

    public static function malformedEnvelope(string $settingKey): self
    {
        return new self(sprintf(
            'Settings "%s": envelope cifrato malformato.',
            $settingKey
        ));
    }

    public static function keyBindingMismatch(string $settingKey): self
    {
        return new self(sprintf(
            'Settings "%s": l\'envelope e\' stato cifrato per un\'altra key. Possibile copia di un valore fra settings diversi.',
            $settingKey
        ));
    }

    public static function contextMismatch(string $settingKey): self
    {
        return new self(sprintf(
            'Settings "%s": l\'envelope appartiene a un altro context di deployment.',
            $settingKey
        ));
    }

    public static function expectedEncryptedValue(string $settingKey): self
    {
        return new self(sprintf(
            'Settings "%s": la key e\' dichiarata cifrata ma il valore memorizzato e\' in chiaro. Eseguire "padosoft-settings:encryption-migrate --write" oppure rimuovere la key dall\'allowlist.',
            $settingKey
        ));
    }

    public static function alreadyEncrypted(string $settingKey): self
    {
        return new self(sprintf(
            'Settings "%s": il valore da cifrare e\' gia\' un envelope canonico. Cifrarlo di nuovo produrrebbe un doppio strato.',
            $settingKey
        ));
    }

    public static function missingKey(string $settingKey): self
    {
        return new self(sprintf(
            'Settings "%s": nessuna chiave di cifratura configurata. Impostare padosoft-settings.encryption.key oppure app.key.',
            $settingKey
        ));
    }
}
