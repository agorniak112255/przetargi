<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parametry wpisane ręcznie na karcie — wiersze „parametr: wartość”.
 *
 * Jedyne dane karty, których nie rusza żadna automatyka: ani import cennika, ani synchronizacja
 * B2B, ani wzbogacanie, ani import z Presty. Tu trafia to, czego nie ma w opisie producenta i nie
 * zawsze stoi na innych stronach, a bez czego nie da się odpowiedzieć na wymaganie przetargu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->json('manual_specs')->nullable()->after('price_list_attributes');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('manual_specs');
        });
    }
};
