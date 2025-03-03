<?php

namespace Padosoft\Laravel\Settings\Test;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;
use Padosoft\Laravel\Settings\Events\SettingCreated;
use Padosoft\Laravel\Settings\Events\SettingDeleted;
use Padosoft\Laravel\Settings\Events\SettingUpdated;
use Padosoft\Laravel\Settings\Exceptions\DecryptException;
use Padosoft\Laravel\Settings\Settings;
use Padosoft\Laravel\Settings\SettingsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class SettingTest extends TestCase
{

    public function setUp(): void
    {
        parent::setUp();
    }


    #[Test]
    public function hasSettings()
    {
        \Illuminate\Support\Facades\Cache::forget('hasDbSettingsTable');
        $this->assertTrue(hasDbSettingsTable());
    }


    #[Test]
    public function settingsManagerCanLoadOnStartup()
    {
        $returnValue = \SettingsManager::loadOnStartUp();
        $this->assertTrue($returnValue);

        $returnValue = \SettingsManager::overrideConfig();
        $this->assertTrue($returnValue);
    }


    #[Test]
    public function itCanOverrideConfig()
    {
        $returnValue = \SettingsManager::loadOnStartUp();
        $this->assertTrue($returnValue);
        $model = new Settings();
        $model->key = 'app.name';
        $model->config_override = 'app.name';
        $model->value = 'Settings manager';
        $model->save();
        $returnValue = \SettingsManager::overrideConfig();
        $this->assertEquals('Settings manager', config('app.name'));
    }


    #[Test]
    public function canCreateSetting()
    {
        /*
        $model = TestModel::create();
        $model->name = 'test';
        $model->save();
        */

        $model = new Settings();
        $model->key = 'test';
        $model->value = 'test_value';

        $model->save();
        $this->assertDatabaseHas('settings', [
            'key' => 'test'
        ]);
    }


    #[Test]
    public function settingIsCached()
    {
        /*
        $model = TestModel::create();
        $model->name = 'test';
        $model->save();
        */

        $model = new Settings();
        $model->key = 'test';
        $model->value = 'test_value';

        $model->save();
        $this->assertDatabaseHas('settings', [
            'key' => 'test'
        ]);
        settings()->set('test', 'value2');
        $this->assertNotEquals(settings()->getModel('test', true)->value, 'value2');
    }


    public static function newdataProvider(): array
    {
        return [
            'Email in numero' => [
                'EmailPino',
                'Numero di email',
                'numeric',
                'pino@lapianta.com',
                2,
                true
            ],

            'Email in email' => [
                'Email1',
                'Email',
                'email',
                'pino@lapianta.com',
                'pino@lapianta2.com',
                false
            ],
            'Empty in email' => [
                'Email1',
                'Email',
                'email',
                '',
                'pino@lapianta2.com',
                true
            ],
            'Valore Not nullable' => [
                'EmailNotNullable',
                'Email',
                'isEmailList',
                '',
                'pino@lapianta2.com',
                true
            ],
            'Valore nullable' => [
                'EmailNullable',
                'Email',
                'nullable|email',
                null,
                '',
                false
            ],
            'Valore nullable2' => [
                'EmailNullable2',
                'Email',
                'nullable|email',
                '',
                '',
                false
            ],
            'Numero in email' => [
                'NumeroPrincipale',
                'Numero in email',
                'email',
                '5',
                'pino@lapianta.com',
                true
            ],
            'Numero in numero' => [
                'NumeroSecondario',
                'Numero in numero',
                'numeric',
                45,
                55,
                false
            ],
            'regex' => [
                'regex',
                'regex',
                'regex:/(^[0-9,]+$)|(^.{0}$)/',
                '17,15',
                '17,15',
                false
            ],
        ];
    }


    #[Test]
    #[DataProvider('newdataProvider')]
    public function test_newdata($key, $descr, $validation_rule, $value, $valueSupport, $exception)
    {
        settings()->UpdateOrCreate($key, $descr, $valueSupport, $validation_rule);
        $this->assertDatabaseHas('settings', [
            'key' => $key
        ]);
        $this->assertSame($valueSupport ?? '', settings()->getRaw($key) ?? '');
        if ($exception) {
            $this->expectException(ValidationException::class);
            $this->expectExceptionCode(0);
            //$this->expectExceptionMessage(__('validation.'.$validation_rule,['attribute'=>'value']));//"Value: {$value} is not valid.");

        }


        settings()->UpdateOrCreate($key, $descr, $value, $validation_rule);
        if ($exception) {
            $this->assertSame($valueSupport ?? '', settings()->getRaw($key) ?? '');
        } else {
            $this->assertSame($value ?? '', settings()->getRaw($key) ?? '');
        }
    }


    #[Test]
    public function testSetAndStoreWithValidation()
    {
        $newSetting = new SettingsManager();
        $newSetting->UpdateOrCreate('prova.1', 'Unit Test', 'ciao', 'string');
        $this->assertDatabaseHas('settings', ['key' => 'prova.1', 'value' => 'ciao', 'validation_rules' => 'string']);
        $newSetting->setAndStore('prova.1', 'bye', 'string');
        $this->assertDatabaseHas('settings', ['key' => 'prova.1', 'value' => 'bye', 'validation_rules' => 'string']);

        $newSetting->setAndStore('prova.1', 'goodbye');
        $this->assertDatabaseHas('settings', ['key' => 'prova.1', 'value' => 'goodbye', 'validation_rules' => 'string']);

        $newSetting->setAndStore('prova.1', 'goodbye non validato', '');
        $this->assertDatabaseHas('settings', ['key' => 'prova.1', 'value' => 'goodbye non validato', 'validation_rules' => '']);

        $newSetting->setAndStore('prova.1', '5', '');
        $this->assertDatabaseHas('settings', ['key' => 'prova.1', 'value' => '5', 'validation_rules' => '']);

        $newSetting->setAndStore('prova.1', '5', 'integer');
        $this->assertDatabaseHas('settings', ['key' => 'prova.1', 'value' => '5', 'validation_rules' => 'integer']);

        $newSetting->setAndStore('prova.1', 'ciao', 'integer');
        $this->assertDatabaseHas('settings', ['key' => 'prova.1', 'value' => '5', 'validation_rules' => 'integer']);


        $newSetting->setAndStore('prova.1', '7', 'integer');
        $this->assertDatabaseHas('settings', ['key' => 'prova.1', 'value' => '7', 'validation_rules' => 'integer']);

        $newSetting->setAndStore('prova.1', 'test@test.com', 'email');
        $this->assertDatabaseHas('settings', ['key' => 'prova.1', 'value' => 'test@test.com', 'validation_rules' => 'email']);

        $newSetting->setAndStore('prova.1', 'test;test@test.com', 'regex:/' . Settings::PATTERN_EMAIL_ALIAS . '/');
        $this->assertDatabaseHas('settings', ['key' => 'prova.1', 'value' => 'test;test@test.com', 'validation_rules' => 'regex:/' . Settings::PATTERN_EMAIL_ALIAS . '/']);

        try {
            $newSetting->setAndStore('prova.1', 'test@test.com');
        } catch (\Exception $e) {
            $this->assertDatabaseHas('settings', ['key' => 'prova.1', 'value' => 'test;test@test.com', 'validation_rules' => 'regex:/' . Settings::PATTERN_EMAIL_ALIAS . '/']);
        }
        try {
            $newSetting->setAndStore('prova.2', 'test@test.com');
        } catch (\Exception $e) {
            $this->assertDatabaseMissing('settings', ['key' => 'prova.2']);
        }
    }


    #[Test]
    #[DataProvider('newdataProvider')]
    public function test_canSetNewSettings($key, $descr, $validation_rule, $value, $valueSupport, $exception)
    {
        $settingManager = settings();
        try {
            $settingManager->UpdateOrCreate($key, $descr, $valueSupport, $validation_rule);
            $settingManager->set($key, $value, $validation_rule);
        } catch (\Exception $e) {
            $this->expectExceptionCode(0);
            $this->expectExceptionMessage("Value: {$value} is not valid.");
            //throw new \Exception($e->getMessage(),$e->getCode());
        }
        if ($exception) {
            $this->assertFalse(settings($key) === $value);
        } else {
            $this->assertSame(settings($key) ?? '', $value ?? '');
        }
    }


    #[Test]
    public function itReloadSettingsAfterExpire()
    {
        $settingManager = settings();
        $settingManager->UpdateOrCreate('prova.1', 'Unit Test', 'ciao', 'string', '', 1);
        $settingManager->loadOnStartUp();
        $settingManager->setAndStore('prova.1', 'bye', 'string');
        $this->assertDatabaseHas('settings', ['key' => 'prova.1', 'value' => 'bye', 'validation_rules' => 'string']);
        $this->assertEquals('bye', $settingManager->get('prova.1'));
        DB::connection('sqlite')->table('settings')->where('key', 'prova.1')->update(['value' => 'bye2']);
        $settingManager->clearCache();
        $this->assertDatabaseHas('settings', ['key' => 'prova.1', 'value' => 'bye2', 'validation_rules' => 'string']);
        $this->assertEquals('bye', $settingManager->get('prova.1'));
        $settingManager->setMemoryExpires(5);
        $settingManager->clearCache();
        sleep(5);
        $this->assertEquals('bye2', $settingManager->get('prova.1'));
        $settingManager->setMemoryExpires(600);
        DB::connection('sqlite')->table('settings')->where('key', 'prova.1')->update(['value' => 'bye3']);
        $this->assertDatabaseHas('settings', ['key' => 'prova.1', 'value' => 'bye3', 'validation_rules' => 'string']);
        $settingManager->clearCache();
        $this->assertEquals('bye2', $settingManager->get('prova.1'));
    }


    #[Test]
    public function canCreateAndRetriveEncryptedSetting()
    {
        /*
        $model = TestModel::create();
        $model->name = 'test';
        $model->save();
        */
        config(['app.key' => 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF']);
        config(['app.cipher' => 'AES-256-CBC']);
        config(['padosoft-settings.encrypted_keys' => ['test']]);
        $model = new Settings();
        $model->key = 'test';
        $model->value = 'test_value';
        $model->save();
        $this->assertDatabaseHas('settings', [
            'key' => 'test'
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'test',
            'value' => 'test_value',
        ]);
        $this->assertEquals('test_value', Settings::where('key', 'test')->first()->value);
    }


    #[Test]
    public function throwInExceptionIfCannotDecryptValye()
    {
        /*
        $model = TestModel::create();
        $model->name = 'test';
        $model->save();
        */
        config(['app.key' => 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF']);
        config(['app.cipher' => 'AES-256-CBC']);
        $model = new Settings();
        $model->key = 'test';
        $model->value = 'test_value';
        $model->save();
        $this->assertDatabaseHas('settings', [
            'key' => 'test',
            'value' => 'test_value',
        ]);
        config(['padosoft-settings.encrypted_keys' => ['test']]);
        $this->expectException(DecryptException::class);
        Settings::where('key', 'test')->first()->value;
        //$this->assertEquals('test_value',);
    }

    #[Test]
    public function assertIsDispatchedEvents()
    {
        Event::fake([
                        SettingCreated::class,
                        SettingUpdated::class,
                        SettingDeleted::class,
                    ]);
        $model = new Settings();
        $model->key = 'test_event_created' . time();
        $model->value = 'test_value';
        $model->save();
        $this->assertDatabaseHas('settings', ['id' => $model->id]);
        Event::assertDispatched(function (SettingCreated $settingCreated) use ($model) {
            return $model->id == $settingCreated->setting->id;
        });
        $model->value = 'test_value_modified';
        $model->save();
        Event::assertDispatched(function (SettingUpdated $settingUpdated) use ($model) {
            return $model->value == $settingUpdated->setting->value;
        });
        $model->delete();
        $this->assertDatabaseMissing('settings', ['id' => $model->id]);
        Event::assertDispatched(function (SettingDeleted $settingDeleted) use ($model) {
            return $model->id == $settingDeleted->setting->id;
        });
    }

    #[Test]
    public function assertCanGetValueFromLocalConnection()
    {
        $model = new Settings();
        $model->key = 'testValueFromLocal';
        $model->value = 'test_value_local';
        $model->validation_rules = 'string';
        $connection = (new Settings)->getConnection()->getDatabaseName();
        Redis::connection('local')->hset('laravel_pds_settings' . $connection, 'testValueFromLocal', $model->toJson());
        Redis::hset('laravel_pds_settings' . $connection, 'testValueFromLocal', 'culo');
        config()->set('padosoft-settings.local_connection', 'local');
        $localValue = settings()->get('testValueFromLocal');
        $this->assertEquals('test_value_local', $localValue);
        config(['padosoft-settings.local_connection' => null]);
    }

    #[Test]
    public function assertItSetValueOnRemoteAndLocalConnection()
    {
        config()->set('padosoft-settings.local_connection', 'local');
        $model = new Settings();
        $model->key = 'testSetValueFromLocal';
        $model->value = 'test_value_local';
        $model->validation_rules = 'string';
        $model->save();
        $connection = (new Settings)->getConnection()->getDatabaseName();
        $localValue = json_decode(Redis::connection('local')->hget('laravel_pds_settings' . $connection, 'testSetValueFromLocal'), true);
        $remoteValue = json_decode(Redis::connection('default')->hget('laravel_pds_settings' . $connection, 'testSetValueFromLocal'), true);

        $this->assertEquals('test_value_local', $localValue['value']);
        $this->assertEquals('test_value_local', $remoteValue['value']);
        config(['padosoft-settings.local_connection' => null]);
    }

    #[Test]
    public function assertItSetValueOnLocalIfMissing()
    {

        $model = new Settings();
        $model->key = 'testSetValueToLocal';
        $model->value = 'test_value_local';
        $model->validation_rules = 'string';
        $model->save();
        config()->set('padosoft-settings.local_connection', 'local');
        $connection = (new Settings)->getConnection()->getDatabaseName();
        $remoteValue = settings('testSetValueToLocal');
        $localValue = json_decode(Redis::connection('local')->hget('laravel_pds_settings' . $connection, 'testSetValueToLocal'), true);

        $this->assertEquals('test_value_local', $localValue['value']);
        $this->assertEquals('test_value_local', $remoteValue);
        config(['padosoft-settings.local_connection' => null]);
    }
    #[Test]
    public function assertLocalValueExpires()
    {
        Redis::connection('local')->flushdb();
        config(['padosoft-settings.local_connection' => null]);
        $model = new Settings();
        $model->key = 'testExpireValueToLocal2';
        $model->value = 'test_value_local';
        $model->validation_rules = 'string';
        $model->save();
        config()->set('padosoft-settings.local_connection', 'local');
        config()->set('padosoft-settings.local_expire', 5);
        $connection = (new Settings)->getConnection()->getDatabaseName();
        $remoteValue = (new SettingsManager())->get('testExpireValueToLocal2');
        $localValue = json_decode(Redis::connection('local')->hget('laravel_pds_settings' . $connection, 'testExpireValueToLocal2'), true);

        $this->assertEquals('test_value_local', $localValue['value']);
        $this->assertEquals('test_value_local', $remoteValue);
        sleep(10);
        config(['padosoft-settings.local_connection' => null]);
        $this->assertNull(Redis::connection('local')->hget('laravel_pds_settings' . $connection, 'testExpireValueToLocal2'));
    }

}
