<?php

namespace Padosoft\Laravel\Settings\Test;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Padosoft\Laravel\Settings\Encryption\EnvelopeCodec;
use Padosoft\Laravel\Settings\Encryption\SettingsCipher;
use Padosoft\Laravel\Settings\Exceptions\EncryptionException;
use Padosoft\Laravel\Settings\Settings;
use Padosoft\Laravel\Settings\SettingsManager;

/**
 * Copertura della pipeline di cifratura.
 *
 * I test piu' importanti sono i quattro che incrociano il percorso di scrittura
 * (Eloquent oppure Manager) con lo stato della cache (calda oppure fredda).
 * Nelle 6.x nessuno dei due percorsi era corretto in entrambi gli stati:
 * la scrittura via Eloquent esplodeva a cache fredda dopo aver scritto il
 * valore in chiaro in Redis, e setAndStore() restituiva il ciphertext a cache
 * calda perche' il valore veniva cifrato due volte.
 */
class EncryptionTest extends TestCase
{
    protected const KEY_A = 'abcdefghijklmnopqrstuvwxyz012345';
    protected const KEY_B = '543210zyxwvutsrqponmlkjihgfedcba';

    protected const SECRET_KEY = 'integration.api.secret';
    protected const SECRET_VALUE = 'valore-di-prova-non-un-segreto-reale';

    public function setUp(): void
    {
        parent::setUp();

        config(['padosoft-settings.encryption.key' => self::KEY_A]);
        config(['padosoft-settings.encryption.previous_keys' => '']);
        config(['padosoft-settings.encryption.context' => 'test-context']);
        config(['padosoft-settings.encryption.require_context' => false]);
        config(['padosoft-settings.encryption.strict' => true]);
        config(['padosoft-settings.encrypted_keys' => [self::SECRET_KEY]]);

        SettingsCipher::flush();
    }

    protected function tearDown(): void
    {
        SettingsCipher::flush();
        parent::tearDown();
    }

    /**
     * Simula un processo PHP nuovo: cache Redis svuotata e Manager ricostruito.
     */
    protected function coldManager(): SettingsManager
    {
        settings()->clearCache();
        $this->app->forgetInstance(SettingsManager::class);
        $this->app->forgetInstance('Padosoft\Laravel\Settings\SettingsManager');

        return settings();
    }

    protected function rawFromDatabase(string $key): ?string
    {
        $row = DB::table('settings')->where('key', $key)->first();

        return $row === null ? null : $row->value;
    }

    protected function createSecretViaEloquent(string $value = self::SECRET_VALUE): Settings
    {
        $model = new Settings();
        $model->key = self::SECRET_KEY;
        $model->value = $value;
        $model->validation_rules = 'nullable|string';
        $model->save();

        return $model;
    }

    // ---------------------------------------------------------------------
    // I quattro percorsi che nelle 6.x divergevano
    // ---------------------------------------------------------------------

    #[Test]
    public function eloquentWriteThenWarmReadReturnsPlaintext(): void
    {
        $this->createSecretViaEloquent();

        $this->assertSame(self::SECRET_VALUE, settings(self::SECRET_KEY));
    }

    #[Test]
    public function eloquentWriteThenColdReadReturnsPlaintext(): void
    {
        $this->createSecretViaEloquent();

        $manager = $this->coldManager();

        $this->assertSame(self::SECRET_VALUE, $manager->get(self::SECRET_KEY));
    }

    #[Test]
    public function managerWriteThenWarmReadReturnsPlaintextNotCiphertext(): void
    {
        settings()->UpdateOrCreate(self::SECRET_KEY, 'test', 'iniziale', 'nullable|string');
        settings()->setAndStore(self::SECRET_KEY, self::SECRET_VALUE, 'nullable|string');

        $value = settings(self::SECRET_KEY);

        $this->assertSame(self::SECRET_VALUE, $value);
        $this->assertStringStartsNotWith(EnvelopeCodec::PREFIX, (string) $value);
    }

