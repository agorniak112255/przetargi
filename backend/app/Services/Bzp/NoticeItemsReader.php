<?php

declare(strict_types=1);

namespace App\Services\Bzp;

use App\Models\ProcurementNotice;
use App\Services\Ai\AiTask;
use App\Services\Ai\JsonResponseParser;
use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Pozycje towarów z TREŚCI ogłoszenia o zamówieniu (opisy części, sekcja „Przedmiot zamówienia”) — dla postępowań,
 * w których zamawiający wymienia towary i ilości w samym ogłoszeniu („- hełm strażacki – 23 szt.”, „dostawa …
 * ubrań specjalnych … w ilości 190 kpl.”). Model wypisuje towary z cytatem; aplikacja sprawdza cytat w tekście:
 *  - quote_found — cytat jest w tekście ogłoszenia (bez cytatu w tekście pozycja jest tylko propozycją modelu),
 *  - quantity, unit — tylko gdy liczba / jednostka stoi w znalezionym cytacie; inaczej null (nie zgadujemy),
 *  - spec — cechy towaru (normy, klasy, materiał, rozmiary…): model podaje fragmenty przepisane z ogłoszenia, aplikacja
 *    szuka każdego w OKNIE pozycji (od cytatu tej pozycji do najbliższego cytatu albo nazwy innej pozycji, nagłówka
 *    następnej części) i zapisuje WYCINEK Z TEKSTU OGŁOSZENIA, nigdy sformułowanie modelu; fragment nieznaleziony
 *    w oknie odpada, bez znalezionego cytatu spec = null; przeczenie tuż przed wycinkiem („nie zawierające lateksu”)
 *    wchodzi do wycinka,
 *  - bhp — ocena modelu, czy to odzież robocza / środek ochrony indywidualnej (wnioskowanie, nie fakt z ogłoszenia).
 * Nic nie trafia do przetargu — wynik idzie do podglądu kreatora i podsumowania asortymentu w szczegółach ogłoszenia.
 * readCached() zapamiętuje wynik po treści (ten sam tekst = ten sam wynik) i pilnuje, żeby model nie czytał tego samego
 * dwa razy naraz (blokada na klucz) ani więcej niż SLOTS ogłoszeń naraz (model na Sparkach — kilka zapytań naraz).
 * Weryfikacja liczy na bajtach tekstu znormalizowanego (strpos, bez tablic znaków) — CLI na produkcji ma 128 MB.
 */
final class NoticeItemsReader
{
    /** Kod wyjątku „ogłoszenie bez opisu przedmiotu” — stan trwały (ponowienie nic nie da), nie awaria. */
    public const NO_DESCRIPTION = 1001;

    private const MAX_TEXT = 60000;

    private const MAX_ITEMS = 300;

    /** Zmiana polecenia albo weryfikacji = nowa wersja (stare wyniki w pamięci podręcznej przestają obowiązywać). */
    private const PROMPT_VERSION = 'p2';

    private const CACHE_DAYS = 14;

    /** Pusta lista może wynikać z chwilowej słabości modelu — pamiętamy ją krócej. */
    private const CACHE_EMPTY_DAYS = 1;

    /** Najwyżej tyle odczytów modelem naraz (panel ogłoszenia i „Załóż przetarg” u wszystkich użytkowników). */
    private const SLOTS = 2;

    private const SLOT_KEY = 'notice-items-slot:';

    /**
     * Czas życia blokady na klucz i miejsca na odczyt. Musi przekraczać najdłuższy odczyt: zapytanie do modelu ma limit
     * profilu (do 240 s) plus ponowienia klienta przy przeciążeniu, a set_time_limit(180) w kontrolerach liczy tylko
     * czas pracy PHP, nie czekanie na odpowiedź. Zwykłe zakończenie i wyjątek zwalniają blokady od razu (finally), przekroczenie
     * czasu PHP — funkcja zamykająca (releaseOnShutdown). Tylko twarde zabicie procesu zostawia blokadę do wygaśnięcia:
     * ten sam odczyt dostaje wtedy 503 po LOCK_WAIT_SECONDS, a miejsce na odczyt wraca po LOCK_SECONDS.
     */
    private const LOCK_SECONDS = 420;

    /** Tyle czeka drugie żądanie na ten sam odczyt (panel otwarty, a ktoś klika „Załóż przetarg”). */
    private const LOCK_WAIT_SECONDS = 60;

    private const MAX_SPEC = 1000;

    private const MAX_FRAGMENTS = 30;

    /** Okno ostatniej pozycji części: najwyżej tyle bajtów (ok. 2000 znaków) za cytatem. */
    private const TAIL_WINDOW = 3000;

    /** Luźne dopasowanie fragmentu: najwięcej znaków między kolejnymi słowami fragmentu w tekście. */
    private const MAX_GAP = 40;

    /** Najwięcej wystąpień cytatu / nazwy branych pod uwagę (ten sam cytat w kilku częściach). */
    private const MAX_OCCURRENCES = 50;

