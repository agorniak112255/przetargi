<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b_accounts', function (Blueprint $table): void {
            $table->string('connector', 40)->nullable()->after('note');
            $table->string('sync_frequency', 10)->default('off')->after('connector');
            $table->boolean('sync_images')->default(true)->after('sync_frequency');
            $table->timestamp('sync_requested_at')->nullable()->after('sync_images');
            $table->string('last_sync_status', 10)->nullable()->after('sync_requested_at');
            $table->timestamp('last_sync_started_at')->nullable()->after('last_sync_status');
            $table->timestamp('last_sync_finished_at')->nullable()->after('last_sync_started_at');
            $table->text('last_sync_message')->nullable()->after('last_sync_finished_at');
            $table->foreignId('last_price_list_id')->nullable()->after('last_sync_message')
                ->constrained('price_lists')->nullOnDelete();
        });

        // Produkt u dostawcy ↔ karta w katalogu. Hash opisu zapisanego przez synchronizację
        // pozwala odświeżać opis ze źródła, ale nie nadpisać opisu poprawionego ręcznie.
        Schema::create('b2b_product_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('b2b_account_id')->constrained('b2b_accounts')->cascadeOnDelete();
            $table->string('remote_id', 64);
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('remote_sku')->nullable();
            $table->string('description_hash', 40)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['b2b_account_id', 'remote_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_product_links');

        Schema::table('b2b_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('last_price_list_id');
            $table->dropColumn([
                'connector',
                'sync_frequency',
                'sync_images',
                'sync_requested_at',
                'last_sync_status',
                'last_sync_started_at',
                'last_sync_finished_at',
                'last_sync_message',
            ]);
        });
    }
};
