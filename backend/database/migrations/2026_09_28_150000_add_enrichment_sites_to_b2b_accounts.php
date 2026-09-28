<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Strony z opisami przy koncie B2B (decyzja użytkownika 28.09.2026): karta tego konta z opisem z B2B krótszym niż
 * próg jest szukana najpierw na tych stronach, a AI pisze opis z tekstu B2B i znalezionych stron razem
 * (B2bDescriptionSupplement). enrichment_sites — lista hostów; enrichment_min_chars — próg w znakach samego tekstu,
 * null = B2bDescriptionSupplement::DEFAULT_MIN_CHARS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b_accounts', function (Blueprint $table): void {
            $table->json('enrichment_sites')->nullable()->after('note');
            $table->unsignedSmallInteger('enrichment_min_chars')->nullable()->after('enrichment_sites');
        });
    }

    public function down(): void
    {
        Schema::table('b2b_accounts', function (Blueprint $table): void {
            $table->dropColumn(['enrichment_sites', 'enrichment_min_chars']);
        });
    }
};
