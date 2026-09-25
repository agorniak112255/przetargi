<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Który dostawca OpenRoutera faktycznie odpowiedział i ile razy klient poluzował przypiętego dostawcę.
 * Pomiar przetargu 1 (14.09, raport 20260914_131814): jeden z trzech przebiegów tym samym kodem dał 5 złych kart
 * z oceną 95, pozostałe żadnej, a serwer w tym czasie pobierał opisy produktów — bez tej listy nie da się sprawdzić,
 * czy po limicie zapytań odpowiadał inny dostawca modelu.
 */
final class AiServedProviderTally
{
    /** @var array<string, int> */
    private array $served = [];

    private int $relaxedPins = 0;

    private int $profileFallbacks = 0;

    /** @var list<?string> */
    private array $lastBatch = [];

    /** @var list<?array{model: ?string, provider: ?string, profile: ?string, fallback: bool}> */
    private array $lastBatchOrigins = [];

    /** @var array{model: ?string, provider: ?string, profile: ?string, fallback: bool}|null */
    private ?array $lastJsonOrigin = null;

    /**
     * Tokeny i próby wszystkich wywołań modelu w tym żądaniu/procesie (AiUsage) — wyszukiwarka bierze z niego
     * różnicę wokół etapu (usageMark/usageSince), a nie z wyniku chat(), który zna tylko ostatnią odpowiedź.
     *
     * @var array{prompt_tokens: int, completion_tokens: int, reasoning_tokens: int, cached_tokens: int, cost: ?float, calls: int, failed_calls: int}|null
     */
    private ?array $usageTotal = null;

    /** @var list<?array{prompt_tokens: int, completion_tokens: int, reasoning_tokens: int, cached_tokens: int, cost: ?float, calls: int, failed_calls: int}> */
    private array $lastBatchUsage = [];

    public function reset(): void
    {
        $this->served = [];
        $this->relaxedPins = 0;
        $this->profileFallbacks = 0;
        $this->lastBatch = [];
        $this->lastBatchOrigins = [];
        $this->lastBatchUsage = [];
        $this->lastJsonOrigin = null;
        $this->usageTotal = null;
    }

    /**
     * Dostawca każdej odpowiedzi ostatniego chatJsonMany w kolejności zapytań; null = brak odpowiedzi albo API
     * bez pola „provider”. Wyszukiwanie przypisuje go pozycji, pomiar łączy złe karty z dostawcą modelu.
     * Obok pełne pochodzenie odpowiedzi (model, dostawca, profil, zejście na konfigurację główną) — magazyn zrozumień
     * zapisuje je przy wpisie i nie zapisuje odpowiedzi z zejścia.
     *
     * Do tego tokeny każdej pozycji paczki — suma wszystkich prób tego zapytania (ponowienia, zejście na konfigurację
     * główną); null = żadne wywołanie nie podało tokenów ani nie padło.
     *
     * @param  list<?string>  $providers
     * @param  list<?array{model: ?string, provider: ?string, profile: ?string, fallback: bool}>  $origins
     * @param  list<?array{prompt_tokens: int, completion_tokens: int, reasoning_tokens: int, cached_tokens: int, cost: ?float, calls: int, failed_calls: int}>  $usages
     */
    public function recordBatch(array $providers, array $origins = [], array $usages = []): void
    {
        $this->lastBatch = array_values($providers);
        $this->lastBatchOrigins = array_values($origins);
        $this->lastBatchUsage = array_values($usages);
    }

    public function forgetBatch(): void
    {
        $this->lastBatch = [];
        $this->lastBatchOrigins = [];
        $this->lastBatchUsage = [];
    }

    /** @return list<?string> */
    public function lastBatch(): array
    {
        return $this->lastBatch;
    }

    /** @return list<?array{model: ?string, provider: ?string, profile: ?string, fallback: bool}> */
    public function lastBatchOrigins(): array
    {
        return $this->lastBatchOrigins;
    }

