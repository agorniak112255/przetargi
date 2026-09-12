<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nazwa modelu z cennika („Nazwa Modelu”: BAXTER, RUSH+ 2.0, TRACKER u Bollé).
 * Kod BAXCSP nie istnieje w żadnym sklepie jako słowo, a nazwa produktu u tego
 * dostawcy to opis soczewek — bez nazwy modelu nie ma czym szukać karty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('model_name', 120)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('model_name');
        });
    }
};
