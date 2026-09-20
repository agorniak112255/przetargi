<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\RequirementUnderstanding;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Jedno zrozumienie na wymaganie. Model pytany o to samo wymaganie za każdym razem inaczej nazywał produkt
 * i warunki, a od nich zależy, które karty trafią do oceny — wynik dopasowania zmieniał się więc między
 * przebiegami, choć nic się nie zmieniło. Zapisujemy surową odpowiedź modelu: dalsze przetwarzanie (aliasy
 * katalogu, normalizacja) liczy się przy każdym odczycie i korzysta z późniejszych poprawek kodu.
 *
 * Magazyn nigdy nie może zatrzymać wyszukiwania: błąd bazy = brak zapisanego zrozumienia.
 */
final class RequirementUnderstandingStore
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(string $requirement, string $promptVersion): ?array
    {
        try {
            $row = RequirementUnderstanding::query()
                ->where('requirement_hash', $this->hash($requirement))
                ->where('prompt_version', $promptVersion)
                ->first();
        } catch (Throwable $e) {
            Log::info('Zapisane zrozumienie wymagania niedostępne', ['error' => $e->getMessage()]);

            return null;
        }
        $answer = $row?->answer;

        return is_array($answer) && $this->isUsable($answer) ? $answer : null;
    }

    /**
     * Zapis tylko prawdziwej odpowiedzi modelu — pusta odpowiedź albo awaria nie mogą zostać „zrozumieniem” na stałe.
     *
     * @param  array<string, mixed>  $answer
     */
    public function put(string $requirement, string $promptVersion, array $answer): void
    {
        if (! $this->isUsable($answer)) {
            return;
        }
        try {
            RequirementUnderstanding::query()->firstOrCreate(
                ['requirement_hash' => $this->hash($requirement), 'prompt_version' => $promptVersion],
                ['requirement' => mb_substr(trim($requirement), 0, 20000), 'answer' => $answer],
            );
        } catch (Throwable $e) {
            Log::info('Zrozumienie wymagania nie zostało zapisane', ['error' => $e->getMessage()]);
        }
    }

    /** @param  array<string, mixed>  $answer */
    private function isUsable(array $answer): bool
    {
        return is_string($answer['needed'] ?? null) && trim($answer['needed']) !== '';
    }

    /** Ta sama treść wpisana z inną wielkością liter albo innymi odstępami to to samo wymaganie. */
    private function hash(string $requirement): string
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $requirement)));

        return hash('sha256', $normalized);
    }
}