    /**
     * Per indeks ostatniej paczki chatJsonMany: suma wszystkich prób tego indeksu (AiUsage) albo null.
     *
     * @return list<?array{prompt_tokens: int, completion_tokens: int, reasoning_tokens: int, cached_tokens: int, cost: ?float, calls: int, failed_calls: int}>
     */
    public function lastBatchUsage(): array
    {
        return $this->lastBatchUsage;
    }

    /** @param  array{prompt_tokens: int, completion_tokens: int, reasoning_tokens: int, cached_tokens: int, cost: ?float, calls: int, failed_calls: int}|null  $usage */
    public function addUsage(?array $usage): void
    {
        if ($usage === null) {
            return;
        }
        $this->usageTotal = AiUsage::add($this->usageTotal, $usage);
    }

    /** @return array{prompt_tokens: int, completion_tokens: int, reasoning_tokens: int, cached_tokens: int, cost: ?float, calls: int, failed_calls: int} */
    public function usageTotal(): array
    {
        return $this->usageTotal ?? AiUsage::empty();
    }

    /** @return array{prompt_tokens: int, completion_tokens: int, reasoning_tokens: int, cached_tokens: int, cost: ?float, calls: int, failed_calls: int} */
    public function usageMark(): array
    {
        return $this->usageTotal();
    }

    /**
     * @param  array{prompt_tokens: int, completion_tokens: int, reasoning_tokens: int, cached_tokens: int, cost: ?float, calls: int, failed_calls: int}  $mark  z usageMark()
     * @return array{prompt_tokens: int, completion_tokens: int, reasoning_tokens: int, cached_tokens: int, cost: ?float, calls: int, failed_calls: int}
     */
    public function usageSince(array $mark): array
    {
        return AiUsage::diff($this->usageTotal(), $mark);
    }

    /**
     * Pochodzenie odpowiedzi ostatniego chatJson; null = wywołanie padło albo nie szło przez prawdziwego klienta.
     *
     * @param  array{model: ?string, provider: ?string, profile: ?string, fallback: bool}  $origin
     */
    public function recordJsonOrigin(array $origin): void
    {
        $this->lastJsonOrigin = $origin;
    }

    public function forgetJsonOrigin(): void
    {
        $this->lastJsonOrigin = null;
    }

    /** @return array{model: ?string, provider: ?string, profile: ?string, fallback: bool}|null */
    public function lastJsonOrigin(): ?array
    {
        return $this->lastJsonOrigin;
    }

    /** Odpowiedź OpenRoutera niesie pole „provider”; inne API go nie mają i nic się nie liczy. */
    public function served(mixed $payload): void
    {
        $name = is_array($payload) && is_string($payload['provider'] ?? null) ? trim($payload['provider']) : '';
        if ($name === '') {
            return;
        }
        $this->served[$name] = ($this->served[$name] ?? 0) + 1;
    }

    public function relaxedPin(): void
    {
        $this->relaxedPins++;
    }

    /**
     * Zadanie obsłużone przez konfigurację główną, bo profil nie odpowiedział. Pomiar musi to widzieć:
     * 22.09.2026 padł jeden z dwóch węzłów vLLM, połowa rankingu w `search:eval` poszła do innego modelu
     * w chmurze, a raport deklarował przebieg na profilu — porównanie z poprzednim raportem mierzyło
     * podmianę modelu, nie zmianę w wyszukiwarce.
     */
    public function profileFallback(): void
    {
        $this->profileFallbacks++;
    }

    public function profileFallbacks(): int
    {
        return $this->profileFallbacks;
    }

    /**
     * @return array{served: array<string, int>, relaxed_pins: int, profile_fallbacks: int}
     */
    public function snapshot(): array
    {
        $served = $this->served;
        arsort($served);

        return [
            'served' => $served,
            'relaxed_pins' => $this->relaxedPins,
            'profile_fallbacks' => $this->profileFallbacks,
        ];
    }
}
