<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Łączenie kart, krok 6: dane decyzji „Połącz rozmiary” (karta, która zostaje, karty łączone, nazwa i lista rozmiarów
 * z zatwierdzenia, pozycje wiodące, ceny kart sprzed łączenia) — ślad, co dokładnie człowiek zatwierdził. Przykład
 * z produkcji: 3M 6100 S #40819 zostaje, #40815 i #40814 wchodzą w nią, dołączona karta P4S 6X00 #56362.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_match_candidates', function (Blueprint $table): void {
            $table->json('decision_input')->nullable()->after('plan_hash');
        });
    }

    public function down(): void
    {
        Schema::table('card_match_candidates', function (Blueprint $table): void {
            $table->dropColumn('decision_input');
        });
    }
};
