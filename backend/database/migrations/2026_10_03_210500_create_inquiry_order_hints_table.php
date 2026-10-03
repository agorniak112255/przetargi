<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Podpowiedź „możliwe, że to zamówienie z tej oferty” (inquiries:order-hints): dokument sprzedaży z ERP XL klienta
 * zapytania z co najmniej jednym zaoferowanym towarem w 60 dni od odpowiedzi. To wniosek, nie fakt — wyniku
 * zapytania nigdy nie wpisuje sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_order_hints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_inquiry_id')->constrained('client_inquiries')->cascadeOnDelete();
            $table->unsignedSmallInteger('document_type');
            $table->unsignedInteger('document_id');
            $table->string('document_number', 40);
            $table->date('issued_at');
            // wartość netto całego dokumentu i samych pozycji z towarami oferty
            $table->decimal('document_net', 14, 2);
            $table->decimal('matched_net', 14, 2);
            // towary w ofercie / z nich powiązane z towarem XL / znalezione na dokumencie
            $table->unsignedSmallInteger('offered_items');
            $table->unsignedSmallInteger('linked_items');
            $table->unsignedSmallInteger('matched_items');
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            // własna nazwa — domyślna przekracza 64 znaki (limit MySQL/MariaDB)
            $table->unique(['client_inquiry_id', 'document_type', 'document_id'], 'inquiry_order_hints_document_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_order_hints');
    }
};
