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
            'Email in email' => [
                'Email1',
                'Email',
                'email',
                'pino@lapianta.com',
                false
            ],
            'Email in numero' => [
                'Email2',
                'Numero di email',
                'numeric',
                'pino@lapianta.com',
                true
            ],
            'Numero in email' => [
                'Numero1',
                'Numero in email',
                'email',
                '5',
                true
            ],
            'Numero in numero' => [
                'Numero2',
                'Numero in email',
                'numeric',
                '45',
                false
            ],
        ];
    }
    /**
     * @dataProvider newdataProvider
     */
    public function test_newdata($key,$descr,$validation_rule,$value,$exception){

        $newSetting = new SettingsManager();
        try {
            $newSetting->UpdateOrCreate($key,$descr,$value,$validation_rule);
        }catch (\Exception $e){
            $this->expectExceptionCode(0);
            $this->expectExceptionMessage("Value: {$value} is not valid.");
            throw new \Exception($e->getMessage(),$e->getCode());
        }
        if($exception){
            $this->assertDatabaseMissing('settings', [
                'key' => $key
            ]);
        }else{
            $this->assertDatabaseHas('settings', [
                'key' => $key
            ]);
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
    public function test_canSetNewSettings($key,$descr,$validation_rule,$value,$exception){
        $settingManager = new SettingsManager();
        try {
            $settingManager->set($key, $value, $validation_rule);
        }catch(\Exception $e){
            $this->expectExceptionCode(0);
            $this->expectExceptionMessage("Value: {$value} is not valid.");
            throw new \Exception($e->getMessage(),$e->getCode());
        }
        if($exception){
            $this->assertFalse($settingManager->get($key)===$value);
        }else{
            $this->assertTrue($settingManager->get($key)===$value);
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