    /** Przeczenia, które zmieniają sens cechy, gdy stoją tuż przed nią. */
    private const NEGATIONS = ['nie', 'bez', 'brak'];

    public function __construct(
        private readonly OpenAiCompatibleClient $llm,
        private readonly JsonResponseParser $jsonParser,
        private readonly NoticeSections $sections,
        private readonly NoticeBhpLots $bhpLots,
    ) {}

    /**
     * Tekst do odczytu: sekcja „Przedmiot zamówienia” z pełnej treści ogłoszenia; gdy treść już skasowana (system:prune)
     * albo bez tej sekcji — opisy części z odczytu ogłoszenia (opis części ucięty do 2000 znaków przy pobieraniu).
     */
    public function sourceText(ProcurementNotice $notice): string
    {
        return $this->source($notice)['text'];
    }

    /**
     * Odczyt modelem bez pamięci podręcznej (zawsze nowe zapytanie). Zwraca to samo co readCached() i tekst odczytu.
     *
     * @return array{text: string, items: list<array<string, mixed>>, lots: list<array{lot_no: int, name: ?string, bhp: bool}>, source: string, read_at: string, cached: bool}
     */
    public function read(ProcurementNotice $notice): array
    {
        $source = $this->source($notice);
        $this->ensureText($source['text']);
        $payload = $this->withSlot(fn (): array => $this->runModel($notice, $source['text'], $source['kind']));

        return ['text' => $source['text']] + $payload + ['lots' => $this->lots($notice), 'cached' => false];
    }

    /**
     * Odczyt z pamięci podręcznej (klucz: wersja polecenia + treść, bez numeru ogłoszenia — nowa wersja ogłoszenia
     * z tą samą treścią nie woła modelu), a gdy go nie ma — modelem. $refresh — „Odczytaj ponownie” (pomija zapamiętany
     * wynik). $mayRunModel = false (bez uprawnienia do zakładania przetargów albo sam podgląd pamięci) — tylko zapamiętany
     * wynik albo null, bez blokad i bez miejsca na odczyt. Wyjątek (brak opisu — kod NO_DESCRIPTION, model nie
     * odpowiedział) nie jest zapamiętywany. NoticeItemsBusyException — limit odczytów albo ten sam odczyt trwa.
     *
     * @return array{items: list<array<string, mixed>>, lots: list<array{lot_no: int, name: ?string, bhp: bool}>, source: string, read_at: string, cached: bool}|null
     */
    public function readCached(ProcurementNotice $notice, bool $refresh = false, bool $mayRunModel = true): ?array
    {
        $source = $this->source($notice);
        $this->ensureText($source['text']);
        $key = 'notice-items:v2:'.self::PROMPT_VERSION.':'.sha1($source['text']);

        $before = $this->fromCache($key);
        // bez prawa do uruchomienia modelu „ponownie” nic nie zmienia — zostaje wynik zapamiętany
        if ($before !== null && (! $refresh || ! $mayRunModel)) {
            return $before + ['lots' => $this->lots($notice), 'cached' => true];
        }
        if (! $mayRunModel) {
            return null;
        }

        $lock = Cache::lock($key.':lock', self::LOCK_SECONDS);
        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            throw new NoticeItemsBusyException('Ten sam odczyt ogłoszenia trwa u kogoś innego — spróbuj za chwilę.');
        }
        self::releaseOnShutdown($lock);
        try {
            // ktoś odczytał to samo, gdy czekaliśmy na blokadę (przy „Odczytaj ponownie” — tylko odczyt nowszy niż ten,
            // który człowiek chciał zastąpić)
            $hit = $this->fromCache($key);
            if ($hit !== null && (! $refresh || $hit['read_at'] !== ($before['read_at'] ?? null))) {
                $payload = $hit + ['cached' => true];
            } else {
                $payload = $this->withSlot(fn (): array => $this->runModel($notice, $source['text'], $source['kind']));
                Cache::put($key, $payload, now()->addDays($payload['items'] === [] ? self::CACHE_EMPTY_DAYS : self::CACHE_DAYS));
                $payload += ['cached' => false];
            }
        } finally {
            $lock->release();
        }

