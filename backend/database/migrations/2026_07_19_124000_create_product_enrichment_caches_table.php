<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_enrichment_caches', function (Blueprint $table): void {
            $table->id();
            $table->string('manufacturer', 100);
            $table->string('sku', 100);
            $table->text('description');
            $table->json('enrichment_payload')->nullable();
            $table->json('image_urls')->nullable();
            $table->json('source_urls')->nullable();
            $table->timestamps();

            $table->unique(['manufacturer', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_enrichment_caches');
    }
};
