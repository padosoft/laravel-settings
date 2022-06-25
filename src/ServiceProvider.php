<?php

namespace Padosoft\Laravel\Settings;

use Padosoft\Laravel\Settings\SettingsManager;
use Padosoft\Laravel\Settings\Facade as settings;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
        $this->publishes([
            __DIR__ . '/Migrations' => database_path('migrations')
        ]);
        $this->publishes([
            __DIR__ . '/Config/config.php' => config_path('padosoft-settings.php')
        ]);
        settings::overrideConfig();
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
        $this->app->singleton(SettingsManager::class, function () {
            return new SettingsManager();
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [SettingsManager::class];
    }
}
