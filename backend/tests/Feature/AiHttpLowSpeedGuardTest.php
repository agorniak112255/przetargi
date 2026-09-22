<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Http\Client\PendingRequest;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Strażnik ciszy na połączeniu z modelem. Zapytania idą bez strumienia, więc przez cały czas
 * generowania nie płynie ani jeden bajt — granica krótsza niż limit zapytania zrywa poprawną,
 * trwającą odpowiedź. 22.09.2026 sztywne 60 s ucinało ranking wyszukiwarki na gęstym modelu
 * (1200–1500 tokenów po ~23 tok/s, czyli 55–65 s), a wyszukiwanie po cichu schodziło
 * na konfigurację główną w chmurze.
 */
final class AiHttpLowSpeedGuardTest extends TestCase
{
    /**
     * @return array{limit: int, time: int}
     */
    private function guardFor(int $timeoutSeconds): array
    {
        $method = new ReflectionMethod(OpenAiCompatibleClient::class, 'aiHttp');
        /** @var PendingRequest $request */
        $request = $method->invoke($this->app->make(OpenAiCompatibleClient::class), 'sk-test', $timeoutSeconds);
        $curl = $request->getOptions()['curl'] ?? [];

        return [
            'limit' => (int) ($curl[CURLOPT_LOW_SPEED_LIMIT] ?? 0),
            'time' => (int) ($curl[CURLOPT_LOW_SPEED_TIME] ?? 0),
        ];
    }

    public function test_silence_limit_matches_the_request_timeout(): void
    {
        $this->assertSame(['limit' => 1, 'time' => 240], $this->guardFor(240));
        $this->assertSame(['limit' => 1, 'time' => 600], $this->guardFor(600));
    }

    public function test_generation_longer_than_a_minute_is_not_cut_short(): void
    {
        // gęsty model: 1500 tokenów po 23 tok/s to ponad minuta ciszy, a zapytanie jest poprawne
        $this->assertGreaterThan(65, $this->guardFor(240)['time']);
    }
}
