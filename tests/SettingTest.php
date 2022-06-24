<?php

namespace Padosoft\Laravel\Settings\Test;

use Padosoft\Laravel\Settings\Exceptions\DecryptException;
use Padosoft\Laravel\Settings\Settings;
use Padosoft\Laravel\Settings\SettingsManager;

class SettingTest extends TestCase
{

    public function setUp(): void
    {
        parent::setUp();

    }

    /** @test */
    public function hasSettings()
    {
        \Illuminate\Support\Facades\Cache::forget('hasDbSettingsTable');
        $this->assertTrue(hasDbSettingsTable());
    }

    /** @test */
    public function settingsManagerCanLoadOnStartup()
    {
        $returnValue = \SettingsManager::loadOnStartUp();
        $this->assertTrue($returnValue);

        $returnValue = \SettingsManager::overrideConfig();
        $this->assertTrue($returnValue);
    }

    /** @test */
    public function settingsManagerCanStoreFile()
    {
        @unlink(storage_path('settings.php'));
        $returnValue = \SettingsManager::loadOnStartUp();
        $this->assertTrue(file_exists(storage_path('settings.php')));
    }

    /** @test */
    public function settingsManagerReadFromFile()
    {
        $fake_settings=[];
        $key='settings'.time();
        $fake_settings[$key]=
            [
                'value'=>'fake_value'.time(),
                'validation_rule'=>'string',
            ];
        file_put_contents(storage_path('settings.php'),'<?php return '.var_export($fake_settings,true).';');
        $returnValue = \SettingsManager::loadOnStartUp();
        $this->assertEquals($fake_settings[$key]['value'],settings($key));
    }

    /** @test */
    public function itUpdateFileWhenSettingsIsCreated()
    {
        $model = new Settings();
        $model->key = 'test'.time();
        $model->value = 'test_value';

        $model->save();

        $this->assertTrue(file_exists(storage_path('settings.php')));
        $settings=require(storage_path('settings.php'));
        $this->assertIsArray($settings);
        $this->assertArrayHasKey($model->key,$settings);
        $this->assertEquals($settings[$model->key]['value'],$model->value);

    }

    /** @test */
    public function itUpdateFileWhenSettingsIsUpdated()
    {
        $model = new Settings();
        $model->key = 'test'.time();
        $model->value = 'test_value';

        $model->save();

        $model->value = 'test_value2';
        $model->save();

        $this->assertTrue(file_exists(storage_path('settings.php')));
        $settings=require(storage_path('settings.php'));
        $this->assertIsArray($settings);
        $this->assertArrayHasKey($model->key,$settings);
        $this->assertEquals('test_value2',$settings[$model->key]['value']);

    }

    /** @test */
    public function itUpdateFileWhenSettingsIsDeleted()
    {
        $model = new Settings();
        $model->key = 'test'.time();
        $model->value = 'test_value';

        $model->save();
        $this->assertTrue(file_exists(storage_path('settings.php')));
        $settings=require(storage_path('settings.php'));
        $this->assertIsArray($settings);
        $this->assertArrayHasKey($model->key,$settings);

        $model->delete();


        $settings=require(storage_path('settings.php'));
        $this->assertIsArray($settings);
        $this->assertArrayNotHasKey($model->key,$settings);

    }

    /** @test */
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

