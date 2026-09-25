<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Oceny cech widocznych na zdjęciu karty (na razie zabudowana pięta sandałów) — wniosek modelu ze zdjęcia, nie tekst
 * karty (decyzja właściciela z 25.09.2026: tylko gdy karta nie mówi o tym słowami). Jedna ocena na zdjęcie: usunięte
 * albo odrzucone zdjęcie zabiera ocenę kaskadą, nowe zdjęcie główne to nowa ocena.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_visual_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_image_id')->constrained('product_images')->cascadeOnDelete();
            $table->string('feature', 40);
            $table->string('answer', 16);
            $table->char('image_checksum', 64)->nullable();
            $table->string('image_source_url', 1024)->nullable();
            $table->string('model', 191)->nullable();
            $table->string('prompt_version', 40);
            $table->string('what_seen', 255)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'feature', 'product_image_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_visual_checks');
    }
};
