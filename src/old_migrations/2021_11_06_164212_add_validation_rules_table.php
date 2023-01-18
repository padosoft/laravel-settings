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
            Schema::table('settings', function (Blueprint $table) {
                if (!Schema::hasColumn('settings', 'validation_rules')) {
                    $table->char('validation_rules')->nullable()->default('')->after('value');
                }
                $table->tinyInteger('editable')->default(1)->after('load_on_startup');
            });
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
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('validation_rules');
                $table->dropColumn('editable');
            });
        }
    }
}
