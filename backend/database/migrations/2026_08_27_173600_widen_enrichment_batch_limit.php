<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE `ai_settings` MODIFY `enrichment_batch_limit` INT UNSIGNED NOT NULL DEFAULT 5');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE `ai_settings` MODIFY `enrichment_batch_limit` SMALLINT UNSIGNED NOT NULL DEFAULT 5');
    }
};
