<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raport „Wynik kampanii” (04.10.2026): koszt sprzedanego towaru i korekty.
 * cost_value = TrE_KosztKsiegowy pozycji dosłownie, gdy XL go podał (≠ 0); null = XL nie podał kosztu (zwykle FS do WZ
 * — koszt jest na WZ). Korekty FSK (2041) i PAK (2042) z ilością i wartością ze znakiem; corrects_* = dokument
 * korygowany z nagłówka korekty (TrN_ZwrTyp / TrN_ZwrNumer — sprawdzone na produkcji 04.10.2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_sale_lines', function (Blueprint $table): void {
            $table->decimal('cost_value', 14, 2)->nullable()->after('net_value');
            $table->unsignedSmallInteger('corrects_document_type')->nullable()->after('cost_value');
            $table->unsignedInteger('corrects_document_id')->nullable()->after('corrects_document_type');

            $table->index(['corrects_document_type', 'corrects_document_id']);
        });
    }

    public function down(): void
    {
        Schema::table('erp_sale_lines', function (Blueprint $table): void {
            $table->dropIndex(['corrects_document_type', 'corrects_document_id']);
            $table->dropColumn(['cost_value', 'corrects_document_type', 'corrects_document_id']);
        });
    }
};
