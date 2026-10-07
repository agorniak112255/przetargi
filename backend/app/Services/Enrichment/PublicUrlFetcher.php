<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Services\Campaigns\SmtpHostGuard;
use Closure;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Pobieranie stron, zdjęć i PDF spod adresów z zewnątrz (wyniki wyszukiwarek, link wpisany przy karcie) bez SSRF:
 * tylko http(s) na portach 80/443 i serwery, których wszystkie adresy są publiczne (SmtpHostGuard::publicIps).
 * Połączenie przypięte do sprawdzonego adresu (CURLOPT_RESOLVE), więc drugie zapytanie DNS nie podmieni go na adres
 * wewnętrzny. Przekierowania prowadzone ręcznie, każdy krok sprawdzany i przypinany od nowa — allow_redirects Guzzle
 * poszedłby pod nowy serwer bez sprawdzenia. Twardy limit bajtów w trakcie pobierania. Wzorzec: CustomerEmailFinder
 * (94a066b); tu przekierowanie na inny serwer zostaje (sklep → www.sklep, CDN zdjęć), byle publiczny.
 */
final class PublicUrlFetcher
{
    /** Tyle, ile domyślne allow_redirects Guzzle — dotąd pobieranie szło za pięcioma przekierowaniami. */
    public const MAX_REDIRECTS = 5;

    /** Limit strony HTML — dotąd bez limitu; największe karty sklepów to 1–3 MB HTML. */
    public const PAGE_MAX_BYTES = 10_000_000;

    /** Komunikat jak Guzzle przy szóstym przekierowaniu (ProductImageDownloader bierze go za odmowę chwilową). */
    public const TOO_MANY_REDIRECTS = 'Will not follow more than 5 redirects';

    /** @param  SmtpHostGuard|null  $guard  null = z kontenera przy każdym sprawdzeniu (testy podmieniają DNS) */
    public function __construct(private readonly ?SmtpHostGuard $guard = null) {}

    /** Czy adres wolno pobrać — także cudzym czytnikiem (r.jina.ai), który dostaje adres w ścieżce. */
    public function allows(string $url): bool
    {
        return $this->pins($url) !== null;
    }

    /**
     * Wpisy CURLOPT_RESOLVE (nazwa:port:sprawdzony adres) albo null, gdy adresu nie wolno pobrać. Pusta lista:
     * publiczny adres IP wprost w adresie — nie ma czego rozwiązywać.
     *
     * @return list<string>|null
     */
    public function pins(string $url): ?array
    {
        $target = $this->resolve($url);

        return is_array($target) ? $target['pins'] : null;
    }

