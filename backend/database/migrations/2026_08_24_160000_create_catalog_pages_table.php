<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('host', 190)->index();
            $table->char('url_hash', 64)->unique();
            $table->text('url');
            $table->string('title', 500)->nullable();
            // url + tytuł małymi literami — po tym szukamy kodu produktu
            $table->text('haystack');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_pages');
    }
};
