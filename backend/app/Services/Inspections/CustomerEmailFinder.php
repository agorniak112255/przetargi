<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Models\CustomerEmailLookup;
use App\Models\CustomerEmailSuggestion;
use App\Services\Campaigns\SmtpHostGuard;
use App\Services\Enrichment\BlockedPageReader;
use App\Services\Enrichment\DuckDuckGoHtmlSearch;
use App\Services\Enrichment\JinaSearchClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use Throwable;

/**
 * Adresy e-mail klienta XL ze stron WWW (prośba właściciela 06.10.2026) — propozycje do zatwierdzenia przez człowieka,
 * nigdy wprost do ofert. Reguły z próby na 15 prawdziwych klientach bez adresu (06.10.2026: 5 pewnych trafień z NIP-em
 * na stronie, 2 prawdopodobne, 1 błędne — klient bez NIP-u, zbieżność imienia i nazwiska):
 *
 * - szukanie: „nazwa bez formy prawnej + miejscowość” oraz sam NIP w cudzysłowie („NIP 123…” zwraca zagraniczne
 *   firmy o nazwie NIP); wyszukiwarka Jina (na niej sprawdzona jakość), gdy niedostępna — wyszukiwarka aplikacji;
 * - strony: najpierw strona firmy (domena podobna do nazwy klienta) i jej zakładka /kontakt, potem katalogi firm
 *   (Panorama Firm, Aleo, KRS…); portale społecznościowe, przetargowe i zagraniczne pomijane;
 * - adres z katalogu — tylko gdy na tej stronie jest NIP klienta (katalog to wiele firm); adresy samego katalogu
 *   i jego operatora (kontakt@wenet.pl na Panoramie Firm) odrzucane;
 * - adres ze strony firmy — gdy na stronie jest NIP klienta (dowód „nip”) albo domena adresu to domena strony
 *   (dowód „name” — do sprawdzenia: inna firma o podobnej nazwie albo osoba o tym samym nazwisku);
 * - adresy już na karcie XL, zatwierdzone albo odrzucone wcześniej — nie wracają jako nowe propozycje.
 *
 * Czytanie stron idzie przez wspólną kolejkę czytnika (BlockedPageReader, odstęp między stronami), więc jeden klient
 * to kilkanaście–kilkadziesiąt sekund.
 */
final class CustomerEmailFinder
{
    /** Katalogi firm: adres z nich tylko przy NIP-ie klienta na stronie. */
    public const DIRECTORY_HOSTS = [
        'panoramafirm.pl', 'aleo.com', 'krs-pobierz.pl', 'rejestr.io', 'gowork.pl', 'biznesfinder.pl', 'cylex-polska.pl',
        'krs-online.com.pl', 'firmy.net', 'pkt.pl', 'owg.pl', 'baza-firm.com.pl', 'okredo.com', 'targeo.pl', 'zumi.pl',
        'imsig.pl', 'bizraport.pl', 'infoveriti.pl', 'ekrs.pl', 'firmy.info.pl', 'oferteo.pl', 'cabb.pl', 'monitorfirm.pb.pl',
    ];

    /**
     * Katalogi, których czytnik nigdy nie oddaje (blokada) — nie zajmują miejsca w limicie czytanych stron
     * (KOMFORT-MARKET 06.10.2026: z dwóch czytanych katalogów jeden był gowork.pl).
     */
    private const UNREADABLE_HOSTS = ['gowork.pl', 'monitorfirm.pb.pl'];

    /** Najwyżej tyle katalogów (różnych serwisów) na klienta. */
    private const MAX_DIRECTORIES = 3;

    /** Strony, które nie są stroną firmy ani katalogiem z jej danymi (pomijane). */
    private const IGNORED_HOSTS = [
        'facebook.com', 'linkedin.com', 'instagram.com', 'youtube.com', 'twitter.com', 'x.com', 'tiktok.com',
        'wikipedia.org', 'booking.com', 'yelp.com', 'google.com', 'oneplace.marketplanet.pl', 'atlasprzetargow.pl',
        'dnb.com', 'rocketreach.co', 'kompass.com', 'wakacje.pl', 'ceidg.gov.pl', 'biznes.gov.pl', 'olx.pl', 'allegro.pl',
    ];

    /** Adresy operatorów katalogów (nie klienta). */
    private const NOISE_EMAILS = ['kontakt@wenet.pl'];

