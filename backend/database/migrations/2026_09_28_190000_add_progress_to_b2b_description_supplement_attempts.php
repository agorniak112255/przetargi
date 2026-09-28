<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Postęp uzupełniania krótkich opisów B2B widoczny w panelu (prośba użytkownika 28.09.2026: „chcę widzieć, jaki
 * produkt obrabia, co robi”): bieżący etap pracy joba, początek pracy nad kartą i godzina ponowienia, gdy karta czeka
 * na wyszukiwarkę.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b_description_supplement_attempts', function (Blueprint $table): void {
            $table->string('stage', 160)->nullable()->after('status');
            $table->timestamp('started_at')->nullable()->after('attempted_at');
            $table->timestamp('retry_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('b2b_description_supplement_attempts', function (Blueprint $table): void {
            $table->dropColumn(['stage', 'started_at', 'retry_at']);
        });
    }
};
