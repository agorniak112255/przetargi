<?php

declare(strict_types=1);

namespace App\Services\Bzp;

use Carbon\CarbonInterface;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Publiczne API Biuletynu Zamówień Publicznych (GET /mo-board/api/v1/notice, bez klucza).
 *
 * Parametry sprawdzone 03.10.2026: NoticeType, PublicationDateFrom i PublicationDateTo (RRRR-MM-DD) są wymagane;
 * CpvCode to dokładne dopasowanie kodu (bez hierarchii) do dowolnego kodu w ogłoszeniu; PageSize/PageNumber —
 * stronicowanie. Odpowiedź to lista ogłoszeń z pełnym HTML (~10–80 kB każde), więc strona ma 50 pozycji (serwer CLI
 * ma 128 MB), a ogłoszenia są oddawane po jednym (generator) — wywołujący zapisuje i zwalnia każde od razu.
 */
final class BzpClient
{
    /** bezpiecznik: tyle stron na jedno zapytanie (50 × 200 = 10 000 ogłoszeń) */
    private const MAX_PAGES = 200;

    /** liczba wykonanych zapytań HTTP (do podsumowania polecenia) */
    private int $requests = 0;

    /**
     * Ogłoszenia jednego rodzaju z jednym kodem CPV, opublikowane w podanym zakresie dat (włącznie).
     *
     * @return Generator<int, array<string, mixed>>
     *
     * @throws RuntimeException Biuletyn nie odpowiedział poprawnie po ponowieniach
     */
    public function notices(string $type, ?string $cpv, CarbonInterface|string $from, CarbonInterface|string $to): Generator
    {
        $pageSize = max(1, (int) config('bzp.page_size', 50));
        $query = [
            'NoticeType' => $type,
            'PublicationDateFrom' => $this->date($from),
            'PublicationDateTo' => $this->date($to),
            'PageSize' => $pageSize,
        ];
        if ($cpv !== null && $cpv !== '') {
            $query['CpvCode'] = $cpv;
        }

        $previousFirst = null;
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $items = $this->page($query + ['PageNumber' => $page]);
            if ($items === []) {
                return;
            }
            // API, które pomija numer strony, oddawałoby w kółko pierwszą stronę — przerwij zamiast pętli
            $first = is_array($items[0] ?? null) ? (string) ($items[0]['noticeNumber'] ?? '') : '';
            if ($page > 1 && $first !== '' && $first === $previousFirst) {
                return;
            }
            $previousFirst = $first;
            $count = count($items);

            foreach (array_keys($items) as $key) {
                $item = $items[$key];
                unset($items[$key]);
                if (is_array($item)) {
                    yield $item;
                }
                unset($item);
            }
            unset($items);

            if ($count < $pageSize) {
                return;
            }
        }
    }

    public function requests(): int
    {
        return $this->requests;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<mixed>
     */
    private function page(array $query): array
    {
        $pause = (int) config('bzp.pause_ms', 1000);
        if ($this->requests > 0 && $pause > 0) {
            Sleep::for($pause)->milliseconds();
        }
        $this->requests++;

        $throttleMs = max(0, (int) config('bzp.throttle_backoff_ms', 20000));
        try {
            $response = Http::acceptJson()
                ->withUserAgent((string) config('bzp.user_agent'))
                ->timeout((int) config('bzp.timeout', 30))
                ->retry(
                    max(0, (int) config('bzp.retries', 2)) + 1,
                    // po ograniczeniu liczby zapytań (403/429) czekamy dłużej i coraz dłużej; po innych błędach krótko
                    static fn (int $attempt, Throwable $e): int => self::throttled($e) ? $throttleMs * $attempt : max(0, $pause) * 2,
                    // ponawiamy zerwane połączenie, błąd serwera i chwilowe ograniczenie liczby zapytań;
                    // zły parametr (400, 404) nie naprawi się sam
                    static fn (Throwable $e): bool => $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError())
                        || self::throttled($e),
                )
                ->get((string) config('bzp.base_url'), $query);
        } catch (RequestException $e) {
            throw new RuntimeException('Biuletyn Zamówień Publicznych odpowiedział błędem HTTP '.$e->response->status().'.', 0, $e);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Brak połączenia z Biuletynem Zamówień Publicznych: '.$e->getMessage(), 0, $e);
        }

        $data = $response->json();
        unset($response);
        if (! is_array($data) || ! array_is_list($data)) {
            throw new RuntimeException('Biuletyn Zamówień Publicznych zwrócił odpowiedź w nieznanym formacie (oczekiwano listy ogłoszeń).');
        }

        return $data;
    }

    /**
     * Serwer Biuletynu przy serii zapytań odpowiada chwilowo 403 (sprawdzone 03.10.2026 na produkcji: 20 z 76
     * zapytań pierwszego przebiegu, to samo zapytanie chwilę później — 200), a ogólnie przyjętym sygnałem jest 429.
     */
    private static function throttled(Throwable $e): bool
    {
        return $e instanceof RequestException && in_array($e->response->status(), [403, 429], true);
    }

    private function date(CarbonInterface|string $value): string
    {
        return $value instanceof CarbonInterface ? $value->format('Y-m-d') : substr($value, 0, 10);
    }
}
