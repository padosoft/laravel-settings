<?php

namespace Padosoft\Laravel\Settings\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Padosoft\Laravel\Settings\Encryption\EnvelopeCodec;
use Padosoft\Laravel\Settings\Encryption\SettingsCipher;
use Padosoft\Laravel\Settings\Settings;

/**
 * Re-wrap dei valori cifrati con una chiave precedente verso la chiave corrente.
 *
 * Procedura di rotazione senza downtime:
 *  1. si aggiunge la nuova chiave come previous su TUTTE le istanze e si rilascia;
 *  2. si promuove la nuova chiave a current e si rilascia;
 *  3. si esegue questo comando con --write;
 *  4. solo quando l'audit non riporta piu' righe con la vecchia chiave, la si
 *     rimuove dalle previous.
 */
class EncryptionRotateCommand extends Command
{
    protected $signature = 'padosoft-settings:encryption-rotate
                            {--key=* : Limita a specifiche key}
                            {--write : Esegue la scrittura (senza questo flag e\' un dry-run)}';

    protected $description = 'Ricifra con la chiave corrente i settings cifrati con una chiave precedente';

    public function handle(): int
    {
        $cipher = SettingsCipher::instance();
        $codec = $cipher->codec();
        $keyring = $codec->keyring();

        if (!$keyring->hasCurrentKey()) {
            $this->error('Nessuna chiave di cifratura disponibile: configurare padosoft-settings.encryption.key oppure app.key.');

            return self::FAILURE;
        }

        $only = array_filter((array) $this->option('key'));
        $write = (bool) $this->option('write');
        $summary = ['already_current' => 0, 'rewrapped' => 0, 'plaintext' => 0, 'failed' => 0];
        $failures = [];

        $run = function () use ($only, $cipher, $codec, $keyring, $write, &$summary, &$failures) {
            $query = Settings::query()->orderBy('key');
            if ($only !== []) {
                $query->whereIn('key', $only);
            }
            if ($write) {
                $query->lockForUpdate();
            }

            foreach ($query->get() as $model) {
                $key = (string) $model->key;
                $raw = $model->getStoredValue();

                if (!EnvelopeCodec::looksCanonical($raw)) {
                    if ($cipher->shouldEncrypt($key)) {
                        $summary['plaintext']++;
                    }
                    continue;
                }

                if ($codec->isEncryptedWithCurrentKey($raw)) {
                    $summary['already_current']++;
                    continue;
                }

                if (!$write) {
                    $summary['rewrapped']++;
                    continue;
                }

                try {
                    $plain = $codec->decode($key, (string) $raw);
                    $envelope = $codec->encode($key, $plain);
                    if ($codec->decode($key, $envelope) !== $plain) {
                        throw new \RuntimeException('round-trip non verificato');
                    }
                    $model->setStoredValue($envelope);
                    $model->save();
                    $summary['rewrapped']++;
                } catch (\Throwable $e) {
                    $summary['failed']++;
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
            return self::FAILURE;
        }

        settings()->clearCache();
        $this->info('Rotazione completata. Cache dei settings invalidata.');
        $this->warn('Rimuovere la chiave precedente dal keyring solo dopo che encryption-audit non riporta piu\' righe con quel key id.');

        return self::SUCCESS;
    }
}
