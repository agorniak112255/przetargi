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

    /** @var list<?string> */
    private array $lastBatch = [];

    public function reset(): void
    {
        $this->served = [];
        $this->relaxedPins = 0;
        $this->lastBatch = [];
    }

    /**
     * Dostawca każdej odpowiedzi ostatniego chatJsonMany w kolejności zapytań; null = brak odpowiedzi albo API
     * bez pola „provider”. Wyszukiwanie przypisuje go pozycji, pomiar łączy złe karty z dostawcą modelu.
     *
     * @param  list<?string>  $providers
     */
    public function recordBatch(array $providers): void
    {
        $this->lastBatch = array_values($providers);
    }

    public function forgetBatch(): void
    {
        $this->lastBatch = [];
    }

    /** @return list<?string> */
    public function lastBatch(): array
    {
        return $this->lastBatch;
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
     * @return array{served: array<string, int>, relaxed_pins: int}
     */
    public function snapshot(): array
    {
        $served = $this->served;
        arsort($served);

        return ['served' => $served, 'relaxed_pins' => $this->relaxedPins];
    }
}