    #[Test]
    public function managerWriteThenColdReadReturnsPlaintext(): void
    {
        settings()->UpdateOrCreate(self::SECRET_KEY, 'test', 'iniziale', 'nullable|string');
        settings()->setAndStore(self::SECRET_KEY, self::SECRET_VALUE, 'nullable|string');

        $manager = $this->coldManager();

        $this->assertSame(self::SECRET_VALUE, $manager->get(self::SECRET_KEY));
    }

    // ---------------------------------------------------------------------
    // Invarianti dello storage
    // ---------------------------------------------------------------------

    #[Test]
    public function databaseHoldsExactlyOneEncryptionLayer(): void
    {
        $this->createSecretViaEloquent();

        $raw = $this->rawFromDatabase(self::SECRET_KEY);

        $this->assertNotNull($raw);
        $this->assertTrue(EnvelopeCodec::looksCanonical($raw));
        $this->assertStringNotContainsString(self::SECRET_VALUE, $raw);

        // Un solo strato: la decifratura restituisce direttamente il plaintext,
        // non un secondo envelope.
        $decoded = SettingsCipher::instance()->codec()->decode(self::SECRET_KEY, $raw);
        $this->assertSame(self::SECRET_VALUE, $decoded);
        $this->assertStringStartsNotWith(EnvelopeCodec::PREFIX, $decoded);
    }

    #[Test]
    public function managerWriteAlsoStoresExactlyOneLayer(): void
    {
        settings()->UpdateOrCreate(self::SECRET_KEY, 'test', 'iniziale', 'nullable|string');
        settings()->setAndStore(self::SECRET_KEY, self::SECRET_VALUE, 'nullable|string');

        $raw = $this->rawFromDatabase(self::SECRET_KEY);

        $this->assertTrue(EnvelopeCodec::looksCanonical($raw));
        $this->assertSame(
            self::SECRET_VALUE,
            SettingsCipher::instance()->codec()->decode(self::SECRET_KEY, $raw)
        );
    }

    #[Test]
    public function plaintextNeverReachesTheCache(): void
    {
        $this->createSecretViaEloquent();

        // scrittura -> cache popolata dagli hook del Model
        $cached = json_encode($this->cacheSnapshot());
        $this->assertStringNotContainsString(self::SECRET_VALUE, $cached);

        // lettura a freddo -> cache ripopolata dal database
        $manager = $this->coldManager();
        $manager->get(self::SECRET_KEY);

        $cached = json_encode($this->cacheSnapshot());
        $this->assertStringNotContainsString(self::SECRET_VALUE, $cached);
    }

    protected function cacheSnapshot(): array
    {
        $redisKey = 'laravel_pds_settings' . DB::connection()->getDatabaseName();

        return \Padosoft\Laravel\Settings\SettingsRedisRepository::hgetall($redisKey) ?: [];
    }

    // ---------------------------------------------------------------------
    // Validazione e cast
    // ---------------------------------------------------------------------

    #[Test]
    public function numericValidationRuleSurvivesEncryption(): void
    {
        $key = 'integration.numeric.secret';
        config(['padosoft-settings.encrypted_keys' => [$key]]);
        SettingsCipher::flush();

        $model = new Settings();
        $model->key = $key;
        $model->value = '4242';
        $model->validation_rules = 'integer';
        $model->save();

        // Nelle 6.x il cast a intero veniva applicato al ciphertext, che
        // diventava 0, e la decifratura falliva subito dopo.
        $this->assertSame(4242, settings($key));
        $this->assertSame(4242, $this->coldManager()->get($key));
    }

    #[Test]
    public function validationRunsOnPlaintextSoTheWriteIsNotSilentlyDropped(): void
    {
        $key = 'integration.url.secret';
        config(['padosoft-settings.encrypted_keys' => [$key]]);
        SettingsCipher::flush();

        settings()->UpdateOrCreate($key, 'test', 'https://example.invalid/a', 'url');
        settings()->setAndStore($key, 'https://example.invalid/b', 'url');

        // Con la validazione sul ciphertext la regola url fallirebbe sempre e
        // set() uscirebbe senza scrivere nulla.
        $this->assertSame('https://example.invalid/b', settings($key));
    }

    // ---------------------------------------------------------------------
    // Fail closed
    // ---------------------------------------------------------------------

