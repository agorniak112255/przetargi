<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Źródła opisu karty (etap 1 opisów z cenników, 08.10.2026): strona albo PDF użyty przy opisie, z werdyktem tożsamości
 * i skrótem tekstu. Sam tekst leży na dysku „sources” ({sha256}.txt.gz, jedna kopia na wiele kart — strona rodziny Coba
 * obsługuje kilka kart). Zapis i retencja (najnowszy wiersz na kartę i adres + wiersze opublikowanych wersji):
 * App\Services\Enrichment\SourceDocumentStore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_source_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // wersja opisu, przy której zapisano źródło — bez klucza obcego (wersje mają własną retencję)
            $table->unsignedBigInteger('description_version_id')->nullable()->index();
            $table->string('url', 2000);
            // sha1(Product::normalizeShopUrl(url))
            $table->char('url_hash', 40);
            $table->string('final_url', 2000)->nullable();
            $table->string('host', 255);
            // tekst surowy strony (przed filtrem stron modelu)
            $table->char('sha256', 64);
            // tekst po filtrze stron — null, gdy taki sam albo nieznany
            $table->char('filtered_sha256', 64)->nullable();
            $table->unsignedInteger('chars');
            $table->timestamp('fetched_at');
            // hard | soft | none (SourceIdentity)
            $table->string('identity_verdict', 8)->nullable();
            $table->string('identity_reason', 255)->nullable();
            // np. „sku:CCLIP25”
            $table->string('identity_key', 80)->nullable();
            // description,image,norms
            $table->string('roles', 40);
            $table->json('markup_codes')->nullable();
            $table->json('norm_facts')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'url_hash', 'sha256'], 'psd_unique');
            $table->index('sha256');
            $table->index(['product_id', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_source_documents');
    }
};
