<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Propozycje łączenia karty dystrybutora z kartą producenta (plan łączenia kart, etapy B i C). Dystrybutor wielu marek
 * (P4S, Raw-Pol, Ardon…) zakłada własną kartę tego samego wyrobu — propozycja powstaje tylko z pewnego klucza (EAN albo
 * kod producenta w marce), a łączy człowiek na ekranie „Łączenie kart”.
 *
 * source_product_id bez klucza obcego: po połączeniu karta-duplikat znika, a wiersz zostaje jako ślad decyzji
 * (source_snapshot: SKU, nazwa, producent sprzed połączenia). Odrzucona para nie wraca przy odświeżeniu propozycji.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_match_candidates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_product_id')->index();
            $table->foreignId('target_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('status', 20)->index();
            $table->string('matched_by', 30)->nullable();
            $table->string('matched_value', 100)->nullable();
            $table->string('matched_source_key', 40)->nullable();
            $table->string('brand', 100)->nullable();
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedInteger('positions')->default(0);
            $table->string('reason', 500)->nullable();
            $table->json('conflict_product_ids')->nullable();
            $table->json('source_snapshot')->nullable();
            $table->string('backup_path', 500)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['source_product_id', 'target_product_id'], 'card_match_candidates_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_match_candidates');
    }
};