    #[Test]
    public function plaintextInDatabaseForAnEncryptedKeyFailsClosed(): void
    {
        // Scrittura via SQL diretto: e' cio' che fanno gli script di import.
        DB::table('settings')->insert([
            'key' => self::SECRET_KEY,
            'descr' => 'inserito via SQL',
            'value' => 'valore-in-chiaro',
            'validation_rules' => 'nullable|string',
            'config_override' => '',
            'load_on_startup' => 0,
        ]);

        $manager = $this->coldManager();

        $this->expectException(EncryptionException::class);
        $manager->get(self::SECRET_KEY);
    }

    #[Test]
    public function nonStrictModeDegradesInsteadOfThrowing(): void
    {
        config(['padosoft-settings.encryption.strict' => false]);
        SettingsCipher::flush();

        DB::table('settings')->insert([
            'key' => self::SECRET_KEY,
            'descr' => 'inserito via SQL',
            'value' => 'valore-in-chiaro',
            'validation_rules' => 'nullable|string',
            'config_override' => '',
            'load_on_startup' => 0,
        ]);

        $this->assertSame('valore-in-chiaro', $this->coldManager()->get(self::SECRET_KEY));
    }

    #[Test]
    public function readingNeverFallsBackToTheDefaultOnADecryptionFailure(): void
    {
        $this->createSecretViaEloquent();

        // Chiave sostituita: l'envelope non e' piu' decifrabile.
        config(['padosoft-settings.encryption.key' => self::KEY_B]);
        config(['padosoft-settings.encryption.previous_keys' => '']);
        config(['app.key' => self::KEY_B]);
        SettingsCipher::flush();

        $manager = $this->coldManager();

        $this->expectException(EncryptionException::class);
        $manager->get(self::SECRET_KEY, 'fallback-silenzioso');
    }

    // ---------------------------------------------------------------------
    // Binding e anti-abuso
    // ---------------------------------------------------------------------

    #[Test]
    public function envelopeIsBoundToItsSettingKey(): void
    {
        $codec = SettingsCipher::instance()->codec();
        $envelope = $codec->encode(self::SECRET_KEY, self::SECRET_VALUE);

        $this->expectException(EncryptionException::class);
        $codec->decode('another.setting.key', $envelope);
    }

    #[Test]
    public function envelopeIsBoundToItsContext(): void
    {
        $codec = SettingsCipher::instance()->codec();
        $envelope = $codec->encode(self::SECRET_KEY, self::SECRET_VALUE);

        config(['padosoft-settings.encryption.context' => 'un-altro-ambiente']);
        SettingsCipher::flush();

        $this->expectException(EncryptionException::class);
        SettingsCipher::instance()->codec()->decode(self::SECRET_KEY, $envelope);
    }

    #[Test]
    public function encodingAnAlreadyCanonicalValueIsRejected(): void
    {
        $codec = SettingsCipher::instance()->codec();
        $envelope = $codec->encode(self::SECRET_KEY, self::SECRET_VALUE);

        // Guard anti doppio strato: senza questo, un envelope riassegnato a
        // $model->value verrebbe cifrato una seconda volta in silenzio.
        $this->expectException(EncryptionException::class);
        $codec->encode(self::SECRET_KEY, $envelope);
    }

    #[Test]
    public function plainValuesContainingColonsAreNotMistakenForEnvelopes(): void
    {
        // Un valore in chiaro con piu' due punti non deve essere scambiato per un
        // envelope: il riconoscimento si basa sul prefisso, non sulla presenza di
        // separatori. Senza questo controllo una connection string o un URL con
        // porta manderebbe in eccezione la lettura di un setting non cifrato.
        foreach ([
            'redis://utente:password-non-reale@host:6379/0',
            'https://example.invalid:8443/percorso:con:due-punti',
            'a:b:c',
            'pdsenc-ma-non-davvero:v1:xxx',
        ] as $plain) {
            $this->assertFalse(EnvelopeCodec::looksCanonical($plain), 'atteso non canonico: ' . $plain);
        }

        $model = new Settings();
        $model->key = 'public.connection';
        $model->value = 'redis://utente:password-non-reale@host:6379/0';
        $model->save();

        $this->assertSame('redis://utente:password-non-reale@host:6379/0', $this->coldManager()->get('public.connection'));
    }

