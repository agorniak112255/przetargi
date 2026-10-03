<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firmy konkurencji z wyników przetargów — jedna firma = jeden wiersz: najpierw po NIP (poprawna suma kontrolna),
 * potem po znormalizowanej nazwie (CompanyName::key), żeby raport nie liczył jednej firmy pod dwiema nazwami.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitors', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 500);
            $table->string('name_key', 191)->index();
            $table->string('nip', 10)->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitors');
    }
};
