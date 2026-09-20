<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zrozumienie wymagania zapisane raz. Do 21.09.2026 każdy przebieg pytał model od nowa, a ten za każdym razem
 * inaczej nazywał produkt i warunki — dla tego samego wymagania do oceny szedł inny zestaw kart (poz. 15 przetargu 1:
 * z 54 różnych kart tylko 4 wspólne dla 5 przebiegów; właściwa karta docierała do modelu w 3–4 przebiegach z 5).
 * Tabela jest też śladem do audytu: widać, jak system zrozumiał dane wymaganie.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('requirement_understandings')) {
            return;
        }

        Schema::create('requirement_understandings', function (Blueprint $table): void {
            $table->id();
            $table->char('requirement_hash', 64);
            $table->string('prompt_version', 64);
            $table->text('requirement');
            $table->json('answer');
            $table->timestamps();
            $table->unique(['requirement_hash', 'prompt_version'], 'requirement_understandings_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requirement_understandings');
    }
};
