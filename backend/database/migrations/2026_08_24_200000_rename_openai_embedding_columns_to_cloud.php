<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_settings')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_settings', 'embedding_openai_model')
                && ! Schema::hasColumn('ai_settings', 'embedding_cloud_model')) {
                $table->renameColumn('embedding_openai_model', 'embedding_cloud_model');
            }
            if (Schema::hasColumn('ai_settings', 'embedding_openai_api_key')
                && ! Schema::hasColumn('ai_settings', 'embedding_cloud_api_key')) {
                $table->renameColumn('embedding_openai_api_key', 'embedding_cloud_api_key');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_settings')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_settings', 'embedding_cloud_model')
                && ! Schema::hasColumn('ai_settings', 'embedding_openai_model')) {
                $table->renameColumn('embedding_cloud_model', 'embedding_openai_model');
            }
            if (Schema::hasColumn('ai_settings', 'embedding_cloud_api_key')
                && ! Schema::hasColumn('ai_settings', 'embedding_openai_api_key')) {
                $table->renameColumn('embedding_cloud_api_key', 'embedding_openai_api_key');
            }
        });
    }
};
