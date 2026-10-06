<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Oferta przeglądu w module Ofert (decyzja właściciela 06.10.2026: na liście „Oferty” z numerem OF-). Oferta zaczepna
 * bez cen: do klienta z ERP XL (customer_xl_gid) idą pozycje z terminem przeglądu (offer_inspection_lines) zamiast
 * produktów. Zwykłe oferty: kind = products, bez zmian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            // Offer::KINDS: products | inspection
            $table->string('kind', 16)->default('products')->after('user_id');
            $table->unsignedInteger('customer_xl_gid')->nullable()->after('kind');
            $table->index(['customer_xl_gid', 'kind']);
        });

        Schema::create('offer_inspection_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->foreignId('inspection_position_id')->nullable()->constrained('inspection_positions')->nullOnDelete();
            // pozycja XL (towar albo usługa) w chwili przygotowania oferty; null = wiersz wpisany ręcznie
            $table->unsignedInteger('xl_gid')->nullable();
            $table->string('name', 500);
            $table->string('unit', 20)->nullable();
            $table->decimal('quantity', 14, 3)->nullable();
            // ostatnia wizyta / zakup i termin z inspection_due w chwili przygotowania (handlowiec może zmienić)
            $table->date('last_on')->nullable();
            $table->date('due_on')->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamps();

            $table->index(['offer_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_inspection_lines');
        Schema::table('offers', function (Blueprint $table): void {
            $table->dropIndex(['customer_xl_gid', 'kind']);
            $table->dropColumn(['kind', 'customer_xl_gid']);
        });
    }
};
