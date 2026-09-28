<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Próby uzupełnienia krótkiego opisu B2B ze stron konta — jedna na parę karta+konto. source_sha1 (odcisk tekstu
 * źródła) i hosts_sha1 (lista stron konta) mówią, dla jakiego wejścia była próba: synchronizacja zleca kartę ponownie
 * tylko po zmianie tekstu u dostawcy albo listy stron, a nie przy każdym przebiegu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_description_supplement_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('b2b_account_id')->constrained('b2b_accounts')->cascadeOnDelete();
            $table->char('source_sha1', 40);
            $table->char('hosts_sha1', 40);
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->char('result_sha1', 40)->nullable();
            $table->json('source_urls')->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'b2b_account_id'], 'b2b_supplement_attempts_product_account_unique');
            $table->index(['b2b_account_id', 'status'], 'b2b_supplement_attempts_account_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_description_supplement_attempts');
    }
};
