<?php

namespace Padosoft\Laravel\Settings\Encryption;

use Illuminate\Contracts\Encryption\DecryptException;
use Padosoft\Laravel\Settings\Exceptions\EncryptionException;

/**
 * Codec dell'envelope cifrato dei settings.
 *
 * Formato memorizzato:
 *
 *     pdsenc:v1:<kid>:<payload Laravel>
 *
 * dove <payload Laravel> e' il risultato di Encrypter::encryptString() del JSON
 *
 *     {"k":"<key del setting>","c":"<context>","v":<valore>}
 *
 * Perche' key e context stanno DENTRO il payload cifrato: il MAC di Laravel
 * autentica il ciphertext ma non il contesto d'uso. Mettendoli all'interno si
 * ottiene il binding — un envelope copiato da un altro setting o da un altro
 * ambiente non passa la verifica invece di decifrare silenziosamente il valore
 * sbagliato.
 *
 * Perche' encryptString e non encrypt: encrypt() serializza il valore e in
 * decifratura chiama unserialize(). Restare su stringa elimina del tutto quella
 * superficie.
 *
 * Il kid resta in chiaro fuori dal payload: e' un digest non reversibile e
 * serve a scegliere la chiave senza provarle tutte, e a rendere l'idempotenza
 * della migrazione verificabile senza decifrare.
 */
class EnvelopeCodec
{
    public const PREFIX = 'pdsenc:';
    public const VERSION = 'v1';

    protected SettingsKeyring $keyring;

    protected string $context;

    protected bool $requireContext;

    public function __construct(?SettingsKeyring $keyring = null, ?string $context = null, ?bool $requireContext = null)
    {
        $this->keyring = $keyring ?? SettingsKeyring::fromConfig();
        $this->context = $context ?? (string) config('padosoft-settings.encryption.context', '');
        $this->requireContext = $requireContext ?? (bool) config('padosoft-settings.encryption.require_context', false);
    }

    public function keyring(): SettingsKeyring
    {
        return $this->keyring;
    }

    /**
     * Riconosce la forma canonica. Attenzione: "sembra canonico" non vuol dire
     * "e' autentico" — l'autenticita' la stabilisce solo decode().
     */
    public static function looksCanonical($raw): bool
    {
        if (!is_string($raw) || strncmp($raw, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            return false;
        }

        return self::parse($raw) !== null;
    }

    /**
     * @return array{version: string, kid: string, payload: string}|null
     */
    public static function parse(string $raw): ?array
    {
        if (strncmp($raw, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            return null;
        }

        $parts = explode(':', substr($raw, strlen(self::PREFIX)), 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$version, $kid, $payload] = $parts;
        if ($version === '' || $kid === '' || $payload === '') {
            return null;
        }

        return ['version' => $version, 'kid' => $kid, 'payload' => $payload];
    }

    public static function keyIdOf(string $raw): ?string
    {
        $parsed = self::parse($raw);

        return $parsed === null ? null : $parsed['kid'];
    }

    /**
     * True se il valore e' gia' cifrato con la chiave corrente: e' il predicato
     * su cui poggiano l'idempotenza di encryption-migrate e il re-wrap di
     * encryption-rotate.
     */
    public function isEncryptedWithCurrentKey($raw): bool
    {
        if (!is_string($raw)) {
            return false;
        }
        $parsed = self::parse($raw);
        if ($parsed === null) {
            return false;
        }

        return $parsed['kid'] === $this->keyring->currentKid();
    }

    /**
     * Cifra un valore in chiaro producendo l'envelope canonico.
     *
     * @param string $settingKey key del setting, legata crittograficamente al valore
     * @param string|null $plain valore in chiaro
     */
    public function encode(string $settingKey, $plain): string
    {
        // Guard anti doppio strato e anti prefix-confusion: un valore che e' gia'
        // (o finge di essere) un envelope non viene mai ri-cifrato in silenzio.
        if (is_string($plain) && strncmp($plain, self::PREFIX, strlen(self::PREFIX)) === 0) {
            throw EncryptionException::alreadyEncrypted($settingKey);
        }

        $encrypter = $this->keyring->currentEncrypter();
        if ($encrypter === null) {
            throw EncryptionException::missingKey($settingKey);
        }

        $inner = json_encode([
            'k' => $settingKey,
            'c' => $this->context,
            'v' => $plain,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return self::PREFIX . self::VERSION . ':' . $this->keyring->currentKid() . ':' . $encrypter->encryptString($inner);
    }

    /**
     * Decifra un envelope canonico verificando versione, chiave, binding e context.
     *
     * @return string|null il valore in chiaro
     */
    public function decode(string $settingKey, string $raw)
    {
        $parsed = self::parse($raw);
        if ($parsed === null) {
            throw EncryptionException::malformedEnvelope($settingKey);
        }

        if ($parsed['version'] !== self::VERSION) {
            throw EncryptionException::malformedEnvelope($settingKey);
        }

        $encrypter = $this->keyring->encrypterFor($parsed['kid']);
        if ($encrypter === null) {
            throw EncryptionException::unknownKeyId($settingKey, $parsed['kid']);
        }

        try {
            $inner = $encrypter->decryptString($parsed['payload']);
        } catch (DecryptException $e) {
            // Il messaggio originale non viene propagato: potrebbe finire nei log.
            throw EncryptionException::undecryptable($settingKey);
        }

        try {
            $data = json_decode($inner, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw EncryptionException::malformedEnvelope($settingKey);
        }

        if (!is_array($data) || !array_key_exists('v', $data)) {
            throw EncryptionException::malformedEnvelope($settingKey);
        }

        if (($data['k'] ?? null) !== $settingKey) {
            throw EncryptionException::keyBindingMismatch($settingKey);
        }

        $envelopeContext = (string) ($data['c'] ?? '');
        if ($this->requireContext && $envelopeContext !== $this->context) {
            throw EncryptionException::contextMismatch($settingKey);
        }
        if (!$this->requireContext && $this->context !== '' && $envelopeContext !== $this->context) {
            throw EncryptionException::contextMismatch($settingKey);
        }

        return $data['v'];
    }
}
