<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skuteczność domen przy szukaniu kart produktu. Liczba prób site: jest ograniczona,
 * więc sklepy, które realnie oddają karty, mają iść w kolejce pierwsze.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_host_scores', function (Blueprint $table): void {
            $table->id();
            $table->string('host', 190)->unique();
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedInteger('misses')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_host_scores');
    }
};
