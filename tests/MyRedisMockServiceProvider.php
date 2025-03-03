<?php
namespace Padosoft\Laravel\Settings\Test;

use Illuminate\Support\ServiceProvider;

class MyRedisMockServiceProvider extends ServiceProvider
{

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app->make('redis')->extend('mock', function () {
            return new MyMockPredisConnector();
        });
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {

    }


}
