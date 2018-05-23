<?php

namespace Padosoft\Laravel\Settings\Test;

use Illuminate\Support\Facades\File;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{

    public function setUp()
    {

        parent::setUp();
        $this->loadMigrationsFrom(realpath(__DIR__ . '/../src/Migrations'));
        $this->setUpDatabase($this->app);
    }

    protected function tearDown()
    {
        //remove created path during test
        //$this->removeCreatedPathDuringTest(__DIR__);
        //$this->artisan('migrate:reset', ['--database' => 'testbench']);
    }

    /**
     * @param Application $app
     */
    protected function getEnvironmentSetUp($app)
    {
        $this->initializeDirectory($app);

        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            //'database' => $this->getSysTempDirectory().'/testbench.sqlite',
            'prefix' => '',
        ]);
    }

    /**
     * @param  $app
     */
    protected function setUpDatabase(Application $app)
    {
        //   file_put_contents($this->getTempDirectory().'/database.sqlite', null);

        //File::copyDirectory(__DIR__ . '/../src/Migrations', $app->databasePath('migrations'));
        $this->artisan('migrate', ['--database' => 'testbench']);
    }

    protected function initializeDirectory(Application $app)
    {

        return;
        /*
        if (File::isDirectory($directory)) {
            File::deleteDirectory($directory);
        }
        File::makeDirectory($directory);
        */
    }

    public function getTempDirectory(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'temp';
    }

    public function getSysTempDirectory(): string
    {
        return sys_get_temp_dir();
    }
}