    #[Test]
    public function tamperedEnvelopeIsRejected(): void
    {
        $codec = SettingsCipher::instance()->codec();
        $envelope = $codec->encode(self::SECRET_KEY, self::SECRET_VALUE);
        $tampered = substr($envelope, 0, -4) . 'AAAA';

        $this->expectException(EncryptionException::class);
        $codec->decode(self::SECRET_KEY, $tampered);
    }

    // ---------------------------------------------------------------------
    // Rotazione delle chiavi
    // ---------------------------------------------------------------------

    #[Test]
    public function valueEncryptedWithAPreviousKeyIsStillReadable(): void
    {
        $this->createSecretViaEloquent();
        $rawWithKeyA = $this->rawFromDatabase(self::SECRET_KEY);

        // Promozione della nuova chiave: la precedente resta nel keyring.
        config(['padosoft-settings.encryption.key' => self::KEY_B]);
        config(['padosoft-settings.encryption.previous_keys' => self::KEY_A]);
        SettingsCipher::flush();

        $this->assertSame(
            self::SECRET_VALUE,
            SettingsCipher::instance()->codec()->decode(self::SECRET_KEY, $rawWithKeyA)
        );
        $this->assertSame(self::SECRET_VALUE, $this->coldManager()->get(self::SECRET_KEY));
    }

    #[Test]
    public function rotateCommandRewrapsToTheCurrentKey(): void
    {
        $this->createSecretViaEloquent();
        $before = $this->rawFromDatabase(self::SECRET_KEY);
        $kidBefore = EnvelopeCodec::keyIdOf($before);

        config(['padosoft-settings.encryption.key' => self::KEY_B]);
        config(['padosoft-settings.encryption.previous_keys' => self::KEY_A]);
        SettingsCipher::flush();

        $this->artisan('padosoft-settings:encryption-rotate', ['--write' => true])->assertExitCode(0);

        $after = $this->rawFromDatabase(self::SECRET_KEY);
        $kidAfter = EnvelopeCodec::keyIdOf($after);

        $this->assertNotSame($kidBefore, $kidAfter);
        $this->assertTrue(SettingsCipher::instance()->codec()->isEncryptedWithCurrentKey($after));
        $this->assertSame(self::SECRET_VALUE, $this->coldManager()->get(self::SECRET_KEY));
    }

    // ---------------------------------------------------------------------
    // Command di migrazione
    // ---------------------------------------------------------------------

    #[Test]
    public function migrateCommandEncryptsPlaintextRows(): void
    {
        DB::table('settings')->insert([
            'key' => self::SECRET_KEY,
            'descr' => 'inserito via SQL',
            'value' => 'valore-in-chiaro',
            'validation_rules' => 'nullable|string',
            'config_override' => '',
            'load_on_startup' => 0,
        ]);

        $this->artisan('padosoft-settings:encryption-migrate', ['--write' => true])->assertExitCode(0);

        $raw = $this->rawFromDatabase(self::SECRET_KEY);
        $this->assertTrue(EnvelopeCodec::looksCanonical($raw));
        $this->assertSame('valore-in-chiaro', $this->coldManager()->get(self::SECRET_KEY));
    }

    #[Test]
    public function migrateCommandIsIdempotent(): void
    {
        DB::table('settings')->insert([
            'key' => self::SECRET_KEY,
            'descr' => 'inserito via SQL',
            'value' => 'valore-in-chiaro',
            'validation_rules' => 'nullable|string',
            'config_override' => '',
            'load_on_startup' => 0,
            'updated_at' => '2020-01-01 00:00:00',
        ]);

        $this->artisan('padosoft-settings:encryption-migrate', ['--write' => true])->assertExitCode(0);
        $firstRaw = $this->rawFromDatabase(self::SECRET_KEY);
        $firstUpdatedAt = DB::table('settings')->where('key', self::SECRET_KEY)->value('updated_at');

        $this->artisan('padosoft-settings:encryption-migrate', ['--write' => true])->assertExitCode(0);
        $secondRaw = $this->rawFromDatabase(self::SECRET_KEY);
        $secondUpdatedAt = DB::table('settings')->where('key', self::SECRET_KEY)->value('updated_at');

        // Seconda esecuzione: nessuna riscrittura, nessun nuovo IV, updated_at intatto.
        $this->assertSame($firstRaw, $secondRaw);
        $this->assertSame($firstUpdatedAt, $secondUpdatedAt);
    }

