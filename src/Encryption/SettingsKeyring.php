<?php

namespace Padosoft\Laravel\Settings\Encryption;

use Illuminate\Encryption\Encrypter;

/**
 * Keyring dedicato alla cifratura dei settings.
 *
 * La chiave dei settings e' separata da APP_KEY: ruotare APP_KEY (sessioni,
 * cookie, signed URL) non deve rendere illeggibili i settings, e viceversa.
 * Se non e' configurata una chiave dedicata si ricade su app.key, cosi' il
 * comportamento storico del package resta valido.
 *
 * Ogni chiave ha un "key id" (kid) che viene scritto in chiaro nell'envelope.
 * Il kid e' un digest troncato e non reversibile: serve a sapere con quale
 * chiave e' stato prodotto un valore senza doverle provare tutte, e rende
 * l'idempotenza della migrazione verificabile in O(1).
 */
class SettingsKeyring
{
    /** @var array<string, Encrypter> kid => encrypter */
    protected array $encrypters = [];

    protected ?string $currentKid = null;

    protected string $cipher;

    /**
     * @param string|null $currentKey chiave corrente (usata in scrittura)
     * @param array<int, string> $previousKeys chiavi accettate solo in lettura
     */
    public function __construct(?string $currentKey = null, array $previousKeys = [], string $cipher = 'AES-256-CBC')
    {
        $this->cipher = $cipher;

        $normalizedCurrent = $this->normalizeKey($currentKey);
        if ($normalizedCurrent !== null) {
            $this->currentKid = self::keyId($normalizedCurrent);
            $this->encrypters[$this->currentKid] = new Encrypter($normalizedCurrent, $cipher);
        }

        foreach ($previousKeys as $previousKey) {
            $normalized = $this->normalizeKey($previousKey);
            if ($normalized === null) {
                continue;
            }
            $kid = self::keyId($normalized);
            if (array_key_exists($kid, $this->encrypters)) {
                continue;
            }
            $this->encrypters[$kid] = new Encrypter($normalized, $cipher);
        }
    }

    /**
     * Costruisce il keyring dalla config, con fallback su app.key.
     */
    public static function fromConfig(): self
    {
        $cipher = (string) config('padosoft-settings.encryption.cipher', 'AES-256-CBC');

        $current = config('padosoft-settings.encryption.key');
        if ($current === null || $current === '') {
            $current = config('app.key');
        }

        $previous = config('padosoft-settings.encryption.previous_keys', []);
        if (is_string($previous)) {
            $previous = explode(',', $previous);
        }
        if (!is_array($previous)) {
            $previous = [];
        }
        $previous = array_values(array_filter(array_map('trim', $previous), static fn ($k) => $k !== ''));

        // Le chiavi applicative precedenti restano valide in lettura: coprono il
        // caso di un valore cifrato quando il package usava ancora app.key.
        $appPrevious = config('app.previous_keys', []);
        if (is_array($appPrevious)) {
            $previous = array_merge($previous, array_values(array_filter($appPrevious)));
        }
        $appKey = config('app.key');
        if (is_string($appKey) && $appKey !== '') {
            $previous[] = $appKey;
        }

        return new self(is_string($current) ? $current : null, $previous, $cipher);
    }

    public function hasCurrentKey(): bool
    {
        return $this->currentKid !== null;
    }

    public function currentKid(): ?string
    {
        return $this->currentKid;
    }

    public function currentEncrypter(): ?Encrypter
    {
        if ($this->currentKid === null) {
            return null;
        }

        return $this->encrypters[$this->currentKid];
    }

    public function encrypterFor(string $kid): ?Encrypter
    {
        return $this->encrypters[$kid] ?? null;
    }

    public function hasKid(string $kid): bool
    {
        return array_key_exists($kid, $this->encrypters);
    }

    /**
     * @return array<int, string>
     */
    public function kids(): array
    {
        return array_keys($this->encrypters);
    }

    /**
     * Identificativo pubblico e non reversibile di una chiave.
     * Non espone materiale crittografico: e' un digest troncato con label.
     */
    public static function keyId(string $rawKey): string
    {
        return substr(hash('sha256', 'pdsenc-kid|' . $rawKey), 0, 8);
    }

    /**
     * Accetta sia il formato "base64:..." di Laravel sia la chiave grezza.
     */
    protected function normalizeKey(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        if (strncmp($key, 'base64:', strlen('base64:')) === 0) {
            $decoded = base64_decode(substr($key, 7), true);
            return $decoded === false ? null : $decoded;
        }

        return $key;
    }
}
