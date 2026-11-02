<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Preserve existing settings rows while repairing databases created by the earlier migration.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE zoom_integration_settings MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE zoom_integration_settings MODIFY id TINYINT UNSIGNED NOT NULL');
        }
    }
};
