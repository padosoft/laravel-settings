<?php

namespace Padosoft\Laravel\Settings\Commands;

use Illuminate\Console\Command;
use Padosoft\Laravel\Settings\Encryption\EnvelopeCodec;
use Padosoft\Laravel\Settings\Encryption\SettingsCipher;
use Padosoft\Laravel\Settings\Settings;

/**
 * Fotografia dello stato di cifratura dei settings.
 *
 * Comando di sola lettura. L'output non contiene mai valori, ciphertext,
 * fingerprint del valore o lunghezze: la lunghezza di un segreto e' un side
 * channel e non va stampata.
 */
class EncryptionAuditCommand extends Command
{
    protected $signature = 'padosoft-settings:encryption-audit
                            {--json : Output in formato JSON}
                            {--only-drift : Mostra solo le righe con un problema}';

    protected $description = 'Verifica lo stato di cifratura dei settings senza modificare nulla';

    public const STATE_OK_ENCRYPTED = 'ok_encrypted';
    public const STATE_OK_PLAINTEXT = 'ok_plaintext';
    public const STATE_NEEDS_ENCRYPTION = 'needs_encryption';
    public const STATE_NEEDS_ROTATION = 'needs_rotation';
    public const STATE_UNKNOWN_KEY_ID = 'unknown_key_id';
    public const STATE_UNDECRYPTABLE = 'undecryptable';
    public const STATE_UNEXPECTED_ENCRYPTION = 'unexpected_encryption';

    public function handle(): int
    {
        $cipher = SettingsCipher::instance();
        $codec = $cipher->codec();
        $keyring = $codec->keyring();

        if (!$keyring->hasCurrentKey()) {
            $this->error('Nessuna chiave di cifratura disponibile: configurare padosoft-settings.encryption.key oppure app.key.');

            return self::FAILURE;
        }

        $rows = [];
        $counters = [];

        Settings::query()->orderBy('key')->chunk(500, function ($settings) use (&$rows, &$counters, $cipher, $codec, $keyring) {
            foreach ($settings as $setting) {
                $key = (string) $setting->key;
                $raw = $setting->getStoredValue();
                $state = $this->stateFor($key, $raw, $cipher, $codec, $keyring);

                $counters[$state] = ($counters[$state] ?? 0) + 1;
                $rows[] = [
                    'key' => $key,
                    'state' => $state,
                    'should_encrypt' => $cipher->shouldEncrypt($key) ? 'yes' : 'no',
                    'key_id' => is_string($raw) ? (EnvelopeCodec::keyIdOf($raw) ?? '') : '',
                    'has_config_override' => ($setting->config_override ?? '') !== '' ? 'yes' : 'no',
                    'load_on_startup' => (int) ($setting->load_on_startup ?? 0),
                    'validation_rules' => (string) ($setting->validation_rules ?? ''),
                ];
            }
        });

        $drifted = array_values(array_filter($rows, static fn ($row) => !in_array($row['state'], [self::STATE_OK_ENCRYPTED, self::STATE_OK_PLAINTEXT], true)));

        if ($this->option('json')) {
            $this->line(json_encode([
                'current_key_id' => $keyring->currentKid(),
                'known_key_ids' => $keyring->kids(),
                'strict' => $cipher->isStrict(),
                'allowlist_count' => count($cipher->allowlist()),
                'totals' => $counters,
                'drift_count' => count($drifted),
                'rows' => $this->option('only-drift') ? $drifted : $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $drifted === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Key id corrente: ' . $keyring->currentKid() . ' | key id noti: ' . implode(', ', $keyring->kids()));
        $this->info('Allowlist: ' . count($cipher->allowlist()) . ' key | strict: ' . ($cipher->isStrict() ? 'si' : 'no'));

        foreach ($counters as $state => $count) {
            $this->line(sprintf('  %-24s %d', $state, $count));
        }

        $toShow = $this->option('only-drift') ? $drifted : $rows;
        if ($toShow !== []) {
            $this->table(
                ['key', 'stato', 'da cifrare', 'key id', 'config_override', 'load_on_startup', 'validation_rules'],
                array_map('array_values', $toShow)
            );
        }

        if ($drifted !== []) {
            $this->warn(count($drifted) . ' righe richiedono un intervento.');

            return self::FAILURE;
        }

        $this->info('Nessun drift rilevato.');

        return self::SUCCESS;
    }

    protected function stateFor(string $key, $raw, SettingsCipher $cipher, EnvelopeCodec $codec, $keyring): string
    {
        $shouldEncrypt = $cipher->shouldEncrypt($key);

        if (!EnvelopeCodec::looksCanonical($raw)) {
            if (!$shouldEncrypt) {
                return self::STATE_OK_PLAINTEXT;
            }

            return self::STATE_NEEDS_ENCRYPTION;
        }

        $kid = EnvelopeCodec::keyIdOf((string) $raw);
        if ($kid === null || !$keyring->hasKid($kid)) {
            return self::STATE_UNKNOWN_KEY_ID;
        }

        $inspection = $cipher->inspect($key, $raw);
        if (!$inspection['ok']) {
            return self::STATE_UNDECRYPTABLE;
        }

        if (!$shouldEncrypt) {
            // Leggibile, ma la key non e' piu' in allowlist: va deciso se
            // reintrodurla o decifrarla esplicitamente.
            return self::STATE_UNEXPECTED_ENCRYPTION;
        }

        if ($kid !== $keyring->currentKid()) {
            return self::STATE_NEEDS_ROTATION;
        }

        return self::STATE_OK_ENCRYPTED;
    }
}
