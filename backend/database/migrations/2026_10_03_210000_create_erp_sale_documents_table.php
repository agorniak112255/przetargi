<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nagłówki faktur i paragonów klientów z Comarch ERP XL (FS, PA, FSE i korekty FS/PA ze znakiem) — jedno źródło
 * sprzedaży dla karty klienta, celów handlowców i podpowiedzi zamówień. Wartość = suma TrE_KsiegowaNetto pozycji
 * dokumentu (netto PLN, jak erp:clients). Tylko klienci z zakładki Klienci (clients.xl_gid); odświeża
 * erp:client-documents co noc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_sale_documents', function (Blueprint $table): void {
            $table->id();
            // TrN_GIDTyp i TrN_GIDNumer — numer dokumentu jest unikalny w obrębie typu
            $table->unsignedSmallInteger('document_type');
            $table->unsignedInteger('document_id');
            $table->string('document_number', 40);
            // invoice | receipt | export_invoice | invoice_correction | receipt_correction (ErpSaleDocument::KINDS)
            $table->string('kind', 20);
            // data sprzedaży z XL (TrN_Data2)
            $table->date('issued_at');
            $table->unsignedInteger('customer_xl_gid')->index();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->decimal('net_value', 14, 2);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['document_type', 'document_id']);
            $table->index(['client_id', 'issued_at']);
            $table->index('issued_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sale_documents');
    }
};
