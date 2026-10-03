<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ogłoszenia pobrane z Biuletynu Zamówień Publicznych (o zamówieniu i o wyniku). Jedno ogłoszenie = jeden wiersz
 * po numerze ogłoszenia; `parsed` = części odczytane parserem w wersji `parser_version`. Pełny HTML zostaje tylko
 * przy ogłoszeniach powiązanych z przetargiem, pozostałe czyści system:prune po 30 dniach.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_notices', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 10)->default('bzp');
            $table->string('notice_type', 40);
            $table->string('notice_number', 40)->unique();
            $table->string('bzp_number', 30)->index();
            $table->string('ocds_id', 100)->nullable()->index();
            $table->string('preceding_bzp_number', 30)->nullable()->index();
            $table->string('object_id', 64)->nullable();
            $table->timestamp('published_at');
            $table->timestamp('submitting_offers_at')->nullable();
            $table->text('order_object');
            $table->json('cpv_codes');
            $table->string('organization_name', 500);
            $table->string('organization_city', 200)->nullable();
            $table->string('organization_province', 10)->nullable();
            $table->string('organization_nip', 20)->nullable();
            $table->string('procedure_result', 255)->nullable();
            $table->json('contractors')->nullable();
            $table->json('parsed')->nullable();
            $table->smallInteger('parser_version')->default(0);
            $table->longText('html_body')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index(['notice_type', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_notices');
    }
};
