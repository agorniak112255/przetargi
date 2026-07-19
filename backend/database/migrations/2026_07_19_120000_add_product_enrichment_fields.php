<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('enrichment_status', 20)->default('none')->after('description');
            $table->timestamp('enriched_at')->nullable()->after('enrichment_status');
            $table->text('enrichment_error')->nullable()->after('enriched_at');
            $table->json('enrichment_payload')->nullable()->after('enrichment_error');
        });

        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('path');
            $table->string('source_url', 2000)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
            $table->unique(['product_id', 'checksum']);
        });

        Schema::create('product_enrichment_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 20);
            $table->unsignedBigInteger('scope_id');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('status', 20)->default('queued');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('force')->default(false);
            $table->timestamps();

            $table->index(['scope', 'scope_id']);
        });

        Schema::table('price_lists', function (Blueprint $table): void {
            $table->json('product_ids')->nullable()->after('skipped_details');
        });

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->boolean('web_search_enabled')->default(true)->after('temperature');
            $table->text('tavily_api_key')->nullable()->after('web_search_enabled');
            $table->string('search_fallback', 30)->default('tavily')->after('tavily_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn(['web_search_enabled', 'tavily_api_key', 'search_fallback']);
        });

        Schema::table('price_lists', function (Blueprint $table): void {
            $table->dropColumn('product_ids');
        });

        Schema::dropIfExists('product_enrichment_batches');
        Schema::dropIfExists('product_images');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'enrichment_status',
                'enriched_at',
                'enrichment_error',
                'enrichment_payload',
            ]);
        });
    }
};
