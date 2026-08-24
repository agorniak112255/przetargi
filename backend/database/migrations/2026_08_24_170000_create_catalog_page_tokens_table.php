<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_page_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_page_id')->constrained('catalog_pages')->cascadeOnDelete();
            $table->string('token', 64);
            $table->unique(['catalog_page_id', 'token']);
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_page_tokens');
    }
};
