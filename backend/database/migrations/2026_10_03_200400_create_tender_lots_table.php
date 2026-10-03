<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wynik przetargu per część zamówienia (przetarg bez części dostaje część nr 1 przy pierwszym zapisie).
 * `manual_fields` = pola wpisane przez człowieka — Biuletyn ich nie nadpisuje; powód przegranej i notatkę
 * wpisuje tylko człowiek. Kwoty zwycięzcy i min/max jak w ogłoszeniu, bez przeliczania walut.
 * `created_by_bzp` = część założona automatycznie przy pierwszym powiązaniu przetargu z ogłoszeniem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('lot_no');
            $table->string('name', 500)->nullable();
            $table->string('cpv_main', 12)->nullable();
            $table->decimal('estimated_value', 14, 2)->nullable();
            $table->decimal('our_net', 14, 2)->nullable();
            $table->decimal('our_vat_rate', 5, 2)->nullable();
            $table->string('outcome', 20)->nullable();
            $table->foreignId('winner_competitor_id')->nullable()->constrained('competitors')->nullOnDelete();
            $table->string('winner_national_id_raw', 40)->nullable();
            $table->decimal('winner_price', 14, 2)->nullable();
            $table->string('currency', 3)->default('PLN');
            $table->unsignedSmallInteger('offers_count')->nullable();
            $table->decimal('lowest_price', 14, 2)->nullable();
            $table->decimal('highest_price', 14, 2)->nullable();
            $table->string('loss_reason', 20)->nullable();
            $table->text('note')->nullable();
            $table->json('manual_fields')->nullable();
            $table->foreignId('bzp_notice_id')->nullable()->constrained('procurement_notices')->nullOnDelete();
            $table->timestamp('bzp_applied_at')->nullable();
            // część założona przez łączenie z Biuletynem (nie przez człowieka) — raport nie liczy jej jako naszej
            // unieważnionej, gdy nie ma w niej naszej ceny ani ręcznego wyniku, a przetarg ma kilka części
            $table->boolean('created_by_bzp')->default(false);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['tender_id', 'lot_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_lots');
    }
};