    /** @test */
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
        settings()->set('test','value2');
        $this->assertNotEquals(settings()->getModel('test',true)->value,'value2');
    }

    public function newdataProvider(){
        return [
            'Email in numero' => [
                'EmailPino',
                'Numero di email',
                'numeric',
                'pino@lapianta.com',
                '2',
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
        ];
    }
    /**
     * @dataProvider newdataProvider
     */
    public function test_newdata($key,$descr,$validation_rule,$value,$valueSupport,$exception){
        $newSetting = new SettingsManager();
        try {
           $newSetting->UpdateOrCreate($key,$descr,$value,$validation_rule);
        }catch (\Exception $e){
            $this->expectExceptionCode(0);
            $this->expectExceptionMessage("Value: {$value} is not valid.");
        }
        if($exception){
            $this->assertNotSame(settings($key), $value);
        }else{
            $this->assertDatabaseHas('settings', [
                'key' => $key
            ]);
            $this->assertSame(settings($key), $value);
        }



    }

    public function testSetAndStoreWithValidation(){
        $newSetting = new SettingsManager();
        $newSetting->UpdateOrCreate('prova.1','Unit Test','ciao','string');
        $this->assertDatabaseHas('settings',['key'=>'prova.1','value'=>'ciao','validation_rules'=>'string']);
        $newSetting->setAndStore('prova.1','bye','string');
        $this->assertDatabaseHas('settings',['key'=>'prova.1','value'=>'bye','validation_rules'=>'string']);

        $newSetting->setAndStore('prova.1','goodbye');
        $this->assertDatabaseHas('settings',['key'=>'prova.1','value'=>'goodbye','validation_rules'=>'string']);

        $newSetting->setAndStore('prova.1','goodbye non validato','');
        $this->assertDatabaseHas('settings',['key'=>'prova.1','value'=>'goodbye non validato','validation_rules'=>'']);

        $newSetting->setAndStore('prova.1','5','');
        $this->assertDatabaseHas('settings',['key'=>'prova.1','value'=>'5','validation_rules'=>'']);

        $newSetting->setAndStore('prova.1','5','numeric');
        $this->assertDatabaseHas('settings',['key'=>'prova.1','value'=>'5','validation_rules'=>'numeric']);

        try {
            $newSetting->setAndStore('prova.1','ciao','numeric');
        }catch(\Exception $e){
            $this->assertDatabaseHas('settings',['key'=>'prova.1','value'=>'5','validation_rules'=>'numeric']);
        }

        $newSetting->setAndStore('prova.1','7','numeric');
        $this->assertDatabaseHas('settings',['key'=>'prova.1','value'=>'7','validation_rules'=>'numeric']);

        $newSetting->setAndStore('prova.1','test@test.com','email');
        $this->assertDatabaseHas('settings',['key'=>'prova.1','value'=>'test@test.com','validation_rules'=>'email']);

        $newSetting->setAndStore('prova.1','test;test@test.com','regex:/'.Settings::PATTERN_EMAIL_ALIAS.'/');
        $this->assertDatabaseHas('settings',['key'=>'prova.1','value'=>'test;test@test.com','validation_rules'=>'regex:/'.Settings::PATTERN_EMAIL_ALIAS.'/']);

        try {
            $newSetting->setAndStore('prova.1','test@test.com');
        }catch(\Exception $e){
            $this->assertDatabaseHas('settings',['key'=>'prova.1','value'=>'test;test@test.com','validation_rules'=>'regex:/'.Settings::PATTERN_EMAIL_ALIAS.'/']);
        }
        try {
            $newSetting->setAndStore('prova.2','test@test.com');
        }catch(\Exception $e){
            $this->assertDatabaseMissing('settings',['key'=>'prova.2']);
        }

    }

    /**
     * @dataProvider newdataProvider
     */
    public function test_canSetNewSettings($key,$descr,$validation_rule,$value,$valueSupport,$exception){
        $settingManager = settings();
        try {
            $settingManager->UpdateOrCreate($key,$descr,$valueSupport,$validation_rule);
            $settingManager->set($key, $value, $validation_rule);
        }catch(\Exception $e){
            $this->expectExceptionCode(0);
            $this->expectExceptionMessage("Value: {$value} is not valid.");
            //throw new \Exception($e->getMessage(),$e->getCode());
        }
        if($exception){
            $this->assertFalse($settingManager->get($key)===$value);
        }else{
            $this->assertSame(settings($key),$value);
        }
    }



    /** @test */
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

    /** @test */
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

}
