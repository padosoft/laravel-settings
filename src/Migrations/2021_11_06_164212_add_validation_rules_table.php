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
                $table->char('validation_rules')->nullable()->default('')->after('value');
                $table->tinyInteger('editable')->default(1)->after('load_on_startup');
            });
            $records = DB::table('settings')->get();
            $id = [];
            foreach ($records as $record) {
                //Se è un numero
                if (SettingsManager::isNumber($record->value)) {
                    $id['float'][]=$record->id;
                }
                if (SettingsManager::isNumberInteger($record->value)) {
                    $id['int'][]=$record->id;
                }
                //Se è un Url
                if (SettingsManager::isURL($record->value)) {
                    $id['url'][]=$record->id;
                }
                //Se è un email
                if (SettingsManager::isEmail($record->value)) {
                    $id['email'][]=$record->id;
                }
                if (SettingsManager::isEmailAndAlias($record->value)) {
                    $id['aliasAndEmail'][]=$record->id;
                }
                if (SettingsManager::isListSeparatedBySemicolon($record->value, ';')) {
                    $id['pv'][]=$record->id;
                }
                if (SettingsManager::isListSeparatedByComma($record->value, ',')) {
                    $id['v'][]=$record->id;
                }
                //Se la descrizione contiene Enable/Disable allora è Booleano
                if (str_contains($record->descr, 'Enable/Disable')||str_contains($record->descr, 'En/Disable')) {
                    $id['boolean'][]=$record->id;
                }
            }
            DB::table('settings')->where('id', '>', 0)->update(['validation_rules'=>'string']);
            DB::table('settings')->whereIn('id', $id['pv']??[])->update(['validation_rules'=>'regex:/(^[0-9;]+$)|(^.{0}$)/']);
            DB::table('settings')->whereIn('id', $id['v']??[])->update(['validation_rules'=>'regex:/(^[0-9,]+$)|(^.{0}$)/']);
            DB::table('settings')->whereIn('id', $id['int']??[])->update(['validation_rules'=>'numeric']);
            DB::table('settings')->whereIn('id', $id['float']??[])->update(['validation_rules'=>'numeric']);
            DB::table('settings')->whereIn('id', $id['boolean']??[])->update(['validation_rules'=>'boolean']);
            DB::table('settings')->whereIn('id', $id['url']??[])->update(['validation_rules'=>'url']);
            DB::table('settings')->whereIn('id', $id['email']??[])->update(['validation_rules'=>'email']);
            DB::table('settings')->whereIn('id', $id['aliasAndEmail']??[])->update(['validation_rules'=>'regex:/([a-z0-9\+_\-]+)*;([a-z0-9\+_\-]+)(\.[a-z0-9\+_\-]+)*@([a-z0-9\-]+\.)+[a-z]{2,6}$/']);
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
