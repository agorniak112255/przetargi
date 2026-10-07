<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wersje opisu karty (etap 1 opisów z cenników, 08.10.2026): każdy zapisany opis (published), propozycja gorsza od
 * bieżącego (proposed), opis zastąpiony nowszym (superseded), odrzucony w przeglądzie (rejected) i przebieg w cieniu
 * (shadow). Bazą porównania jest wersja published, której description_sha1 = sha1 bieżącego opisu karty — zapis opisu
 * innym torem (B2B, reset, edycja) zmienia skrót i baza wygasa sama. Retencja w DescriptionVersionStore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_description_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // published | proposed | superseded | rejected | shadow
            $table->string('status', 16);
            // enrichment | sku_cache | restore | review_approve | legacy_baseline | stored_sources
            $table->string('origin', 24);
            $table->text('description')->nullable();
            $table->json('enrichment_payload')->nullable();
            $table->json('enrichment_trace')->nullable();
            $table->string('packaging')->nullable();
            $table->char('description_sha1', 40)->nullable();
            $table->string('primary_source_url', 2000)->nullable();
            // hard | soft | none (SourceIdentity); null = nieznany (np. baza bez pobrania strony)
            $table->string('identity_verdict', 8)->nullable();
            $table->string('identity_reason', 255)->nullable();
            $table->unsignedSmallInteger('evidence_count')->nullable();
            $table->decimal('completeness', 4, 3)->nullable();
            $table->string('review_reason', 32)->nullable();
            $table->string('reason', 255)->nullable();
            // partia pobierania opisów — bez klucza obcego, partie bywają sprzątane
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // approved | rejected | url_given | restored
            $table->string('decision', 16)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index(['product_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_description_versions');
    }
};
