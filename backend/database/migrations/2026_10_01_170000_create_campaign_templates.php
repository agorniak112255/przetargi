<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Własne szablony maili kampanii (bloki: logo, nagłówek, tekst, grafika, produkty, przycisk, stopka) i wgrane do nich
 * obrazki. Kampania dostaje kopię bloków szablonu (blocks) — zmiana szablonu nie zmienia gotowych kampanii.
 * Kampania z blocks = null renderuje się jak dotąd (heading, intro, layout).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 150);
            $table->boolean('is_shared')->default(false);
            $table->string('brand_color', 7)->nullable();
            $table->json('blocks');
            $table->timestamps();

            $table->index('is_shared');
        });

        // obrazki publiczne (link z maila), plik na dysku local — ścieżka nigdy z żądania
        Schema::create('campaign_assets', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('path', 255);
            $table->string('mime', 20);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('size');
            $table->timestamps();
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->foreignId('template_id')->nullable()->after('layout')->constrained('campaign_templates')->nullOnDelete();
            $table->json('blocks')->nullable()->after('template_id');
            $table->string('brand_color', 7)->nullable()->after('blocks');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('template_id');
            $table->dropColumn(['blocks', 'brand_color']);
        });
        Schema::dropIfExists('campaign_assets');
        Schema::dropIfExists('campaign_templates');
    }
};
