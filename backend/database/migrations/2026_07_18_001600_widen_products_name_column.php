<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // indeks na name (utf8mb4) nie zmieści VARCHAR(1000) — prefix 191
        try {
            Schema::table('products', function ($table): void {
                $table->dropIndex(['name']);
            });
        } catch (\Throwable) {
            // indeks mógł nie istnieć
        }

        DB::statement('ALTER TABLE `products` MODIFY `name` VARCHAR(1000) NOT NULL');

        try {
            DB::statement('ALTER TABLE `products` ADD INDEX `products_name_index` (`name`(191))');
        } catch (\Throwable) {
            // ignore
        }
    }

    public function down(): void
    {
        try {
            Schema::table('products', function ($table): void {
                $table->dropIndex('products_name_index');
            });
        } catch (\Throwable) {
        }

        DB::statement('ALTER TABLE `products` MODIFY `name` VARCHAR(255) NOT NULL');

        try {
            Schema::table('products', function ($table): void {
                $table->index('name');
            });
        } catch (\Throwable) {
        }
    }
};

