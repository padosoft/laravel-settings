# Laravel Settings

[![License](https://poser.pugx.org/anlutro/l4-settings/license.svg)](http://opensource.org/licenses/MIT)

[![CircleCI](https://circleci.com/gh/padosoft/laravel-settings.svg?style=svg)](https://circleci.com/gh/padosoft/laravel-settings)

Persistent on database, application-wide settings for Laravel.

Performance are not invalidated because settings are automatic cached when retrived from database.

## Requirements

    PHP >= 7.1.3
    Laravel 5.8.*|6.*|7.*|8.* (For Laravel framework 5.6.* or 5.7.* please use v1.*)

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
SettingsManager::set('set', 'value');//valid for current session
SettingsManager::store();//persist settings value on db
SettingsManager::setAndStore('set', 'value');//persisted on dataase
?>
```

Call `Setting::store()` explicitly to save changes made.

You could also use the `setting()` helper:

```php
// Get the store instance
setting();

// Get values
setting('foo');
setting('foo', 'default value');
setting()->get('foo');


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
                ['key'=>'login.remember_me', 'value'=>'1','descr'=>'Enable/Disable remeber me feature','config_override'=>'padosoft-users.login.remember-me','load_on_startup'=>0],
                ['key'=>'login.login_reset_token_lifetime', 'value'=>'30','descr'=>'Number of minutes reset token lasts','config_override'=>'auth.expire','load_on_startup'=>0],                
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

## Contact

Open an issue on GitHub if you have any problems or suggestions.


## License

The contents of this repository is released under the [MIT license](http://opensource.org/licenses/MIT).
