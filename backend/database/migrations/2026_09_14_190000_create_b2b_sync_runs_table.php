<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Przebiegi pobierania cennika B2B: postęp i dziennik dla okna „Sprawdź teraz”.
        // updated_at służy za sygnał życia — przebieg bez postępu uznajemy za przerwany.
        Schema::create('b2b_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('b2b_account_id')->constrained('b2b_accounts')->cascadeOnDelete();
            $table->string('status', 10);
            $table->string('trigger', 10);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('total')->nullable();
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('prices_changed')->default(0);
            $table->unsignedInteger('descriptions')->default(0);
            $table->unsignedInteger('images')->default(0);
            $table->string('current_sku')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->json('log')->nullable();
            // Zmiany cen w przebiegu (detectPriceChange + product_id + at), chronologicznie.
            $table->json('price_changes')->nullable();
            $table->timestamps();

            $table->index(['b2b_account_id', 'id']);
            $table->index(['status', 'updated_at']);
        });

        // Synchronizacja B2B nie zakłada wpisów w historii cenników — wpis historii ceny karty
        // wskazuje przebieg, z którego pochodzi.
        Schema::table('product_price_history', function (Blueprint $table): void {
            $table->foreignId('b2b_sync_run_id')->nullable()->after('price_list_id')
                ->constrained('b2b_sync_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_price_history', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('b2b_sync_run_id');
        });

        Schema::dropIfExists('b2b_sync_runs');
    }
};
