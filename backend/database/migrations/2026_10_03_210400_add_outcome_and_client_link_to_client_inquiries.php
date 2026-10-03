<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zapytania klientów:
 * - client_link_source: skąd powiązanie z klientem — manual (handlowiec), email (ten sam adres co na karcie klienta
 *   z ERP XL), nip (NIP z treści maila). Automat (InquiryClientLinker) nigdy nie nadpisuje ręcznego. Istniejące
 *   powiązania (client_id z formularza) to wybór handlowca, więc dostają manual.
 * - outcome*: jak się skończyło zapytanie — wpisuje handlowiec (ordered | partial | not_ordered | unknown) z powodem
 *   i opcjonalnie dokumentem z ERP XL skopiowanym z podpowiedzi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->string('client_link_source', 8)->nullable();
            $table->timestamp('client_linked_at')->nullable();
            $table->string('outcome', 16)->nullable()->index();
            $table->string('outcome_reason', 20)->nullable();
            $table->foreignId('outcome_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('outcome_at')->nullable();
            $table->string('outcome_document_number', 40)->nullable();
            $table->date('outcome_document_date')->nullable();
            $table->decimal('outcome_net_value', 14, 2)->nullable();
        });

        DB::table('client_inquiries')->whereNotNull('client_id')->update(['client_link_source' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->dropForeign(['outcome_by']);
            $table->dropIndex(['outcome']);
        });
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->dropColumn([
                'client_link_source', 'client_linked_at', 'outcome', 'outcome_reason', 'outcome_by', 'outcome_at',
                'outcome_document_number', 'outcome_document_date', 'outcome_net_value',
            ]);
        });
    }
};
