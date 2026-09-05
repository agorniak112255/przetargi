<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_pages', function (Blueprint $table): void {
            $table->string('manufacturer', 80)->nullable()->after('host')->index();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_pages', function (Blueprint $table): void {
            $table->dropColumn('manufacturer');
        });
    }
};
