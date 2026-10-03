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
            // TIMESTAMP bez NULL i bez wartości domyślnej: MariaDB z explicit_defaults_for_timestamp=0 (produkcja) dałaby
            // pierwszej kolumnie ON UPDATE CURRENT_TIMESTAMP (data zmieniałaby się przy każdym zapisie), a kolejnej
            // błąd 1067. Data publikacji jest zawsze podawana (parser odrzuca ogłoszenie bez niej) — bez zmyślonej domyślnej.
            $table->timestamp('published_at')->nullable();
            $table->timestamp('submitting_offers_at')->nullable();
            $table->text('order_object');
            $table->json('cpv_codes');
            $table->string('organization_name', 500);
            $table->string('organization_city', 200)->nullable();
            $table->string('organization_province', 10)->nullable();
            $table->string('organization_nip', 20)->nullable();
            // wynik postępowania z API dla wszystkich części („zawarcie umowy;unieważnienie;…”) — bez obcinania
            $table->text('procedure_result')->nullable();
            $table->json('contractors')->nullable();
            $table->json('parsed')->nullable();
            $table->smallInteger('parser_version')->default(0);
            $table->longText('html_body')->nullable();
            // chwila zapisu; BzpNoticeStore zawsze ją podaje — domyślna bez ON UPDATE
            $table->timestamp('fetched_at')->useCurrent();
            $table->timestamps();

            $table->index(['notice_type', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_notices');
    }
};
