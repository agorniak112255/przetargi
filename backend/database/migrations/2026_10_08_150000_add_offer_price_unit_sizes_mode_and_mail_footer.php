<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uwagi handlowca do ofert (08.10.2026): jednostka ceny pozycji (szt, para, opak., karton — null = jednostka towaru XL,
 * bez niej „szt”), opcjonalne rozmiary pozycji (np. wyprzedaż tylko S i XXXL), ceny w mailu netto albo brutto
 * (offers.price_mode) i stopka maila pracownika w „Moje konto” (users.mail_footer: name, position, mobile, phone,
 * email) — zamiast zwykłego podpisu pod ofertą i kampanią.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_items', function (Blueprint $table): void {
            $table->string('price_unit', 10)->nullable()->after('price_net');
            $table->string('sizes', 200)->nullable()->after('price_unit');
        });

        Schema::table('offers', function (Blueprint $table): void {
            $table->string('price_mode', 5)->default('net');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->json('mail_footer')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('mail_footer');
        });
        Schema::table('offers', function (Blueprint $table): void {
            $table->dropColumn('price_mode');
        });
        Schema::table('offer_items', function (Blueprint $table): void {
            $table->dropColumn(['price_unit', 'sizes']);
        });
    }
};
