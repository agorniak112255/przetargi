<?php

declare(strict_types=1);

namespace App\Services\B2b;

use Closure;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sklep signproject.pl (IdoSell). Nieoficjalne: adresy i pola odczytane ze strony www (14.09.2026).
 * Sesja konta żyje w ciasteczkach; po jej utracie sklep dalej odpowiada 200, ale z cenami anonimowymi —
 * dlatego logowanie sprawdza napis „Wyloguj”, a łącznik porównuje cenę wersji kontrolnej.
 *
 * Zapytania idą po kolei przez jeden stały handler Guzzle (połączenie keep-alive używane ponownie, bez nowego TLS
 * przy każdym zapytaniu). Bez równoległości: sklep szereguje zapytania jednej sesji (pomiar 14.09.2026 — w paczce
 * 6 równoległych zapytań z ciasteczkiem sesji czas do pierwszego bajtu rósł 42 → 75 → 91 → 196 → 397 → 797 ms,
 * te same 6 po kolei trwały razem 285 ms), więc równoległe zapytania byłyby wolniejsze i blokowałyby sklep.
 */
final class SignProjectB2bClient
{
    public const HOST = 'signproject.pl';

    public const BASE = 'https://signproject.pl';

    /** Ile zapytań o ceny wersji (po kolei) między przerwami delayMs w projectorMany(). */
    public const BATCH_SIZE = 6;

    private const LOGIN_PAGE = self::BASE.'/pl/login.html';

    private const SIGNIN = self::BASE.'/signin.php';

    private const SITEMAP_INDEX = self::BASE.'/sitemap.xml.gz';

    private const PROJECTOR = self::BASE.'/ajax/projector.php';

    private const LOGGED_IN_MARKER = 'Wyloguj';

    private const TIMEOUT_SECONDS = 30;

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503, gdy sklep nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    /** Górna granica Retry-After — dłuższa blokada = błąd zapytania, nie wielogodzinne czekanie. */
    private const MAX_RETRY_AFTER_MS = 900_000;

    private CookieJar $jar;

    private bool $loggedIn = false;

    /** false, gdy strona produktu po świeżym logowaniu nie ma „Wyloguj” — wtedy jej nie sprawdzamy. */
    private bool $pageMarkerReliable = true;

    private int $consecutiveFailures = 0;

    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * Stały handler Guzzle (cURL) na cały przebieg — zachowuje otwarte połączenie między zapytaniami
     * (pomiar 14.09.2026: ~0,05 s na zapytanie zamiast ~0,3 s z nowym połączeniem). Podawany przez
     * PendingRequest::setHandler, więc Http::fake w testach dalej przechwytuje zapytania (w przeciwieństwie do setClient).
     *
     * @var callable
     */
    private $handler;

    /**
     * @param  int  $delayMs  przerwa przed każdym pojedynczym zapytaniem i przed każdą serią BATCH_SIZE zapytań o ceny
     * @param  (Closure(int): void)|null  $sleep  pauza w ms (w testach bez czekania)
     */
    public function __construct(
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        private readonly int $delayMs = 150,
        ?Closure $sleep = null,
    ) {
        $this->jar = new CookieJar;
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
        $this->handler = Utils::chooseHandler();
    }

