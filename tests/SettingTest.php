<?php

namespace Padosoft\Laravel\Settings\Test;

use Padosoft\Laravel\Settings\Exceptions\DecryptException;
use Padosoft\Laravel\Settings\Settings;

class SettingTest extends TestCase
{


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
        $ret = $model->save();
        $this->assertDatabaseHas('settings', [
            'key' => 'test'
        ]);
    }

    /** @test */
    public function canCreateAndRetriveEncryptedSetting()
    {
        /*
        $model = TestModel::create();
        $model->name = 'test';
        $model->save();
        */
        config(['app.key'=>'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF']);
        config(['app.cipher'=>'AES-256-CBC']);
        config(['padosoft-settings.encrypted_keys'=>['test']]);
        $model = new Settings();
        $model->key = 'test';
        $model->value = 'test_value';
        $ret = $model->save();
        $this->assertDatabaseHas('settings', [
            'key' => 'test'
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'test',
            'value' => 'test_value',
        ]);
        $this->assertEquals('test_value',Settings::where('key','test')->first()->value);
    }

    /** @test */
    public function throwInExceptionIfCannotDecryptValye()
    {
        /*
        $model = TestModel::create();
        $model->name = 'test';
        $model->save();
        */
        config(['app.key'=>'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF']);
        config(['app.cipher'=>'AES-256-CBC']);
        $model = new Settings();
        $model->key = 'test';
        $model->value = 'test_value';
        $ret = $model->save();
        $this->assertDatabaseHas('settings', [
            'key' => 'test',
            'value' => 'test_value',
        ]);
        config(['padosoft-settings.encrypted_keys'=>['test']]);
        $this->expectException(DecryptException::class);
        Settings::where('key','test')->first()->value;
        //$this->assertEquals('test_value',);
    }

}