    /**
     * Skrzynki, które nie służą do ofert: inspektor ochrony danych i RODO, rekrutacja, newsletter, adresy techniczne
     * (pierwsze produkcyjne szukanie 06.10.2026: iod@ gminy jako propozycja).
     */
    private const ROLE_LOCAL = '/^(iod|rodo|dpo|abi|gdpr|privacy|ochrona\.?danych|ochronadanych|dane\.?osobowe|daneosobowe|rekrutacj[ae]|praca|kariera|cv|hr|newsletter|no-?reply|abuse|postmaster|webmaster|hostmaster)([._-]|\d|$)/u';

    /**
     * Forma prawna i słowa ogólne — poza nimi nazwa klienta do szukania i porównań. Działa na nazwie bez kropek
     * i myślników („SP. Z O.O.” → „SP Z O O”), więc skróty jako litery rozdzielone spacjami.
     */
    private const LEGAL_WORDS = '/(?<![\p{L}\d])(sp\s*z\s*o\s*o|z\s+o\s+o|sp|spółka|spolka|z\s+ograniczoną|ograniczoną|odpowiedzialnością|cywilna|akcyjna|komandytowa|jawna|s\s*a|sp\s*j|sp\s*k|przedsiębiorstwo|zakład|usługowo|usługowy|usługowe|handlowy|handlowe|handlowo|produkcyjno|wielobranżowe|firma|p\s*h\s*u|f\s*h\s*u|p\s*p\s*h\s*u|w|i|z|oddział|siedzibą)(?![\p{L}\d])/iu';

    private const MAX_PAGES = 7;

    /** @var list<array{host: string, url: string, read: bool, nip: bool, emails: int}> strony sprawdzone w ostatnim find() */
    private array $checked = [];

    private const MAX_SUGGESTIONS = 8;

    /** Ogólne skrzynki firmowe — pokazywane przed imiennymi. */
    private const GENERIC_LOCAL = '/^(biuro|sekretariat|kontakt|info|office|firma|handel|zamowienia|zamówienia|sklep|recepcja|poczta|mail|administracja|zarzad|zarząd|bhp)\b/u';

    /** Pobranie strony wprost: limit czasu (strony klienta pobierane naraz) i rozmiaru treści. */
    private const DIRECT_TIMEOUT = 8;

    private const DIRECT_MAX_BYTES = 2_000_000;

    /**
     * Czytnik Jiny tylko jako zapas dla stron, które odmówiły pobrania wprost (403, zapora) — najwyżej tyle na klienta.
     * Czytnik idzie wspólną kolejką z opisami produktów (odstęp między stronami), więc był głównym kosztem czasu:
     * pomiar 06.10.2026 — 3 strony czytnikiem 15–29 s, 6 stron wprost naraz 1–5 s.
     */
    private const READER_FALLBACK = 2;

    public function __construct(
        private readonly JinaSearchClient $jina,
        private readonly DuckDuckGoHtmlSearch $search,
        private readonly BlockedPageReader $reader,
        private readonly SmtpHostGuard $guard,
    ) {}

    /**
     * Szuka adresów klienta w sieci i zapisuje nowe propozycje (status pending).
     *
     * @param  object{xl_gid: int|string, name: ?string, acronym: ?string, nip: ?string, city: ?string, emails: mixed}  $customer  wiersz erp_customers
     * @return array{found: int, error: string|null, pages: list<array{host: string, url: string, read: bool, nip: bool, emails: int}>}
     */
    public function find(object $customer): array
    {
        $this->checked = [];
        $gid = (int) $customer->xl_gid;
        $nip = preg_replace('/\D+/', '', (string) ($customer->nip ?? '')) ?? '';
        $nip = strlen($nip) >= 10 ? substr($nip, -10) : '';
        $name = self::shortName((string) (($customer->name ?? '') !== '' ? $customer->name : ($customer->acronym ?? '')));
        $city = trim((string) preg_replace('/[\d\/]+/', ' ', (string) ($customer->city ?? '')));
        $error = null;
        $candidates = [];

        if ($name !== '') {
            try {
                $results = $this->searchAll(array_values(array_filter([
                    trim($name.' '.$city),
                    $nip !== '' ? '"'.$nip.'"' : null,
                ])));
                $candidates = $this->readCandidates($results, $name, $nip);
            } catch (Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 500);
            }
        }

