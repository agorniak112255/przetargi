<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pozycje FS/PA z ERP XL z towarami niedawno wysłanych kampanii (erp:campaign-sales, co noc) — wynik „kupili odbiorcy
 * kampanii”: kto, kiedy, ile i za ile netto. Kopia dosłowna, XL tylko czytany; anulowany później dokument znika
 * przy następnym odczycie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_sale_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('document_type');
            $table->unsignedInteger('document_id');
            $table->unsignedInteger('line');
            $table->string('document_number', 40);
            $table->date('sold_at');
            $table->unsignedInteger('customer_xl_gid');
            $table->foreignId('erp_customer_id')->nullable()->constrained('erp_customers')->nullOnDelete();
            $table->foreignId('erp_item_id')->constrained('erp_items')->cascadeOnDelete();
            $table->decimal('quantity', 14, 3);
            // PLN netto (TrE_KsiegowaNetto); na paragonie bez VAT
            $table->decimal('net_value', 14, 2);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['document_type', 'document_id', 'line']);
            $table->index(['erp_item_id', 'sold_at']);
            $table->index('erp_customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sale_lines');
    }
};
