<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_settings') || Schema::hasColumn('ai_settings', 'embedding_provider')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->string('embedding_provider', 20)
                ->default('local')
                ->after('embedding_api_key');
            $table->string('embedding_openai_model', 120)
                ->nullable()
                ->after('embedding_provider');
            $table->text('embedding_openai_api_key')
                ->nullable()
                ->after('embedding_openai_model');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_settings') || ! Schema::hasColumn('ai_settings', 'embedding_provider')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn(['embedding_provider', 'embedding_openai_model', 'embedding_openai_api_key']);
        });
    }
};