    #[Test]
    public function migrateCommandIsADryRunByDefault(): void
    {
        DB::table('settings')->insert([
            'key' => self::SECRET_KEY,
            'descr' => 'inserito via SQL',
            'value' => 'valore-in-chiaro',
            'validation_rules' => 'nullable|string',
            'config_override' => '',
            'load_on_startup' => 0,
        ]);

        $this->artisan('padosoft-settings:encryption-migrate')->assertExitCode(0);

        $this->assertSame('valore-in-chiaro', $this->rawFromDatabase(self::SECRET_KEY));
    }

    #[Test]
    public function auditCommandReportsDrift(): void
    {
        DB::table('settings')->insert([
            'key' => self::SECRET_KEY,
            'descr' => 'inserito via SQL',
            'value' => 'valore-in-chiaro',
            'validation_rules' => 'nullable|string',
            'config_override' => '',
            'load_on_startup' => 0,
        ]);

        // Exit code diverso da zero: c'e' almeno una riga da cifrare.
        $this->artisan('padosoft-settings:encryption-audit')->assertExitCode(1);
    }

    // ---------------------------------------------------------------------
    // Superfici di serializzazione
    // ---------------------------------------------------------------------

    #[Test]
    public function toArrayAndToJsonRedactTheEncryptedValue(): void
    {
        $model = $this->createSecretViaEloquent();
        $fresh = Settings::where('key', self::SECRET_KEY)->first();

        $this->assertSame(Settings::REDACTED, $fresh->toArray()['value']);
        $this->assertStringNotContainsString(self::SECRET_VALUE, $fresh->toJson());
        $this->assertStringNotContainsString(EnvelopeCodec::PREFIX, $fresh->toJson());

        // L'accesso esplicito continua a restituire il valore.
        $this->assertSame(self::SECRET_VALUE, $fresh->value);
    }

    #[Test]
    public function nonEncryptedSettingsKeepTheirValueInToArray(): void
    {
        $model = new Settings();
        $model->key = 'public.setting';
        $model->value = 'visibile';
        $model->save();

        $this->assertSame('visibile', Settings::where('key', 'public.setting')->first()->toArray()['value']);
    }

    // ---------------------------------------------------------------------
    // Idempotenza della scrittura sotto IV casuale
    // ---------------------------------------------------------------------

    #[Test]
    public function writingTheSameValueTwiceDoesNotRewriteTheRow(): void
    {
        settings()->UpdateOrCreate(self::SECRET_KEY, 'test', self::SECRET_VALUE, 'nullable|string');
        $firstRaw = $this->rawFromDatabase(self::SECRET_KEY);

        settings()->setAndStore(self::SECRET_KEY, self::SECRET_VALUE, 'nullable|string');
        $secondRaw = $this->rawFromDatabase(self::SECRET_KEY);

        // Con il confronto sul ciphertext l'IV casuale renderebbe ogni set()
        // una modifica, e la riga verrebbe riscritta a ogni richiesta.
        $this->assertSame($firstRaw, $secondRaw);
    }

    // ---------------------------------------------------------------------
    // config_override
    // ---------------------------------------------------------------------

    #[Test]
    public function configOverrideIsRefusedOnAnEncryptedKeyByDefault(): void
    {
        config(['app.a_secret_config' => 'valore-originale']);

        $model = new Settings();
        $model->key = self::SECRET_KEY;
        $model->value = self::SECRET_VALUE;
        $model->config_override = 'app.a_secret_config';
        $model->load_on_startup = 1;
        $model->save();

        $this->coldManager()->overrideConfig();

        // Il segreto non viene propagato dentro config(), dove finirebbe nei
        // dump di debug e in bootstrap/cache/config.php.
        $this->assertSame('valore-originale', config('app.a_secret_config'));
    }
}
