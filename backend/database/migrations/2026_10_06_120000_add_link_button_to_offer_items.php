<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Przycisk z linkiem przy pozycji oferty (np. „Zobacz w sklepie”) zamiast „Zapytaj o ofertę” — oferta podaje cenę,
 * więc pytanie o ofertę jest zbędne (06.10.2026). Te same pola co w campaign_items: link tylko https://, nazwa ≤ 40.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_items', function (Blueprint $table): void {
            $table->string('link_url', 500)->nullable()->after('description');
            $table->string('link_label', 40)->nullable()->after('link_url');
            $table->string('link_color', 7)->nullable()->after('link_label');
        });
    }

    public function down(): void
    {
        Schema::table('offer_items', function (Blueprint $table): void {
            $table->dropColumn(['link_url', 'link_label', 'link_color']);
        });
    }
};