        $known = $this->knownEmails($gid, $customer->emails ?? null);
        $now = CarbonImmutable::now();
        $saved = 0;
        foreach ($candidates as $email => $c) {
            if (isset($known[$email]) || $saved >= self::MAX_SUGGESTIONS) {
                continue;
            }
            // insertOrIgnore: dwa przebiegi naraz (ręczny i nocny, przycisk w oknie) mogą znaleźć ten sam adres —
            // drugi zapis pomijany zamiast przerwać przebieg błędem unikalności
            $saved += DB::table('customer_email_suggestions')->insertOrIgnore([
                'customer_xl_gid' => $gid,
                'email' => $email,
                'source' => $c['source'],
                'source_url' => mb_substr($c['url'], 0, 1000),
                'source_host' => mb_substr($c['host'], 0, 255),
                'evidence' => $c['evidence'],
                'status' => CustomerEmailSuggestion::STATUS_PENDING,
                'found_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        CustomerEmailLookup::query()->updateOrCreate(
            ['customer_xl_gid' => $gid],
            ['checked_at' => $now, 'found' => $saved, 'error' => $error],
        );

        return ['found' => $saved, 'error' => $error, 'pages' => $this->checked];
    }

    /** Nazwa do szukania: bez cudzysłowów, formy prawnej i słów ogólnych („PRZEDSIĘBIORSTWO WIELOBRANŻOWE „ZAWPOL” SP. Z O.O.” → „ZAWPOL”). */
    public static function shortName(string $name): string
    {
        $name = (string) preg_replace('/["„”“\'\-.,()]+/u', ' ', $name);
        $name = (string) preg_replace(self::LEGAL_WORDS, ' ', $name);

        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * Adresy z tekstu strony, małymi literami, bez obrazków (x@2x.png) i śmieci.
     *
     * @return list<string>
     */
    public static function emailsIn(string $text): array
    {
        preg_match_all('/[a-z0-9._%+-]+@[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,}/i', $text, $m);
        $out = [];
        foreach ($m[0] as $email) {
            $email = strtolower(trim($email, '.'));
            if (preg_match('/\.(png|jpe?g|gif|webp|svg|css|js)$/', $email) === 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $out[$email] = true;
        }

        return array_keys($out);
    }

    public static function hostIn(string $host, array $list): bool
    {
        $host = strtolower(preg_replace('/^www\./', '', $host) ?? $host);
        foreach ($list as $d) {
            if ($host === $d || str_ends_with($host, '.'.$d)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $queries
     * @return array<string, array{url: string, title: string, snippet: string}>
     */
    private function searchAll(array $queries): array
    {
        $results = [];
        foreach ($queries as $q) {
            foreach ($this->searchOne($q) as $r) {
                $results[$r['url']] ??= $r;
            }
        }

        return $results;
    }

    /** @return list<array{url: string, title: string, snippet: string}> */
    private function searchOne(string $query): array
    {
        if ($this->jina->isConfigured()) {
            try {
                return $this->jina->search($query, 8);
            } catch (Throwable) {
                // awaria Jiny — wyszukiwarka aplikacji (SearXNG i dalej)
            }
        }

        return $this->search->search($query, 8);
    }

    /**
     * Strony do przeczytania i adresy z dowodem.
     *
     * @param  array<string, array{url: string, title: string, snippet: string}>  $results
     * @return array<string, array{source: string, url: string, host: string, evidence: string, generic: bool}>
     */
    private function readCandidates(array $results, string $name, string $nip): array
    {
        $tokens = array_values(array_filter(
            preg_split('/\s+/u', self::fold($name)) ?: [],
            static fn (string $t): bool => mb_strlen($t) >= 4,
        ));
        $squash = (string) preg_replace('/[^a-z0-9]/', '', self::fold($name));
        $company = [];
        $directories = [];
        foreach (array_keys($results) as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === '' || self::hostIn($host, self::IGNORED_HOSTS)) {
                continue;
            }
            if (self::hostIn($host, self::DIRECTORY_HOSTS)) {
                // jedna strona na serwis, bez serwisów, których czytnik nie oddaje
                $site = (string) preg_replace('/^www\./', '', $host);
                if (! self::hostIn($host, self::UNREADABLE_HOSTS) && ! isset($directories[$site])) {
                    $directories[$site] = $url;
                }

                continue;
            }
            // strona firmy: pierwszy człon domeny zawarty w nazwie albo zawiera któreś słowo nazwy („go-kom” ↔ „GOKOM”)
            $bare = (string) preg_replace('/^www\./', '', $host);
            $stem = (string) preg_replace('/[^a-z0-9]/', '', explode('.', $bare)[0]);
            $similar = strlen($stem) >= 3 && ($squash !== '' && str_contains($squash, $stem)
                || array_filter($tokens, static fn (string $t): bool => str_contains($stem, (string) preg_replace('/[^a-z0-9]/', '', $t))) !== []);
            if ($similar && ! isset($company[$bare])) {
                $company[$bare] = $url;
            }
        }

        $pages = [];
        foreach (array_slice($company, 0, 2, true) as $bare => $url) {
            $pages[] = [$url, CustomerEmailSuggestion::SOURCE_WEBSITE];
            $contact = 'https://'.$bare.'/kontakt';
            if (rtrim($url, '/') !== $contact) {
                $pages[] = [$contact, CustomerEmailSuggestion::SOURCE_WEBSITE];
            }
        }
        foreach (array_slice(array_values($directories), 0, self::MAX_DIRECTORIES) as $url) {
            $pages[] = [$url, CustomerEmailSuggestion::SOURCE_DIRECTORY];
        }

        $out = [];
        $pages = array_slice($pages, 0, self::MAX_PAGES);
        $texts = $this->fetchPages(array_column($pages, 0));
        foreach ($pages as [$url, $source]) {
            $text = $texts[$url] ?? null;
            $host = strtolower((string) preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST)));
            if ($text === null || $text === '') {
                $this->checked[] = ['host' => $host, 'url' => $url, 'read' => false, 'nip' => false, 'emails' => 0];

                continue;
            }
            $nipOnPage = $nip !== '' && str_contains((string) preg_replace('/\D+/', '', $text), $nip);
            $before = count($out);
            foreach (self::emailsIn($text) as $email) {
                $domain = substr($email, (int) strpos($email, '@') + 1);
                if (in_array($email, self::NOISE_EMAILS, true) || self::hostIn($domain, self::DIRECTORY_HOSTS)
                    || preg_match(self::ROLE_LOCAL, substr($email, 0, (int) strpos($email, '@'))) === 1) {
                    continue;
                }
                if ($source === CustomerEmailSuggestion::SOURCE_DIRECTORY) {
                    // katalog to wiele firm — tylko z NIP-em klienta na tej stronie
                    if (! $nipOnPage) {
                        continue;
                    }
                    $evidence = CustomerEmailSuggestion::EVIDENCE_NIP;
                } elseif ($nipOnPage) {
                    $evidence = CustomerEmailSuggestion::EVIDENCE_NIP;
                } elseif ($domain === $host || str_ends_with($domain, '.'.$host) || str_ends_with($host, '.'.$domain)) {
                    $evidence = CustomerEmailSuggestion::EVIDENCE_NAME;
                } else {
                    continue;
                }
                $local = substr($email, 0, (int) strpos($email, '@'));
                $candidate = ['source' => $source, 'url' => $url, 'host' => $host, 'evidence' => $evidence, 'generic' => preg_match(self::GENERIC_LOCAL, $local) === 1];
                $previous = $out[$email] ?? null;
                // mocniejszy dowód wygrywa (NIP przed podobną nazwą)
                if ($previous === null || ($previous['evidence'] !== CustomerEmailSuggestion::EVIDENCE_NIP && $evidence === CustomerEmailSuggestion::EVIDENCE_NIP)) {
                    $out[$email] = $candidate;
                }
            }
            // do komunikatu „co sprawdzono” — także strony z NIP-em, ale bez adresu
            $this->checked[] = ['host' => $host, 'url' => $url, 'read' => true, 'nip' => $nipOnPage, 'emails' => count($out) - $before];
        }

        // NIP przed nazwą, ogólne skrzynki (biuro@, sekretariat@) przed imiennymi
        uksort($out, static function (string $a, string $b) use ($out): int {
            return [$out[$a]['evidence'] === CustomerEmailSuggestion::EVIDENCE_NIP ? 0 : 1, $out[$a]['generic'] ? 0 : 1, $a]
                <=> [$out[$b]['evidence'] === CustomerEmailSuggestion::EVIDENCE_NIP ? 0 : 1, $out[$b]['generic'] ? 0 : 1, $b];
        });

        return $out;
    }

    /**
     * Treść stron: wszystkie wprost i naraz (tylko serwery z adresem publicznym, także po przekierowaniu), a strony,
     * które odmówiły, czytnikiem Jiny — najwyżej READER_FALLBACK, w kolejności listy (strona firmy przed katalogami).
     *
     * @param  list<string>  $urls
     * @return array<string, string|null> adres → tekst (HTML po zdekodowaniu encji) albo null
     */
    private function fetchPages(array $urls): array
    {
        $urls = array_values(array_unique($urls));
        $allowed = array_values(array_filter($urls, function (string $url): bool {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

            return in_array($scheme, ['http', 'https'], true) && $this->guard->problem((string) parse_url($url, PHP_URL_HOST)) === null;
        }));
        $guard = $this->guard;
        $responses = $allowed === [] ? [] : Http::pool(fn (Pool $pool): array => array_map(
            static fn (string $url) => $pool->as($url)
                ->timeout(self::DIRECT_TIMEOUT)
                ->connectTimeout(4)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.5',
                ])
                ->withOptions(['allow_redirects' => [
                    'max' => 3,
                    // przekierowanie na serwer w sieci wewnętrznej — przerwane
                    'on_redirect' => static function ($request, $response, UriInterface $uri) use ($guard): void {
                        if ($guard->problem($uri->getHost()) !== null) {
                            throw new RuntimeException('Przekierowanie poza sieć publiczną.');
                        }
                    },
                ]])
                ->get($url),
            $allowed,
        ));

        $out = [];
        $failed = [];
        foreach ($urls as $url) {
            $r = $responses[$url] ?? null;
            $body = $r instanceof Response && $r->successful() ? substr($r->body(), 0, self::DIRECT_MAX_BYTES) : '';
            if ($body !== '' && mb_strlen(strip_tags($body)) >= 80) {
                $out[$url] = self::decodeHtml($body);
            } else {
                $out[$url] = null;
                // serwer z adresem wewnętrznym nie idzie też do czytnika — tylko strony, które odmówiły pobrania
                if (in_array($url, $allowed, true)) {
                    $failed[] = $url;
                }
            }
        }
        foreach (array_slice($failed, 0, self::READER_FALLBACK) as $url) {
            $out[$url] = $this->reader->fetchMarkdown($url);
        }

        return $out;
    }

    /** HTML → tekst do szukania adresów i NIP-u: encje (&#64;, &amp;), adresy Cloudflare (data-cfemail), mailto. */
    public static function decodeHtml(string $html): string
    {
        $html = (string) preg_replace_callback('/data-cfemail="([0-9a-f]+)"/i', static function (array $m): string {
            $hex = $m[1];
            $key = hexdec(substr($hex, 0, 2));
            $email = '';
            for ($i = 2; $i < strlen($hex); $i += 2) {
                $email .= chr(hexdec(substr($hex, $i, 2)) ^ $key);
            }

            return ' '.$email.' ';
        }, $html);
        $html = str_ireplace('mailto:', ' ', $html);
        // ucieczki ze skryptów i JSON-a na stronie („>” = „>”, „@” = „@”) — inaczej „u003ebiuro@…”
        $html = (string) preg_replace_callback('/\\\\u([0-9a-f]{4})/i', static fn (array $m): string => mb_chr((int) hexdec($m[1]), 'UTF-8') ?: ' ', $html);

        return html_entity_decode(rawurldecode((string) preg_replace('/%(?![0-9a-f]{2})/i', '%25', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Adresy, które nie mogą wrócić jako nowa propozycja: z karty XL i wszystkie dotychczasowe propozycje klienta
     * (oczekujące, zatwierdzone, odrzucone).
     *
     * @return array<string, true>
     */
    private function knownEmails(int $gid, mixed $cardEmails): array
    {
        $known = [];
        $list = is_string($cardEmails) ? json_decode($cardEmails, true) : $cardEmails;
        foreach (is_array($list) ? $list : [] as $e) {
            $known[strtolower(trim((string) $e))] = true;
        }
        foreach (DB::table('customer_email_suggestions')->where('customer_xl_gid', $gid)->pluck('email') as $e) {
            $known[strtolower((string) $e)] = true;
        }

        return $known;
    }

    private static function fold(string $s): string
    {
        return mb_strtolower(strtr($s, [
            'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n', 'Ó' => 'o', 'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z',
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]));
    }
}
