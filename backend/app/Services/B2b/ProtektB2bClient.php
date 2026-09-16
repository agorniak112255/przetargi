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
 * protekt.pl (Pimcore). Witryna producenta, nie sklep B2B: nie ma API ani logowania, ceny są jawne
 * i te same dla wszystkich (sprawdzone 16.09.2026 — brak /api, Datahub GraphQL wyłączony, frontend
 * nie wysyła żadnego zapytania o dane). Cena konta = cena ze strony minus rabat z konfiguracji konta
 * (B2bDiscountRuleResolver), bo rabatów Protekt nigdzie nie publikuje.
 *
 * Lista kart: dziewięć map strony (po jednej na dział), odświeżanych codziennie. Mapy zawierają wpisy
 * martwe — adres z mapy potrafi zwrócić 404. Takie wpisy NIE liczą się do licznika awarii: gdyby
 * liczyły, dwadzieścia martwych wpisów pod rząd przerwałoby cały kilkugodzinny przebieg.
 */
final class ProtektB2bClient
{
    public const HOST = 'protekt.pl';

    public const BASE = 'https://protekt.pl';

    /** Działy z mapy strony (sitemap.xml wskazuje po jednej mapie na dział). */
    private const SITEMAP_SECTIONS = [
        'indywidualny-sprzet-ochrony-osobistej',
        'statywy-i-trojnogi-bezpieczenstwa',
        'stale-systemy-asekuracyjne',
        'lekkie-suwnice-bramowe-i-podnoszenie-ladunkow',
        'wyposazenie-dla-arborystow',
        'sprzet-strazacki-i-ratunkowy',
        'linki-narzedziowe-worki-torby-i-plecaki',
        'mobilne-stanowiska-pracy',
        'przemyslowe-helmy-ochronne',
    ];

    private const SITEMAP_URL = self::BASE.'/sitemap/pl/sitemap.products_%s_2.xml';

    /** Mapa kategorii: slug~cID — z niej bierzemy nazwę kategorii dla reguł rabatowych. */
    private const CATEGORIES_SITEMAP = self::BASE.'/sitemap/pl/sitemap.categories_2.xml';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503, gdy witryna nie podała Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    /** Górna granica Retry-After — dłuższa blokada = błąd zapytania, nie wielogodzinne czekanie. */
    private const MAX_RETRY_AFTER_MS = 900_000;

    private int $consecutiveFailures = 0;

    /** @var array<string, string> cID => slug kategorii */
    private array $categories = [];

    private Closure $sleep;

    public function __construct(
        private readonly int $delayMs = 150,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * Adresy kart ze wszystkich map, w kolejności z map, bez powtórzeń (ta sama karta bywa w dwóch działach).
     *
     * @return list<string>
     */
    public function sitemapProductUrls(): array
    {
        $urls = [];
        $seen = [];
        $failed = [];
        foreach (self::SITEMAP_SECTIONS as $section) {
            $xml = $this->sitemapBody(sprintf(self::SITEMAP_URL, $section), $failed);
            if ($xml === null) {
                continue;
            }
            foreach (self::locations($xml) as $url) {
                $path = (string) parse_url($url, PHP_URL_PATH);
                // Karta produktu to /slug~pID~cID — pozostałe wpisy (kategorie, strony) pomijamy.
                if (preg_match('#~p\d+~c\d+$#', $path) !== 1) {
                    continue;
                }
                if (isset($seen[$path])) {
                    continue;
                }
                $seen[$path] = true;
                $urls[] = self::BASE.$path;
            }
        }

        if ($urls === []) {
            throw new RuntimeException(
                'Nie udało się pobrać żadnej mapy strony '.self::HOST.
                ($failed !== [] ? ' ('.implode('; ', $failed).')' : '')
            );
        }

        return $urls;
    }

    /**
     * Nazwy kategorii po ich identyfikatorze z adresu karty (~cID). Bez tej mapy reguły rabatowe
     * po kategorii nie miałyby do czego się odnieść; gdy mapa nie wstanie, zostaje samo cID.
     *
     * @return array<string, string>
     */
    public function categorySlugs(): array
    {
        if ($this->categories !== []) {
            return $this->categories;
        }

        $failed = [];
        $xml = $this->sitemapBody(self::CATEGORIES_SITEMAP, $failed);
        if ($xml === null) {
            return $this->categories = [];
        }

        foreach (self::locations($xml) as $url) {
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match('#/([a-z0-9\-]+)~c(\d+)$#', $path, $m) === 1) {
                $this->categories[$m[2]] = $m[1];
            }
        }

        return $this->categories;
    }

    /**
     * Strona karty. gone = adres z mapy już nie istnieje (404/410) — wpis martwy, nie awaria witryny.
     *
     * @return array{status: 'ok'|'gone', html?: string, url: string}
     */
    public function productPage(string $url): array
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url), allowGone: true);

        if ($response === null) {
            return ['status' => 'gone', 'url' => $url];
        }

        return ['status' => 'ok', 'html' => $response->body(), 'url' => $url];
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    public function imageBytes(string $url): array
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));
        $mime = strtolower(trim(explode(';', (string) ($response?->header('Content-Type') ?? ''))[0]));

        return ['bytes' => (string) $response?->body(), 'mime' => $mime];
    }

    /**
     * @param  list<string>  $failed
     */
    private function sitemapBody(string $url, array &$failed): ?string
    {
        try {
            $xml = $this->send(static fn (PendingRequest $http): Response => $http->get($url))?->body();
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $failed[] = basename($url).': '.$e->getMessage();

            return null;
        }
        if ($xml === null || ! str_contains($xml, '<urlset')) {
            $failed[] = basename($url).': nieznany format mapy';

            return null;
        }

        return $xml;
    }

    /**
     * @return list<string>
     */
    private static function locations(string $xml): array
    {
        preg_match_all('#<loc>\s*([^<]+?)\s*</loc>#i', $xml, $m);

        return array_map(
            static fn (string $loc): string => html_entity_decode($loc, ENT_QUOTES | ENT_XML1, 'UTF-8'),
            $m[1],
        );
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
                // Martwy wpis w mapie nie świadczy o awarii witryny — licznik awarii zostaje nietknięty.
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
