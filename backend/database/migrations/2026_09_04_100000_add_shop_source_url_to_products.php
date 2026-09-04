<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || Schema::hasColumn('products', 'shop_source_url')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->string('shop_source_url', 2000)->nullable()->after('packaging');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'shop_source_url')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('shop_source_url');
        });
    }
};
