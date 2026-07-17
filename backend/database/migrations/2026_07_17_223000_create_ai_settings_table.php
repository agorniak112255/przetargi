<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('provider', 50)->default('openai_compatible');
            $table->string('base_url', 255)->default('https://api.openai.com/v1');
            $table->text('api_key')->nullable();
            $table->string('model', 120)->default('gpt-4o-mini');
            $table->unsignedSmallInteger('timeout_seconds')->default(90);
            $table->decimal('temperature', 3, 2)->default(0.1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
