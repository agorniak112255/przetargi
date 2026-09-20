<?php

declare(strict_types=1);

namespace App\Services\B2b;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * atg-glovesolutions.com — publiczna witryna producenta rękawic ATG (Drupal): bez logowania i bez klucza API
 * (sprawdzone 20.09.2026). Cen witryna nie podaje wcale, więc łącznik jest źródłem samej treści karty
 * (AtgB2bConnector, B2bContentOnlySite): opisu, tabelki parametrów, norm, zdjęć i plików PDF.
 *
 * Klient robi tylko zapytania — odczytem odpowiedzi zajmuje się łącznik. Ochrona przed przeciążeniem witryny
 * i przed zapętleniem przebiegu jak u ARTRY i Protekta: przerwa między zapytaniami, odczekanie po 429/503
 * i bezpiecznik po serii błędów. Karta, która zniknęła między mapą strony a pobraniem (404/410), nie liczy się
 * jako awaria witryny — to zwykły martwy wpis w mapie.
 *
 * Pliki do pobrania leżą pod /download/{uuid} i przekierowują na wersję językową tego samego adresu, dlatego
 * zapytania muszą podążać za przekierowaniem (allow_redirects niżej).
 */
final class AtgB2bClient
{
    public const HOST = 'atg-glovesolutions.com';

    /** Polska wersja witryny — każda karta istnieje w 23 językach pod tym samym numerem artykułu. */
    public const LANGUAGE = 'pl';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503, gdy witryna nie podała Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    /** Górna granica Retry-After — dłuższa blokada = błąd zapytania, nie wielogodzinne czekanie. */
    private const MAX_RETRY_AFTER_MS = 900_000;

    private int $consecutiveFailures = 0;

    private Closure $sleep;

    public function __construct(
        private readonly int $delayMs = 150,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    public static function baseUrl(): string
    {
        return 'https://www.'.self::HOST;
    }

    /** Treść strony albo mapy; wyjątek, gdy witryna jej nie wydała. */
    public function body(string $url): string
    {
        return (string) $this->send(static fn (PendingRequest $http): Response => $http->get($url))?->body();
    }

    /** Jak body(), ale 404/410 daje null zamiast wyjątku (karta zdjęta po wygenerowaniu mapy). */
    public function bodyOrNull(string $url): ?string
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url), allowGone: true);

        return $response?->body();
    }

    /**
     * Zawartość pliku z witryny — zdjęcie wyrobu albo PDF z załączników karty.
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url): array
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));
        $mime = strtolower(trim(explode(';', (string) ($response?->header('Content-Type') ?? ''))[0]));

        return ['bytes' => (string) $response?->body(), 'mime' => $mime];
    }

    /**
     * Nazwa pliku, którą witryna podaje w nagłówku pobrania — adres to sam identyfikator (/download/{uuid}),
     * więc nazwa jest jedynym miejscem, w którym widać, co to za dokument („product_data_sheet_34-8753_pl.pdf”).
     * Pytamy o same nagłówki (HEAD), bez treści; null = witryna nazwy nie podała.
     */
    public function fileName(string $url): ?string
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->head($url));
        $header = (string) ($response?->header('Content-Disposition') ?? '');
        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $header, $m) !== 1) {
            return null;
        }

        return trim(rawurldecode($m[1])) ?: null;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @param  bool  $allowGone  404/410 → null zamiast wyjątku i bez licznika awarii (martwy wpis w mapie)
     */
    private function send(callable $call, bool $allowGone = false): ?Response
    {
        $retries = 0;
        while (true) {
            if ($this->delayMs > 0) {
                ($this->sleep)($this->delayMs);
            }

            $response = null;
            $error = null;
            try {
                $response = $call(Http::timeout(30)->withOptions([
                    'allow_redirects' => ['max' => 5, 'track_redirects' => true],
                ]));
            } catch (ConnectionException $e) {
                $error = 'brak połączenia ('.$e->getMessage().')';
            }

            if ($response !== null && $response->successful()) {
                $this->consecutiveFailures = 0;

                return $response;
            }

            if ($allowGone && $response !== null && in_array($response->status(), [404, 410], true)) {
                return null;
            }

            if ($response !== null && in_array($response->status(), [429, 503], true) && $retries < count(self::BACKOFF_MS)) {
                ($this->sleep)(self::retryAfterMs($response) ?? self::BACKOFF_MS[$retries]);
                $retries++;

                continue;
            }

            $error ??= self::HOST.' odpowiedziało HTTP '.$response?->status();
            $this->consecutiveFailures++;
            if ($this->consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                throw new B2bFatalException(
                    self::MAX_CONSECUTIVE_FAILURES.' kolejnych błędów zapytań do '.self::HOST.' (ostatni: '.$error.') — pobieranie przerwane'
                );
            }

            throw new RuntimeException($error);
        }
    }

    private static function retryAfterMs(Response $response): ?int
    {
        $value = trim((string) $response->header('Retry-After'));
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            $ms = (int) $value * 1000;
        } else {
            $at = strtotime($value);
            if ($at === false) {
                return null;
            }
            $ms = max(0, ($at - time()) * 1000);
        }

        return min($ms, self::MAX_RETRY_AFTER_MS);
    }
}
