<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_enrichment_batches', function (Blueprint $table): void {
            $table->string('current_sku', 100)->nullable()->after('force');
            $table->string('current_name', 255)->nullable()->after('current_sku');
            $table->string('message', 500)->nullable()->after('current_name');
        });
    }

    public function down(): void
    {
        Schema::table('product_enrichment_batches', function (Blueprint $table): void {
            $table->dropColumn(['current_sku', 'current_name', 'message']);
        });
    }
};
