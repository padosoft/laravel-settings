<?php

namespace Padosoft\Laravel\Settings\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Padosoft\Laravel\Settings\Encryption\EnvelopeCodec;
use Padosoft\Laravel\Settings\Encryption\SettingsCipher;
use Padosoft\Laravel\Settings\Settings;

/**
 * Cifra i valori delle key in allowlist che sono ancora in chiaro.
 *
 * Dry-run per default: senza --write non tocca nulla.
 * Idempotente: una riga gia' cifrata con la chiave corrente viene saltata, non
 * viene riscritta e updated_at non cambia.
 */
class EncryptionMigrateCommand extends Command
{
    protected $signature = 'padosoft-settings:encryption-migrate
                            {--key=* : Limita a specifiche key}
                            {--write : Esegue la scrittura (senza questo flag e\' un dry-run)}';

    protected $description = 'Cifra i settings in allowlist ancora memorizzati in chiaro';

    public function handle(): int
    {
        $cipher = SettingsCipher::instance();
        $codec = $cipher->codec();

        if (!$codec->keyring()->hasCurrentKey()) {
            $this->error('Nessuna chiave di cifratura disponibile: configurare padosoft-settings.encryption.key oppure app.key.');

            return self::FAILURE;
        }

        $allowlist = $cipher->allowlist();
        if ($allowlist === []) {
            $this->warn('Allowlist vuota: niente da fare.');

            return self::SUCCESS;
        }

        $only = array_filter((array) $this->option('key'));
        $targets = $only === [] ? $allowlist : array_values(array_intersect($allowlist, $only));

        if ($targets === []) {
            $this->error('Nessuna delle key indicate e\' presente in allowlist.');

            return self::FAILURE;
        }

        $write = (bool) $this->option('write');
        $summary = ['already_current' => 0, 'encrypted' => 0, 'needs_rotation' => 0, 'missing' => 0, 'failed' => 0];
        $failures = [];

        $run = function () use ($targets, $cipher, $codec, $write, &$summary, &$failures) {
            foreach ($targets as $key) {
                $query = Settings::query()->where('key', $key);
                if ($write) {
                    $query->lockForUpdate();
                }
                $model = $query->first();

                if ($model === null) {
                    $summary['missing']++;
                    continue;
                }

                $raw = $model->getStoredValue();

                if (EnvelopeCodec::looksCanonical($raw)) {
                    if ($codec->isEncryptedWithCurrentKey($raw)) {
                        // Idempotenza: nessuna scrittura, nessun evento, updated_at intatto.
                        $summary['already_current']++;
                        continue;
                    }
                    $summary['needs_rotation']++;
                    continue;
                }

                if (!$write) {
                    $summary['encrypted']++;
                    continue;
                }

                try {
                    $envelope = $codec->encode($key, $raw);
                    // Verifica di round-trip PRIMA di persistere: se non si
                    // rilegge, il valore originale non viene toccato.
                    if ($codec->decode($key, $envelope) !== $raw) {
                        throw new \RuntimeException('round-trip non verificato');
                    }
                    $model->setStoredValue($envelope);
                    $model->save();
                    $summary['encrypted']++;
                } catch (\Throwable $e) {
                    $summary['failed']++;
                    // Solo key e motivo: mai il valore.
                    $failures[] = $key . ' :: ' . $e->getMessage();
                }
            }
        };

        if ($write) {
            DB::transaction($run);
        } else {
            $run();
        }

        foreach ($summary as $label => $count) {
            $this->line(sprintf('  %-18s %d', $label, $count));
        }

        foreach ($failures as $failure) {
            $this->error('  ' . $failure);
        }

        if (!$write) {
            $this->warn('Dry-run: nessuna modifica applicata. Rilanciare con --write per eseguire.');

            return self::SUCCESS;
        }

        if ($summary['failed'] > 0) {
            $this->error('Migrazione conclusa con errori: la transazione e\' stata annullata se il fallimento ha interrotto il blocco.');

            return self::FAILURE;
        }

        settings()->clearCache();
        $this->info('Migrazione completata. Cache dei settings invalidata.');

        if ($summary['needs_rotation'] > 0) {
            $this->warn($summary['needs_rotation'] . ' righe sono cifrate con una chiave precedente: eseguire padosoft-settings:encryption-rotate.');
        }

        return self::SUCCESS;
    }
}
