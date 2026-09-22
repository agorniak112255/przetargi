<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Ai\AiServedProviderTally;
use Tests\TestCase;

/**
 * Pomiar musi wiedzieć, czy naprawdę szedł na modelu, którym się deklaruje. 22.09.2026 padł jeden
 * z dwóch węzłów vLLM, aplikacja po cichu zeszła na konfigurację główną w chmurze, a `search:eval`
 * wypisał spadek nDCG o 0,05 jako skutek zmiany w wyszukiwarce — choć połowę rankingu zrobił
 * zupełnie inny model.
 */
final class SearchEvalProfileFallbackTest extends TestCase
{
    public function test_tally_counts_fallbacks_and_reset_clears_them(): void
    {
        $tally = $this->app->make(AiServedProviderTally::class);
        $tally->reset();

        $this->assertSame(0, $tally->profileFallbacks());

        $tally->profileFallback();
        $tally->profileFallback();

        $this->assertSame(2, $tally->profileFallbacks());
        $this->assertSame(2, $tally->snapshot()['profile_fallbacks']);

        // każdy przebieg pomiaru liczy od zera, inaczej zliczałby zejścia z poprzedniego
        $tally->reset();
        $this->assertSame(0, $tally->profileFallbacks());
        $this->assertSame(0, $tally->snapshot()['profile_fallbacks']);
    }
}
