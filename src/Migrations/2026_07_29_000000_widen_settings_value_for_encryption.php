<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allarga settings.value a TEXT.
 *
 * La migration storica del package crea `value` come VARCHAR(255). Un envelope
 * cifrato supera quella soglia gia' per un segreto di poche decine di caratteri:
 * in MySQL strict mode la scrittura fallirebbe, in non-strict verrebbe troncata
 * in silenzio e il valore sarebbe perso in modo irreversibile.
 *
 * L'indice su `value` non puo' restare su una colonna TEXT senza lunghezza di
 * prefisso: va eliminato e ricreato come prefisso.
 *
 * Idempotente: se la colonna e' gia' TEXT non fa nulla.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('settings') || !Schema::hasColumn('settings', 'value')) {
            return;
        }

        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'mysql') {
            // SQLite non applica la lunghezza dei VARCHAR e Postgres usa gia'
            // tipi senza limite pratico: non c'e' nulla da correggere.
            return;
        }

        $database = $connection->getDatabaseName();

        $column = DB::selectOne(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, 'settings', 'value']
        );

        if ($column !== null && strtolower($column->DATA_TYPE) === 'text') {
            return;
        }

        $hasIndex = DB::selectOne(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$database, 'settings', 'settings_value_index']
        );

        if ($hasIndex !== null) {
            DB::statement('ALTER TABLE `settings` DROP INDEX `settings_value_index`');
        }

        DB::statement('ALTER TABLE `settings` MODIFY `value` TEXT NULL');

        if ($hasIndex !== null) {
            DB::statement('ALTER TABLE `settings` ADD INDEX `settings_value_index` (`value`(255))');
        }
    }

    public function down(): void
    {
        // Nessun rollback automatico: riportare la colonna a VARCHAR(255)
        // troncherebbe gli envelope gia' scritti, distruggendo i valori.
    }
};
