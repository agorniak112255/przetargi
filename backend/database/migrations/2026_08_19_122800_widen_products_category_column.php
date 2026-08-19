<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('category', 255)->nullable()->change();
            });

            return;
        }

        DB::statement('ALTER TABLE `products` MODIFY `category` VARCHAR(255) NULL');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('category', 100)->nullable()->change();
            });

            return;
        }

        DB::statement('ALTER TABLE `products` MODIFY `category` VARCHAR(100) NULL');
    }
};
