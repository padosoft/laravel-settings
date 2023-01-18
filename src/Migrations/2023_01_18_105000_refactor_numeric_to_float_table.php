<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Padosoft\Laravel\Settings\SettingsManager;

class AddValidationRulesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('validation_rules', 'numeric')->update([
              'validation_rules' => 'float',
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
        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('validation_rules', 'float')->update([
                'validation_rules' => 'numeric',
            ]);
        }
    }
}
