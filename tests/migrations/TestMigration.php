<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Padosoft\Laravel\Settings\SettingsManager;

class TestMigration extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        \Padosoft\Laravel\Settings\Settings::create([
            'descr'=>'test complex',
            'key'=>'smartshop.email_notifiche.giacenza_negativa_articolo',
            'value'=>'',
            'validation_rules'=>'nullable|isEmailList',
            'load_on_startup'=>1
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('settings')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('validation_rules');
                $table->dropColumn('editable');
            });
        }
    }
}
