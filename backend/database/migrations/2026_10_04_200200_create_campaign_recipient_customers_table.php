<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raport „Wynik kampanii” (04.10.2026): którym klientom ERP XL odpowiada odbiorca kampanii — zamrożone przy starcie
 * wysyłki i przy dopisaniu odbiorców, żeby wynik zamkniętego miesiąca nie zmieniał się po edycji kart w XL.
 * matched_by: direct = odbiorca wybrany jako klient XL (campaign_recipients.erp_customer_id); email = adres odbiorcy
 * z grupy jest na karcie kontrahenta (erp_customers.emails). cards_count = na ilu kartach był ten adres (> 1 → zakupy
 * kilku kontrahentów, np. oddziałów; raport to oznacza).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_recipient_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('campaign_recipient_id')->constrained('campaign_recipients')->cascadeOnDelete();
            $table->foreignId('erp_customer_id')->constrained('erp_customers')->cascadeOnDelete();
            $table->string('matched_by', 10);
            $table->unsignedSmallInteger('cards_count')->default(1);
            $table->timestamps();

            $table->unique(['campaign_recipient_id', 'erp_customer_id'], 'crc_recipient_customer_unique');
            $table->index(['campaign_id', 'erp_customer_id']);
            $table->index('erp_customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipient_customers');
    }
};