    public function login(): void
    {
        // nowa sesja — stare ciasteczka mogły należeć do wygasłej
        $this->jar = new CookieJar;
        $this->loggedIn = false;

        try {
            $this->send(static fn (PendingRequest $http): Response => $http->get(self::LOGIN_PAGE));
            $response = $this->send(fn (PendingRequest $http): Response => $http->asForm()->post(self::SIGNIN, [
                'operation' => 'login',
                'login' => $this->username,
                'password' => $this->password,
            ]));
            $confirmed = self::hasLoggedInMarker($response->body());
            if (! $confirmed) {
                $confirmed = self::hasLoggedInMarker(
                    $this->send(static fn (PendingRequest $http): Response => $http->get(self::BASE.'/'))->body()
                );
            }
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if (! $confirmed) {
            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane: sklep nie potwierdził zalogowania (brak „Wyloguj” na stronie) — sprawdź login i hasło'
            );
        }

        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /**
     * ID produktów z polskiej części mapy strony (/pl/products/{slug}-{id}), w kolejności z mapy.
     * complete = false, gdy nie pobrała się lub nie dała się odczytać któraś podmapa.
     *
     * @return array{ids: list<int>, urls: array<int, string>, complete: bool}
     */
    public function sitemapProductIds(): array
    {
        try {
            $index = self::decodeSitemap($this->send(static fn (PendingRequest $http): Response => $http->get(self::SITEMAP_INDEX))->body());
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Nie udało się pobrać mapy strony '.self::HOST.': '.$e->getMessage(), 0, $e);
        }

        $urls = [];
        $complete = true;
        if (str_contains($index, '<sitemapindex')) {
            $children = self::locations($index);
            if ($children === []) {
                $complete = false;
            }
            foreach ($children as $child) {
                try {
                    $xml = self::decodeSitemap($this->send(static fn (PendingRequest $http): Response => $http->get($child))->body());
                } catch (B2bFatalException $e) {
                    throw $e;
                } catch (RuntimeException) {
                    $complete = false;

                    continue;
                }
                if (! str_contains($xml, '<urlset')) {
                    $complete = false;

                    continue;
                }
                array_push($urls, ...self::locations($xml));
            }
        } elseif (str_contains($index, '<urlset')) {
            $urls = self::locations($index);
        } else {
            throw new RuntimeException('Mapa strony '.self::HOST.' ma nieznany format');
        }

        $byId = [];
        foreach ($urls as $url) {
            if (preg_match('#^https?://(?:www\.)?signproject\.pl/pl/products/[^/?\#]*-(\d+)/?$#i', $url, $m) === 1) {
                $id = (int) $m[1];
                if ($id > 0 && ! isset($byId[$id])) {
                    $byId[$id] = $url;
                }
            }
        }

        return ['ids' => array_keys($byId), 'urls' => $byId, 'complete' => $complete && $byId !== []];
    }

    /**
     * Dane karty produktu z ajax/projector.php. anonymous = osobne, puste ciasteczka (cena bez konta).
     *
     * @return array<string, mixed>
     */
    public function projector(int $id, string $get, bool $anonymous = false): array
    {
        $response = $this->send(
            static fn (PendingRequest $http): Response => $http->acceptJson()->get(self::PROJECTOR, ['product' => $id, 'get' => $get]),
            $anonymous ? new CookieJar : null,
        );

        return self::projectorJson($id, $response);
    }

    /**
     * Dane wielu produktów z ajax/projector.php z ciasteczkami konta: seriami po BATCH_SIZE zapytań po kolei,
     * z przerwą delayMs przed każdą serią (nie przed każdym zapytaniem). Każde zapytanie jak w projector():
     * 429/503 z Retry-After/backoffem, licznik kolejnych błędów (20 z rzędu = B2bFatalException).
     * Błąd jednego ID nie przerywa pozostałych — wynik to JSON albo RuntimeException z opisem.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>|RuntimeException> w kolejności $ids (bez powtórzeń)
     */
    public function projectorMany(array $ids, string $get): array
    {
        $results = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::BATCH_SIZE) as $batch) {
            if ($this->delayMs > 0) {
                ($this->sleep)($this->delayMs);
            }
            foreach ($batch as $id) {
                try {
                    $response = $this->send(
                        static fn (PendingRequest $http): Response => $http->acceptJson()->get(self::PROJECTOR, ['product' => $id, 'get' => $get]),
                        pause: false,
                    );
                    $results[$id] = self::projectorJson($id, $response);
                } catch (B2bFatalException $e) {
                    throw $e;
                } catch (RuntimeException $e) {
                    $results[$id] = $e;
                }
            }
        }

        return $results;
    }

    /**
     * HTML strony produktu. Brak „Wyloguj” przy zalogowanym kliencie = sesja mogła wygasnąć → jedno ponowne logowanie.
     */
    public function productPage(string $url): string
    {
        $html = $this->send(static fn (PendingRequest $http): Response => $http->get($url))->body();
        if ($this->loggedIn && $this->pageMarkerReliable && ! self::hasLoggedInMarker($html)) {
            $this->login();
            $html = $this->send(static fn (PendingRequest $http): Response => $http->get($url))->body();
            if (! self::hasLoggedInMarker($html)) {
                // logowanie potwierdzone, a strona nadal bez napisu — ten szablon go nie ma, nie logujemy się w kółko
                $this->pageMarkerReliable = false;
            }
        }

        return $html;
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    public function imageBytes(string $url): array
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return ['bytes' => $response->body(), 'mime' => $mime];
    }

    public static function hasLoggedInMarker(string $html): bool
    {
        return mb_stripos($html, self::LOGGED_IN_MARKER) !== false;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @param  bool  $pause  przerwa delayMs przed zapytaniem (false = przerwę robi seria w projectorMany)
     */
    private function send(callable $call, ?CookieJar $jar = null, bool $pause = true): Response
    {
        $retries = 0;
        while (true) {
            if ($pause && $this->delayMs > 0) {
                ($this->sleep)($this->delayMs);
            }

            $response = null;
            $error = null;
            try {
                $response = $call(Http::timeout(self::TIMEOUT_SECONDS)
                    ->withOptions(['cookies' => $jar ?? $this->jar])
                    ->setHandler($this->handler));
            } catch (ConnectionException $e) {
                $error = 'brak połączenia ('.$e->getMessage().')';
            }

            if ($response !== null && $response->successful()) {
                $this->consecutiveFailures = 0;

                return $response;
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

    /**
     * @return array<string, mixed>
     */
    private static function projectorJson(int $id, Response $response): array
    {
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('odpowiedź '.self::HOST.' dla produktu '.$id.' nie jest poprawnym JSON');
        }

        return $json;
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

    /** Pliki .xml.gz bywają podawane już rozpakowane. */
    private static function decodeSitemap(string $bytes): string
    {
        if (str_starts_with($bytes, "\x1f\x8b")) {
            $decoded = @gzdecode($bytes);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $bytes;
    }

    /**
     * @return list<string>
     */
    private static function locations(string $xml): array
    {
        preg_match_all('#<loc>\s*([^<]+?)\s*</loc>#i', $xml, $m);

        return array_map(static fn (string $loc): string => html_entity_decode($loc, ENT_QUOTES | ENT_XML1, 'UTF-8'), $m[1]);
    }
}
