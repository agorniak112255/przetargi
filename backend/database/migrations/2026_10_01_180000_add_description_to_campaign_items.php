<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Krótki opis produktu w mailu kampanii (układy „z opisem”): description = tekst wpisany przez handlowca przy pozycji
 * (null = wycinek opisu karty, ProductExcerpt); snap_description / snap_norms = co dostał klient (zapis przy starcie
 * wysyłki, jak cena i stan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_items', function (Blueprint $table): void {
            $table->string('description', 300)->nullable()->after('note');
            $table->string('snap_description', 300)->nullable()->after('snap_image_url');
            $table->string('snap_norms', 300)->nullable()->after('snap_description');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_items', function (Blueprint $table): void {
            $table->dropColumn(['description', 'snap_description', 'snap_norms']);
        });
    }
};
