<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Przeglądy (prośba właściciela 06.10.2026):
 *
 * - WZ jako źródło: faktura do WZ nie ma w XL własnych pozycji (towar jest tylko na WZ, faktura w spinaczu WZ —
 *   sonda 06.10.2026: 9 923 takich FS w 2025, 36,8 mln zł netto). inspection_sale_lines dostaje numer faktury ze
 *   spinacza (do wyświetlenia przy WZ). Towary pozycji dostają historię od nowa (history_loaded_at = null) — najbliższy
 *   odczyt dociągnie ich WZ od 2019.
 * - Dane klienta z kartoteki XL dla klientów z terminami: adres, telefony, osoby kontaktowe, opiekun. Kolumny bez prawa
 *   odczytu loginu aplikacji zostają puste, dopóki administrator XL ich nie udostępni.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_sale_lines', function (Blueprint $table): void {
            // faktura ze spinacza WZ (TrN_SpiTyp 2033 / TrN_SpiNumer); null = dokument sam jest fakturą albo WZ bez faktury
            $table->string('invoice_number', 40)->nullable()->after('document_number');
        });

        Schema::table('erp_customers', function (Blueprint $table): void {
            $table->string('street', 200)->nullable()->after('city');
            $table->string('address_line2', 200)->nullable()->after('street');
            $table->string('postal_code', 20)->nullable()->after('address_line2');
            $table->string('voivodeship', 100)->nullable()->after('postal_code');
            $table->string('phone', 100)->nullable()->after('voivodeship');
            $table->string('phone2', 100)->nullable()->after('phone');
            // osoby kontaktowe z karty XL (KntOsoby, bez archiwalnych): [{name, position, email, phone, mobile}]
            $table->json('contacts')->nullable()->after('phone2');
            // opiekun klienta z XL (KntOpiekun → PrcKarty), dosłownie
            $table->string('account_manager', 150)->nullable()->after('contacts');
            $table->string('account_manager_email', 150)->nullable()->after('account_manager');
            $table->timestamp('details_synced_at')->nullable()->after('account_manager_email');
        });

        DB::table('inspection_positions')->where('xl_type', 1)->update(['history_loaded_at' => null]);
    }

    public function down(): void
    {
        Schema::table('erp_customers', function (Blueprint $table): void {
            $table->dropColumn([
                'street', 'address_line2', 'postal_code', 'voivodeship', 'phone', 'phone2', 'contacts', 'account_manager',
                'account_manager_email', 'details_synced_at',
            ]);
        });
        Schema::table('inspection_sale_lines', function (Blueprint $table): void {
            $table->dropColumn('invoice_number');
        });
    }
};
