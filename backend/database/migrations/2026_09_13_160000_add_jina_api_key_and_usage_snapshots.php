<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table): void {
            // klucz Jina (s.jina.ai + reader r.jina.ai) z panelu; pusty = JINA_API_KEY z .env
            $table->text('jina_api_key')->nullable()->after('tavily_api_key');
        });

        // próbki salda tokenów Jina — z nich liczymy zużycie na dobę i datę wyczerpania
        Schema::create('jina_usage_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tokens_left');
            $table->timestamp('taken_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jina_usage_snapshots');
        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn('jina_api_key');
        });
    }
};
