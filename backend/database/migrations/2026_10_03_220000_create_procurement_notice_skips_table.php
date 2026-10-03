<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decyzja „pominięte” przy postępowaniu z Biuletynu (zakładka Ogłoszenia) — jedna na postępowanie (bzp_number, numer
 * ogłoszenia bez wersji), wspólna dla zespołu, więc nowa wersja ogłoszenia (…/02) nie wraca do „Nowe”.
 * procurement_notice_id = wersja ogłoszenia, przy której zapadła decyzja (pochodzenie). Usunięcie tej wersji usuwa
 * decyzję; usunięcie konta zostawia decyzję bez autora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_notice_skips', function (Blueprint $table): void {
            $table->id();
            // jak procurement_notices.bzp_number
            $table->string('bzp_number', 30)->unique();
            $table->foreignId('procurement_notice_id')->constrained('procurement_notices')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // nullable: MariaDB z explicit_defaults_for_timestamp=0 (produkcja) dałaby kolumnie NOT NULL
            // ON UPDATE CURRENT_TIMESTAMP; chwilę zapisuje model
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_notice_skips');
    }
};