        return $payload + ['lots' => $this->lots($notice)];
    }

    /**
     * @param  array<mixed>  $rows
     * @param  list<int>  $lotNumbers
     * @return list<array{lot_no: ?int, name: string, spec: ?string, spec_fragments: list<string>, quantity: ?int, unit: ?string, quote: string, quote_found: bool, bhp: bool}>
     */
    public function verify(array $rows, string $text, array $lotNumbers): array
    {
        $ctx = self::mapNormalize($text);
        $ctx['text'] = $text;
        $ctx['tokens'] = self::tokens($ctx['hay']);
        $headers = self::lotHeaders($ctx);

        // 1. cytaty: wystąpienie w tekście — wolne (niezajęte przez inną pozycję), w części pozycji, gdy ją znamy,
        //    i od poprzedniego cytatu (model wypisuje zwykle w kolejności ogłoszenia)
        $candidates = [];
        $cursor = 0;
        $taken = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $quote = trim((string) ($row['quote'] ?? ''));
            // przepisany opis pola ze schematu to nie dane z ogłoszenia
            if ($name === '' || $name === 'nazwa towaru' || $name === 'rodzaj towaru' || $quote === 'fragment ogłoszenia') {
                continue;
            }
            $lotNo = is_numeric($row['lot_no'] ?? null) ? (int) $row['lot_no'] : null;
            if ($lotNo !== null && ! in_array($lotNo, $lotNumbers, true)) {
                $lotNo = null;
            }
            $needle = self::normalize($quote);
            $start = $needle === '' ? null : self::locateQuote($ctx['hay'], $needle, $lotNo, $headers, $cursor, $taken);
            if ($start !== null) {
                $taken[$start] = true;
                $cursor = $start + 1;
            }
            $candidates[] = [
                'row' => $row, 'name' => $name, 'quote' => $quote, 'lot_no' => $lotNo,
                'start' => $start, 'length' => strlen($needle), 'norm_name' => self::normalize($name),
            ];
        }

        // 2. granice okien: początki znalezionych cytatów i nazwy pozycji bez znalezionego cytatu (ich cechy nie mogą
        //    trafić do okna sąsiada), posortowane; każda z właścicielem, żeby nie ucinać okna własną nazwą
        $boundaries = [];
        foreach ($candidates as $i => $candidate) {
            if ($candidate['start'] !== null) {
                $boundaries[] = [$candidate['start'], $i, null];
            } elseif (mb_strlen($candidate['norm_name']) >= 4) {
                foreach (self::wordOccurrences($ctx['hay'], $candidate['norm_name']) as $position) {
                    $boundaries[] = [$position, $i, $candidate['norm_name']];
                }
            }
        }
        usort($boundaries, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $out = [];
        $seen = [];
        foreach ($candidates as $i => $candidate) {
            $row = $candidate['row'];
            $name = $candidate['name'];
            $quote = $candidate['quote'];
            $quoteFound = $candidate['start'] !== null;

            $quantity = null;
            $modelQuantity = $row['quantity'] ?? null;
            if ($quoteFound && is_numeric($modelQuantity) && (int) $modelQuantity >= 1 && (float) $modelQuantity === (float) (int) $modelQuantity) {
                // liczba musi stać w cytacie jako osobna liczba („1 000” = 1000)
                $digits = (string) (int) $modelQuantity;
                $quoteNumbers = preg_match_all('/\d(?:[\d\x{00A0} ]*\d)?/u', $quote, $m) ? array_map(
                    static fn (string $n): string => (string) preg_replace('/\D/u', '', $n),
                    $m[0],
                ) : [];
                if (in_array($digits, $quoteNumbers, true)) {
                    $quantity = (int) $modelQuantity;
                }
            }

            $fragments = [];
            if ($quoteFound) {
                // 3. cechy tylko w oknie tej pozycji: cecha innego towaru z ogłoszenia nie przechodzi
                $start = (int) $candidate['start'];
                $end = self::windowEnd($start, $start + $candidate['length'], strlen($ctx['hay']), $i, $candidate['norm_name'], $boundaries, $headers);
                $fragments = self::verifiedFragments($row['spec'] ?? null, $candidate['norm_name'], $ctx, $start, $end);
            }

            $key = mb_strtolower($name).'|'.($candidate['lot_no'] ?? '').'|'.($quantity ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $out[] = [
                'lot_no' => $candidate['lot_no'],
                'name' => mb_substr($name, 0, 1000),
                'spec' => $fragments === [] ? null : implode('; ', $fragments),
                'spec_fragments' => $fragments,
                'quantity' => $quantity,
                'unit' => $quoteFound ? self::unitInQuote($row['unit'] ?? null, self::normalize($quote)) : null,
                'quote' => mb_substr($quote, 0, 2000),
                'quote_found' => $quoteFound,
                'bhp' => ($row['bhp'] ?? false) === true,
            ];
            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array{text: string, kind: 'subject'|'lots'}
     */
    private function source(ProcurementNotice $notice): array
    {
        foreach ($this->sections->extract($notice->html_body) as $section) {
            if ($section['key'] === 'subject' && trim($section['text']) !== '') {
                return ['text' => $section['text'], 'kind' => 'subject'];
            }
        }

        $parts = [];
        foreach ((array) ($notice->parsed['lots'] ?? []) as $lot) {
            if (! is_array($lot)) {
                continue;
            }
            $name = trim((string) ($lot['name'] ?? ''));
            $description = trim((string) ($lot['description'] ?? ''));
            $header = isset($lot['lot_no']) && is_numeric($lot['lot_no']) ? 'Część '.(int) $lot['lot_no'] : '';
            // opis części zwykle zaczyna się od jej nazwy — bez powtórzenia
            $body = $description !== '' && $name !== '' && ! str_starts_with($description, $name)
                ? $name."\n".$description
                : ($description !== '' ? $description : $name);
            $parts[] = trim($header."\n".$body);
        }

        return ['text' => trim(implode("\n\n", array_filter($parts, static fn (string $p): bool => $p !== ''))), 'kind' => 'lots'];
    }

    private function ensureText(string $text): void
    {
        if ($text === '') {
            throw new RuntimeException('Ogłoszenie nie ma opisu przedmiotu zamówienia — pozycje są w dokumentach postępowania.', self::NO_DESCRIPTION);
        }
    }

    /**
     * @return array{items: list<array<string, mixed>>, source: string, read_at: string}|null
     */
    private function fromCache(string $key): ?array
    {
        $hit = Cache::get($key);
        if (! is_array($hit) || ! is_array($hit['items'] ?? null) || ! is_string($hit['read_at'] ?? null)) {
            return null;
        }

        return ['items' => $hit['items'], 'source' => (string) ($hit['source'] ?? 'subject'), 'read_at' => $hit['read_at']];
    }

    /**
     * Wspólny limit odczytów modelem (wzorem EnrichmentSlots, bez czekania — zajęte = „spróbuj za chwilę”).
     *
     * @template T
     *
     * @param  callable(): T  $run
     * @return T
     */
    private function withSlot(callable $run): mixed
    {
        $slot = null;
        for ($i = 0; $i < self::SLOTS; $i++) {
            $lock = Cache::lock(self::SLOT_KEY.$i, self::LOCK_SECONDS);
            if ($lock->get()) {
                $slot = $lock;
                break;
            }
        }
        if (! $slot instanceof Lock) {
            throw new NoticeItemsBusyException;
        }
        self::releaseOnShutdown($slot);
        try {
            return $run();
        } finally {
            $slot->release();
        }
    }

    /**
     * Przekroczenie czasu PHP („Maximum execution time”) kończy żądanie bez bloków finally — funkcje zamykające jeszcze
     * działają. Zwolnienie cudzej blokady jest niemożliwe (blokada sprawdza właściciela), podwójne nic nie robi.
     */
    private static function releaseOnShutdown(Lock $lock): void
    {
        register_shutdown_function(static function () use ($lock): void {
            try {
                $lock->release();
            } catch (Throwable) {
                // zamykanie procesu — blokada i tak wygaśnie po LOCK_SECONDS
            }
        });
    }

    /**
     * @return array{items: list<array<string, mixed>>, source: string, read_at: string}
     */
    private function runModel(ProcurementNotice $notice, string $text, string $kind): array
    {
        $excerpt = mb_strlen($text) > self::MAX_TEXT ? mb_substr($text, 0, self::MAX_TEXT) : $text;

        $schema = ['items' => [[
            'lot_no' => 'numer części albo null',
            'name' => 'rodzaj towaru',
            'spec' => ['cecha towaru przepisana z ogłoszenia'],
            'quantity' => 'liczba albo null',
            'unit' => 'jednostka albo null',
            'quote' => 'fragment ogłoszenia',
            'bhp' => true,
        ]]];
        $messages = [
            [
                'role' => 'system',
                'content' => 'Jesteś asystentem przetargowym firmy sprzedającej odzież roboczą, obuwie robocze i środki ochrony indywidualnej (BHP). Odpowiadasz wyłącznie poprawnym JSON.',
            ],
            [
                'role' => 'user',
                'content' => "Poniżej opis przedmiotu zamówienia z ogłoszenia o zamówieniu publicznym. Wypisz każdy towar, który zamawiający chce kupić.\n"
                    ."Zasady:\n"
                    ."- jeden towar = jedna pozycja; komplet opisany jako całość (np. „ubranie specjalne składające się z kurtki i spodni”) to jedna pozycja;\n"
                    ."- name: sam rodzaj towaru z ogłoszenia, w mianowniku (np. „hełm strażacki”, „rękawice ochronne”) — bez norm, klas, rozmiarów i innych cech;\n"
                    ."- spec: lista cech tego towaru podanych w ogłoszeniu przy nim (normy, klasy, materiał, rozmiary, kolor, parametry); każda cecha to osobny fragment ogłoszenia przepisany dokładnie, znak w znak, razem z przeczeniem, jeśli stoi przy cesze (np. „nie zawierające lateksu”), bez rozwijania skrótów i bez zmiany odmiany; bez warunków całej części lub zamówienia (terminy, gwarancja, dostawa, płatność) i bez własnych wniosków; pusta lista, gdy ogłoszenie nie podaje cech;\n"
                    ."- quantity: liczba sztuk/kompletów/par z ogłoszenia; null, gdy ogłoszenie jej nie podaje;\n"
                    ."- unit: jednostka z ogłoszenia, przepisana jak w ogłoszeniu (szt., kpl., par, op.); null, gdy brak;\n"
                    ."- lot_no: numer części zamówienia, w której jest towar; null, gdy zamówienie nie ma części;\n"
                    ."- quote: fragment ogłoszenia z nazwą i ilością tego towaru, przepisany znak w znak (bez zmian, bez skrótów);\n"
                    ."- bhp: true dla odzieży roboczej, ochronnej, specjalnej i służbowej, obuwia roboczego i ochronnego, rękawic, ochrony głowy, oczu, twarzy, słuchu i dróg oddechowych, sprzętu chroniącego przed upadkiem z wysokości i innych środków ochrony indywidualnej; false dla pojazdów, maszyn, narzędzi, elektroniki, łączności, mebli, leków i wszystkiego innego.\n"
                    ."Nie wypisuj usług, warunków, terminów, kryteriów ani informacji o trybie postępowania. Czego nie ma w tekście, tego nie wpisuj. Gdy ogłoszenie nie wymienia żadnego towaru, zwróć pustą listę.\n"
                    .'Schemat: '.json_encode($schema, JSON_UNESCAPED_UNICODE)."\n\n---\n".$excerpt,
            ],
        ];

        $raw = $this->llm->chat($messages, null, true, null, AiTask::TenderDocument);
        $parsed = $this->jsonParser->parse((string) ($raw['content'] ?? ''));
        $rows = $parsed['items'] ?? null;
        if (! is_array($rows)) {
            throw new RuntimeException('Nie udało się odczytać pozycji z treści ogłoszenia. Spróbuj ponownie.');
        }
        unset($raw, $parsed);

        return [
            // weryfikacja na tym, co widział model (cytat spoza wycinka nie jest „z ogłoszenia, które przeczytał”)
            'items' => $this->verify($rows, $excerpt, $this->lotNumbers($notice)),
            'source' => $kind,
            'read_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Części ogłoszenia z oceną „towary BHP” (NoticeBhpLots — wnioskowanie z kodów CPV i opisu) — liczone na bieżąco,
     * nie z pamięci podręcznej (zależą od ogłoszenia i listy kodów, nie od odczytu modelem).
     *
     * @return list<array{lot_no: int, name: ?string, bhp: bool}>
     */
    private function lots(ProcurementNotice $notice): array
    {
        $out = [];
        foreach ((array) ($notice->parsed['lots'] ?? []) as $lot) {
            if (! is_array($lot) || ! is_numeric($lot['lot_no'] ?? null)) {
                continue;
            }
            $out[] = [
                'lot_no' => (int) $lot['lot_no'],
                'name' => is_string($lot['name'] ?? null) ? $lot['name'] : null,
                'bhp' => $this->bhpLots->forLot($lot)['bhp'],
            ];
        }

        return $out;
    }

    /** @return list<int> */
    private function lotNumbers(ProcurementNotice $notice): array
    {
        $out = [];
        foreach ((array) ($notice->parsed['lots'] ?? []) as $lot) {
            if (is_array($lot) && is_numeric($lot['lot_no'] ?? null)) {
                $out[] = (int) $lot['lot_no'];
            }
        }

        return $out;
    }

    /**
     * Wystąpienie cytatu dla pozycji: najpierw wolne (ten sam cytat w dwóch częściach — druga pozycja dostaje drugie
     * wystąpienie), w części pozycji (gdy ją znamy i tekst ma nagłówki części), od kursora; inaczej pierwsze pasujące.
     *
     * @param  list<array{0: int, 1: int}>  $headers
     * @param  array<int, true>  $taken
     */
    private static function locateQuote(string $hay, string $needle, ?int $lotNo, array $headers, int $cursor, array $taken): ?int
    {
        $occurrences = [];
        $offset = 0;
        while (count($occurrences) < self::MAX_OCCURRENCES && ($found = strpos($hay, $needle, $offset)) !== false) {
            $occurrences[] = $found;
            $offset = $found + 1;
        }
        if ($occurrences === []) {
            return null;
        }
        $free = array_values(array_filter($occurrences, static fn (int $p): bool => ! isset($taken[$p])));
        $pool = $free !== [] ? $free : $occurrences;
        if ($lotNo !== null && $headers !== []) {
            $inLot = array_values(array_filter($pool, static fn (int $p): bool => self::lotAt($headers, $p) === $lotNo));
            if ($inLot !== []) {
                $pool = $inLot;
            }
        }
        foreach ($pool as $position) {
            if ($position >= $cursor) {
                return $position;
            }
        }

        return $pool[0];
    }

    /**
     * Koniec okna pozycji (bajt tekstu znormalizowanego): najbliższa granica za początkiem cytatu — cytat innej pozycji
     * (także wewnątrz własnego cytatu, gdy cytat obejmuje kilka towarów), nazwa pozycji bez znalezionego cytatu,
     * nagłówek części — najwyżej TAIL_WINDOW bajtów za cytatem.
     *
     * @param  list<array{0: int, 1: int, 2: ?string}>  $boundaries
     * @param  list<array{0: int, 1: int}>  $headers
     */
    private static function windowEnd(int $start, int $quoteEnd, int $length, int $own, string $ownName, array $boundaries, array $headers): int
    {
        $end = min($length, $quoteEnd + self::TAIL_WINDOW);
        foreach ($boundaries as [$position, $owner, $name]) {
            if ($position <= $start || $owner === $own || ($name !== null && $name === $ownName)) {
                continue;
            }
            $end = min($end, $position);
            break;
        }
        foreach ($headers as [$position]) {
            if ($position > $start) {
                $end = min($end, $position);
                break;
            }
        }

        return $end;
    }

    /**
     * Fragmenty cech od modelu → wycinki z tekstu ogłoszenia w oknie pozycji. Najpierw dokładnie (po normalizacji jak
     * cytat, na granicy słów); gdy brak — luźno: słowa fragmentu w tej samej kolejności, odstęp ≤ MAX_GAP znaków, liczby,
     * kody i słowa krótsze niż 4 znaki identyczne, dłuższe słowa po rdzeniu (5 pierwszych liter). Fragment zawarty już
     * w nazwie odpada; przeczenie tuż przed wycinkiem dołączone. Zwraca oryginalne wycinki (białe znaki złączone),
     * łącznie najwyżej MAX_SPEC znaków.
     *
     * @param  array{hay: string, map: list<int>, text: string, tokens: array{words: list<string>, starts: list<int>, ends: list<int>}}  $ctx
     * @return list<string>
     */
    private static function verifiedFragments(mixed $spec, string $normalizedName, array $ctx, int $start, int $end): array
    {
        $raw = is_string($spec) ? [$spec] : (is_array($spec) ? $spec : []);
        // słowa okna wyznaczone raz na pozycję (zakres indeksów, bez kopiowania słów tekstu)
        $first = self::firstTokenAtOrAfter($ctx['tokens']['starts'], $start);
        $last = self::firstTokenAtOrAfter($ctx['tokens']['starts'], $end);
        $out = [];
        $seen = [];
        $length = 0;
        foreach (array_slice($raw, 0, self::MAX_FRAGMENTS) as $fragment) {
            if (! is_string($fragment)) {
                continue;
            }
            $needle = self::normalize($fragment);
            if ($needle === '' || $needle === 'cecha towaru przepisana z ogłoszenia' || mb_strlen($needle) > 300
                || str_contains($normalizedName, $needle)) {
                continue;
            }
            $span = self::exactSpan($ctx['hay'], $needle, $start, $end)
                ?? self::looseSpan($ctx['hay'], $ctx['tokens'], $needle, $first, $last, $end);
            if ($span === null) {
                continue;
            }
            $span[0] = self::withNegation($ctx['tokens'], $span[0]);
            $excerpt = self::excerpt($ctx, $span[0], $span[1]);
            $excerptKey = self::normalize($excerpt);
            if ($excerpt === '' || isset($seen[$excerptKey]) || str_contains($normalizedName, $excerptKey)) {
                continue;
            }
            $added = mb_strlen($excerpt) + ($out === [] ? 0 : 2);
            if ($length + $added > self::MAX_SPEC) {
                break;
            }
            $seen[$excerptKey] = true;
            $out[] = $excerpt;
            $length += $added;
        }

        return $out;
    }

    /**
     * @return array{0: int, 1: int}|null [początek, koniec) w bajtach tekstu znormalizowanego
     */
    private static function exactSpan(string $hay, string $needle, int $start, int $end): ?array
    {
        $length = strlen($needle);
        $offset = $start;
        while ($offset < $end && ($found = strpos($hay, $needle, $offset)) !== false && $found + $length <= $end) {
            // „S3” nie może pasować do środka „S30”, „EN 388” do „EN 3880”
            if (self::onWordBoundary($hay, $found, $length)) {
                return [$found, $found + $length];
            }
            $offset = $found + 1;
        }

        return null;
    }

    /**
     * @param  array{words: list<string>, starts: list<int>, ends: list<int>}  $tokens
     * @return array{0: int, 1: int}|null
     */
    private static function looseSpan(string $hay, array $tokens, string $needle, int $first, int $last, int $end): ?array
    {
        $words = preg_match_all('/[\p{L}\p{N}]+/u', $needle, $m) > 0 ? $m[0] : [];
        if ($words === []) {
            return null;
        }
        $budget = 20000;
        for ($i = $first; $i < $last && $tokens['ends'][$i] <= $end; $i++) {
            if (! self::wordMatches($words[0], $tokens['words'][$i])) {
                continue;
            }
            $match = self::chain($hay, $words, 1, $tokens, $i, $last, $end, $budget);
            if ($match !== null) {
                return [$tokens['starts'][$i], $tokens['ends'][$match]];
            }
            if ($budget <= 0) {
                break;
            }
        }

        return null;
    }

    /**
     * Dalsze słowa fragmentu po kolei, każde najwyżej MAX_GAP znaków za poprzednim. Zwraca indeks ostatniego słowa.
     *
     * @param  list<string>  $words
     * @param  array{words: list<string>, starts: list<int>, ends: list<int>}  $tokens
     */
    private static function chain(string $hay, array $words, int $k, array $tokens, int $previous, int $last, int $end, int &$budget): ?int
    {
        if ($k >= count($words)) {
            return $previous;
        }
        for ($j = $previous + 1; $j < $last && $tokens['ends'][$j] <= $end; $j++) {
            $gap = $tokens['starts'][$j] - $tokens['ends'][$previous];
            // bajty ≥ znaki; liczba znaków dopiero, gdy bajtów jest więcej niż MAX_GAP
            if ($gap > self::MAX_GAP && ($gap > 4 * self::MAX_GAP || mb_strlen(substr($hay, $tokens['ends'][$previous], $gap)) > self::MAX_GAP)) {
                return null;
            }
            if (--$budget <= 0) {
                return null;
            }
            if (self::wordMatches($words[$k], $tokens['words'][$j])) {
                $match = self::chain($hay, $words, $k + 1, $tokens, $j, $last, $end, $budget);
                if ($match !== null) {
                    return $match;
                }
            }
        }

        return null;
    }

    /** Liczby, kody (EN, S3, SRC, XL) i słowa krótsze niż 4 znaki — identyczne; dłuższe — ten sam rdzeń (5 liter). */
    private static function wordMatches(string $word, string $token): bool
    {
        if (preg_match('/\d/u', $word) === 1 || mb_strlen($word) < 4) {
            return $word === $token;
        }

        return mb_strlen($token) >= 4 && mb_substr($word, 0, 5) === mb_substr($token, 0, 5);
    }

    /**
     * „nie” / „bez” / „brak” tuż przed wycinkiem zmienia sens cechy — wycinek zaczyna się od przeczenia.
     *
     * @param  array{words: list<string>, starts: list<int>, ends: list<int>}  $tokens
     */
    private static function withNegation(array $tokens, int $spanStart): int
    {
        $index = self::firstTokenAtOrAfter($tokens['starts'], $spanStart);
        $previous = $index - 1;
        if ($previous >= 0 && in_array($tokens['words'][$previous], self::NEGATIONS, true)
            && $spanStart - $tokens['ends'][$previous] <= 3) {
            return $tokens['starts'][$previous];
        }

        return $spanStart;
    }

    /**
     * Wycinek oryginalnego tekstu dla zakresu bajtów tekstu znormalizowanego (białe znaki złączone).
     *
     * @param  array{hay: string, map: list<int>, text: string}  $ctx
     */
    private static function excerpt(array $ctx, int $start, int $end): string
    {
        $from = $ctx['map'][$start];
        $lastChar = $ctx['map'][$end - 1];
        $to = $lastChar + self::utf8Length($ctx['text'], $lastChar);

        return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', substr($ctx['text'], $from, $to - $from)));
    }

    /** Jednostka od modelu tylko wtedy, gdy stoi w cytacie („szt”, „kpl.”, „par”) — w postaci z cytatu. */
    private static function unitInQuote(mixed $unit, string $normalizedQuote): ?string
    {
        $unit = rtrim(self::normalize(is_scalar($unit) ? (string) $unit : ''), '.');
        if ($unit === '' || $unit === 'jednostka albo null') {
            return null;
        }
        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($unit, '/').'\.?(?![\p{L}\p{N}])/u';

        return preg_match($pattern, $normalizedQuote, $m) === 1 ? mb_substr($m[0], 0, 16) : null;
    }

    /**
     * Nagłówki części w tekście („Część 2”, „Pakiet nr 3”, „Zadanie IV”) — [bajt, numer], rosnąco. Liczby rzymskie tylko
     * pisane wielkimi literami w ogłoszeniu („część i opis” to nie część pierwsza).
     *
     * @param  array{hay: string, map: list<int>, text: string}  $ctx
     * @return list<array{0: int, 1: int}>
     */
    private static function lotHeaders(array $ctx): array
    {
        $pattern = '/(?<![\p{L}\p{N}])(?:część|cześć|czesc|pakiet|zadanie)\s*(?:nr\.?\s*)?(\d+|[ivxl]+)(?![\p{L}\p{N}])/u';
        if (preg_match_all($pattern, $ctx['hay'], $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }
        $out = [];
        foreach ($m as $match) {
            [$number, $offset] = $match[1];
            if (ctype_digit($number)) {
                $out[] = [(int) $match[0][1], (int) $number];

                continue;
            }
            $original = substr($ctx['text'], $ctx['map'][$offset], strlen($number));
            $value = self::romanValue($number);
            if ($value !== null && ctype_upper($original)) {
                $out[] = [(int) $match[0][1], $value];
            }
        }

        return $out;
    }

    private static function romanValue(string $roman): ?int
    {
        $values = ['i' => 1, 'v' => 5, 'x' => 10, 'l' => 50];
        $total = 0;
        $length = strlen($roman);
        for ($i = 0; $i < $length; $i++) {
            $value = $values[$roman[$i]];
            $next = $i + 1 < $length ? $values[$roman[$i + 1]] : 0;
            $total += $value < $next ? -$value : $value;
        }

        return $total >= 1 && $total <= 89 ? $total : null;
    }

    /** @param  list<array{0: int, 1: int}>  $headers */
    private static function lotAt(array $headers, int $position): ?int
    {
        $lot = null;
        foreach ($headers as [$headerAt, $number]) {
            if ($headerAt > $position) {
                break;
            }
            $lot = $number;
        }

        return $lot;
    }

    /** @return list<int> wystąpienia (bajty) całego słowa / frazy w tekście znormalizowanym */
    private static function wordOccurrences(string $hay, string $needle): array
    {
        $out = [];
        $offset = 0;
        $length = strlen($needle);
        while (count($out) < self::MAX_OCCURRENCES && ($found = strpos($hay, $needle, $offset)) !== false) {
            if (self::onWordBoundary($hay, $found, $length)) {
                $out[] = $found;
            }
            $offset = $found + 1;
        }

        return $out;
    }

    /** Fraza nie zaczyna się ani nie kończy w środku słowa (gdy jej brzeg jest literą albo cyfrą). */
    private static function onWordBoundary(string $hay, int $at, int $length): bool
    {
        $isWord = static fn (string $char): bool => $char !== '' && preg_match('/^[\p{L}\p{N}]/u', $char) === 1;
        $before = '';
        if ($at > 0) {
            $i = $at - 1;
            while ($i > 0 && (ord($hay[$i]) & 0xC0) === 0x80) {
                $i--;
            }
            $before = substr($hay, $i, $at - $i);
        }
        $afterAt = $at + $length;
        $after = $afterAt < strlen($hay) ? substr($hay, $afterAt, self::utf8Length($hay, $afterAt)) : '';
        $first = substr($hay, $at, self::utf8Length($hay, $at));
        $lastAt = $afterAt - 1;
        while ($lastAt > $at && (ord($hay[$lastAt]) & 0xC0) === 0x80) {
            $lastAt--;
        }
        $last = substr($hay, $lastAt, $afterAt - $lastAt);

        return (! $isWord($first) || ! $isWord($before)) && (! $isWord($last) || ! $isWord($after));
    }

    /** @param  list<int>  $starts */
    private static function firstTokenAtOrAfter(array $starts, int $position): int
    {
        $low = 0;
        $high = count($starts);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($starts[$middle] < $position) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * Słowa i liczby tekstu znormalizowanego z położeniem w bajtach (trzy równoległe listy — mniej pamięci niż lista trójek).
     *
     * @return array{words: list<string>, starts: list<int>, ends: list<int>}
     */
    private static function tokens(string $hay): array
    {
        $tokens = ['words' => [], 'starts' => [], 'ends' => []];
        if (preg_match_all('/[\p{L}\p{N}]+/u', $hay, $m, PREG_OFFSET_CAPTURE) < 1) {
            return $tokens;
        }
        foreach ($m[0] as [$word, $offset]) {
            $tokens['words'][] = $word;
            $tokens['starts'][] = $offset;
            $tokens['ends'][] = $offset + strlen($word);
        }

        return $tokens;
    }

    /**
     * Normalizacja jak normalize() z mapą: bajt tekstu znormalizowanego → bajt początku znaku oryginału (do wycinka
     * z oryginału). Przejście po bajtach, bez tablicy znaków.
     *
     * @return array{hay: string, map: list<int>}
     */
    private static function mapNormalize(string $text): array
    {
        $hay = '';
        $map = [];
        $pendingSpace = false;
        $lastSpace = 0;
        $length = strlen($text);
        $i = 0;
        while ($i < $length) {
            $size = self::utf8Length($text, $i);
            $char = substr($text, $i, $size);
            // te same białe znaki co normalize() (\s z /u to też U+2009, U+2002–200A, U+2028, U+3000, U+0085)
            $space = $size === 1 ? ctype_space($char) : preg_match('/^[\s\x{00A0}\x{202F}]$/u', $char) === 1;
            if ($space) {
                $pendingSpace = $hay !== '';
                $lastSpace = $i;
                $i += $size;

                continue;
            }
            if ($pendingSpace) {
                $hay .= ' ';
                $map[] = $lastSpace;
                $pendingSpace = false;
            }
            $lower = $size === 1 ? strtolower($char) : mb_strtolower(self::replaceChars($char));
            $hay .= $lower;
            for ($b = strlen($lower); $b > 0; $b--) {
                $map[] = $i;
            }
            $i += $size;
        }

        return ['hay' => $hay, 'map' => $map];
    }

    /** Długość znaku UTF-8 w bajtach od bajtu $at (bajt spoza początku znaku — 1). */
    private static function utf8Length(string $text, int $at): int
    {
        $byte = ord($text[$at] ?? "\0");

        return match (true) {
            $byte >= 0xF0 => 4,
            $byte >= 0xE0 => 3,
            $byte >= 0xC0 => 2,
            default => 1,
        };
    }

    /** Porównanie cytatu z tekstem: bez różnic w białych znakach, myślnikach, cudzysłowach i wielkości liter. */
    private static function normalize(string $text): string
    {
        $text = self::replaceChars($text);
        $text = (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text);

        return mb_strtolower(trim($text));
    }

    private static function replaceChars(string $text): string
    {
        return str_replace(['–', '—', '‒', '−', '„', '”', '“', '«', '»', '’', '‘'], ['-', '-', '-', '-', '"', '"', '"', '"', '"', "'", "'"], $text);
    }
}
