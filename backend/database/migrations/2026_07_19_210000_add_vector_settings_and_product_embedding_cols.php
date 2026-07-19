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
            $table->boolean('vector_enabled')->default(false)->after('search_fallback');
            $table->string('qdrant_url', 255)->nullable()->after('vector_enabled');
            $table->text('qdrant_api_key')->nullable()->after('qdrant_url');
            $table->string('qdrant_collection', 120)->nullable()->after('qdrant_api_key');
            $table->string('embedding_model', 120)->nullable()->after('qdrant_collection');
            $table->string('embedding_base_url', 255)->nullable()->after('embedding_model');
            $table->text('embedding_api_key')->nullable()->after('embedding_base_url');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->timestamp('embedding_synced_at')->nullable()->after('enriched_at');
            $table->string('embedding_hash', 64)->nullable()->after('embedding_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'vector_enabled',
                'qdrant_url',
                'qdrant_api_key',
                'qdrant_collection',
                'embedding_model',
                'embedding_base_url',
                'embedding_api_key',
            ]);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['embedding_synced_at', 'embedding_hash']);
        });
    }
};
