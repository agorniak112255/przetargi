<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('path');
            $table->string('source_url', 2000)->nullable();
            $table->string('title', 255)->nullable();
            $table->string('kind', 32)->default('certificate'); // certificate|datasheet|other
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->unsignedInteger('size_bytes')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
            $table->unique(['product_id', 'checksum']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_documents');
    }
};
