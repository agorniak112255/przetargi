<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturer_sites', function (Blueprint $table): void {
            $table->id();
            $table->string('brand_key', 80);
            $table->string('manufacturer', 100);
            $table->string('host', 190);
            $table->string('source', 20)->default('discovered');
            $table->timestamps();
            $table->unique(['brand_key', 'host']);
            $table->index('brand_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturer_sites');
    }
};
