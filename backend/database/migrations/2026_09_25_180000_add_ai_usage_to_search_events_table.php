<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koszt i przebieg jednej pozycji wyszukiwania (ekran „Statystyki AI”): tokeny ze wszystkich odpowiedzi modelu
 * (także pustych, uciętych i ponowień), liczba wywołań, karty ostatniej oceny, stan modelu i skąd pochodzi pozycja
 * (przetarg, zapytanie z poczty). Kolumny skalarne, żeby agregaty liczyły się bez funkcji JSON bazy (testy na sqlite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_events', function (Blueprint $table): void {
            $table->unsignedInteger('prompt_tokens')->nullable()->after('ai_note');
            $table->unsignedInteger('completion_tokens')->nullable()->after('prompt_tokens');
            $table->unsignedInteger('reasoning_tokens')->nullable()->after('completion_tokens');
            $table->unsignedSmallInteger('llm_calls')->nullable()->after('reasoning_tokens');
            $table->unsignedTinyInteger('rank_calls')->nullable()->after('llm_calls');
            $table->unsignedSmallInteger('rank_card_count')->nullable()->after('rank_calls');
            $table->string('model_state', 16)->nullable()->after('rank_card_count');
            $table->string('model', 191)->nullable()->after('model_state');
            $table->string('provider', 191)->nullable()->after('model');
            $table->boolean('fallback')->nullable()->after('provider');
            // Etapy: [{stage, cards?, source?, prompt_tokens, completion_tokens, reasoning_tokens, calls}].
            $table->json('usage')->nullable()->after('fallback');
            // Jedno żądanie dopasowania (paczka „Dopasuj wszystkie”) albo jedno zapytanie z poczty — grupuje pozycje fali.
            $table->string('run_id', 26)->nullable()->after('usage');
            $table->string('context_type', 24)->nullable()->after('run_id');
            $table->unsignedBigInteger('context_id')->nullable()->after('context_type');
            // Numery pozycji przetargu z tą samą treścią (prefetch deduplikuje po treści).
            $table->json('context_items')->nullable()->after('context_id');

            $table->index(['task', 'created_at']);
            $table->index(['context_type', 'context_id']);
            $table->index('run_id');
        });
    }

    public function down(): void
    {
        Schema::table('search_events', function (Blueprint $table): void {
            $table->dropIndex(['task', 'created_at']);
            $table->dropIndex(['context_type', 'context_id']);
            $table->dropIndex(['run_id']);
            $table->dropColumn([
                'prompt_tokens', 'completion_tokens', 'reasoning_tokens', 'llm_calls', 'rank_calls', 'rank_card_count',
                'model_state', 'model', 'provider', 'fallback', 'usage', 'run_id', 'context_type', 'context_id',
                'context_items',
            ]);
        });
    }
};
