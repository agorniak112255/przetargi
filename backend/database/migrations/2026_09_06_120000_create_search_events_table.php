<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('task', 32)->default('product_search');
            $table->string('prompt_version', 40)->nullable();
            $table->text('query');
            $table->string('needed', 255)->nullable();
            $table->json('intent')->nullable();
            // Pula z retrievalu — po niej liczy się recall etapu 1.
            $table->json('candidate_ids')->nullable();
            // Karty faktycznie pokazane modelowi (RANK_CARDS).
            $table->json('rank_card_ids')->nullable();
            // Surowe trafienia modelu przed bramkami: [{id, score}].
            $table->json('llm_matches')->nullable();
            // Wynik oddany użytkownikowi w kolejności: [{id, sku, score}].
            $table->json('returned')->nullable();
            $table->unsignedSmallInteger('result_count')->default(0);
            $table->unsignedSmallInteger('candidate_count')->default(0);
            $table->unsignedTinyInteger('passes')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('timings_ms')->nullable();
            $table->string('ai_note', 255)->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('search_event_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // open | pick | add_to_offer
            $table->string('action', 24);
            // Pozycja produktu na liście wyników (1 = pierwszy), gdy da się ustalić.
            $table->unsignedSmallInteger('position')->nullable();
            $table->timestamps();

            $table->unique(['search_event_id', 'product_id', 'action']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_event_actions');
        Schema::dropIfExists('search_events');
    }
};
