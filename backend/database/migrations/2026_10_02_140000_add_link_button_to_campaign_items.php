<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drugi przycisk przy produkcie w mailu kampanii (obok „Zapytaj o ofertę”): link wpisany przez handlowca (np. do
 * sklepu), nazwa przycisku i kolor z palety CampaignBlocks::BRAND_COLORS. Wszystkie trzy albo żadne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_items', function (Blueprint $table): void {
            $table->string('link_url', 500)->nullable()->after('description');
            $table->string('link_label', 40)->nullable()->after('link_url');
            $table->string('link_color', 7)->nullable()->after('link_label');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_items', function (Blueprint $table): void {
            $table->dropColumn(['link_url', 'link_label', 'link_color']);
        });
    }
};