    /**
     * Adres do wysłania z wpisami CURLOPT_RESOLVE albo odmowa: BlockedUrlException (sieć wewnętrzna, inny port lub
     * schemat) albo ConnectionException, gdy DNS nic nie zwrócił — jak dotąd „Could not resolve host” z curla (chwilowa
     * awaria DNS sklepu to nie adres prywatny). Nazwa z polskimi znakami idzie do curla już jako sprawdzony punycode:
     * curl (libidn2) mógłby zamienić ją inaczej niż intl, ominąć przypięcie i sam zapytać DNS.
     *
     * @return array{url: string, pins: list<string>}|BlockedUrlException|ConnectionException
     */
    private function resolve(string $url): array|BlockedUrlException|ConnectionException
    {
        try {
            // ten sam parser co Guzzle przy wysyłce — nazwa sprawdzona tu to nazwa, pod którą pójdzie połączenie
            $uri = new Uri(trim($url));
        } catch (Throwable) {
            return new BlockedUrlException($url);
        }
        $scheme = strtolower($uri->getScheme());
        $host = strtolower($uri->getHost());
        $port = $uri->getPort();
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ($port !== null && ! in_array($port, [80, 443], true))) {
            return new BlockedUrlException($url);
        }
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return SmtpHostGuard::isPublicIp($literal) ? ['url' => trim($url), 'pins' => []] : new BlockedUrlException($url);
        }
        $ascii = $host;
        if (preg_match('/[^\x20-\x7e]/', $host) === 1) {
            $converted = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46) : false;
            if (! is_string($converted) || $converted === '') {
                return new BlockedUrlException($url);
            }
            $ascii = strtolower($converted);
            if (preg_match('/[^\x20-\x7e]/', $ascii) === 1) {
                return new BlockedUrlException($url);
            }
        }
        $ips = ($this->guard ?? app(SmtpHostGuard::class))->checkedIps($ascii);
        if ($ips === SmtpHostGuard::NOT_FOUND) {
            return new ConnectionException('Could not resolve host: '.$ascii);
        }
        if (! is_array($ips)) {
            return new BlockedUrlException($url);
        }
        $ip = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];
        $pins = [];
        // nazwa z kropką na końcu i bez niej, oba porty (http → https)
        foreach (array_unique([$ascii, rtrim($ascii, '.')]) as $name) {
            foreach ([80, 443] as $p) {
                $pins[] = $name.':'.$p.':'.$ip;
            }
        }

        return ['url' => $ascii === $host ? trim($url) : (string) $uri->withHost($ascii), 'pins' => $pins];
    }

    /**
     * GET z przekierowaniami prowadzonymi ręcznie.
     *
     * @param  Closure(): PendingRequest  $request  żądanie z nagłówkami i czasami — wołane od nowa dla każdego kroku
     *                                              (ciasteczka między krokami: jeden CookieJar w opcjach)
     *
     * @throws BlockedUrlException adres albo cel przekierowania poza siecią publiczną
     * @throws ConnectionException brak połączenia, nieznana nazwa, timeout, więcej niż 5 przekierowań
     * @throws RuntimeException odpowiedź większa niż $maxBytes
     */
    public function get(Closure $request, string $url, int $maxBytes): Response
    {
        for ($redirects = 0; ; $redirects++) {
            $target = $this->resolve($url);
            if (! is_array($target)) {
                throw $target;
            }
            $url = $target['url'];
            try {
                $response = $this->hop($request(), $target['pins'], $maxBytes)->get($url);
            } catch (ConnectionException $e) {
                throw $this->tooLarge($e, $maxBytes) ?? $e;
            }
            $next = $this->redirectTarget($response, $url);
            if ($next === null) {
                return $response;
            }
            if ($redirects >= self::MAX_REDIRECTS) {
                throw new ConnectionException(self::TOO_MANY_REDIRECTS);
            }
            $url = $next;
        }
    }

    /**
     * Wiele adresów naraz (Http::pool), przekierowania kolejnymi rundami puli. Wynik jak z Http::pool: odpowiedź albo
     * wyjątek — BlockedUrlException dla adresu poza siecią publiczną, ostatnia odpowiedź 3xx po pięciu przekierowaniach.
     *
     * @param  array<int|string, string>  $urls  klucz => adres
     * @param  Closure(PendingRequest, int|string): PendingRequest  $configure  nagłówki i czasy dla klucza (każdy krok)
     * @return array<int|string, Response|Throwable>
     */
    public function pool(array $urls, Closure $configure, int $maxBytes): array
    {
        $out = [];
        $current = $urls;
        for ($redirects = 0; $current !== []; $redirects++) {
            $pins = [];
            foreach ($current as $key => $url) {
                $target = $this->resolve($url);
                if (! is_array($target)) {
                    $out[$key] = $target;
                } else {
                    $current[$key] = $target['url'];
                    $pins[$key] = $target['pins'];
                }
            }
            $responses = $pins === [] ? [] : Http::pool(function (Pool $pool) use ($pins, $current, $configure, $maxBytes): void {
                foreach ($pins as $key => $pin) {
                    $this->hop($configure($pool->as((string) $key), $key), $pin, $maxBytes)->get($current[$key]);
                }
            });
            $next = [];
            foreach (array_keys($pins) as $key) {
                $response = $responses[$key] ?? new ConnectionException('Brak odpowiedzi w puli.');
                $target = $this->redirectTarget($response, $current[$key]);
                if ($target !== null && $redirects < self::MAX_REDIRECTS) {
                    $next[$key] = $target;

                    continue;
                }
                $out[$key] = $response instanceof ConnectionException ? ($this->tooLarge($response, $maxBytes) ?? $response) : $response;
            }
            $current = $next;
        }

        return array_replace(array_intersect_key($urls, $out), $out);
    }

    private function hop(PendingRequest $request, array $pins, int $maxBytes): PendingRequest
    {
        $curl = [
            // twardy limit rozmiaru: deklarowany (Content-Length) i faktycznie pobrany
            CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static fn ($ch, int $dlTotal, int $dlNow): int => $dlNow > $maxBytes ? 1 : 0,
        ];
        if ($pins !== []) {
            $curl[CURLOPT_RESOLVE] = $pins;
        }

        return $request->withOptions(['allow_redirects' => false, 'curl' => $curl]);
    }

    /** Adres następnego kroku albo null, gdy to nie przekierowanie (tak jak Guzzle: 3xx z nagłówkiem Location). */
    private function redirectTarget(mixed $response, string $url): ?string
    {
        if (! $response instanceof Response || $response->status() < 300 || $response->status() > 399) {
            return null;
        }
        $location = trim($response->header('Location'));
        if ($location === '') {
            return null;
        }
        try {
            return (string) UriResolver::resolve(new Uri($url), new Uri($location));
        } catch (Throwable) {
            return null;
        }
    }

    /** Przerwane przez limit (curl 63: Content-Length ponad limit, 42: przerwane w trakcie) — odmowa stała. */
    private function tooLarge(ConnectionException $e, int $maxBytes): ?RuntimeException
    {
        return preg_match('/cURL error (?:42|63):/', $e->getMessage()) === 1
            ? new RuntimeException('Plik większy niż '.round($maxBytes / 1_000_000, 1).' MB — przerwane.', 0, $e)
            : null;
    }
}
