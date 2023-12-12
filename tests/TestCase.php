<?php

namespace Padosoft\Laravel\Settings\Test;

use Illuminate\Support\Facades\File;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\Laravel\Settings\ServiceProvider;

abstract class TestCase extends Orchestra
{

    public function setUp() : void
    {

        parent::setUp();
        //$this->loadMigrationsFrom(realpath(__DIR__ . '/../src/Migrations'));
        $this->setUpDatabase($this->app);
    }

    protected function tearDown() : void
    {
        //remove created path during test
        //$this->removeCreatedPathDuringTest(__DIR__);
        //$this->artisan('migrate:reset', ['--database' => 'testbench']);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [
            ServiceProvider::class,
            ValidatorServiceProvider::class,
            \Lunaweb\RedisMock\Providers\RedisMockServiceProvider::class
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'SettingsManager' => 'Padosoft\Laravel\Settings\Facade',
        ];
    }

    /**
     * @param Application $app
     */
    protected function getEnvironmentSetUp($app)
    {

        //$this->initializeDirectory($app);

        /*$app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            //'database' => $this->getSysTempDirectory().'/testbench.sqlite',
            'prefix' => '',
        ]);*/

        $app['config']->set('padosoft-settings.enabled', false);
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $app['config']->set('database.redis.client', 'mock');
        $app['config']->set('padosoft-settings.enabled', true);
        $app['config']->set('padosoft-settings.cast', ['isEmailList' => [
            'class' => \Padosoft\Laravel\Settings\Test\ListSemiColonEmailCast::class,
            'validate' => 'isEmailList',
            'recognize' => ['contains:;']
        ]]);
    }

    /**
     * @param  $app
     */
    protected function setUpDatabase(Application $app)
    {
        //   file_put_contents($this->getTempDirectory().'/database.sqlite', null);
        //File::copyDirectory(__DIR__ . '/../src/Migrations', $app->databasePath('migrations'));
        $app['db']->connection()->getSchemaBuilder()->create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
            $table->softDeletes();
        });

        $app['db']->connection()->getSchemaBuilder()->create('admins', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
        });
        include_once __DIR__ . '/../src/Migrations/2018_03_19_164012_create_settings_table.php';
        (new \CreateSettingsTable())->up();
        include_once __DIR__ . '/../src/Migrations/2018_04_11_164012_update_settings_table.php';
        (new \UpdateSettingsTable())->up();
        include_once __DIR__ . '/../src/Migrations/2021_11_06_164212_add_validation_rules_table.php';
        (new \AddValidationRulesTable())->up();
        include_once __DIR__ . '/migrations/TestMigration.php';
        (new \TestMigration())->up();
        $fake_settings['fake_value']=
            [
                'descr'=>'test complex',
                'key'=>'smartshop.email_notifiche.giacenza_negativa_articolo',
                'value'=>'',
                'validation_rules'=>'nullable|isEmailList',
                'load_on_startup'=>1
            ];
        file_put_contents(storage_path('settings.tpl'), '<?php return '.var_export($fake_settings, true).';');

        \Illuminate\Support\Facades\Cache::forget('hasDbSettingsTable');
    }
}
