<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Oferty pozostałych firm w części (z informacji z otwarcia ofert) — jedna oferta firmy na część.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_lot_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tender_lot_id')->constrained('tender_lots')->cascadeOnDelete();
            $table->foreignId('competitor_id')->constrained('competitors')->cascadeOnDelete();
            $table->decimal('price', 14, 2);
            $table->string('currency', 3)->default('PLN');
            $table->string('source', 10)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tender_lot_id', 'competitor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_lot_offers');
    }
};
