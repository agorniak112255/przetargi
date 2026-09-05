<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tender_items', function (Blueprint $table): void {
            $table->foreignId('companion_product_id')
                ->nullable()
                ->after('main_product_id')
                ->constrained('products')
                ->nullOnDelete();
            $table->decimal('companion_offer_price', 12, 2)
                ->nullable()
                ->after('offer_price');
        });
    }

    public function down(): void
    {
        Schema::table('tender_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('companion_product_id');
            $table->dropColumn('companion_offer_price');
        });
    }
};
