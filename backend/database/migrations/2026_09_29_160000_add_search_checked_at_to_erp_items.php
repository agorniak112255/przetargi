<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Propozycje z wyszukiwarki dla towarów XL bez kodu (erp:suggest): kiedy towar przeszukano ostatnio — kolejny przebieg
 * bierze najpierw nieprzeszukane, a przeszukane wracają dopiero po kilkudziesięciu dniach (katalog rośnie).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_items', function (Blueprint $table): void {
            $table->timestamp('search_checked_at')->nullable()->after('match_value');
        });
    }

    public function down(): void
    {
        Schema::table('erp_items', function (Blueprint $table): void {
            $table->dropColumn('search_checked_at');
        });
    }
};
