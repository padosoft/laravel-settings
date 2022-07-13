# Laravel Settings

[![License](https://poser.pugx.org/anlutro/l4-settings/license.svg)](http://opensource.org/licenses/MIT)

[![CircleCI](https://circleci.com/gh/padosoft/laravel-settings.svg?style=shield)](https://circleci.com/gh/padosoft/laravel-settings)

Persistent on database, fast in memory, application-wide settings for Laravel.

Performance are not invalidated because settings are automatic cached when retrived from database.

## Requirements

    PHP >= 7.1.3
    Laravel 5.8.*|6.*|7.*|8.*|9.* (For Laravel framework 5.6.* or 5.7.* please use v1.*)

## Installation 
    
1. `composer require padosoft/laravel-settings`
2. Publish the config and migration files by running `php artisan vendor:publish --provider="Padosoft\Laravel\Settings\ServiceProvider"`.
3. Run `php artisan migrate`
 
**Before running the migrations be sure you have in your `AppServiceProviders.php` the following lines:** 
```php
use Illuminate\Support\Facades\Schema;

public function boot()
{
    Schema::defaultStringLength(191);
}
```
## Installation - Laravel < 5.5

4. Add `Padosoft\Laravel\Settings\ServiceProvider` to the array of providers in `config/app.php`.
5. Add `'SettingsManager' => 'Padosoft\Laravel\Settings\Facade'` to the array of aliases in `config/app.php`.

## Usage

You can either access the setting store via its facade or inject it by type-hinting towards the abstract class `anlutro\LaravelSettings\SettingStore`.

```php
<?php
SettingsManager::get('foo', 'default value');
SettingsManager::get('foo', 'default value',false,true); //Get cast value without validation
SettingsManager::get('foo', 'default value',false,false); //Get raw value
SettingsManager::get('foo', 'default value',true,false); //Get validated value without cast
SettingsManager::set('set', 'value');//valid for current session
SettingsManager::set('set', 'value','validationRule');//with validation rule valid for current session
SettingsManager::store();//persist settings value on db
SettingsManager::setAndStore('set', 'value','validationRule');//persisted on database
?>
```

Call `Setting::store()` explicitly to save changes made.

You could also use the `setting()` helper:

```php
// Get the store instance
settings();

// Get values
settings('foo');
settings('foo', 'default value',true,false);//Get cast value without validation
settingsRaw('foo', 'default value');//Get raw value
settingsAsString('foo', 'default value');//Get value as a String Validated
settings('foo', 'default value',false,true);//Get raw value
settings('foo', 'default value',false,false);//Get validated value without cast
settings('foo', 'default value',);
settings()->get('foo');


```



#### Using in other packages

If you want to use settings manager on other packages you must provide migrations to populate settings table.
For Example:
```php
<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
class PopulateSettings extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (config('padosoft-settings.enabled',false)) {
            DB::table('settings')->insert([
                //LOGIN
                ['key'=>'login.remember_me', 'value'=>'1','descr'=>'Enable/Disable remeber me feature','config_override'=>'padosoft-users.login.remember-me','validation_rules'=>'boolean','editable'=>1,'load_on_startup'=>0],
                ['key'=>'login.login_reset_token_lifetime', 'value'=>'30','descr'=>'Number of minutes reset token lasts','config_override'=>'auth.expire','validation_rules'=>'numeric','editable'=>1,,'load_on_startup'=>0],                
            ]);
        }

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {

    }
}
```
Please take care of populate config_override column with config key you want your setting should override
##Add new type of settings with cast and Validation in Config/config.php
```php
/*
      |--------------------------------------------------------------------------
      | Larvel Settings Manager
      |--------------------------------------------------------------------------
      |
      | This option controls if the settings manager is enabled.
      | This option should not be is overwritten here but using settings db table
      |
      |
      |
      |
      */

    'enabled' => true,
    'encrypted_keys' => [],
    'cast' => [
        //Example new cast and validation
        //class = class for cast
        //method = method for cast
        //validate = rule for validation
        'boolean' => ['class' => \Padosoft\Laravel\Settings\CastSettings::class, 'method' => 'boolean', 'validate' => 'boolean'],
        'listPipe' => ['class' => \App\Casts\ListPipeCast::class, 'validate' => 'regex:/(^[0-9|]+$)|(^.{0}$)/'],
        'booleanString' => ['class' => \App\Casts\BooleanString::class,'validate' => 'regex:/^(true|false)/'],
        'booleanInt' => ['class' => \App\Casts\BooleanInt::class,'validate' => 'regex:/^(0|1)/'],
        ],

```




## Contact

Open an issue on GitHub if you have any problems or suggestions.


## License

The contents of this repository is released under the [MIT license](http://opensource.org/licenses/MIT).
