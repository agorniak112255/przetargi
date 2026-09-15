<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ceny karty osobno dla każdego źródła (decyzja użytkownika 15.09.2026: cenniki z plików i cenniki B2B nie
 * nadpisują sobie cen). Jeden slot pliku na kartę (source_key „file”, najnowszy import pliku) i jeden slot na konto
 * B2B („b2b:{id}”). products.catalog_price_net/purchase_price/discount_percent/currency zostają ceną obowiązującą,
 * liczoną ze slotów przez App\Services\Pricing\ProductEffectivePrice.
 *
 * source_key jest NOT NULL i to on jest w UNIQUE — (product_id, source_type, b2b_account_id) z NULL dla pliku
 * nie blokowałby wielu slotów pliku w MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_source_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('source_key', 40);
            $table->foreignId('b2b_account_id')->nullable()->constrained('b2b_accounts')->nullOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            $table->decimal('catalog_price_net', 12, 2)->nullable();
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedInteger('pack_qty')->nullable();
            $table->timestamp('checked_at')->nullable();
            // slot odtworzony z historii cen przy wdrożeniu (historia nie ma waluty ani rabatu — waluta z karty, rabat null)
            $table->boolean('migrated')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'source_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_source_prices');
    }
};
