<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class CreateSettingsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->increments('id');
                $table->string('descr')->default('')->index();
                $table->string('key')->default('')->unique();
                $table->string('value')->default('')->index();
                $table->string('config_override')->default('')->index();
                $table->boolean('load_on_startup')->default(0)->index();
                $table->timestamps();
            });
        }
        file_put_contents(config_path('padosoft-settings.php'), '<?php
return [
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

    \'enabled\'=>true,
    \'encrypted_keys\'=>[],
];');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists($this->tablename);
    }
}
