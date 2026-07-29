<?php

namespace Padosoft\Laravel\Settings\Encryption;

use Illuminate\Support\Facades\Log;
use Padosoft\Laravel\Settings\Exceptions\EncryptionException;

/**
 * UNICO punto di cifratura/decifratura dei settings.
 *
 * Model e SettingsManager non implementano crittografia: chiamano entrambi
 * toStorage()/fromStorage(). E' questa la ragione per cui non puo' piu'
 * verificarsi il doppio strato che affliggeva le versioni 6.x, dove il mutator
 * del Model e SettingsManager::set() cifravano entrambi lo stesso valore.
 *
 * Invariante: cio' che sta nel database e cio' che sta in cache sono lo stesso
 * identico raw. La decifratura avviene solo al confine di lettura.
 */
class SettingsCipher
{
    protected static ?self $instance = null;

    protected static ?string $signature = null;

    protected EnvelopeCodec $codec;

    public function __construct(?EnvelopeCodec $codec = null)
    {
        $this->codec = $codec ?? new EnvelopeCodec();
    }

    /**
     * Il keyring viene ricostruito quando la configurazione di cifratura cambia:
     * serve ai test, che impostano le chiavi a runtime, e alla promozione a
     * caldo di una chiave durante una rotazione.
     */
    public static function instance(): self
    {
        $signature = self::configSignature();

        if (self::$instance === null || self::$signature !== $signature) {
            self::$instance = new self();
            self::$signature = $signature;
        }

        return self::$instance;
    }

    /**
     * Da invocare quando cambia la configurazione (tipicamente nei test).
     */
    public static function flush(): void
    {
        self::$instance = null;
        self::$signature = null;
    }

    protected static function configSignature(): string
    {
        return md5(json_encode([
            config('padosoft-settings.encryption'),
            config('app.key'),
            config('app.previous_keys'),
            config('app.cipher'),
        ]));
    }

    public function codec(): EnvelopeCodec
    {
        return $this->codec;
    }

    /**
     * Letta dalla config a ogni chiamata: l'allowlist puo' cambiare a runtime e
     * memorizzarla renderebbe incoerente il comportamento dopo un config().
     *
     * @return array<int, string>
     */
    public function allowlist(): array
    {
        $configured = config('padosoft-settings.encrypted_keys', []);

        return is_array($configured) ? array_values($configured) : [];
    }

    public function shouldEncrypt(string $settingKey): bool
    {
        return in_array($settingKey, $this->allowlist(), true);
    }

    public function isStrict(): bool
    {
        return (bool) config('padosoft-settings.encryption.strict', true);
    }

    /**
     * Prepara il valore per la persistenza (DB e cache condividono questo raw).
     *
     * @param string|null $plain
     * @return string|null
     */
    public function toStorage(string $settingKey, $plain)
    {
        if (!$this->shouldEncrypt($settingKey)) {
            return $plain;
        }

        return $this->codec->encode($settingKey, $plain);
    }

    /**
     * Riporta in chiaro un valore letto da DB o cache.
     *
     * Un envelope canonico viene sempre decifrato, anche se la key non e' piu'
     * nell'allowlist: togliere una key dall'allowlist non deve rendere
     * illeggibili i dati gia' scritti.
     *
     * @param string|null $raw
     * @return string|null
     */
    public function fromStorage(string $settingKey, $raw)
    {
        if ($raw === null) {
            return null;
        }

        if (EnvelopeCodec::looksCanonical($raw)) {
            return $this->codec->decode($settingKey, $raw);
        }

        if (!$this->shouldEncrypt($settingKey)) {
            return $raw;
        }

        // La key e' dichiarata cifrata ma il valore memorizzato e' in chiaro.
        // Succede quando qualcuno scrive con SQL diretto bypassando il Model, o
        // quando una key viene aggiunta all'allowlist prima della migrazione.
        // Il default e' fallire chiuso: restituire il default silenziosamente
        // farebbe partire le integrazioni con una credenziale vuota.
        if ($this->isStrict()) {
            throw EncryptionException::expectedEncryptedValue($settingKey);
        }

        Log::warning('[padosoft-settings] key dichiarata cifrata con valore in chiaro nello storage.', [
            'key' => $settingKey,
        ]);

        return $raw;
    }

    /**
     * Come fromStorage() ma senza mai lanciare: usata dai command di audit, che
     * devono poter fotografare anche uno stato incoerente.
     *
     * @return array{ok: bool, value: string|null, reason: string|null}
     */
    public function inspect(string $settingKey, $raw): array
    {
        try {
            return ['ok' => true, 'value' => $this->fromStorage($settingKey, $raw), 'reason' => null];
        } catch (EncryptionException $e) {
            return ['ok' => false, 'value' => null, 'reason' => $e->getMessage()];
        }
    }
}
