<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opis wspólny dla modelu (etap 2 opisów z cenników, 08.10.2026): klucz modelu zamrożony w pozycji partii
 * (model_key = marka | rodzina SKU | rdzeń nazwy, liczony w locie przez ProductModelKey), lider grupy
 * (model_leader_id — lider ma własne id) i wersja opisu lidera po jego przebiegu (model_leader_version_id) —
 * podstawa opisu członków także po restarcie workera. Znacznik przejęcia pozycji członka (model_claim_uuid = uuid
 * zadania ApplyModelDescriptionJob, stały między próbami): ponowienie po limicie czasu rozpoznaje własne pozycje
 * „running” i przejmuje je bez względu na wiek, cudze tylko porzucone. Bez zmian w products, bez nowych tabel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_enrichment_batch_items', function (Blueprint $table): void {
            $table->string('model_key', 160)->nullable()->after('previous_error');
            $table->unsignedBigInteger('model_leader_id')->nullable()->after('model_key');
            $table->unsignedBigInteger('model_leader_version_id')->nullable()->after('model_leader_id');
            $table->string('model_claim_uuid', 36)->nullable()->after('model_leader_version_id');
            $table->index(['batch_id', 'model_key'], 'pebi_batch_model');
        });
    }

    public function down(): void
    {
        Schema::table('product_enrichment_batch_items', function (Blueprint $table): void {
            $table->dropIndex('pebi_batch_model');
            $table->dropColumn(['model_key', 'model_leader_id', 'model_leader_version_id', 'model_claim_uuid']);
        });
    }
};
