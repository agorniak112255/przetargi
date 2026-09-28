<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wyroby z cenami rozmiarów, których pozycje leżą na kilku kartach (dawny podział według ceny, decyzja
        // użytkownika 28.09.2026) — lista z przebiegu dla scalenia kart (etap 2). null = przebieg sprzed zmiany,
        // przebieg próbny albo przerwany błędem.
        Schema::table('b2b_sync_runs', function (Blueprint $table): void {
            $table->json('size_spread')->nullable()->after('price_changes');
        });
    }

    public function down(): void
    {
        Schema::table('b2b_sync_runs', function (Blueprint $table): void {
            $table->dropColumn('size_spread');
        });
    }
};
