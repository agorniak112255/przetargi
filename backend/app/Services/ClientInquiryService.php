<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSubstitute;
use App\Models\SearchEvent;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Clients\InquiryClientLinker;
use App\Services\Inquiries\OrderHintBuilder;
use App\Services\Notifications\AppNotificationMessage;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Pricing\SourcePriceComparison;
use App\Services\Pricing\SupplierSpecialMask;
use App\Services\Search\SearchEventRecorder;
use App\Support\InquiryLinks;
use App\Support\InquiryMailText;
use App\Support\InquiryQueryText;
use App\Support\InquiryReplyHtml;
use App\Support\InquiryRequirements;
use App\Support\InquirySignature;
use App\Support\OfferPricing;
use App\Support\OfferProductText;
use App\Support\OfferTermText;
use App\Support\OfferValidity;
use App\Support\PpeAssortment;
use App\Support\ProductSizeVariant;
use App\Support\WithdrawnProductNote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ClientInquiryService
{
    /** Kanał zapytania, którego treść handlowiec wczytał z pliku klienta (Excel, PDF, Word). */
    public const CHANNEL_FILE = 'file';

    /** Kanał zapytania założonego z otwartego maila przez dodatek do Thunderbirda. */
    private const CHANNEL_THUNDERBIRD = 'thunderbird';

    /**
     * Nagłówek, który formularz stawia nad tekstem wczytanym z pliku („=== Plik klienta: zapytanie.xlsx ===”).
     * Oddziela wklejony nad nim mail (cięty jak mail) od treści pliku (idzie w całości). Ten sam nagłówek stawia
     * dodatek do Thunderbirda nad tekstem załącznika maila (od 1.29.0).
     */
    private const FILE_MARKER = '/^=== Plik klienta: .+ ===$/mu';

    /** Najmniej fraz w planie szukania — tyle było przy stałym limicie 8 pozycji. */
    private const MIN_PRODUCT_QUERIES = 10;

    private const MAX_MATCHES_PER_QUERY = 3;

    /** Ile podobnych kart z katalogu pokazać przy pozycji, której model nic nie zatwierdził (similarCandidates). */
    private const MAX_SIMILAR_CANDIDATES = 6;

    /** Limit pozycji zapytań sprzed ustawienia (analysis.max_items) — dla starych rekordów. */
    private const LEGACY_MAX_LINE_ITEMS = 8;

    /** Budżet odpowiedzi modelu przy czytaniu maila: stała część i dopłata na pozycję (cytat, fraza, ilość). */
    private const EXTRACT_TOKENS_BASE = 1500;

    private const EXTRACT_TOKENS_PER_ITEM = 160;

    private const EXTRACT_TOKENS_MIN = 3500;

    /** Uwaga o sprzeczności w wierszu klienta — jedno zdanie, nie akapit. */
    private const CONFLICT_MAX = 300;

    /** Ile wyrobów dobranych ręcznie trzymamy przy jednej pozycji. */
    private const MAX_MANUAL_CANDIDATES = 3;

    /** Najmniej kart pytań — tyle było przy stałym limicie 8 pozycji; rośnie z limitem (cardCap()). */
    private const MIN_CARDS = 28;

    /** Od tego wyniku najlepszy kandydat jest „pewny” (jeśli drugi nie depcze mu po piętach). */
    private const CONFIDENT_SCORE = 80;

    /** Drugi kandydat bliżej niż tyle punktów = pozycja niejednoznaczna. */
    private const AMBIGUOUS_GAP = 11;

    private const PRICE_MODES = ['none', 'catalog', 'catalog_margin'];

    /**
     * Klucz `answers` z ceną wpisaną ręcznie przy pozycji: `manual_price:item_N` =>
     * ['option_id' => 'p:<id wyrobu>', 'custom' => '159.00']. Cena należy do wyrobu,
     * przy którym ją wpisano — po wyborze innego wyrobu przestaje obowiązywać.
     */
    public const MANUAL_PRICE_PREFIX = 'manual_price:';

    /** Zdanie zamykające list; podpis zostawiamy stopce handlowca w poczcie. */
    private const OUTRO = 'W razie pytań zapraszamy do kontaktu.';

    /** Jednostki z maila, które umiemy oddzielić od liczby („30szt”, „4 pary”, „2 op.”). */
    private const UNIT_PATTERN = '(?:(?:sztuk[ai]?|szt\.?|pcs\.?|par[ay]?|opakowa[nń][a-z]*|opak\.?|op\.?|komplet[a-zóy]*|kpl\.?|zestaw[a-zóy]*|zest\.?|karton(?:y|ów|ow|ami|ach|em|ie|om|a|u)?)(?![\p{L}]))';

    /** Wiersz otwarty liczbą („1. Buty robocze”, „30 szt. Rękawice”) — liczba, jednostka, reszta. */
    private const ROW_NUMBER = '/^(\d+)\s*('.self::UNIT_PATTERN.')?[\s.,:–-]+(.+)$/iu';

    /** Data na początku wiersza („24.07.2026 płatności…”) — nie numer pozycji ani ilość. */
    private const LEADING_DATE = '/^\d{1,2}[.\-\/]\d{1,2}[.\-\/]\d{2,4}(?!\d)/u';

    /**
     * Adres z kodem pocztowym („35-232 Rzeszów, ul. Miłocińska 17”, „35-232 Rzeszów”): kod NN-NNN, nazwa miejscowości
     * wielkimi literami, potem przecinek, koniec wiersza albo „ul.”. ROW_NUMBER brał „35” za ilość (zapytanie #86).
     * Kody wyrobów w tym samym zapisie („11-800 Rękawice nitrylowe 10 par”) nie pasują: po nazwie idzie mała litera.
     */
    private const POSTAL_ADDRESS_LINE = '/^\d{2}-\d{3}\s+(\p{Lu}[\p{L}\-]*(?:\s+\p{Lu}[\p{L}\-]*)*)\s*(?:,|$|(?:ul|al|os|pl)\.)/u';

    /** Komórka z samym numerem („1”, „12.”) — Lp. albo numer kolumny, nigdy nazwa wyrobu. */
    private const BARE_NUMBER_CELL = '/^\d{1,5}\.?$/u';

    /** „ESD” we frazie ekstraktora, także „ESD-owe” — do wycięcia, gdy klient go nie napisał. */
    private const ESD_MENTION = '/(?<![\p{L}\d])esd(?:-(?:ow\p{L}*|safe))?(?![\p{L}\d])/iu';

    /**
     * Norma antystatyki o numerze %s we frazie ekstraktora — z oznaczeniem („PN-EN ISO”, „IEC”), częściami i rokiem
     * („EN 1149-5:2018”, „IEC 61340-5-1”, „EN 1149-1, -3, -5”) i słowem, które ją wprowadza („zgodne z”, „wg”, „normą”).
     */
    private const NORM_MENTION = '/(?:(?<![\p{L}\d])(?:zgodn\p{L}*\s+z|wg\.?|według|spełniając\p{L}*|certyfikat\p{L}*|norm\p{L}*)(?:\s+norm\p{L}*)?\s+)?'
        .'(?:(?<![\p{L}\d])(?:pn|en|iso|iec)(?:[\s-]*(?:en|iso|iec)(?![\p{L}]))*[\s-]*)?(?<!\d)%s(?:[-\/]{1,2}\d{1,2}|,\s*-\d{1,2}|:\s?\d{4})*(?!\d)/iu';

    /** Miejsce po wyciętym zapisie (znak z obszaru prywatnego Unicode — nie stoi w tekście maila). */
    private const CUT_MARK = "\u{E000}";

    /**
     * Mail łamany w stałej szerokości (ok. 72–78 znaków) tnie wiersz pozycji w połowie.
     * Wiersz od tej długości, po którym treść idzie dalej małą literą, uznajemy za złamany.
     */
    private const WRAPPED_LINE_MIN = 60;

    private ?int $minMatchScore = null;

    /**
     * Teksty kart do sprawdzania warunków szczególnych, id → tekst. Ten sam
     * wyrób bywa kandydatem w kilku pozycjach, a w jednym żądaniu wystarczy
     * przeczytać go raz.
     *
     * @var array<int, string>
     */
    private array $cardCheckTexts = [];

    /**
     * Wycofanie przez producenta z opisu karty, id → wynik WithdrawnProductNote::parse (null = nie wycofany).
     * Na czas żądania, jak $cardCheckTexts — lista zapytań liczy „do sprawdzenia” dla wielu wierszy naraz.
     *
     * @var array<int, array{successor: ?string}|null>
     */
    private array $withdrawnNotes = [];

    /**
     * Rundy szukania w katalogu od ostatniego wyzerowania — do logu `client-inquiry.timings`.
     *
     * @var list<array{queries: int, ms: int, stages_ms: array<string, int>}>
     */
    private array $searchRounds = [];

    /**
     * Zdarzenia wyszukiwania pozycji (ekran „Statystyki AI”) czekające na numer zapytania — szukanie idzie przed
     * ClientInquiry::create. Zerowane na każde wywołanie, bo usługa przelicza wiele zapytań w jednej pętli (rematch).
     *
     * @var list<array{query: string, result: array<string, mixed>}>
     */
    private array $pendingSearchEvents = [];

    /** Limit pozycji tej analizy (maxLineItems()); null = jeszcze nie odczytany z ustawień. */
    private ?int $maxItems = null;

    /**
     * Postęp wyszukiwania dla analizy w tle (etap, gotowe, wszystkie); null = bez zgłaszania (rematch, testy).
     *
     * @var (callable(string, int, int): void)|null
     */
    private $searchProgress = null;

    /**
     * Widok ceny specjalnej B2B dla bieżącej operacji (autor analizy, handlowiec przy pozycji, widz zapytania) —
     * ustawia go withPriceMask(). Poza takim zakresem mask() ukrywa: zapomniane wejście nie może odsłonić ceny.
     */
    private ?SupplierSpecialMask $priceMask = null;

    public function __construct(
        private readonly OpenAiCompatibleClient $llm,
        private readonly ProductInquirySearch $search,
        private readonly NbpExchangeRateService $fx,
        private readonly AiSettingsService $aiSettings,
        private readonly PpeAssortment $assortment = new PpeAssortment,
    ) {}

    /**
     * Wykonuje $fn z podanym widokiem ceny specjalnej B2B (prices.supplier_special.view): wiersze kandydatów,
     * ceny oferty i list liczą się od ceny, którą ten użytkownik widzi. Usługa żyje długo (worker kolejki), więc
     * poprzedni widok wraca po wyjściu — także po wyjątku i przy zagnieżdżeniu.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function withPriceMask(SupplierSpecialMask $mask, callable $fn): mixed
    {
        $previous = $this->priceMask;
        $this->priceMask = $mask;
        try {
            return $fn();
        } finally {
            $this->priceMask = $previous;
        }
    }

    /** Widok ceny bieżącej operacji; bez ustawionego — ukrywający (bezpieczny domyślny). */
    private function mask(): SupplierSpecialMask
    {
        return $this->priceMask ?? SupplierSpecialMask::hiding();
    }

    /**
     * Najwięcej pozycji zapytania — ustawienie „Strojenie AI” (domyślnie 50), odczytane raz na instancję usługi.
     * Od niego zależą też heurystyki parsera (numeracja listy, rozbicie rozmiarów): liczą się w granicach limitu.
     */
    private function maxLineItems(): int
    {
        return $this->maxItems ??= $this->aiSettings->inquiryMaxItems();
    }

    /**
     * Najwięcej fraz w planie szukania: klucz każdej pozycji i drugie tyle na frazy modelu (zapas pozycji,
     * po który sięga druga runda) — przy stałym limicie 8 pozycji było to 10 fraz.
     */
    private function queryCap(int $itemCount): int
    {
        return max(self::MIN_PRODUCT_QUERIES, 2 * $itemCount + 2);
    }

    /** Karty pytań: do trzech na pozycję (towar, pytanie modelu, zamienniki) i karty wspólne listu. */
    private function cardCap(): int
    {
        return max(self::MIN_CARDS, 3 * $this->maxLineItems() + 8);
    }

    /**
     * Budżet odpowiedzi przy czytaniu maila. Model oddaje wszystkie pozycje w jednym JSON-ie — ucięta odpowiedź
     * to zgubione pozycje, więc budżet rośnie z limitem (8 pozycji = dawne 3500, 50 = 9500).
     */
    private function extractMaxTokens(): int
    {
        return max(self::EXTRACT_TOKENS_MIN, self::EXTRACT_TOKENS_BASE + self::EXTRACT_TOKENS_PER_ITEM * $this->maxLineItems());
    }

    /**
     * Treść, którą dostają model i parser pozycji. Mail idzie bez cytatu, nagłówka przekazania i stopki — inaczej
     * adres albo telefon z podpisu stają się pozycjami zamówienia. Tekst z pliku (pismo, tabela) idzie w całości:
     * cięcie stopki kończyło go na pierwszym wierszu z telefonem, czyli zwykle na nagłówku firmowym nad tabelą.
     * Temat maila („PD: …”) mówi, czy pod nagłówkiem Outlooka jest przekazane zapytanie, czy cytat odpowiedzi.
     * Nadawca (threadSender) — czy blok Outlooka to wcześniejsza wiadomość tego samego klienta (ponaglenie).
     */
    public static function analysisText(string $body, ?string $channel, ?string $subject = null, ?string $sender = null): string
    {
        if (! self::hasFilePart($body, $channel)) {
            return InquiryMailText::forAnalysis($body, $subject, $sender);
        }
        [$mail, $file] = self::splitAtFileMarker($body);

        return $mail === '' ? $file : InquiryMailText::forAnalysis($mail, $subject, $sender)."\n\n".$file;
    }

    /**
     * Adres nadawcy do cięcia maila albo null. Nasza skrzynka (handel@ przekazuje mail dalej) odpada: blok
     * „Od: handel@” w historii to nasza oferta, a nie wcześniejsza wiadomość klienta.
     */
    public static function threadSender(?string $from): ?string
    {
        $email = InquirySignature::splitFrom($from)['email'];
        if ($email === null) {
            return null;
        }
        $domain = mb_strtolower(substr((string) strrchr($email, '@'), 1));
        foreach ((array) config('inquiries.internal_email_domains', []) as $internal) {
            $internal = mb_strtolower(trim((string) $internal));
            if ($internal !== '' && ($domain === $internal || str_ends_with($domain, '.'.$internal))) {
                return null;
            }
        }

        return $email;
    }

    /**
     * Czy treść ma część z pliku klienta. Formularz w przeglądarce ustawia wtedy kanał „file”. Dodatek do
     * Thunderbirda zostaje przy swoim kanale (zapytanie dalej jest odpowiedzią na ten mail), a tekst załączników
     * dokleja pod nagłówkiem pliku — bez tego rozpoznania cięcie stopki maila ucinałoby pismo z załącznika
     * na pierwszym wierszu z telefonem.
     */
    private static function hasFilePart(string $body, ?string $channel): bool
    {
        return $channel === self::CHANNEL_FILE
            || ($channel === self::CHANNEL_THUNDERBIRD
                && preg_match(self::FILE_MARKER, str_replace(["\r\n", "\r"], "\n", $body)) === 1);
    }

    /**
     * Treść zapytania z pliku: [mail wklejony nad pierwszym nagłówkiem pliku, reszta od nagłówka]. Bez nagłówka
     * (handlowiec go usunął) całość traktujemy jak plik — lepiej nie ciąć maila niż uciąć pismo.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitAtFileMarker(string $body): array
    {
        $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
        if (preg_match(self::FILE_MARKER, $body, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return ['', $body];
        }
        $at = (int) $m[0][1];

        return [trim(substr($body, 0, $at)), trim(substr($body, $at))];
    }

    /**
     * Zapytanie powstaje od razu, a analiza (czytanie maila, szukanie w katalogu, szkic listu) idzie w tle —
     * AnalyzeClientInquiryJob woła runQueuedAnalysis(). Przy 50 pozycjach analiza trwa kilka minut, a serwer
     * ucina tak długie żądania. Tu tylko to, co nie pyta modelu: odciski treści (duplikaty widać od razu),
     * nadawca i kontakt ze stopki, warunki z ostatniego zapytania.
     *
     * @param  array{message_id?: string|null, channel?: string|null, from?: string|null, sent_at?: string|null, file_name?: string|null, duplicate_of_id?: int|null}  $source
     */
    public function createPending(
        User $user,
        string $body,
        string $tone,
        ?int $clientId,
        ?string $subject,
        array $source = [],
    ): ClientInquiry {
        $channel = $this->nullable($source['channel'] ?? null) ?? 'web';
        $fromFile = self::hasFilePart($body, $channel);
        $threadSender = self::threadSender($this->nullable($source['from'] ?? null));
        $fingerprints = $this->fingerprints(self::analysisText($body, $channel, $this->nullable($subject), $threadSender));
        // Nadawca z nagłówka From i kontakt z odciętej stopki — obie rzeczy
        // pochodzą wprost z maila, nic tu nie jest domyślane.
        $sender = InquirySignature::splitFrom($this->nullable($source['from'] ?? null));
        // Plik nie ma stopki maila — „stopką” byłoby wszystko po nagłówku firmowym pisma.
        $mailPart = $fromFile ? self::splitAtFileMarker($body)[0] : $body;
        $contact = $mailPart === ''
            ? null
            : InquirySignature::extract($mailPart, $sender['email'], $this->nullable($subject), $threadSender);
        // Przed zapisem nowego wiersza: potem to on byłby „ostatnim zapytaniem” i warunki przepadłyby.
        $preferences = $this->lastPreferences($user);

        return ClientInquiry::query()->create([
            'user_id' => $user->id,
            'client_id' => $clientId,
            // klient wybrany w formularzu to wybór handlowca — nocne powiązania automatyczne go nie ruszają
            'client_link_source' => $clientId !== null ? InquiryClientLinker::SOURCE_MANUAL : null,
            'client_linked_at' => $clientId !== null ? CarbonImmutable::now() : null,
            'tone' => $tone,
            'source_channel' => $channel,
            // temat z ekstrakcji dopisuje analiza, gdy handlowiec żadnego nie podał
            'source_subject' => $this->nullable($subject),
            'source_message_id' => $this->normalizeMessageId($source['message_id'] ?? null),
            'source_fingerprint' => $fingerprints['full'],
            'source_fingerprint_tail' => $fingerprints['tail'],
            'duplicate_of_id' => isset($source['duplicate_of_id']) ? (int) $source['duplicate_of_id'] : null,
            'source_from_name' => $sender['name'],
            'source_from_email' => $sender['email'],
            'source_sent_at' => $this->parseSentAt($source['sent_at'] ?? null),
            'contact' => $contact,
            'source_body' => $body,
            'offer_terms' => $preferences['terms'] === [] ? null : $preferences['terms'],
            // nazwa pliku klienta, z którego wczytano treść (source_channel: file) — widać ją od razu
            'analysis' => $fromFile ? ['source_file_name' => $this->nullable($source['file_name'] ?? null)] : null,
            'analysis_status' => ClientInquiry::ANALYSIS_QUEUED,
            'analysis_run_id' => (string) Str::ulid(),
            'analysis_progress' => ['stage' => 'queued', 'done' => 0, 'total' => 0],
        ]);
    }

    /**
     * Przebieg analizy w tle. Każdy zapis (start, postęp, wynik, błąd) jest warunkowy na `analysis_run_id`:
     * zadanie dostarczone drugi raz, spóźnione po ponowieniu albo dla skasowanego zapytania niczego nie nadpisze.
     * Wynik idzie jednym zapisem na końcu (analiza, odpowiedzi, szkic listu i status razem) — do tej chwili
     * zapytanie jest zablokowane do zmian, więc handlowiec nie dostanie pustego listu ani nie straci poprawek.
     * Nie rzuca: błąd zostaje przy zapytaniu jako „failed” z komunikatem dla handlowca.
     */
    public function runQueuedAnalysis(int $inquiryId, string $runId): void
    {
        $claimed = ClientInquiry::query()
            ->whereKey($inquiryId)
            ->where('analysis_run_id', $runId)
            ->where('analysis_status', ClientInquiry::ANALYSIS_QUEUED)
            ->update([
                'analysis_status' => ClientInquiry::ANALYSIS_RUNNING,
                'analysis_started_at' => now(),
                'analysis_progress' => json_encode(['stage' => 'extract', 'done' => 0, 'total' => 0]),
                'analysis_error' => null,
            ]);
        if ($claimed !== 1) {
            return;
        }
        $inquiry = ClientInquiry::query()->with('user')->find($inquiryId);
        if (! $inquiry instanceof ClientInquiry || ! $inquiry->user instanceof User) {
            return;
        }

        try {
            // autor zapytania przygotowuje ofertę — od jego widoku ceny specjalnej liczą się kandydaci i list
            $this->withPriceMask(
                SupplierSpecialMask::forUser($inquiry->user),
                fn () => $this->runAnalysis($inquiry, $runId),
            );
        } catch (Throwable $e) {
            report($e);
            $this->markAnalysisFailed($inquiryId, $runId, $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Błąd analizy zapytania: '.$e->getMessage());
        }
    }

    /**
     * Błąd przebiegu przy zapytaniu — tylko gdy to wciąż ten przebieg i jeszcze się nie skończył.
     * Woła go też AnalyzeClientInquiryJob::failed() (limit czasu, worker zabity w trakcie).
     */
    public function markAnalysisFailed(int $inquiryId, string $runId, string $message): void
    {
        ClientInquiry::query()
            ->whereKey($inquiryId)
            ->where('analysis_run_id', $runId)
            ->whereIn('analysis_status', [ClientInquiry::ANALYSIS_QUEUED, ClientInquiry::ANALYSIS_RUNNING])
            ->update([
                'analysis_status' => ClientInquiry::ANALYSIS_FAILED,
                'analysis_error' => mb_substr(trim($message) !== '' ? trim($message) : 'Analiza zapytania nie powiodła się.', 0, 500),
                'analysis_finished_at' => now(),
            ]);
    }

    /**
     * Ponowienie analizy po błędzie albo po przerwanym przebiegu. Zwraca nowy identyfikator przebiegu, gdy ten
     * zapis go ustawił — podwójne kliknięcie ustawi go raz, więc zadanie idzie do kolejki raz. null = nie wolno.
     *
     * $includeDone (uprawnienie inquiries.reanalyze): także gotowa analiza — nowy wynik zastąpi pozycje, wybory
     * i szkic listu. Nigdy po wysłaniu odpowiedzi ani gdy list czeka na Thunderbirda (reanalysisBlocker()):
     * warunek siedzi też w zapisie, więc wysyłka zlecona w tej samej chwili wygrywa.
     */
    public function restartAnalysis(ClientInquiry $inquiry, bool $includeDone = false): ?string
    {
        $runId = (string) Str::ulid();
        $changed = ClientInquiry::query()
            ->whereKey($inquiry->id)
            ->where(function ($q) use ($includeDone): void {
                $q->where('analysis_status', ClientInquiry::ANALYSIS_FAILED)
                    ->orWhere(function ($stale): void {
                        $stale->where('analysis_status', ClientInquiry::ANALYSIS_RUNNING)
                            ->where('analysis_started_at', '<', now()->subMinutes(ClientInquiry::ANALYSIS_STALE_MINUTES));
                    });
                if ($includeDone) {
                    $q->orWhere(function ($done): void {
                        // zapytania sprzed analizy w tle nie mają statusu — effectiveAnalysisStatus() liczy je jako gotowe
                        $done->where(fn ($s) => $s->where('analysis_status', ClientInquiry::ANALYSIS_DONE)->orWhereNull('analysis_status'))
                            ->whereNull('replied_at')
                            ->whereNull('send_requested_at');
                    });
                }
            })
            ->update([
                'analysis_status' => ClientInquiry::ANALYSIS_QUEUED,
                'analysis_run_id' => $runId,
                'analysis_progress' => json_encode(['stage' => 'queued', 'done' => 0, 'total' => 0]),
                'analysis_error' => null,
                'analysis_started_at' => null,
                'analysis_finished_at' => null,
            ]);

        return $changed === 1 ? $runId : null;
    }

    /** Powód, dla którego gotowej analizy nie wolno już uruchomić od nowa; null = można. */
    public function reanalysisBlocker(ClientInquiry $inquiry): ?string
    {
        if ($inquiry->replied_at !== null) {
            return 'Odpowiedź na to zapytanie już wysłano.';
        }
        if ($inquiry->send_requested_at !== null) {
            return 'List czeka na wysłanie w Thunderbirdzie.';
        }

        return null;
    }

    /**
     * Powiadomienie autora: analiza zapisana (dzwonek / e-mail według jego preferencji). Raz na przebieg —
     * ponowiona analiza to nowy przebieg i nowe powiadomienie. Błąd powiadomienia nie psuje gotowej analizy.
     */
    private function notifyAnalysisReady(ClientInquiry $inquiry, User $user, string $runId, int $lineItems): void
    {
        try {
            $inquiry->loadMissing('client:id,name');
            $who = $this->nullable($inquiry->client?->name)
                ?? $this->nullable($inquiry->source_from_name)
                ?? $this->nullable($inquiry->source_from_email);
            $subject = $this->nullable($inquiry->source_subject);
            app(NotificationDispatcher::class)->send($user, new AppNotificationMessage(
                event: 'inquiry_analysis_ready',
                subjectKey: 'inquiry:'.$inquiry->id,
                title: 'Analiza zapytania jest gotowa',
                body: implode(' · ', array_filter([$who, $subject, 'pozycji w zapytaniu: '.$lineItems])),
                url: '/inquiries/'.$inquiry->id,
                data: ['inquiry_id' => (int) $inquiry->id],
            ), 'run:'.$runId);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Czytanie maila, szukanie w katalogu i szkic listu — w pamięci; zapis tylko w saveAnalysisResult(). */
    private function runAnalysis(ClientInquiry $inquiry, string $runId): void
    {
        $started = hrtime(true);
        /** @var User $user */
        $user = $inquiry->user;
        $body = (string) $inquiry->source_body;
        $subject = $this->nullable($inquiry->source_subject);
        $channel = $this->nullable($inquiry->source_channel);
        $fromFile = self::hasFilePart($body, $channel);
        // Limit z ustawień w chwili analizy — zapisany przy zapytaniu, bo panel może go potem zmienić.
        $this->maxItems = $this->aiSettings->inquiryMaxItems();
        $progress = $this->progressWriter((int) $inquiry->id, $runId);

        $threadSender = self::threadSender($inquiry->source_from_email);
        $analysisBody = self::analysisText($body, $channel, $subject, $threadSender);
        // Klient bywa pisze model w temacie („11-571”), a w treści tylko ilość i rozmiar.
        // Najpierw temat nadany przez klienta (z nagłówka przekazania), potem temat maila.
        // Z pliku: nagłówki przekazania czytamy tylko z maila wklejonego nad treścią pliku.
        $mailPart = $fromFile ? self::splitAtFileMarker($body)[0] : $body;
        $forwardedSubject = $mailPart === '' ? null : InquiryMailText::forwardedSubject($mailPart, $subject, $threadSender);
        $subjectHint = InquiryQueryText::subjectProductHint($forwardedSubject)
            ?? InquiryQueryText::subjectProductHint($subject);
        $extractStarted = hrtime(true);
        $extractSubject = $forwardedSubject ?? $subject;
        $extracted = $this->extract($analysisBody, $extractSubject);
        $extractMs = self::msSince($extractStarted);
        $resolved = $this->resolveLineItemsWithOmitted($analysisBody, $extracted['line_items'], $subjectHint, $extractSubject);
        $lineItems = $resolved['items'];
        // karta modelu przy pozycji, którą scaliliśmy z sumą albo rozmiarami, idzie do tej, która została
        foreach ($extracted['cards'] as $i => $card) {
            $target = $resolved['merged_ids'][trim((string) ($card['item_id'] ?? ''))] ?? null;
            if ($target !== null) {
                $extracted['cards'][$i]['item_id'] = $target;
            }
        }
        // Link w pozycji wskazuje kartę wprost — ten sam adres zapisał przy karcie łącznik B2B.
        $linked = app(InquiryProductLinks::class)->attach($lineItems, $analysisBody);
        $lineItems = $linked['items'];
        $queries = $this->uniqueQueries(
            $lineItems,
            // mail bez żadnej pozycji („Proszę o ofertę”) — szukamy przynajmniej wyrobu z tematu
            $lineItems === [] && $extracted['product_queries'] === [] && $subjectHint !== null
                ? [$subjectHint]
                : array_map(InquiryLinks::withoutUrls(...), $extracted['product_queries'])
        );
        $progress(ProductAiSearchService::PROGRESS_STAGE_UNDERSTAND, 0, 0, count($lineItems), true);
        $this->searchRounds = [];
        $this->pendingSearchEvents = [];
        $this->searchProgress = static fn (string $stage, int $done, int $total) => $progress($stage, $done, $total, count($lineItems));
        $searchStarted = hrtime(true);
        try {
            $matches = $this->matchInRounds($lineItems, $queries);
        } finally {
            $this->searchProgress = null;
        }
        $searchMs = self::msSince($searchStarted);
        $progress('reply', 0, 0, count($lineItems), true);
        $substitutes = $this->loadSubstitutes($matches);
        $cards = $this->buildCards($extracted['cards'], $matches, $lineItems, $substitutes);
        $margin = $user->defaultMarginPercent();

        $inquiry->forceFill([
            'source_subject' => $subject ?? $extracted['subject'],
            'analysis' => [
                'subject' => $extracted['subject'],
                // Ślad audytowy: co dokładnie poszło do modelu, gdy mail był cięty.
                'analyzed_body' => $analysisBody === $body ? null : $analysisBody,
                // nazwa pliku klienta, z którego wczytano treść (source_channel: file)
                'source_file_name' => $fromFile ? $this->nullable($inquiry->analysis['source_file_name'] ?? null) : null,
                // wyrób z tematu maila, dopisany do szukania pozycji bez nazwy (query_source: subject)
                'subject_hint' => $subjectHint,
                // limit pozycji, z którym liczyła ta analiza (ustawienie w Strojeniu AI)
                'max_items' => $this->maxLineItems(),
                'questions' => $extracted['questions'],
                'product_queries' => $queries,
                'line_items' => $lineItems,
                // wiersze maila poza pozycjami (ponad limit albo niepewne pokrycie) — handlowiec dopisuje je ręcznie
                'omitted_items' => $resolved['omitted'],
                'matches' => $matches,
                // karty wskazane linkiem z maila, po pozycjach (InquiryProductLinks)
                'link_candidates' => $this->linkCandidates($linked['products']),
                'substitutes' => $substitutes,
                'cards' => $cards,
                'margin_used' => $margin,
            ],
        ]);
        // Pracownik ma od razu zobaczyć gotowy list: domyślne decyzje + szkic (jak saveReply(), bez zapisu).
        $answers = $this->defaultAnswers($inquiry, 'catalog_margin', $margin);
        $analysis = $inquiry->analysis;
        $analysis['margin_used'] = $this->marginPercent($answers);
        $inquiry->forceFill(['analysis' => $analysis, 'answers' => $answers, 'extra_note' => null]);
        $draft = $this->writeReply($inquiry, $answers, null);

        $saved = DB::transaction(function () use ($inquiry, $runId, $answers, $draft): bool {
            $row = ClientInquiry::query()->lockForUpdate()->find($inquiry->id);
            if (! $row instanceof ClientInquiry
                || $row->analysis_run_id !== $runId
                || $row->analysis_status !== ClientInquiry::ANALYSIS_RUNNING) {
                return false;
            }
            $row->forceFill([
                'source_subject' => $inquiry->source_subject,
                'analysis' => $inquiry->analysis,
                'answers' => $answers,
                'extra_note' => null,
                'reply_subject' => $draft['subject'],
                'reply_body' => $draft['body'],
                'reply_html' => $draft['html'],
                'analysis_status' => ClientInquiry::ANALYSIS_DONE,
                'analysis_progress' => null,
                'analysis_error' => null,
                'analysis_finished_at' => now(),
            ])->save();

            return true;
        });
        if (! $saved) {
            // przebieg zastąpiony albo zapytanie skasowane — statystyka wyszukiwań przepada razem z nim
            $this->pendingSearchEvents = [];

            return;
        }
        $this->flushSearchEvents((int) $inquiry->id, (int) $user->id);

        // Gdzie idzie czas analizy (#71 MESKO, 24.09.2026: 160 s, a z dat w bazie dało się
        // odtworzyć tylko dwa odcinki). `other` = parser pozycji, linki, zamienniki i zapis listu.
        $totalMs = self::msSince($started);
        Log::info('client-inquiry.timings', [
            'inquiry_id' => $inquiry->id,
            'line_items' => count($lineItems),
            'timings_ms' => [
                'extract' => $extractMs,
                'search' => $searchMs,
                'other' => max(0, $totalMs - $extractMs - $searchMs),
                'total' => $totalMs,
            ],
            'search_rounds' => $this->searchRounds,
        ]);

        // po pomiarze czasów: wysyłka powiadomienia (z e-mailem przez SMTP) nie wlicza się do czasu analizy
        $this->notifyAnalysisReady($inquiry, $user, $runId, count($lineItems));
    }

    /**
     * Zapis postępu przebiegu: najwyżej co 2 s (wyszukiwanie zgłasza każdą ocenioną kartę), zawsze przy zmianie
     * etapu. Warunkowy na przebieg i status — nie wskrzesza skasowanego zapytania, nie psuje nowszego przebiegu.
     *
     * @return callable(string, int, int, int, bool=): void
     */
    private function progressWriter(int $inquiryId, string $runId): callable
    {
        $last = ['stage' => null, 'at' => 0.0];

        return static function (string $stage, int $done, int $total, int $items, bool $force = false) use ($inquiryId, $runId, &$last): void {
            $now = microtime(true);
            if (! $force && $stage === $last['stage'] && $now - $last['at'] < 2.0 && $done < $total) {
                return;
            }
            $last = ['stage' => $stage, 'at' => $now];
            ClientInquiry::query()
                ->whereKey($inquiryId)
                ->where('analysis_run_id', $runId)
                ->where('analysis_status', ClientInquiry::ANALYSIS_RUNNING)
                ->update(['analysis_progress' => json_encode(
                    ['stage' => $stage, 'done' => $done, 'total' => $total, 'items' => $items],
                )]);
        };
    }

    /**
     * Przysłane odpowiedzi nadpisują zapisane; brak klucza = decyzja domyślna.
     * `extraNote` false = nie ruszaj zapisanego dopisku (klucza nie było w żądaniu).
     *
     * @param  array<string, array{option_id: string, custom?: string|null}>  $answers
     */
    public function compose(
        ClientInquiry $inquiry,
        array $answers,
        string|false|null $extraNote,
        ?string $tone = null,
        array|false $terms = false,
    ): ClientInquiry {
        $saved = is_array($inquiry->answers) ? $inquiry->answers : [];
        $merged = array_merge($saved, $answers);
        foreach ($answers as $key => $answer) {
            if (! str_starts_with((string) $key, self::MANUAL_PRICE_PREFIX)) {
                continue;
            }
            // Puste pole kasuje cenę ręczną; zapisujemy kwotę w jednym zapisie,
            // żeby list i panel czytały tę samą liczbę.
            $pln = OfferPricing::plnFromInput(is_array($answer) ? ($answer['custom'] ?? null) : null);
            if ($pln === null) {
                unset($merged[$key]);
            } else {
                $merged[$key] = [
                    'option_id' => (string) ($answer['option_id'] ?? ''),
                    'custom' => number_format($pln, 2, '.', ''),
                ];
            }
        }
        foreach ($this->defaultAnswers($inquiry, $this->priceModeOf($merged), $this->marginPercent($merged)) as $key => $answer) {
            $merged[$key] ??= $answer;
        }
        // Warunki oferty wchodzą do listu, więc muszą być zapisane przed jego złożeniem.
        // `false` = klucza nie było w żądaniu, czyli zostawiamy zapisane.
        if ($terms !== false) {
            $inquiry->forceFill(['offer_terms' => $this->offerTerms($terms)]);
        }

        return $this->saveReply(
            $inquiry,
            $merged,
            $extraNote === false ? $this->nullable($inquiry->extra_note) : $this->nullable($extraNote),
            $tone,
        );
    }

    /**
     * Wyrób wyszukany ręcznie przez handlowca: dopisujemy go do kandydatów tej
     * pozycji i od razu czynimy jej propozycją. Oceny dopasowania nie wpisujemy —
     * tego wyrobu nikt do tej pozycji nie oceniał, a liczba udawałaby werdykt modelu.
     *
     * `extraNote`/`terms` jak w compose(): false = zostaw zapisane.
     *
     * @param  array<string, mixed>|false  $terms
     *
     * `actor` — widok ceny specjalnej B2B handlowca, który wybiera wyrób (autor zapytania): od niego cena wiersza
     * i list.
     *
     * @throws RuntimeException gdy pozycji albo wyrobu nie ma
     */
    public function pickProduct(
        ClientInquiry $inquiry,
        string $itemId,
        int $productId,
        SupplierSpecialMask $actor,
        string|false|null $extraNote = false,
        array|false $terms = false,
    ): ClientInquiry {
        return $this->withPriceMask($actor, fn (): ClientInquiry => $this->pickProductMasked($inquiry, $itemId, $productId, $extraNote, $terms));
    }

    /**
     * @param  array<string, mixed>|false  $terms
     */
    private function pickProductMasked(
        ClientInquiry $inquiry,
        string $itemId,
        int $productId,
        string|false|null $extraNote,
        array|false $terms,
    ): ClientInquiry {
        $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];
        $item = null;
        foreach ($this->lineItemsOf($analysis) as $one) {
            if ((string) ($one['id'] ?? '') === $itemId) {
                $item = $one;
                break;
            }
        }
        if ($item === null) {
            throw new RuntimeException('Nie ma takiej pozycji w tym zapytaniu.');
        }

        $product = Product::query()->find($productId);
        if ($product === null) {
            throw new RuntimeException('Nie ma takiego wyrobu w katalogu.');
        }
        $safe = $this->safeProduct([
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'manufacturer' => $product->manufacturer,
            'norms' => $product->norms,
            'catalog_price_net' => $product->catalog_price_net,
            'purchase_price' => $product->purchase_price,
            'currency' => $product->currency ?? 'PLN',
            'stock' => $product->stock,
            // skąd wiersz: wybór człowieka, nie wyszukiwarka
            'ai_match_source' => 'manual',
        ]);
        if ($safe === null) {
            throw new RuntimeException('Wyrób bez kodu albo nazwy nie może trafić do oferty.');
        }

        $candidates = $this->candidatesForItem($this->matchGroups($analysis), $item, $analysis);
        if ($this->candidateById($candidates, 'p:'.$safe['id']) === null) {
            $manual = $this->manualCandidates($analysis, $itemId);
            $manual[] = $safe;
            // Najstarsze ręczne wypadają; właśnie dodany zostaje, bo staje się propozycją.
            $byItem = is_array($analysis['manual_candidates'] ?? null) ? $analysis['manual_candidates'] : [];
            $byItem[$itemId] = array_slice($manual, -self::MAX_MANUAL_CANDIDATES);
            $analysis['manual_candidates'] = $byItem;
            $inquiry->forceFill(['analysis' => $analysis])->save();
        }

        return $this->compose(
            $inquiry,
            ['product:'.$itemId => ['option_id' => 'p:'.$safe['id']]],
            $extraNote,
            null,
            $terms,
        );
    }

    /**
     * Ponowne szukanie w katalogu dla zapisanego zapytania — tymi samymi frazami
     * (`analysis.product_queries`) i tą samą drogą co runAnalysis(). Na to, że wynik
     * z chwili analizy bywa zły: model nie odpowiadał albo wyszukiwarka miała błąd
     * (24.09.2026, zapytania #64, #65, #67 z „brak w katalogu” dla wyrobów z katalogu).
     *
     * Wymieniamy `matches` i `substitutes`; karty, wyroby dobrane ręcznie i pytania
     * zostają. Linki z maila liczymy od nowa (relinked()): zapytania sprzed rozpoznawania
     * linków mają adres strony we frazie i nie mają kart z linku (#69). Decyzje handlowca
     * zostają — zmienia się tylko „do sprawdzenia” przy pozycji, która przed ponownym
     * szukaniem nie miała żadnego kandydata: tam nikt niczego nie wybierał, więc wybór
     * liczymy od nowa.
     *
     * Bez `$apply` nic nie zapisujemy — ale wyszukiwarka i model są pytane naprawdę.
     *
     * @return array{
     *     inquiry_id: int,
     *     skipped: string|null,
     *     warnings: list<string>,
     *     items: list<array{id: string, before: array{sku: string, name: string, score: int, link: bool}|null, after: array{sku: string, name: string, score: int, link: bool}|null, answer_before: string|null, answer_after: string|null}>
     * }
     */
    public function rematch(ClientInquiry $inquiry, bool $apply): array
    {
        // Ofertę przygotowuje autor zapytania — także z polecenia CLI, gdzie nikt nie jest zalogowany. Autor
        // bez konta (usunięty) = widok ukrywający.
        return $this->withPriceMask(
            SupplierSpecialMask::forUser($inquiry->loadMissing('user')->user),
            fn (): array => $this->rematchMasked($inquiry, $apply),
        );
    }

    /**
     * @return array{inquiry_id: int, skipped: string|null, warnings: list<string>, items: list<array<string, mixed>>}
     */
    private function rematchMasked(ClientInquiry $inquiry, bool $apply): array
    {
        $report = ['inquiry_id' => (int) $inquiry->id, 'skipped' => null, 'warnings' => [], 'items' => []];
        $blocker = $this->rematchBlocker($inquiry);
        if ($blocker !== null) {
            $report['skipped'] = $blocker;

            return $report;
        }

        // Pozycje i ich frazy zapisuje tylko analiza, więc wynik z tego odczytu obowiązuje
        // też w transakcji niżej.
        $relinked = $this->relinked($inquiry);
        if ($relinked['queries'] === []) {
            $report['skipped'] = 'brak fraz wyszukiwania po wycięciu adresów stron';

            return $report;
        }

        // Szukanie trwa (model ocenia karty), więc idzie przed jakimkolwiek zapisem.
        // Rundy liczy tylko log analizy — polecenie przelicza wiele zapytań jedną usługą.
        $this->searchRounds = [];
        $this->pendingSearchEvents = [];
        $matches = $this->matchInRounds($relinked['items'], $relinked['queries']);
        $substitutes = $this->loadSubstitutes($matches);
        $rematchedAt = CarbonImmutable::now()->toIso8601String();

        if (! $apply) {
            // Podgląd obiecuje „nic się nie zapisuje” (polecenie bywa uruchamiane na produkcji do odczytu) —
            // zdarzeń wyszukiwania też nie zapisujemy.
            $this->pendingSearchEvents = [];
            $plan = $this->rematchPlan($inquiry, $relinked['analysis'], $matches, $substitutes, $rematchedAt);

            return array_merge($report, ['warnings' => $plan['warnings'], 'items' => $plan['items']]);
        }
        // Zapis przed transakcją, żeby statystyka nie mieszała się z zapisem zapytania.
        $this->flushSearchEvents((int) $inquiry->id, auth()->id() !== null ? (int) auth()->id() : null);

        return DB::transaction(function () use ($inquiry, $relinked, $matches, $substitutes, $rematchedAt, $report): array {
            // Handlowiec mógł w tym czasie wysłać list albo coś wybrać — decyduje stan z bazy.
            $fresh = ClientInquiry::query()->lockForUpdate()->find($inquiry->id);
            if ($fresh === null) {
                $report['skipped'] = 'zapytanie usunięte w trakcie szukania';

                return $report;
            }
            $blocker = $this->rematchBlocker($fresh);
            if ($blocker !== null) {
                $report['skipped'] = $blocker;

                return $report;
            }

            $plan = $this->rematchPlan($fresh, $relinked['analysis'], $matches, $substitutes, $rematchedAt);
            $fresh->forceFill(['analysis' => $plan['analysis'], 'answers' => $plan['answers']])->save();
            // Ta sama droga co zmiana wyboru na ekranie: brakujące odpowiedzi dostają
            // wybór domyślny, dopisek i warunki oferty zostają.
            $saved = $this->compose($fresh, [], false, null, false);

            $answers = is_array($saved->answers) ? $saved->answers : [];
            foreach ($plan['items'] as $i => $row) {
                $plan['items'][$i]['answer_after'] = $this->chosenOptionFor(
                    $plan['after_items'][$row['id']]['item'],
                    $plan['after_items'][$row['id']]['candidates'],
                    $answers,
                );
            }

            return array_merge($report, ['warnings' => $plan['warnings'], 'items' => $plan['items']]);
        });
    }

    /** Powód, dla którego zapytania nie wolno już przeliczać; null = można. */
    private function rematchBlocker(ClientInquiry $inquiry): ?string
    {
        if (! $inquiry->isAnalyzed()) {
            return 'analiza zapytania jeszcze trwa albo się nie powiodła';
        }
        if ($inquiry->replied_at !== null) {
            return 'odpowiedź już wysłana';
        }
        if ($inquiry->send_requested_at !== null) {
            return 'list czeka na wysłanie';
        }
        // Bez zapisanych fraz szukanie dałoby pustą listę i skasowało to, co było.
        if ($this->stringList($inquiry->analysis['product_queries'] ?? null) === []) {
            return 'brak zapisanych fraz wyszukiwania';
        }
        // Ręczna poprawka treści kasuje tabelę HTML, a zapis składa list od nowa — poprawki handlowca
        // by przepadły. Takie zapytanie poprawia się przy pozycji („Szukaj AI”), nie hurtem.
        if (trim((string) $inquiry->reply_body) !== '' && $inquiry->reply_html === null) {
            return 'list poprawiony ręcznie — nowy wynik nadpisałby poprawki; użyj „Szukaj AI” przy pozycji';
        }

        return null;
    }

    /**
     * Linki z maila przy zapisanych pozycjach, liczone tak jak w runAnalysis(): karty spod adresu
     * (`link_candidates`), fraza pozycji bez adresu i z nazwą karty, `product_queries` bez adresów.
     * Zapytania sprzed rozpoznawania linków (#69) szukały frazą z adresem strony, a jego słowa
     * pasowały do całej rodziny (ROLEX 1 po 99%, klient wskazał ROLEX 5).
     *
     * Frazy przeliczamy tylko wtedy, gdy zmieniła się pozycja albo któraś fraza ma adres —
     * pozostałe zapytania szukają dokładnie tym, co zapisała analiza.
     *
     * @return array{queries: list<string>, items: list<array<string, mixed>>, analysis: array<string, mixed>}
     */
    private function relinked(ClientInquiry $inquiry): array
    {
        $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];
        $stored = $this->stringList($analysis['product_queries'] ?? null);
        $items = array_values(array_filter(
            is_array($analysis['line_items'] ?? null) ? $analysis['line_items'] : [],
            'is_array',
        ));
        // runAnalysis() zapisuje przycięty mail tylko wtedy, gdy różni się od całego
        $body = is_string($analysis['analyzed_body'] ?? null) ? $analysis['analyzed_body'] : (string) $inquiry->source_body;
        $linked = app(InquiryProductLinks::class)->attach($items, $body);

        $patch = [];
        if ($linked['products'] !== [] || array_key_exists('link_candidates', $analysis)) {
            $patch['link_candidates'] = $this->linkCandidates($linked['products']);
        }
        $withUrl = array_filter($stored, static fn (string $query): bool => InquiryLinks::extract($query) !== []);
        if ($linked['items'] === $items && $withUrl === []) {
            return ['queries' => $stored, 'items' => $items, 'analysis' => $patch];
        }

        // W zapisie stoją stare klucze wyszukiwania pozycji — zastępują je nowe z uniqueQueries() —
        // i frazy modelu, które w runAnalysis() idą bez adresów. Klucz równy frazie pozycji z modelu
        // bywa też frazą modelu (model podaje te same), a jego grupa jest zapasem pozycji
        // (groupsForItem), więc zostaje.
        $itemKeys = [];
        $itemQueries = [];
        foreach ($items as $item) {
            $itemKeys[mb_strtolower(trim($this->itemSearchKey($item)))] = true;
            $itemQueries[mb_strtolower(trim((string) ($item['query'] ?? '')))] = true;
        }
        $modelQueries = [];
        foreach ($stored as $query) {
            $lower = mb_strtolower(trim($query));
            if (! isset($itemKeys[$lower]) || isset($itemQueries[$lower])) {
                $modelQueries[] = InquiryLinks::withoutUrls($query);
            }
        }
        $queries = $this->uniqueQueries($linked['items'], $modelQueries);

        return [
            'queries' => $queries,
            'items' => $linked['items'],
            'analysis' => [...$patch, 'line_items' => $linked['items'], 'product_queries' => $queries],
        ];
    }

    /**
     * Stan po ponownym szukaniu, liczony w pamięci: nowa analiza, odpowiedzi po zdjęciu
     * „do sprawdzenia” bez wyboru i raport pozycji. `answer_after` odtwarza to, co
     * zrobi compose() — brakującą odpowiedź zastępuje wybór domyślny.
     *
     * @param  array<string, mixed>  $relinked  pola analizy przeliczone z linków (relinked())
     * @param  list<array{query: string, products: list<array<string, mixed>>}>  $matches
     * @param  array<int, list<array<string, mixed>>>  $substitutes
     * @return array{
     *     analysis: array<string, mixed>,
     *     answers: array<string, mixed>,
     *     warnings: list<string>,
     *     items: list<array{id: string, before: array{sku: string, name: string, score: int, link: bool}|null, after: array{sku: string, name: string, score: int, link: bool}|null, answer_before: string|null, answer_after: string|null}>,
     *     after_items: array<string, array{item: array<string, mixed>, candidates: list<array<string, mixed>>}>
     * }
     */
    private function rematchPlan(ClientInquiry $inquiry, array $relinked, array $matches, array $substitutes, string $rematchedAt): array
    {
        $old = is_array($inquiry->analysis) ? $inquiry->analysis : [];
        $new = array_merge($old, $relinked, [
            'matches' => $matches,
            'substitutes' => $substitutes,
            // ślad audytowy: kiedy wynik szukania zastąpił ten z chwili analizy
            'rematched_at' => $rematchedAt,
        ]);
        $answers = is_array($inquiry->answers) ? $inquiry->answers : [];
        $kept = $answers;
        $oldGroups = $this->matchGroups($old);
        $newGroups = $this->matchGroups($new);
        // „Było” liczymy starą pozycją: jej stara fraza jest kluczem starej grupy wyników.
        $oldItems = [];
        foreach ($this->lineItemsOf($old) as $item) {
            $oldItems[(string) $item['id']] = $item;
        }

        $warnings = [];
        $rows = [];
        foreach ($this->lineItemsOf($new) as $item) {
            $itemId = (string) $item['id'];
            $key = 'product:'.$itemId;
            $oldItem = $oldItems[$itemId] ?? $item;
            $before = $this->candidatesForItem($oldGroups, $oldItem, $old);
            $after = $this->candidatesForItem($newGroups, $item, $new);
            $saved = trim((string) ($answers[$key]['option_id'] ?? ''));
            // „Do sprawdzenia” przy pustej liście nie było wyborem — nie było z czego wybrać.
            if (in_array($saved, ['check', 'category'], true) && $before === []) {
                unset($kept[$key]);
            }
            if (str_starts_with($saved, 'p:') && $this->candidateById($before, $saved) !== null
                && $this->candidateById($after, $saved) === null) {
                $warnings[] = "{$itemId}: wybrany wyrób {$saved} nie jest już kandydatem — pozycja dostanie wybór domyślny.";
            }
            // Zapisany wybór zostaje, choć klient wskazał linkiem inną kartę (stare zapytania
            // wybierały bez linków, #69) — decyzję zmienia handlowiec przy pozycji.
            $chosen = $this->candidateById($after, $saved);
            if (($after[0]['source'] ?? null) === 'link' && ($before[0]['source'] ?? null) !== 'link'
                && $chosen !== null && ($chosen['source'] ?? null) !== 'link') {
                $warnings[] = "{$itemId}: link z zapytania wskazuje {$after[0]['sku']}, a zapisany wybór {$saved} ({$chosen['sku']}) zostaje — zmień go przy pozycji.";
            }
            $rows[$itemId] = ['item' => $item, 'old_item' => $oldItem, 'before' => $before, 'after' => $after];
        }

        // Jak compose(): brakującą odpowiedź zastępuje domyślna z nowych kandydatów.
        $simulated = $kept;
        foreach ($rows as $itemId => $row) {
            $simulated['product:'.$itemId] ??= ['option_id' => $this->defaultOptionFor($row['item'], $row['after'])];
        }

        $items = [];
        $afterItems = [];
        foreach ($rows as $itemId => $row) {
            $items[] = [
                'id' => (string) $itemId,
                'before' => $this->rematchTop($row['before']),
                'after' => $this->rematchTop($row['after']),
                // wybór widoczny na ekranie i w liście, nie surowy klucz z `answers`
                'answer_before' => $this->chosenOptionFor($row['old_item'], $row['before'], $answers),
                'answer_after' => $this->chosenOptionFor($row['item'], $row['after'], $simulated),
            ];
            $afterItems[(string) $itemId] = ['item' => $row['item'], 'candidates' => $row['after']];
        }

        return [
            'analysis' => $new,
            'answers' => $kept,
            'warnings' => $warnings,
            'items' => $items,
            'after_items' => $afterItems,
        ];
    }

    /**
     * Pierwszy kandydat pozycji tak, jak stoi na ekranie.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return array{sku: string, name: string, score: int, link: bool}|null
     */
    private function rematchTop(array $candidates): ?array
    {
        $top = $candidates[0] ?? null;
        if ($top === null) {
            return null;
        }

        return [
            'sku' => (string) ($top['sku'] ?? ''),
            'name' => (string) ($top['name'] ?? ''),
            'score' => (int) ($top['score'] ?? 0),
            // karta spod adresu z maila nie ma oceny modelu — jej 0 to nie wynik
            'link' => ($top['source'] ?? null) === 'link',
        ];
    }

    /**
     * Warunki oferty w kolejności z modelu, bez pustych. Puste pole znaczy „nie wiem”
     * i do listu nie idzie — zdanie „Termin realizacji:” bez treści czytałoby się jak
     * pomyłka, a dopisanie czegokolwiek byłoby obietnicą, której nikt nie złożył.
     *
     * @param  array<string, mixed>  $terms
     * @return array<string, string>|null
     */
    private function offerTerms(array $terms): ?array
    {
        $out = [];
        foreach (array_keys(ClientInquiry::OFFER_TERMS) as $key) {
            $value = $this->nullable($terms[$key] ?? null);
            if ($value !== null) {
                $out[$key] = mb_substr($value, 0, 200);
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * Zapisane warunki oferty tego zapytania.
     *
     * @return array<string, string>
     */
    private function termsOf(ClientInquiry $inquiry): array
    {
        $saved = is_array($inquiry->offer_terms) ? $inquiry->offer_terms : [];

        return $this->offerTerms($saved) ?? [];
    }

    /**
     * @param  array<string, array{option_id: string, custom?: string|null}>  $answers
     */
    private function saveReply(
        ClientInquiry $inquiry,
        array $answers,
        ?string $extraNote,
        ?string $tone = null,
    ): ClientInquiry {
        $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];
        $analysis['margin_used'] = $this->marginPercent($answers);

        $inquiry->forceFill([
            'analysis' => $analysis,
            'answers' => $answers,
            'extra_note' => $extraNote,
        ]);
        // Szablon zmieniony na stronie odpowiedzi: zapisujemy go przed pisaniem
        // listu, bo z niego bierze się kształt każdej pozycji.
        if ($tone !== null && in_array($tone, ClientInquiry::TONES, true)) {
            $inquiry->forceFill(['tone' => $tone]);
        }

        $draft = $this->writeReply($inquiry, $answers, $extraNote);

        $inquiry->forceFill([
            'reply_subject' => $draft['subject'],
            'reply_body' => $draft['body'],
            'reply_html' => $draft['html'],
        ])->save();

        return $inquiry->fresh(['client']) ?? $inquiry;
    }

    /**
     * Ustawienia, z którymi startuje nowa odpowiedź. Szablon i warunki pochodzą
     * z ostatniego zapytania użytkownika. Cena zawsze startuje jako cena oferty
     * (zakup + marża) z domyślną marżą z konta — handlowiec zmienia ją na stronie
     * odpowiedzi tylko dla tego jednego listu.
     *
     * @return array{tone: string, price_mode: string, margin: float, terms: array<string, string>}
     */
    public function lastPreferences(User $user): array
    {
        $last = ClientInquiry::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();
        // Bez historii bierzemy pełną specyfikację: tak wyglądały listy, które
        // handlowcy wysyłali do tej pory, więc pierwszy list nie zmienia formy.
        $tone = $last !== null && in_array($last->tone, ClientInquiry::TONES, true)
            ? (string) $last->tone
            : ClientInquiry::TONE_HANDLOWY;

        return [
            'tone' => $tone,
            'price_mode' => 'catalog_margin',
            // Świeżo z bazy: model dopiero co utworzony w pamięci nie zna jeszcze
            // wartości domyślnej kolumny (18%) i dostałby marżę z konfiguracji.
            'margin' => ($user->fresh() ?? $user)->defaultMarginPercent(),
            // Warunki bywają te same przy kolejnych ofertach — podpowiadamy ostatnie,
            // żeby handlowiec ich nie przepisywał. Zmienić może je przy każdym liście.
            'terms' => $last === null ? [] : $this->termsOf($last),
        ];
    }

    /**
     * Zapytanie założone już przez tę osobę z tego samego maila — chroni przed
     * powtórną, kosztowną analizą przy drugim kliknięciu w dodatku.
     */
    public function existingForMessage(User $user, ?string $messageId): ?ClientInquiry
    {
        $normalized = $this->normalizeMessageId($messageId);
        if ($normalized === null) {
            return null;
        }

        return ClientInquiry::query()
            ->where('user_id', $user->id)
            ->where('source_message_id', $normalized)
            ->latest('id')
            ->first();
    }

    /**
     * Zapytania powstałe z podanych maili — dla dodatku do Thunderbirda, który
     * oznacza nimi pozycje na liście wiadomości u wszystkich handlowców.
     *
     * Dopasowanie idzie wyłącznie po Message-ID: jest pewne (ten sam mail
     * wysłany na kilka adresów albo przekierowany przez serwer zachowuje
     * identyfikator) i nie wymaga wysyłania treści maili na serwer. Mail
     * przekazany ręcznie ma inny Message-ID — ten przypadek łapie odcisk treści
     * przy zakładaniu zapytania (findOthersInquiry), nie to oznaczanie.
     *
     * Brak klucza w wyniku znaczy „sprawdzone, nie ma nic” — dodatek zdejmuje
     * wtedy swoje oznaczenie. Inaczej po usunięciu zapytania znacznik zostałby
     * na mailu na zawsze i kłamał.
     *
     * Jeden mail może mieć kilka zapytań (świadome „Załóż mimo to”), więc pod
     * każdym identyfikatorem jest lista — od najstarszego, czyli od osoby,
     * która zaczęła. Przy obcinaniu nadmiaru nigdy nie wypada zapytanie
     * pytającego ani takie z wysłaną odpowiedzią: to dwie informacje, dla
     * których całe oznaczanie istnieje.
     *
     * @param  list<string>  $messageIds
     * @return array<string, list<array<string, mixed>>>
     */
    public function byMessageIds(User $user, array $messageIds, int $perMessage = 5): array
    {
        $normalized = [];
        foreach ($messageIds as $raw) {
            $id = $this->normalizeMessageId($raw);
            if ($id !== null) {
                $normalized[$id] = true;
            }
        }
        if ($normalized === []) {
            return [];
        }

        // Porównanie w MySQL nie zważa na wielkość liter, a Message-ID formalnie
        // ją rozróżnia. Dlatego wynik kluczujemy zapisem, o który pytał dodatek,
        // a nie zapisem z bazy: inaczej przy różnicy w wielkości liter dodatek
        // nie znalazłby swojego klucza i uznałby mail za nieobrabiany.
        // Testy chodzą na SQLite (porównanie wrażliwe na wielkość liter), więc
        // ten przypadek widać dopiero na produkcyjnym MySQL-u.
        $asked = [];
        foreach (array_keys($normalized) as $id) {
            $asked[mb_strtolower((string) $id, 'UTF-8')] = (string) $id;
        }

        $rows = ClientInquiry::query()
            ->select(['id', 'user_id', 'source_message_id', 'replied_at', 'created_at'])
            ->whereIn('source_message_id', array_keys($normalized))
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->groupBy(function (ClientInquiry $row) use ($asked): string {
                $key = mb_strtolower((string) $row->source_message_id, 'UTF-8');

                return $asked[$key] ?? (string) $row->source_message_id;
            });

        $found = [];
        foreach ($rows as $messageId => $group) {
            // Co musi się zmieścić w wyniku: wysłana odpowiedź (grozi drugą
            // ofertą u klienta) i własne zapytanie pytającego (inaczej dodatek
            // pomalowałby mail jako cudzy). Reszta dobierana od najstarszej.
            $important = fn (ClientInquiry $row): bool => $row->replied_at !== null
                || (int) $row->user_id === (int) $user->id;

            $must = $group->filter($important)->take($perMessage);
            $room = $perMessage - $must->count();
            $picked = $room > 0
                ? $must->concat($group->reject($important)->take($room))
                : $must;

            $found[(string) $messageId] = $picked
                ->sortBy('id')
                ->map(fn (ClientInquiry $row): array => [
                    'id' => $row->id,
                    'user' => $row->user === null
                        ? null
                        : ['id' => $row->user->id, 'name' => $row->user->name],
                    'mine' => (int) $row->user_id === (int) $user->id,
                    'created_at' => $row->created_at?->toIso8601String(),
                    'replied_at' => $row->replied_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        return $found;
    }

    /**
     * Message-ID zapytań ruszonych po podanej chwili — z tego dodatek dowiaduje
     * się, które maile ma sprawdzić, gdy właśnie go zainstalowano albo gdy
     * komputer był wyłączony.
     *
     * Zwracamy same identyfikatory, bo pełny obraz maila (ile zapytań, kto,
     * czy odpowiedź poszła) dodatek i tak bierze potem z byMessageIds — jeden
     * wiersz z tej listy nie wystarczyłby, gdy nad mailem siedzą dwie osoby.
     *
     * @return array{ids: list<string>, next_since: string, has_more: bool}
     */
    public function messageIdsTouchedSince(?CarbonImmutable $since, int $limit = 500): array
    {
        $from = $since ?? CarbonImmutable::now()->subDays(90);

        $rows = ClientInquiry::query()
            ->select(['id', 'source_message_id', 'updated_at'])
            ->whereNotNull('source_message_id')
            ->where('updated_at', '>=', $from)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $ids = $rows
            ->pluck('source_message_id')
            ->filter()
            ->unique()
            ->values()
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $hasMore = $rows->count() >= $limit;
        $last = $rows->last();

        return [
            'ids' => $ids,
            // Przy urwanej liście wracamy od ostatniego wiersza (ta sama chwila
            // może się powtórzyć — powtórne sprawdzenie maila nic nie psuje).
            'next_since' => $hasMore && $last !== null
                ? (string) $last->updated_at?->toIso8601String()
                : CarbonImmutable::now()->toIso8601String(),
            'has_more' => $hasMore,
        ];
    }

    /** Message-ID bez nawiasów „< >”, żeby porównanie nie zależało od zapisu. */
    public function normalizeMessageId(mixed $value): ?string
    {
        $id = $this->nullable($value);
        if ($id === null) {
            return null;
        }
        $id = trim($id, '<>');
        $id = trim($id);

        return $id === '' ? null : mb_substr($id, 0, 255);
    }

    /**
     * List zapisany przy ostatnim złożeniu, przeliczony przy otwarciu zapytania tym samym
     * kodem i z tych samych danych co panel. Panel liczy pozycje na bieżąco, a list był kopią
     * z chwili zapisu — po wdrożeniu zmiany listu panel i list mówiły co innego (#73: panel
     * z wyceną według rozmiarów, list jeszcze bez niej).
     *
     * Nie ruszamy listu wysłanego ani czekającego na wysyłkę — klient dostał albo ma dostać
     * zatwierdzoną treść — ani poprawionego ręcznie (poprawka treści kasuje `reply_html`).
     * Temat zostaje: handlowiec mógł go zmienić, a ta poprawka tabeli nie kasuje. Data zmiany
     * zapytania też zostaje — przeliczenie nie jest pracą handlowca.
     */
    public function refreshStoredReply(ClientInquiry $inquiry): ClientInquiry
    {
        if ($inquiry->replied_at !== null
            || $inquiry->send_requested_at !== null
            || trim((string) $inquiry->reply_body) === ''
            || trim((string) $inquiry->reply_html) === '') {
            return $inquiry;
        }

        $answers = is_array($inquiry->answers) ? $inquiry->answers : [];
        try {
            $draft = $this->writeReply($inquiry, $answers, $this->nullable($inquiry->extra_note));
        } catch (Throwable $e) {
            // Przeliczenie to dodatek do otwarcia strony — błąd trafia do logu, a handlowiec
            // dostaje zapisany list zamiast strony z błędem.
            report($e);

            return $inquiry;
        }
        if ($draft['body'] === (string) $inquiry->reply_body && $draft['html'] === (string) $inquiry->reply_html) {
            return $inquiry;
        }

        $inquiry->timestamps = false;
        try {
            $inquiry->forceFill(['reply_body' => $draft['body'], 'reply_html' => $draft['html']])->save();
        } finally {
            $inquiry->timestamps = true;
        }

        return $inquiry;
    }

    /**
     * List w innym szablonie — tylko do obejrzenia (np. cudze zapytanie): z pozycji, decyzji, warunków i dopisku
     * autora, w pamięci, bez zapisu. Ręcznych poprawek treści autora w nim nie ma — to list złożony od nowa.
     *
     * @return array{subject: string, body: string, html: string|null}
     */
    public function previewReply(ClientInquiry $inquiry, string $tone, SupplierSpecialMask $viewer): array
    {
        $copy = clone $inquiry;
        $copy->setAttribute('tone', $tone);
        $answers = is_array($copy->answers) ? $copy->answers : [];
        $draft = $this->withPriceMask($viewer, fn (): array => $this->writeReply($copy, $answers, $this->nullable($copy->extra_note)));

        return ['subject' => (string) $draft['subject'], 'body' => (string) $draft['body'], 'html' => $draft['html']];
    }

    /**
     * Tabela HTML do maila. Kolumna bywa pusta przy listach napisanych, zanim
     * tabela powstała — wtedy odtwarzamy ją z zapisanych odpowiedzi. Robimy to
     * tylko wtedy, gdy zapisany list to wciąż nasz tekst: po ręcznej poprawce
     * klient dostałby tabelę mówiącą co innego niż zatwierdzona treść.
     */
    public function replyHtmlFor(ClientInquiry $inquiry): ?string
    {
        $stored = (string) ($inquiry->reply_html ?? '');
        if ($stored !== '') {
            return $stored;
        }

        $body = (string) ($inquiry->reply_body ?? '');
        if ($body === '') {
            return null;
        }

        $answers = is_array($inquiry->answers) ? $inquiry->answers : [];
        $draft = $this->writeReply($inquiry, $answers, $this->nullable($inquiry->extra_note));

        return trim((string) $draft['body']) === trim($body) ? (string) $draft['html'] : null;
    }

    /**
     * Odcisk treści zapytania: pełny i bez pierwszej linii.
     *
     * Mail przekazany ręcznie ze skrzynki ogólnej dostaje nowy Message-ID,
     * a osoba przekazująca dopisuje zwykle u góry jedno zdanie („zapytanie:”).
     * Drugi odcisk, liczony bez pierwszej linii, łapie właśnie ten przypadek.
     *
     * @return array{full: string|null, tail: string|null}
     */
    public function fingerprints(string $analysisBody): array
    {
        $normalized = $this->normalizeForFingerprint($analysisBody);
        if ($normalized === null) {
            return ['full' => null, 'tail' => null];
        }

        $lines = preg_split('/\R/u', trim($analysisBody)) ?: [];
        while ($lines !== [] && trim((string) $lines[0]) === '') {
            array_shift($lines);
        }
        array_shift($lines);
        $tail = $this->normalizeForFingerprint(implode("\n", $lines));

        return [
            'full' => hash('sha256', $normalized),
            'tail' => $tail === null ? null : hash('sha256', $tail),
        ];
    }

    /** Same znaki treści: bez wielkości liter, bez powtórzonych spacji. */
    private function normalizeForFingerprint(string $text): ?string
    {
        $flat = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $flat = trim(mb_strtolower($flat));

        // Zbyt krótki fragment dopasowałby przypadkowe, niezwiązane maile.
        return mb_strlen($flat) < 40 ? null : $flat;
    }

    /**
     * Zapytanie z tego samego maila założone przez KOGOŚ INNEGO. Message-ID jest
     * pewny (mail na kilka adresów, przekierowanie serwerowe), odcisk treści
     * ratuje przypadek maila przekazanego ręcznie.
     *
     * @param  array{full: string|null, tail: string|null}  $fingerprints
     * @return array{inquiry: ClientInquiry, match: string}|null
     */
    public function findOthersInquiry(User $user, ?string $messageId, array $fingerprints): ?array
    {
        $normalizedId = $this->normalizeMessageId($messageId);
        if ($normalizedId !== null) {
            $byMessage = ClientInquiry::query()
                ->where('user_id', '!=', $user->id)
                ->where('source_message_id', $normalizedId)
                ->with('user:id,name')
                ->latest('id')
                ->first();

            if ($byMessage instanceof ClientInquiry) {
                return ['inquiry' => $byMessage, 'match' => 'message_id'];
            }
        }

        $hashes = array_values(array_filter([$fingerprints['full'] ?? null, $fingerprints['tail'] ?? null]));
        if ($hashes === []) {
            return null;
        }

        $byText = ClientInquiry::query()
            ->where('user_id', '!=', $user->id)
            ->where(function ($builder) use ($hashes): void {
                $builder->whereIn('source_fingerprint', $hashes)
                    ->orWhereIn('source_fingerprint_tail', $hashes);
            })
            ->with('user:id,name')
            ->latest('id')
            ->first();

        return $byText instanceof ClientInquiry
            ? ['inquiry' => $byText, 'match' => 'fingerprint']
            : null;
    }

    /**
     * Krótka wizytówka zapytania do ostrzeżenia o duplikacie.
     *
     * @return array<string, mixed>
     */
    public function duplicateRef(ClientInquiry $inquiry, ?string $match = null): array
    {
        $owner = $inquiry->relationLoaded('user') ? $inquiry->user : $inquiry->user()->first();

        $ref = [
            'id' => $inquiry->id,
            'user' => $owner === null ? null : ['id' => $owner->id, 'name' => $owner->name],
            'created_at' => $inquiry->created_at?->toIso8601String(),
            'source_subject' => $inquiry->source_subject,
            'replied_at' => $inquiry->replied_at?->toIso8601String(),
        ];
        if ($match !== null) {
            $ref['match'] = $match;
        }

        return $ref;
    }

    /**
     * Zapytania innych osób z tego samego maila — do paska ostrzeżenia w karcie.
     *
     * @return list<array<string, mixed>>
     */
    public function duplicatesOf(ClientInquiry $inquiry, int $limit = 5): array
    {
        $hashes = array_values(array_filter([$inquiry->source_fingerprint, $inquiry->source_fingerprint_tail]));
        $messageId = $this->normalizeMessageId($inquiry->source_message_id);

        if ($hashes === [] && $messageId === null) {
            return [];
        }

        $rows = ClientInquiry::query()
            ->where('id', '!=', $inquiry->id)
            ->where(function ($builder) use ($hashes, $messageId): void {
                if ($messageId !== null) {
                    $builder->orWhere('source_message_id', $messageId);
                }
                if ($hashes !== []) {
                    $builder->orWhereIn('source_fingerprint', $hashes)
                        ->orWhereIn('source_fingerprint_tail', $hashes);
                }
            })
            ->with('user:id,name')
            ->latest('id')
            ->limit($limit)
            ->get();

        return $rows->map(fn (ClientInquiry $row): array => $this->duplicateRef($row))->values()->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function duplicateRefById(int $id): ?array
    {
        $origin = ClientInquiry::query()->with('user:id,name')->find($id);

        return $origin === null ? null : $this->duplicateRef($origin);
    }

    /**
     * Pełny payload API zapytania (kontrakt GET /inquiries/{id}).
     *
     * `viewer` — widok ceny specjalnej B2B tego, kto patrzy (autor albo kierownik z inquiries.view_others): ceny
     * kandydatów i zamienników liczą się od ceny, którą on widzi, także gdy autor zapisał cenę specjalną. Zapisany
     * list (reply_body, reply_html) idzie, jaki jest — to treść autora dla klienta.
     *
     * @return array<string, mixed>
     */
    public function present(ClientInquiry $inquiry, SupplierSpecialMask $viewer): array
    {
        return $this->withPriceMask($viewer, fn (): array => $this->presentMasked($inquiry, $viewer));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMasked(ClientInquiry $inquiry, SupplierSpecialMask $viewer): array
    {
        $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];
        $answers = is_array($inquiry->answers) ? $inquiry->answers : [];
        $client = $inquiry->relationLoaded('client') ? $inquiry->client : null;
        // Jedno zapytanie do bazy i tylko wtedy, gdy autor nie był wcześniej wczytany.
        $author = $inquiry->loadMissing('user')->user;
        // warunek zamawiania (UVEX „po 10 szt.”) i zdjęcie karty tylko w odpowiedzi — itemsView() zostaje widokiem zapisanej analizy
        $items = $this->withImages($this->withOrderQuantities($this->itemsView($this->maskedCopy($inquiry, $viewer)), $viewer));
        $omitted = $this->omittedItemsOf($analysis);

        return [
            'id' => $inquiry->id,
            'client_id' => $inquiry->client_id,
            'client' => $client instanceof Client
                ? ['id' => $client->id, 'name' => $client->name]
                : null,
            'tone' => (string) $inquiry->tone,
            'source_subject' => $inquiry->source_subject,
            'source_channel' => (string) $inquiry->source_channel,
            'source_message_id' => $inquiry->source_message_id,
            'source_from_name' => $inquiry->source_from_name,
            'source_from_email' => $inquiry->source_from_email,
            'source_sent_at' => $inquiry->source_sent_at?->toIso8601String(),
            // plik klienta, z którego wczytano treść; null = mail
            'source_file_name' => $this->nullable($analysis['source_file_name'] ?? null),
            ...$this->analysisStateView($inquiry),
            'contact' => is_array($inquiry->contact) ? $inquiry->contact : null,
            'user' => $author instanceof User
                ? ['id' => $author->id, 'name' => $author->name]
                : null,
            'source_body' => (string) $inquiry->source_body,
            'questions' => $this->stringList($analysis['questions'] ?? null),
            // wiersz maila, który nie wszedł do pozycji, zawsze wymaga ręki handlowca
            'attention_count' => $this->countAttention($items) + count($omitted),
            'replied_at' => $inquiry->replied_at?->toIso8601String(),
            'duplicate_of' => $inquiry->duplicate_of_id === null
                ? null
                : $this->duplicateRefById((int) $inquiry->duplicate_of_id),
            'duplicates' => $this->duplicatesOf($inquiry),
            'send_requested_at' => $inquiry->send_requested_at?->toIso8601String(),
            'price' => [
                'answer_key' => 'price',
                'mode' => $this->priceModeOf($answers),
                'margin' => $this->marginPercent($answers),
                'margin_max' => OfferPricing::marginMax(),
            ],
            'items' => $items,
            // wiersze maila poza pozycjami — nie ma ich w liście, handlowiec dopisuje je sam
            'omitted_items' => $omitted,
            'omitted_limit' => (int) ($analysis['max_items'] ?? self::LEGACY_MAX_LINE_ITEMS),
            'global_cards' => $this->globalCards($analysis),
            'cards' => $this->storedCards($analysis),
            'answers' => $answers,
            'extra_note' => $inquiry->extra_note,
            'terms' => $this->termsView($inquiry),
            'reply_subject' => $inquiry->reply_subject,
            'reply_body' => $inquiry->reply_body,
            'reply_html' => $this->replyHtmlFor($inquiry),
            'created_at' => $inquiry->created_at?->toIso8601String(),
            // jak się skończyło: wynik, powiązanie z klientem, ważność oferty, podpowiedzi z ERP XL (can_edit — kontroler)
            ...$this->outcomeFields($inquiry),
        ];
    }

    /**
     * Pola wyniku zapytania (etap 4): wynik wpisany przez handlowca, pewne powiązanie z klientem (z regułą), do kiedy
     * ważna jest oferta (OfferValidity z warunku „Ważność oferty” i dnia odpowiedzi; null, gdy tekstu nie da się
     * jednoznacznie odczytać) i podpowiedzi z ERP XL (OrderHintBuilder — wniosek, nie fakt).
     *
     * @return array{outcome: array<string, mixed>, client_link: array<string, mixed>|null, offer_valid_until: string|null, validity_text: string|null, order_hints: array<string, mixed>}
     */
    public function outcomeFields(ClientInquiry $inquiry): array
    {
        $validity = $this->termsOf($inquiry)['validity'] ?? null;
        $until = $inquiry->replied_at !== null ? OfferValidity::until($validity, CarbonImmutable::instance($inquiry->replied_at)) : null;

        return [
            'outcome' => $this->outcomeView($inquiry, false),
            'client_link' => InquiryClientLinker::present($inquiry),
            'offer_valid_until' => $until?->toDateString(),
            'validity_text' => $validity,
            'order_hints' => OrderHintBuilder::present($inquiry),
        ];
    }

    /**
     * Wynik zapytania do widoku: kto i kiedy wpisał, powód, dokument z ERP XL skopiowany z potwierdzonej podpowiedzi.
     *
     * @return array{outcome: string|null, reason: string|null, by: array{id: int, name: string}|null, at: string|null, document: array{number: string, date: string|null, net_value: string|null}|null, can_edit: bool}
     */
    public function outcomeView(ClientInquiry $inquiry, bool $canEdit): array
    {
        $by = $inquiry->outcome_by === null ? null : $inquiry->outcomeBy()->first(['id', 'name']);
        $number = $this->nullable($inquiry->outcome_document_number);

        return [
            'outcome' => $inquiry->outcome,
            'reason' => $inquiry->outcome_reason,
            'by' => $by instanceof User ? ['id' => (int) $by->id, 'name' => (string) $by->name] : null,
            'at' => $inquiry->outcome_at?->toIso8601String(),
            'document' => $number === null ? null : [
                'number' => $number,
                'date' => $inquiry->outcome_document_date?->toDateString(),
                'net_value' => $inquiry->outcome_net_value !== null ? (string) $inquiry->outcome_net_value : null,
            ],
            'can_edit' => $canEdit,
        ];
    }

    /**
     * Wyroby, które poszły do klienta w liście: wybrany (albo domyślny) wyrób każdej pozycji i zatwierdzony zamiennik
     * wskazany przy pozycji — ta sama reguła co offerRows(). Każdy raz, w kolejności pozycji. Podstawa podpowiedzi
     * „możliwe zamówienie z oferty” (OrderHintBuilder). Same identyfikatory — ceny nie są tu potrzebne (widok ukrywający).
     *
     * @return list<int>
     */
    public function offeredProductIds(ClientInquiry $inquiry): array
    {
        return $this->withPriceMask(SupplierSpecialMask::hiding(), function () use ($inquiry): array {
            $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];
            $answers = is_array($inquiry->answers) ? $inquiry->answers : [];
            $matches = $this->matchGroups($analysis);
            $ids = [];
            foreach ($this->lineItemsOf($analysis) as $item) {
                $candidates = $this->candidatesForItem($matches, $item, $analysis);
                $product = $this->chosenProductForItem($item, $candidates, $answers);
                if ($product === null) {
                    continue;
                }
                $ids[(int) ($product['id'] ?? 0)] = true;
                $substitute = $this->chosenSubstituteForItem($item, $this->substitutesForItem($analysis, $candidates), $answers);
                if ($substitute !== null) {
                    $ids[(int) ($substitute['id'] ?? 0)] = true;
                }
            }
            unset($ids[0]);

            return array_keys($ids);
        });
    }

    /**
     * Stan analizy w tle do widoku (kontrakt API: analysis_status, analysis_progress, analysis_error …). Przebieg
     * „running” bez końca po ClientInquiry::ANALYSIS_STALE_MINUTES pokazujemy jako przerwany — do ponowienia.
     *
     * @return array<string, mixed>
     */
    public function analysisStateView(ClientInquiry $inquiry): array
    {
        $status = $inquiry->effectiveAnalysisStatus();
        $progress = is_array($inquiry->analysis_progress) ? $inquiry->analysis_progress : null;
        $error = $this->nullable($inquiry->analysis_error);
        if ($status === ClientInquiry::ANALYSIS_FAILED && $inquiry->analysis_status === ClientInquiry::ANALYSIS_RUNNING) {
            $error = 'Analiza została przerwana — serwer nie dokończył jej w '.ClientInquiry::ANALYSIS_STALE_MINUTES.' minut. Uruchom ją ponownie.';
        }
        $pending = $status !== ClientInquiry::ANALYSIS_DONE;

        return [
            'analysis_status' => $status,
            'analysis_progress' => $pending && $progress !== null ? [
                'stage' => (string) ($progress['stage'] ?? 'queued'),
                'done' => (int) ($progress['done'] ?? 0),
                'total' => (int) ($progress['total'] ?? 0),
            ] : null,
            'analysis_error' => $status === ClientInquiry::ANALYSIS_FAILED ? ($error ?? 'Analiza zapytania nie powiodła się.') : null,
            'analysis_started_at' => $pending ? $inquiry->analysis_started_at?->toIso8601String() : null,
            // chwila wstawienia do kolejki: założenie albo ponowienie (restartAnalysis() zapisuje updated_at) —
            // podpowiedź „długo czeka” liczona od założenia straszyła zaraz po ponowieniu starego zapytania
            'analysis_queued_at' => $status === ClientInquiry::ANALYSIS_QUEUED ? $inquiry->updated_at?->toIso8601String() : null,
            'analysis_line_items' => $pending && isset($progress['items']) ? (int) $progress['items'] : null,
            'can_retry_analysis' => $status === ClientInquiry::ANALYSIS_FAILED,
        ];
    }

    /** Liczba pozycji do sprawdzenia — liczona w PHP z zapisanego analysis + answers. */
    public function attentionCount(ClientInquiry $inquiry): int
    {
        $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];

        // wiersz maila, który nie wszedł do pozycji, zawsze wymaga ręki handlowca
        return $this->countAttention($this->itemsView($inquiry)) + count($this->omittedItemsOf($analysis));
    }

    /**
     * Widok pozycji nad kluczami `answers` (product:item_N, substitutes:item_N) — nie nowy model.
     *
     * @return list<array<string, mixed>>
     */
    public function itemsView(ClientInquiry $inquiry): array
    {
        $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];
        $answers = is_array($inquiry->answers) ? $inquiry->answers : [];
        $matches = $this->matchGroups($analysis);
        $priceMode = $this->priceModeOf($answers);
        $margin = $this->marginPercent($answers);

        $out = [];
        foreach ($this->lineItemsOf($analysis) as $item) {
            $itemId = (string) $item['id'];
            $candidates = $this->candidatesForItem($matches, $item, $analysis);
            $substitutes = $this->substitutesForItem($analysis, $candidates);
            $confidence = $this->confidenceFor($candidates, $item);
            $chosen = $this->chosenOptionFor($item, $candidates, $answers);
            $cards = $this->itemCards($analysis, $itemId);
            $qtyUnit = $this->qtyUnit($item);

            $flags = [];
            if (($candidates[0]['similar'] ?? false) === true) {
                // model nic nie zatwierdził — przy pozycji tylko podobne karty z katalogu do wyboru ręcznego
                $flags[] = 'similar_only';
            } elseif ($confidence === 'none' && $candidates !== []) {
                $flags[] = 'low_score';
            }
            if ($candidates === [] && $this->searchFailedForItem($matches, $item)) {
                // model nie ocenił kart — o katalogu nic nie wiemy, więc nie „brak w katalogu”
                $flags[] = 'model_failed';
            }
            $absentBrand = $this->requestedBrandAbsent($candidates);
            if ($absentBrand !== null) {
                // Klient prosił o markę spoza katalogu: kandydaci to zamienniki. Wysoka ocena modelu mówi
                // „pasuje do wymagania”, nie „to ta marka” — dlatego najwyżej „sprawdź”.
                $flags[] = 'brand_not_in_catalog';
                if ($confidence === 'high') {
                    $confidence = 'medium';
                }
            }
            if ($this->isAmbiguous($candidates, $item)) {
                $flags[] = 'ambiguous';
            }
            // W mailu stała liczba, ale okazała się numerem pozycji, nie ilością. Bez tej
            // flagi brak ilości w ofercie zauważyłby dopiero klient. Mail opisowy, w którym
            // ilości nigdy nie było, flagi nie dostaje — tam nie ma czego szukać.
            if ($qtyUnit['qty'] === null && $this->nullable($item['qty_source'] ?? null) !== null) {
                $flags[] = 'qty_unknown';
            }
            $product = $this->candidateById($candidates, $chosen);
            $cardSize = $this->chosenSizeMismatch($item, $product);
            if ($cardSize !== null) {
                // Wybrana karta to wariant w innym rozmiarze, a list podaje rozmiar z zapytania — oferta
                // wyglądałaby jak potwierdzenie rozmiaru, którego nie dajemy. Wyboru nie zmieniamy: to decyzja
                // handlowca. Wysoka ocena modelu mówi „pasuje do wymagania”, nie „to ten rozmiar”.
                $flags[] = 'size_mismatch';
                if ($confidence === 'high') {
                    $confidence = 'medium';
                }
            }
            $breakdown = $this->sizeBreakdownOf($item);
            if ($breakdown !== null && $breakdown['matches_qty'] === false) {
                // Rozmiary z maila nie sumują się do ilości pozycji: list liczy wartość z rozmiarów,
                // a która liczba jest prawdziwa, wie dopiero klient — handlowiec ma to zobaczyć.
                $flags[] = 'size_breakdown_mismatch';
                if ($confidence === 'high') {
                    $confidence = 'medium';
                }
            }
            $manualPrice = $this->manualPriceFor($item, $product, $answers);
            if ($product !== null && $priceMode !== 'none' && $manualPrice === null
                && $this->letterPrice($product, $priceMode, $margin) === null) {
                $flags[] = 'no_price';
            }

            // Warunki szczególne z wiersza klienta — i to, czy karta je potwierdza.
            $requirements = $this->requirementsOf($item);
            if ($requirements !== []) {
                $this->warmCardChecks(array_map(
                    static fn (array $candidate): int => (int) ($candidate['id'] ?? 0),
                    $candidates,
                ));
            }
            $checkable = array_values(array_filter(
                $requirements,
                static fn (array $one): bool => ($one['checkable'] ?? false) === true,
            ));
            // Do bazy idziemy tylko wtedy, gdy jest co sprawdzać — pozycje bez
            // warunków (a takich jest większość) nie czytają żadnej karty.
            $unconfirmed = [];
            if ($checkable !== []) {
                $unconfirmed = $product === null
                    ? array_map(static fn (array $one): string => (string) $one['text'], $checkable)
                    : InquiryRequirements::unconfirmed(
                        $checkable,
                        $this->cardCheckText((int) ($product['id'] ?? 0)),
                    );
            }
            if ($unconfirmed !== []) {
                $flags[] = 'requirement_unconfirmed';
            }
            if (count($checkable) !== count($requirements)) {
                // Klauzula, której nie da się sprawdzić regułą — musi ją przeczytać człowiek.
                $flags[] = 'requirement_note';
            }
            $conflict = $this->nullable($item['conflict'] ?? null);
            if ($conflict !== null) {
                // wiersz klienta sam sobie przeczy (ocena modelu) — handlowiec powinien to wyjaśnić
                $flags[] = 'requirement_conflict';
            }
            if (($item['query_source'] ?? null) === 'subject') {
                // wiersz nie nazywał wyrobu — szukaliśmy tym z tematu maila (nasz wniosek)
                $flags[] = 'product_from_subject';
            }
            foreach ($cards as $card) {
                // karta AI bez odpowiedzi — list jej nie uwzględnia, pracownik powinien zerknąć
                if (! isset($answers[(string) $card['id']])) {
                    $flags[] = 'card_default';
                    break;
                }
            }

            $out[] = [
                'id' => $itemId,
                'quote' => $this->nullable($item['quote'] ?? null),
                'qty' => $qtyUnit['qty'],
                'unit' => $qtyUnit['unit'],
                'size' => $this->nullable($item['size'] ?? null),
                // Sprzeczność w wierszu klienta wskazana przez model; null = brak albo stary rekord.
                'conflict' => $conflict,
                // Marka z zapytania, której nie ma w katalogu (kandydaci to zamienniki); null = brak takiej sytuacji.
                'brand_not_in_catalog' => $absentBrand,
                // Rozmiar z nazwy wybranej karty, gdy inny niż w zapytaniu; null = zgodny albo nie do stwierdzenia.
                'size_mismatch' => $cardSize,
                // Rozmiary i ilości z cytatu pozycji-sumy (słowa klienta); null = pozycja bez takiego rozbicia.
                'size_breakdown' => $breakdown,
                // Fraza, którą ta pozycja szukała w katalogu — podpowiedź dla
                // ręcznego wyszukiwania przy pozycji, nie nowe źródło danych.
                'query' => $this->nullable($item['search_query'] ?? null)
                    ?? $this->nullable($item['query'] ?? null),
                'answer_key' => 'product:'.$itemId,
                'substitute_key' => $substitutes !== [] ? 'substitutes:'.$itemId : null,
                'manual_price_key' => self::MANUAL_PRICE_PREFIX.$itemId,
                // cena wpisana przez handlowca dla wybranego wyrobu; null = liczymy z trybu cen
                'manual_price' => $manualPrice,
                'confidence' => $confidence,
                'chosen' => $chosen,
                'flags' => $flags,
                'requirements' => array_map(
                    fn (array $one): array => [
                        'text' => (string) $one['text'],
                        'checkable' => ($one['checkable'] ?? false) === true,
                        // null, gdy warunku nie da się sprawdzić albo nic nie wybrano
                        'ok' => ($one['checkable'] ?? false) !== true || $product === null
                            ? null
                            : ! in_array((string) $one['text'], $unconfirmed, true),
                    ],
                    $requirements,
                ),
                'candidates' => array_map(
                    fn (array $p): array => array_merge($this->candidateView($p, $margin), [
                        // null = pozycja bez warunków do sprawdzenia
                        'requirements_ok' => $checkable === [] ? null : $this->meetsRequirements($checkable, $p),
                    ]),
                    $candidates,
                ),
                'substitutes' => array_map(
                    fn (array $p): array => array_merge($this->candidateView($p, $margin), ['score' => null, 'reason' => null]),
                    $substitutes
                ),
                'cards' => $cards,
            ];
        }

        return $this->withWithdrawal($out, $answers);
    }

    /**
     * Wycofanie przez producenta przy każdym kandydacie i zamienniku, a przy pozycji flaga `withdrawn`, gdy wycofany
     * jest wyrób, który wejdzie do listu (wybrany albo zatwierdzony zamiennik). Stan karty z chwili odpowiedzi —
     * w analizie opisu nie ma, a producent wycofuje wyroby także po założeniu zapytania.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $answers
     * @return list<array<string, mixed>>
     */
    private function withWithdrawal(array $items, array $answers): array
    {
        $ids = [];
        foreach ($items as $item) {
            foreach ([...$item['candidates'], ...$item['substitutes']] as $product) {
                $ids[] = (int) $product['id'];
            }
        }
        $notes = $this->withdrawnNotesFor($ids);

        foreach ($items as $i => $item) {
            foreach (['candidates', 'substitutes'] as $list) {
                foreach ($item[$list] as $j => $product) {
                    $items[$i][$list][$j]['withdrawn'] = $notes[(int) $product['id']] ?? null;
                }
            }

            $inLetter = [(string) $item['chosen']];
            if ($item['substitute_key'] !== null) {
                $inLetter[] = trim((string) ($answers[$item['substitute_key']]['option_id'] ?? ''));
            }
            foreach ($inLetter as $option) {
                if (str_starts_with($option, 'p:') && ($notes[(int) substr($option, 2)] ?? null) !== null) {
                    $items[$i]['flags'][] = 'withdrawn';
                    // Jak przy marce spoza katalogu: wysoka ocena mówi „pasuje do wymagania”, nie „da się
                    // zamówić” — o ofercie wycofanego wyrobu albo następcy decyduje handlowiec, więc najwyżej „sprawdź”.
                    if ($items[$i]['confidence'] === 'high') {
                        $items[$i]['confidence'] = 'medium';
                    }
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{successor: ?string}|null>
     */
    private function withdrawnNotesFor(array $ids): array
    {
        $missing = [];
        foreach ($ids as $id) {
            if ($id > 0 && ! array_key_exists($id, $this->withdrawnNotes)) {
                $missing[$id] = true;
            }
        }
        if ($missing !== []) {
            // Tylko karty z dopiskiem — reszta paczki nie przenosi opisów z bazy.
            $rows = Product::query()
                ->whereIn('id', array_keys($missing))
                ->where('description', 'like', '%'.WithdrawnProductNote::PREFIX.'%')
                ->get(['id', 'description']);
            foreach ($rows as $product) {
                $this->withdrawnNotes[(int) $product->id] = WithdrawnProductNote::parse((string) $product->description);
            }
            foreach (array_keys($missing) as $id) {
                $this->withdrawnNotes[$id] ??= null;
            }
        }

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $this->withdrawnNotes[$id] ?? null;
        }

        return $out;
    }

    /**
     * Warunek zamawiania obowiązującego źródła (UVEX „po 10 szt.”) przy kandydatach i zamiennikach — liczony przy
     * odpowiedzi, jednym przebiegiem dla wszystkich pozycji. Do zapisanej analizy nie trafia: stan konta B2B się
     * zmienia, a analysis to migawka z chwili analizy.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  SupplierSpecialMask  $viewer  widok ceny specjalnej — najwyższa cena rozmiaru slotu specjalnego przeskalowana
     * @return list<array<string, mixed>>
     */
    private function withOrderQuantities(array $items, SupplierSpecialMask $viewer): array
    {
        $ids = [];
        foreach ($items as $item) {
            foreach ([...$item['candidates'], ...$item['substitutes']] as $product) {
                $ids[(int) $product['id']] = true;
            }
        }
        unset($ids[0]);
        if ($ids === []) {
            return $items;
        }
        $quantities = app(SourcePriceComparison::class)->orderQuantities(
            Product::query()->whereIn('id', array_keys($ids))->get(['id', 'manufacturer']),
            $viewer,
        );
        foreach ($items as $i => $item) {
            foreach (['candidates', 'substitutes'] as $list) {
                foreach ($item[$list] as $j => $product) {
                    $items[$i][$list][$j]['order_quantity'] = $quantities[(int) $product['id']] ?? null;
                }
            }
        }

        return $items;
    }

    /**
     * Zdjęcie główne karty (a bez znacznika pierwsze w kolejności karty) przy kandydatach i zamiennikach: miniatura
     * do widoku pozycji i pełne zdjęcie po jej kliknięciu — jednym zapytaniem dla wszystkich pozycji. Stan karty
     * z chwili odpowiedzi: zdjęcia dochodzą z synchronizacji i znikają po odrzuceniu, więc do zapisanej analizy
     * nie trafiają. null = karta bez zdjęcia.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function withImages(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            foreach ([...$item['candidates'], ...$item['substitutes']] as $product) {
                $ids[(int) $product['id']] = true;
            }
        }
        unset($ids[0]);
        if ($ids === []) {
            return $items;
        }
        $images = [];
        foreach (ProductImage::primaryFor(array_keys($ids)) as $productId => $image) {
            $images[$productId] = [
                'thumb_url' => $image->thumbUrl(),
                'image_url' => $image->url(),
            ];
        }
        foreach ($items as $i => $item) {
            foreach (['candidates', 'substitutes'] as $list) {
                foreach ($item[$list] as $j => $product) {
                    $image = $images[(int) $product['id']] ?? null;
                    $items[$i][$list][$j]['thumb_url'] = $image['thumb_url'] ?? null;
                    $items[$i][$list][$j]['image_url'] = $image['image_url'] ?? null;
                }
            }
        }

        return $items;
    }

    /**
     * Jedna funkcja domyślnego wyboru: dla runAnalysis() (zapis answers) i compose() (brak odpowiedzi).
     *
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $candidates  posortowani malejąco po score
     */
    public function defaultOptionFor(array $item, array $candidates): string
    {
        if ($this->confidenceFor($candidates, $item) === 'none') {
            return 'check';
        }
        // Marki z zapytania nie ma w katalogu — zamiennik innej marki wybiera handlowiec, nie list.
        // Zapytanie #67: „MedaSept EASYGRIP PURPLE” → Unicare niebieskie z 95% szło do listu jak trafienie.
        if ($this->requestedBrandAbsent($candidates) !== null) {
            return 'check';
        }
        // Klient wskazał kartę linkiem: domyślnie wchodzi ona albo nic. Inny wyrób, który
        // „lepiej potwierdza warunek”, byłby naszym wyborem wbrew linkowi.
        if (($candidates[0]['source'] ?? null) === 'link') {
            $candidates = array_values(array_filter(
                $candidates,
                static fn (array $p): bool => ($p['source'] ?? null) === 'link',
            ));
        }

        // Klient postawił warunek („w szczególności na kwas siarkowy 96%”):
        // do listu wchodzi tylko karta, która ten warunek potwierdza. Gdy żadna
        // nie potwierdza, pozycja idzie jak brak w katalogu — „potwierdzimy po
        // weryfikacji”. Milczenie o warunku czyta się jak jego spełnienie,
        // a tego o wyrobie nie wiemy.
        $required = $this->checkableRequirements($item);
        if ($required === []) {
            return 'p:'.(int) $candidates[0]['id'];
        }

        $this->warmCardChecks(array_map(
            static fn (array $candidate): int => (int) ($candidate['id'] ?? 0),
            $candidates,
        ));

        foreach ($candidates as $candidate) {
            if ($this->meetsRequirements($required, $candidate)) {
                return 'p:'.(int) $candidate['id'];
            }
        }

        return 'check';
    }

    /**
     * Marka nazwana w zapytaniu, której nie ma w katalogu — kandydaci z wyszukiwania to zamienniki innej marki.
     * Liczy się pierwszy kandydat: karta z linku czy wybrana ręcznie stoi przed nimi i znacznika nie ma.
     *
     * @param  list<array<string, mixed>>  $candidates
     */
    private function requestedBrandAbsent(array $candidates): ?string
    {
        return $this->nullable($candidates[0]['requested_brand_absent'] ?? null);
    }

    /**
     * @param  list<array<string, mixed>>  $candidates  posortowani malejąco po score (kod z maila na czele)
     * @param  array<string, mixed>  $item
     * @return 'high'|'medium'|'none'
     */
    public function confidenceFor(array $candidates, array $item = []): string
    {
        $best = $candidates[0] ?? null;
        if ($best === null) {
            return 'none';
        }
        // Podobna karta z katalogu nie jest trafieniem — model jej nie zatwierdził (wiersz reguły ma 92, ale to nie ocena).
        if (($best['similar'] ?? false) === true) {
            return 'none';
        }
        // Karta z linku nie ma oceny modelu — o pewności decyduje adres, nie wynik.
        if (($best['source'] ?? null) === 'link') {
            return $this->linkedSingle($candidates) ? 'high' : 'medium';
        }
        $score = (int) ($best['score'] ?? 0);
        // Klient wpisał dokładny kod z katalogu — to nie jest zgadywanie modelu.
        // Ale gdy model ocenił ten wiersz poniżej progu, zgodny bywa sam ciąg znaków,
        // a nie wyrób: taki wiersz zostaje na czele listy do wyboru ręcznego, jednak
        // nie wchodzi do listu jako pewne dopasowanie.
        if ($score >= $this->minMatchScore() && $this->skuQuotedIndex($item, $candidates) === 0) {
            return 'high';
        }
        if ($score >= self::CONFIDENT_SCORE && ! $this->isAmbiguous($candidates, $item)) {
            return 'high';
        }

        return $score >= $this->minMatchScore() ? 'medium' : 'none';
    }

    /**
     * Indeks kandydata, którego SKU stoi dosłownie w cytacie z maila (osobny token, min. 4 znaki).
     *
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $candidates
     */
    private function skuQuotedIndex(array $item, array $candidates): ?int
    {
        $quote = mb_strtolower(trim((string) ($item['quote'] ?? '')));
        if ($quote === '') {
            return null;
        }
        foreach ($candidates as $i => $candidate) {
            $sku = mb_strtolower(trim((string) ($candidate['sku'] ?? '')));
            if (mb_strlen($sku) < 4) {
                continue;
            }
            if (preg_match('/(?<![a-z0-9])'.preg_quote($sku, '/').'(?![a-z0-9])/u', $quote) === 1) {
                return $i;
            }
        }

        return null;
    }

    /** Próg z panelu Strojenie AI — raz na instancję, żeby lista zapytań nie odpytywała ustawień per pozycja. */
    private function minMatchScore(): int
    {
        return $this->minMatchScore ??= $this->aiSettings->matchMinScore();
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<string, mixed>  $item
     */
    private function isAmbiguous(array $candidates, array $item = []): bool
    {
        if (count($candidates) < 2 || $this->skuQuotedIndex($item, $candidates) === 0) {
            return false;
        }
        if (($candidates[0]['source'] ?? null) === 'link') {
            // pod linkiem stoi kilka kart albo inny wariant adresu — wybiera handlowiec
            return ! $this->linkedSingle($candidates);
        }
        $best = (int) ($candidates[0]['score'] ?? 0);
        $second = (int) ($candidates[1]['score'] ?? 0);

        return $best >= self::CONFIDENT_SCORE && $best - $second < self::AMBIGUOUS_GAP;
    }

    /**
     * Domyślne `answers`: towar per pozycja, zamienniki „no” tam, gdzie są zatwierdzone, tryb ceny.
     *
     * @return array<string, array{option_id: string, custom?: string|null}>
     */
    public function defaultAnswers(ClientInquiry $inquiry, string $priceMode, float $margin): array
    {
        $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];
        $matches = $this->matchGroups($analysis);
        $answers = [];
        foreach ($this->lineItemsOf($analysis) as $item) {
            $itemId = (string) $item['id'];
            $candidates = $this->candidatesForItem($matches, $item, $analysis);
            $answers['product:'.$itemId] = ['option_id' => $this->defaultOptionFor($item, $candidates)];
            if ($this->substitutesForItem($analysis, $candidates) !== []) {
                $answers['substitutes:'.$itemId] = ['option_id' => 'no'];
            }
        }
        $answers['price'] = [
            'option_id' => in_array($priceMode, self::PRICE_MODES, true) ? $priceMode : 'none',
            'custom' => $this->formatMargin($margin),
        ];

        return $answers;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function countAttention(array $items): int
    {
        $n = 0;
        foreach ($items as $item) {
            // Brak ilości widać przy pozycji; w liście numerowanym bez ilości dotyczy
            // każdego wiersza i licznik „do sprawdzenia” przestałby cokolwiek znaczyć.
            // Wyrób z tematu to informacja o źródle frazy, nie wątpliwość co do dopasowania.
            $flags = array_diff(is_array($item['flags'] ?? null) ? $item['flags'] : [], ['qty_unknown', 'product_from_subject']);
            if (($item['confidence'] ?? 'none') !== 'high' || $flags !== []) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return list<array{query: string, products: list<array<string, mixed>>}>
     */
    private function matchGroups(array $analysis): array
    {
        $out = [];
        foreach (is_array($analysis['matches'] ?? null) ? $analysis['matches'] : [] as $group) {
            if (! is_array($group)) {
                continue;
            }
            $products = [];
            foreach (is_array($group['products'] ?? null) ? $group['products'] : [] as $product) {
                if (is_array($product) && (int) ($product['id'] ?? 0) > 0) {
                    $products[] = $product;
                }
            }
            $out[] = [
                'query' => (string) ($group['query'] ?? ''),
                'products' => $products,
                'model_failed' => ($group['model_failed'] ?? false) === true,
            ];
        }

        return $out;
    }

    /**
     * Wiersze maila poza pozycjami (resolveLineItemsWithOmitted()); stare rekordy ich nie mają.
     *
     * @param  array<string, mixed>  $analysis
     * @return list<array{quote: string, qty: string|null, unit: string|null, size: string|null}>
     */
    private function omittedItemsOf(array $analysis): array
    {
        $out = [];
        foreach (is_array($analysis['omitted_items'] ?? null) ? $analysis['omitted_items'] : [] as $row) {
            $quote = is_array($row) ? $this->nullable($row['quote'] ?? null) : null;
            if ($quote === null) {
                continue;
            }
            $out[] = [
                'quote' => $quote,
                'qty' => $this->nullable($row['qty'] ?? null),
                'unit' => $this->nullable($row['unit'] ?? null),
                'size' => $this->nullable($row['size'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * Pozycje z analizy; mail opisowy (bez line_items) dostaje jedną pseudo-pozycję item_1
     * z pierwszym zapytaniem produktowym — bez cytatu, bo to parafraza modelu, nie tekst klienta.
     *
     * @param  array<string, mixed>  $analysis
     * @return list<array<string, mixed>>
     */
    private function lineItemsOf(array $analysis): array
    {
        $items = [];
        foreach (is_array($analysis['line_items'] ?? null) ? $analysis['line_items'] : [] as $item) {
            if (is_array($item) && trim((string) ($item['id'] ?? '')) !== '') {
                $items[] = $item;
            }
        }
        if ($items !== []) {
            return $items;
        }

        $query = $this->stringList($analysis['product_queries'] ?? null)[0]
            ?? $this->nullable($this->matchGroups($analysis)[0]['query'] ?? null);
        if ($query === null) {
            return [];
        }

        return [[
            'id' => 'item_1',
            'quote' => null,
            'qty' => null,
            'unit' => null,
            'query' => $query,
            'size' => null,
        ]];
    }

    /**
     * Kandydaci pozycji malejąco po score (także poniżej progu — do ręcznego wyboru).
     * Na końcu listy stoją wyroby dobrane ręcznie przez handlowca (`manual_candidates`):
     * nie mają oceny modelu, więc nie mogą decydować o pewności ani o wyborze domyślnym.
     *
     * @param  list<array{query: string, products: list<array<string, mixed>>}>  $matches
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $analysis
     * @return list<array<string, mixed>>
     */
    private function candidatesForItem(array $matches, array $item, array $analysis = []): array
    {
        $products = $this->rated($this->productsForItem($matches, $item));
        usort($products, static fn (array $a, array $b): int => ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0)));
        $products = array_values(array_slice($products, 0, self::MAX_MATCHES_PER_QUERY));
        if ($products === [] && ! $this->searchFailedForItem($matches, $item)) {
            $products = $this->similarCandidates($matches, $item);
        }

        // Kod z maila na czoło — przy równych wynikach wariantów to on jest domyślny.
        // Ale wiersz oceniony poniżej progu zostaje na swoim miejscu: zgodny bywa sam
        // ciąg znaków (indeks filtra „2820” przy okularach 3M), a przesunięcie go na
        // czoło odbierało wybór domyślny kandydatowi, którego model ocenił wysoko.
        $quoted = $this->skuQuotedIndex($item, $products);
        if ($quoted !== null && $quoted > 0 && (int) ($products[$quoted]['score'] ?? 0) >= $this->minMatchScore()) {
            [$hit] = array_splice($products, $quoted, 1);
            array_unshift($products, $hit);
        }

        // Karta wskazana linkiem z maila staje na czele, przed oceną modelu: klient pokazał
        // ją adresem strony, który łącznik B2B zapisał przy karcie (#69: ROLEX 5, a model dał
        // po 99% ROLEX-om 1, 2 i 3). Ta sama karta z wyszukiwarki drugi raz się nie pokazuje.
        $linked = $this->linkedCandidates($analysis, (string) ($item['id'] ?? ''));
        if ($linked !== []) {
            $linkedIds = array_map(static fn (array $p): int => (int) $p['id'], $linked);
            $products = [...$linked, ...array_values(array_filter(
                $products,
                static fn (array $p): bool => ! in_array((int) ($p['id'] ?? 0), $linkedIds, true),
            ))];
        }

        $seen = [];
        foreach ($products as $product) {
            $seen[(int) ($product['id'] ?? 0)] = true;
        }
        foreach ($this->manualCandidates($analysis, (string) ($item['id'] ?? '')) as $manual) {
            if (! isset($seen[(int) $manual['id']])) {
                $products[] = $manual;
            }
        }

        return $products;
    }

    /**
     * Podobne karty z katalogu, gdy model żadnej nie zatwierdził: wiersze listy zapasowej (ten sam rodzaj w katalogu,
     * reguła klasy) — dotąd ukryte przez rated(), więc handlowiec widział „brak w katalogu”, choć katalog miał np.
     * statywy PROTEKT TM 6 / TM 15 pod „TM 9-N” (zapytanie #88, 30.09.2026). Oznaczone `similar`: nigdy nie są
     * wybierane domyślnie (confidenceFor → none), do listu wchodzą tylko po wyborze handlowca.
     *
     * @param  list<array<string, mixed>>  $matches
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private function similarCandidates(array $matches, array $item): array
    {
        $stems = $this->productWordStems((string) ($item['search_query'] ?? $item['query'] ?? ''), true);
        // Lista zapasowa bywa przypadkowa („Zestaw serwisowy 3M” za 27 880 zł pod „Wycieraczką gumową”) — podobna
        // karta musi mieć w nazwie ten sam wyraz wyrobu co pozycja („statyw”, „urządzenie ewakuacyjne”).
        $rows = array_values(array_filter(
            $this->productsForItem($matches, $item),
            fn (array $row): bool => in_array((string) ($row['source'] ?? ''), ['catalog', 'rule'], true)
                && array_intersect($stems, $this->productWordStems((string) ($row['name'] ?? ''))) !== [],
        ));
        usort($rows, static fn (array $a, array $b): int => ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0)));

        return array_map(
            static fn (array $row): array => $row + ['similar' => true],
            array_slice($rows, 0, self::MAX_SIMILAR_CANDIDATES),
        );
    }

    /**
     * Rdzenie wyrazów wyrobu (5 pierwszych liter bez polskich znaków) do porównania nazwy podobnej karty z pozycją.
     * Bez wyrazów ogólnych („bezpieczeństwa”, „ochronny”, „zestaw”); po stronie pozycji także bez zapisanych
     * wersalikami (marka, model: PROTEKT, RUP) — łączyłyby amortyzator ze statywem tej samej marki. Nazwy kart bywają
     * całe wersalikami („KALOSZE BEZPIECZNE PCV”), więc tam wersaliki zostają.
     *
     * @return list<string>
     */
    private function productWordStems(string $text, bool $skipUppercase = false): array
    {
        $generic = ['bezpi', 'ochro', 'roboc', 'zesta', 'kompl', 'jedno', 'wielo', 'uniwe', 'stand', 'profe', 'damsk', 'meski', 'dzial'];
        preg_match_all('/\p{L}{5,}/u', $text, $words);
        // pozycja cała wersalikami („KALOSZE PCV S5”) — wersaliki to wtedy zwykłe słowa, nie marka
        $skipUppercase = $skipUppercase && preg_match('/\p{Ll}/u', $text) === 1;
        $out = [];
        foreach ($words[0] as $word) {
            if ($skipUppercase && mb_strtoupper($word) === $word) {
                continue;
            }
            $stem = mb_substr(mb_strtolower(Str::ascii($word)), 0, 5);
            if (! in_array($stem, $generic, true)) {
                $out[$stem] = $stem;
            }
        }

        return array_values($out);
    }

    /**
     * Karty wskazane linkiem z maila przy tej pozycji (zapis z chwili analizy).
     *
     * @param  array<string, mixed>  $analysis
     * @return list<array<string, mixed>>
     */
    private function linkedCandidates(array $analysis, string $itemId): array
    {
        $byItem = is_array($analysis['link_candidates'] ?? null) ? $analysis['link_candidates'] : [];
        $out = [];
        foreach (is_array($byItem[$itemId] ?? null) ? $byItem[$itemId] : [] as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Karty z linków jako wiersze kandydatów. Oceny modelu nie mają — tej karty nikt nie
     * porównywał z wierszem, wskazał ją adres — więc wynik zostaje 0, a powód mówi, skąd są.
     *
     * @param  array<string, list<array{product: Product, url: string, match: string, variant: string|null}>>  $byItem
     * @return array<string, list<array<string, mixed>>>
     */
    private function linkCandidates(array $byItem): array
    {
        $out = [];
        foreach ($byItem as $itemId => $hits) {
            foreach ($hits as $hit) {
                $product = $hit['product'];
                $host = (string) preg_replace('/^www\./', '', mb_strtolower((string) parse_url($hit['url'], PHP_URL_HOST)));
                $variant = $hit['variant'] === null ? '' : ' Wariant z linku: '.$hit['variant'].'.';
                $safe = $this->safeProduct([
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'manufacturer' => $product->manufacturer,
                    'norms' => $product->norms,
                    'catalog_price_net' => $product->catalog_price_net,
                    'purchase_price' => $product->purchase_price,
                    'currency' => $product->currency ?? 'PLN',
                    'stock' => $product->stock,
                    'ai_match_source' => 'link',
                    'reason' => ($hit['match'] === 'exact'
                        ? 'Link z zapytania prowadzi do strony tej karty ('.$host.').'
                        : 'Link z zapytania prowadzi do tego wyrobu na '.$host.', ale w innym wariancie adresu (kolor, rozmiar) — sprawdź wariant.')
                        .$variant,
                ]);
                if ($safe === null) {
                    continue;
                }
                // ślad: który adres z maila i jak zgodny wskazał kartę
                $safe['link'] = ['url' => $hit['url'], 'match' => $hit['match']];
                $out[(string) $itemId][] = $safe;
            }
        }

        return $out;
    }

    /**
     * Czy na czele stoi jedyna karta z linku o tym samym adresie — pewne wskazanie klienta.
     * Kilka kart pod jednym adresem (kolory, rozmiary) albo inny wariant adresu wybiera handlowiec.
     *
     * @param  list<array<string, mixed>>  $candidates
     */
    private function linkedSingle(array $candidates): bool
    {
        $linked = array_values(array_filter(
            $candidates,
            static fn (array $p): bool => ($p['source'] ?? null) === 'link',
        ));

        return count($linked) === 1
            && ($candidates[0]['source'] ?? null) === 'link'
            && ($linked[0]['link']['match'] ?? null) === 'exact';
    }

    /**
     * Wyroby dobrane ręcznie przy tej pozycji, w kolejności dodania.
     *
     * @param  array<string, mixed>  $analysis
     * @return list<array<string, mixed>>
     */
    private function manualCandidates(array $analysis, string $itemId): array
    {
        if ($itemId === '') {
            return [];
        }
        $byItem = is_array($analysis['manual_candidates'] ?? null) ? $analysis['manual_candidates'] : [];
        $out = [];
        foreach (is_array($byItem[$itemId] ?? null) ? $byItem[$itemId] : [] as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Odsiewa wiersze, których model nie ocenił: podstawione „ten sam rodzaj
     * w katalogu” i skróty regułowe. To one podsuwały zestaw serwisowy 3M pod
     * wycieraczkę — z widoku znikają, ale w `analysis.matches` zostają, bo to
     * zapis tego, co wyszukiwarka naprawdę zwróciła.
     *
     * Te same źródła i to samo ustawienie z panelu, co w dopasowaniu przetargowym.
     * Wiersze bez znacznika (stare rekordy, zamienniki) przepuszczamy.
     *
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function rated(array $products): array
    {
        if ($this->aiSettings->matchAllowsCatalogRows()) {
            return $products;
        }

        return array_values(array_filter(
            $products,
            static fn (array $row): bool => ! in_array((string) ($row['source'] ?? ''), ['catalog', 'rule'], true),
        ));
    }

    /**
     * Zatwierdzone zamienniki kandydatów pozycji (zapisane w analysis.substitutes przy analizie).
     *
     * @param  array<string, mixed>  $analysis
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function substitutesForItem(array $analysis, array $candidates): array
    {
        $byMain = is_array($analysis['substitutes'] ?? null) ? $analysis['substitutes'] : [];
        $seen = [];
        $out = [];
        foreach ($candidates as $product) {
            $pid = (int) ($product['id'] ?? 0);
            foreach (is_array($byMain[$pid] ?? null) ? $byMain[$pid] : [] as $sub) {
                $sid = is_array($sub) ? (int) ($sub['id'] ?? 0) : 0;
                if ($sid <= 0 || isset($seen[$sid])) {
                    continue;
                }
                $seen[$sid] = true;
                $out[] = $sub;
            }
        }

        return $out;
    }

    /**
     * Wybór pozycji z `answers` albo domyślny. Tylko „p:<id>” z listy kandydatów/zamienników
     * albo „check”; stare „category” liczy się jak „check”.
     *
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<string, mixed>  $answers
     */
    private function chosenOptionFor(array $item, array $candidates, array $answers): string
    {
        $itemId = (string) ($item['id'] ?? '');
        // „product” = stara karta ogólna maila opisowego (rekordy sprzed pseudo-pozycji)
        foreach (['product:'.$itemId, 'product'] as $key) {
            $option = trim((string) ($answers[$key]['option_id'] ?? ''));
            if ($option === 'check' || $option === 'category') {
                return 'check';
            }
            if (str_starts_with($option, 'p:') && $this->candidateById($candidates, $option) !== null) {
                return $option;
            }
        }

        return $this->defaultOptionFor($item, $candidates);
    }

    /**
     * Warunki szczególne pozycji: „w szczególności na kwas siarkowy 96%”.
     *
     * Liczone z cytatu za każdym razem, a nie zapisywane w analizie: źródłem
     * jest wiersz klienta, który i tak stoi w bazie, a reguły siedzą w kodzie
     * z testami. Dzięki temu poprawiona reguła działa też dla zapytań
     * założonych wcześniej — a wysłane listy zostają takie, jakie poszły.
     *
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private function requirementsOf(array $item): array
    {
        return InquiryRequirements::fromQuote((string) ($item['quote'] ?? ''));
    }

    /**
     * Warunki, które da się sprawdzić w karcie (nazwane substancje).
     *
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private function checkableRequirements(array $item): array
    {
        return array_values(array_filter(
            $this->requirementsOf($item),
            static fn (array $requirement): bool => ($requirement['checkable'] ?? false) === true,
        ));
    }

    /**
     * Czy karta tego kandydata potwierdza wszystkie sprawdzalne warunki pozycji.
     *
     * @param  list<array<string, mixed>>  $requirements
     * @param  array<string, mixed>  $candidate
     */
    private function meetsRequirements(array $requirements, array $candidate): bool
    {
        if ($requirements === []) {
            return true;
        }
        $id = (int) ($candidate['id'] ?? 0);
        if ($id === 0) {
            return false;
        }

        return InquiryRequirements::unconfirmed($requirements, $this->cardCheckText($id)) === [];
    }

    /**
     * Tekst karty, w którym szukamy potwierdzenia warunku: opis, normy,
     * podsumowanie wersji i tabelki z kart B2B dostawców (tam bywają tabele
     * czasów przenikania, których opis nie niesie).
     */
    private function cardCheckText(int $productId): string
    {
        $this->warmCardChecks([$productId]);

        return $this->cardCheckTexts[$productId] ?? '';
    }

    /**
     * Wczytuje teksty kart jednym zapytaniem dla całej paczki wyrobów.
     *
     * Bez tego każdy kandydat w każdej pozycji szedł do bazy osobno: zapytanie
     * o dziesięć pozycji po trzech kandydatach to trzydzieści zapytań zamiast
     * jednego. Wynik zostaje na czas żądania — ten sam wyrób bywa kandydatem
     * w kilku pozycjach.
     *
     * @param  list<int>  $ids
     */
    private function warmCardChecks(array $ids): void
    {
        $missing = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && ! array_key_exists($id, $this->cardCheckTexts)) {
                $missing[$id] = true;
            }
        }
        if ($missing === []) {
            return;
        }

        $rows = Product::query()
            ->with('shopCards:id,product_id,fields')
            ->whereIn('id', array_keys($missing))
            ->get(['id', 'description', 'norms', 'variant_summary']);

        foreach ($rows as $product) {
            $parts = [
                (string) $product->description,
                (string) $product->norms,
                (string) $product->variant_summary,
            ];
            foreach ($product->shopCards as $card) {
                // Tabelka dostawcy jako tekst — szukamy w niej nazw substancji,
                // więc wystarczy zapis JSON z zachowanymi polskimi znakami.
                $parts[] = (string) json_encode($card->fields, JSON_UNESCAPED_UNICODE);
            }
            $this->cardCheckTexts[(int) $product->id] = trim(implode(' ', array_filter($parts)));
        }

        // Karty, której nie ma w bazie, nie pytamy drugi raz.
        foreach (array_keys($missing) as $id) {
            $this->cardCheckTexts[$id] ??= '';
        }
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return array<string, mixed>|null
     */
    private function candidateById(array $products, string $option): ?array
    {
        if (! str_starts_with($option, 'p:')) {
            return null;
        }
        $id = (int) substr($option, 2);
        foreach ($products as $product) {
            if ((int) ($product['id'] ?? 0) === $id) {
                return $product;
            }
        }

        return null;
    }

    /**
     * Rozmiar z nazwy wybranej karty („P-50mX - Szelki bezpieczeństwa - rozmiar S”), gdy różni się od rozmiaru
     * z zapytania. Zgodna jest tylko karta dokładnie w rozmiarze klienta: zapytanie o „M-XL” to trzy rozmiary,
     * więc karta M też go nie pokrywa. Null, gdy rozmiar się zgadza albo nie da się tego stwierdzić — pozycja
     * bez rozmiaru, rozmiar klienta, którego nie czytamy („L/52”), albo karta bez jednego rozmiaru w nazwie
     * (model z listą rozmiarów, zakres „rozmiar M-XL”). Rozmiaru z kodu SKU nie zgadujemy.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $product
     */
    private function chosenSizeMismatch(array $item, ?array $product): ?string
    {
        $requested = trim((string) ($item['size'] ?? ''));
        if ($product === null || $requested === '') {
            return null;
        }
        $sizes = new ProductSizeVariant;
        $card = $sizes->singleSizeFromName((string) ($product['name'] ?? ''));
        $wanted = $sizes->parseSizeList($requested);
        if ($card === null || $wanted === [] || $wanted === [$card]) {
            return null;
        }

        return mb_strtoupper($card);
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private function candidateView(array $product, float $margin): array
    {
        return [
            'id' => (int) $product['id'],
            'sku' => (string) ($product['sku'] ?? ''),
            'name' => (string) ($product['name'] ?? ''),
            'manufacturer' => (string) ($product['manufacturer'] ?? ''),
            'norms' => (string) ($product['norms'] ?? ''),
            'catalog_pln' => is_numeric($product['catalog_pln'] ?? null) ? (float) $product['catalog_pln'] : null,
            // ta sama marża, którą policzy list — inaczej panel pokazywał cenę z marży domyślnej
            'offer_pln' => $this->offerPln($product, $margin),
            'stock' => isset($product['stock']) && is_numeric($product['stock']) ? (int) $product['stock'] : null,
            'score' => (int) ($product['score'] ?? 0),
            'reason' => $this->nullable($product['reason'] ?? null),
            'source' => $this->nullable($product['source'] ?? null),
            // podobna karta z katalogu, której model nie zatwierdził (similarCandidates)
            'similar' => ($product['similar'] ?? false) === true,
            // uzupełnia present() hurtem (withOrderQuantities); null = brak warunku
            'order_quantity' => null,
            // uzupełnia present() hurtem (withImages): miniatura i pełne zdjęcie; null = karta bez zdjęcia
            'thumb_url' => null,
            'image_url' => null,
        ];
    }

    /**
     * Karty AI przypięte do pozycji (bez kart towaru/zamienników — te są widokiem `items`).
     *
     * @param  array<string, mixed>  $analysis
     * @return list<array<string, mixed>>
     */
    private function itemCards(array $analysis, string $itemId): array
    {
        $out = [];
        foreach ($this->storedCards($analysis) as $card) {
            $id = (string) $card['id'];
            if (str_starts_with($id, 'product:') || str_starts_with($id, 'substitutes:')) {
                continue;
            }
            if (($card['kind'] ?? null) === 'item' && (string) ($card['item_id'] ?? '') === $itemId) {
                $out[] = $this->cardView($card);
            }
        }

        return $out;
    }

    /**
     * Karty AI bez pozycji (ogólne niejasności) — bez kart handlowych i starych kart towaru.
     *
     * @param  array<string, mixed>  $analysis
     * @return list<array<string, mixed>>
     */
    private function globalCards(array $analysis): array
    {
        $out = [];
        foreach ($this->storedCards($analysis) as $card) {
            $id = (string) $card['id'];
            if (in_array($id, ['price', 'substitutes', 'product', 'missing'], true)) {
                continue;
            }
            if (($card['kind'] ?? 'global') !== 'item') {
                $out[] = $this->cardView($card);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array{id: string, title: string, prompt: string, options: list<array{id: string, label: string}>, allow_custom: bool}
     */
    private function cardView(array $card): array
    {
        $options = [];
        foreach (is_array($card['options'] ?? null) ? $card['options'] : [] as $option) {
            if (is_array($option) && isset($option['id'], $option['label'])) {
                $options[] = ['id' => (string) $option['id'], 'label' => (string) $option['label']];
            }
        }

        return [
            'id' => (string) $card['id'],
            'title' => (string) ($card['title'] ?? ''),
            'prompt' => (string) ($card['prompt'] ?? ''),
            'options' => $options,
            'allow_custom' => (bool) ($card['allow_custom'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return list<array<string, mixed>>
     */
    private function storedCards(array $analysis): array
    {
        $out = [];
        foreach (is_array($analysis['cards'] ?? null) ? $analysis['cards'] : [] as $card) {
            if (is_array($card) && isset($card['id'])) {
                $out[] = $card;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function priceModeOf(array $answers): string
    {
        $mode = trim((string) ($answers['price']['option_id'] ?? 'none'));

        return in_array($mode, self::PRICE_MODES, true) ? $mode : 'none';
    }

    private function formatMargin(float $margin): string
    {
        return rtrim(rtrim(number_format($margin, 2, '.', ''), '0'), '.');
    }

    /**
     * Ilość i jednostka pozycji. Stare rekordy mają `qty` = „30 szt.” — rozbijamy je tak samo.
     *
     * @param  array<string, mixed>  $item
     * @return array{qty: string|null, unit: string|null}
     */
    private function qtyUnit(array $item): array
    {
        $unit = $this->nullable($item['unit'] ?? null);
        $raw = $item['qty'] ?? null;
        if (is_int($raw) || is_float($raw)) {
            return ['qty' => $this->formatQty((string) $raw), 'unit' => $unit];
        }
        $raw = $this->nullable(is_string($raw) ? $raw : null);
        if ($raw === null) {
            return ['qty' => null, 'unit' => $unit];
        }
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(.*)$/u', $raw, $m) !== 1) {
            return ['qty' => null, 'unit' => $unit];
        }

        return [
            'qty' => $this->formatQty($m[1]),
            'unit' => $unit ?? $this->nullable($m[2]),
        ];
    }

    private function formatQty(string $number): string
    {
        $number = str_replace(',', '.', $number);
        if (str_contains($number, '.')) {
            $number = rtrim(rtrim($number, '0'), '.');
        }

        return $number === '' ? '0' : $number;
    }

    /**
     * „30 szt.” / „30” / null — do kart i nagłówka pozycji w liście.
     *
     * @param  array<string, mixed>  $item
     */
    private function qtyLabel(array $item): ?string
    {
        $qu = $this->qtyUnit($item);
        if ($qu['qty'] === null) {
            return null;
        }

        return $qu['unit'] === null ? $qu['qty'] : $qu['qty'].' '.$qu['unit'];
    }

    /**
     * @return array{
     *     subject: string|null,
     *     questions: list<string>,
     *     product_queries: list<string>,
     *     line_items: list<array<string, mixed>>,
     *     cards: list<array<string, mixed>>
     * }
     */
    private function extract(string $body, ?string $subject = null): array
    {
        // Temat idzie osobnym wierszem nad treścią: bywa w nim model wyrobu, a cytaty
        // pozycji dalej mają pochodzić z treści.
        $content = $subject === null ? $body : 'Temat maila: '.$subject."\n\n".$body;
        try {
            $raw = $this->llm->chatJson([
                [
                    'role' => 'system',
                    'content' => 'Jesteś asystentem handlowca BHP/PPE (Supon). '
                        .'Z maila klienta wyodrębnij tylko to, co widać w treści. Nie wymyślaj faktów. '
                        .'subject: krótki temat odpowiedzi (bez Re:). '
                        .'questions: konkretne pytania klienta. '
                        .'line_items: KAŻDA osobna pozycja (osobny wiersz, ilość albo rozmiar = osobna pozycja). '
                        .'Prośby o dokumenty (deklaracja zgodności, instrukcja, karta produktu, certyfikat, atest) '
                        .'i o warunki (termin realizacji, dostawa, płatność, ważność oferty) to NIE pozycje — wpisz je do questions. '
                        .'Nie łącz „rękawice 9” i „rękawice 10” w jedną. Max '.$this->maxLineItems().'. '
                        .'Każda pozycja: id (item_1…), quote (DOKŁADNY cytat wiersza z maila), '
                        .'qty (SAMA liczba jako string, np. „30”; brak → null), unit (jednostka DOKŁADNIE jak w mailu: „szt.”, „par”, „op.”; brak → null), '
                        .'query (fraza do katalogu BEZ rozmiaru, Z warunkiem: substancja, norma, typ), size (lub null), '
                        .'conflict: TYLKO gdy wiersz sam sobie przeczy — np. materiał, który z natury nie spełnia normy podanej '
                        .'w tym samym wierszu (drelich, bawełna albo dzianina bez powłoki a EN 374: tkanina przepuszcza '
                        .'chemikalia) — jedno zdanie po polsku, co z czym się kłóci; w każdym innym wypadku null. '
                        .'Nie oceniaj katalogu, ceny ani dostępności. '
                        .'Temat maila (pierwszy wiersz, jeśli jest) bywa nazwą albo kodem wyrobu: gdy pozycja w treści podaje '
                        .'tylko ilość i rozmiar, weź do query nazwę albo kod z tematu. Temat nie jest osobną pozycją. '
                        .'Nie dopisuj rodzaju wyrobu, którego nie ma ani w temacie, ani w treści. '
                        .'product_queries: unikalne query z line_items. '
                        .'cards: max 4 — TYLKO prawdziwe niejasności (rozmiar, wariant, termin). '
                        .'Nie pytaj o oczywistości. item_id jeśli karta dotyczy jednej pozycji. '
                        .'Każda karta: id (snake), title, prompt, options[{id,label}] (2-4), allow_custom (bool), item_id. '
                        .'JSON: {"subject":"","questions":[],"product_queries":[],"line_items":[],"cards":[]}.',
                ],
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ], 0.1, $this->extractMaxTokens(), null, AiTask::ClientInquiry);
        } catch (Throwable $e) {
            throw new RuntimeException('Nie udało się przeanalizować zapytania: '.$e->getMessage(), 0, $e);
        }

        $cards = [];
        foreach ($raw['cards'] ?? [] as $card) {
            $normalized = $this->normalizeCard($card);
            if ($normalized !== null) {
                $cards[] = $normalized;
            }
        }

        // Bez przycinania do limitu pozycji: model rozbija wiersz na rozmiary i bywa, że
        // podaje go jeszcze raz jako sumę — przycięcie przed scaleniem zabierało ostatnie
        // wiersze maila (#71). Limit liczy resolveLineItemsWithOmitted(), a nadmiar pokazuje.
        $lineItems = [];
        $index = 1;
        foreach ($raw['line_items'] ?? [] as $row) {
            $normalized = $this->normalizeLineItem($row, $index);
            if ($normalized !== null) {
                $lineItems[] = $normalized;
                $index++;
            }
        }

        return [
            'subject' => $this->nullable($raw['subject'] ?? null),
            'questions' => $this->stringList($raw['questions'] ?? null),
            'product_queries' => $this->stringList($raw['product_queries'] ?? null),
            'line_items' => $lineItems,
            'cards' => $cards,
        ];
    }

    /**
     * Szukanie planu fraz w dwóch rundach. Fraza modelu dla pozycji (`query`, inna niż jej klucz)
     * jest tylko zapasem — groupsForItem() sięga po nią, gdy klucz pozycji nic nie znalazł — więc
     * szukamy jej dopiero wtedy. Dotąd szła w tej samej fali co klucze: na produkcji 37 z 96 szukań
     * (zapytania #41–#71) to były takie frazy i żadna nie została użyta, a każda to osobna ocena
     * kart przez model, który liczy lokalnie — #71 (MESKO): 10 ocen dla 5 wyrobów, 160 s.
     *
     * W pierwszej rundzie zostaje wszystko, czego nie da się przypisać pozycji jako zapasu: fraza,
     * której pozycja nie ma swojego klucza w planie (stare rekordy, przycięcie do
     * queryCap()), jest jej jedynym szukaniem. Po awarii modelu drugiej rundy nie ma —
     * kazałaby czekać drugi raz na model, który przed chwilą nie odpowiedział, a pozycja i tak
     * pokazuje „model nie odpowiedział”.
     *
     * @param  list<array<string, mixed>>  $lineItems
     * @param  list<string>  $queries  plan z uniqueQueries() (`analysis.product_queries`)
     * @return list<array{query: string, products: list<array<string, mixed>>}>
     */
    private function matchInRounds(array $lineItems, array $queries): array
    {
        $planned = [];
        foreach ($queries as $query) {
            $planned[mb_strtolower(trim($query))] = true;
        }
        $itemKeys = [];
        foreach ($lineItems as $item) {
            $itemKeys[mb_strtolower(trim($this->itemSearchKey($item)))] = true;
        }
        // fraza zapasowa → klucze pozycji, które po nią sięgają; null = jedyne szukanie którejś pozycji
        $spareFor = [];
        foreach ($lineItems as $item) {
            $key = mb_strtolower(trim($this->itemSearchKey($item)));
            $phrase = mb_strtolower(trim((string) ($item['query'] ?? '')));
            if ($phrase === '' || isset($itemKeys[$phrase]) || ! isset($planned[$phrase])) {
                continue;
            }
            if (! isset($planned[$key]) || (array_key_exists($phrase, $spareFor) && $spareFor[$phrase] === null)) {
                $spareFor[$phrase] = null;

                continue;
            }
            $spareFor[$phrase][] = $key;
        }

        $first = [];
        $spare = [];
        foreach ($queries as $query) {
            $lower = mb_strtolower(trim($query));
            // isset() na null daje false — fraza, która jest czyimś jedynym szukaniem, idzie od razu
            if (isset($spareFor[$lower])) {
                $spare[$lower] = $query;
            } else {
                $first[] = $query;
            }
        }
        $matches = $this->matchProducts($first);
        if ($spare === []) {
            return $matches;
        }

        $byQuery = [];
        foreach ($matches as $group) {
            $byQuery[mb_strtolower(trim($group['query']))] = $group;
        }
        $retry = [];
        foreach ($spare as $lower => $query) {
            foreach ($spareFor[$lower] as $key) {
                $group = $byQuery[$key] ?? null;
                if ($group !== null && $group['products'] === [] && ($group['model_failed'] ?? false) !== true) {
                    $retry[] = $query;

                    break;
                }
            }
        }

        return $retry === [] ? $matches : [...$matches, ...$this->matchProducts($retry)];
    }

    /**
     * Klucz, pod którym pozycja szuka w katalogu: zapisany przy analizie albo — w starych
     * rekordach — liczony z frazy i cytatu.
     *
     * @param  array<string, mixed>  $item
     */
    private function itemSearchKey(array $item): string
    {
        return $this->nullable($item['search_query'] ?? null) ?? $this->catalogSearchQuery(
            (string) ($item['query'] ?? ''),
            (string) ($item['quote'] ?? '')
        );
    }

    private static function msSince(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1e6);
    }

    /**
     * Zapis zdarzeń wyszukiwania pozycji zapytania (koszt, pula, karty oceny) z numerem zapytania. Recorder połyka
     * własne błędy, więc statystyka nie psuje analizy maila.
     */
    private function flushSearchEvents(int $inquiryId, ?int $userId): void
    {
        $events = $this->pendingSearchEvents;
        $this->pendingSearchEvents = [];
        if ($events === []) {
            return;
        }
        $recorder = app(SearchEventRecorder::class);
        $runId = (string) Str::ulid();
        foreach ($events as $event) {
            $recorder->record(
                $event['query'],
                $event['result'],
                is_array($event['result']['trace'] ?? null) ? $event['result']['trace'] : [],
                $userId,
                SearchEvent::TASK_INQUIRY,
                ['run_id' => $runId, 'context_type' => SearchEvent::CONTEXT_INQUIRY, 'context_id' => $inquiryId],
            );
        }
    }

    /**
     * @param  list<string>  $queries
     * @return list<array{query: string, products: list<array<string, mixed>>}>
     */
    private function matchProducts(array $queries): array
    {
        // Plan fraz jest już przycięty (uniqueQueries); rematch szuka zapisanym planem, choćby limit potem zmalał.
        $sliced = array_values($queries);
        $started = hrtime(true);
        try {
            // z zapasem: wiersze nieocenione odsiewamy dopiero przy pokazywaniu,
            // więc przycięcie do trójki przed odsiewem zabrałoby dobre trafienia
            $rawGroups = $this->search->findMany($sliced, self::MAX_MATCHES_PER_QUERY * 3, $this->searchProgress);
        } catch (Throwable $e) {
            Log::warning('Zapytanie klienta: wyszukiwanie w katalogu padło', ['error' => $e->getMessage()]);
            $rawGroups = [];
            foreach ($sliced as $query) {
                $rawGroups[] = ['query' => $query, 'products' => [], 'model_state' => ProductAiSearchService::MODEL_STATE_UNAVAILABLE];
            }
        }
        $this->searchRounds[] = [
            'queries' => count($sliced),
            'ms' => self::msSince($started),
            'stages_ms' => is_array($rawGroups[0]['timings_ms'] ?? null) ? $rawGroups[0]['timings_ms'] : [],
        ];

        $groups = [];
        foreach ($rawGroups as $i => $result) {
            $query = (string) ($result['query'] ?? $sliced[$i] ?? '');
            if (is_array($result['ai_usage'] ?? null)) {
                $this->pendingSearchEvents[] = ['query' => $query, 'result' => $result];
            }
            // Klient nazwał markę, której nie ma w katalogu: każdy wynik to zamiennik innej marki.
            // Znacznik na wierszu, bo pewność i wybór domyślny liczą się z samych kandydatów.
            $absentBrand = $this->nullable($result['requested_brand_absent'] ?? null);
            $products = [];
            foreach ($result['products'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $safe = $this->safeProduct($row);
                if ($safe !== null) {
                    if ($absentBrand !== null) {
                        $safe['requested_brand_absent'] = $absentBrand;
                    }
                    $products[] = $safe;
                }
            }
            $group = ['query' => $query, 'products' => $products];
            // Po awarii modelu nikt kart nie ocenił — pusta lista w widoku to wtedy nie „brak
            // w katalogu”. Także gdy zostały wiersze zapasowe („ten sam rodzaj”), bo widok je odsiewa.
            if (($result['model_state'] ?? null) === ProductAiSearchService::MODEL_STATE_UNAVAILABLE) {
                $group['model_failed'] = true;
            }
            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * @param  list<array<string, mixed>>  $aiCards
     * @param  list<array{query: string, products: list<array<string, mixed>>}>  $matches
     * @param  list<array<string, mixed>>  $lineItems
     * @param  array<int, list<array<string, mixed>>>  $subsByProductId
     * @return list<array<string, mixed>>
     */
    public function buildCards(array $aiCards, array $matches, array $lineItems = [], array $subsByProductId = []): array
    {
        $flat = $this->flatProducts($matches);
        $best = $flat[0] ?? null;
        $confidentSingle = count($flat) === 1
            && $best !== null
            && (int) ($best['score'] ?? 0) >= 80;

        if ($confidentSingle && $aiCards === [] && count($lineItems) <= 1) {
            return [];
        }

        $cards = [];
        $used = [];
        if ($lineItems !== []) {
            foreach ($lineItems as $item) {
                $products = $this->productsForItem($matches, $item);
                $productCard = $this->productCardForItem($item, $products);
                $cards[] = $productCard;
                $used[] = (string) $productCard['id'];

                foreach ($this->aiCardsForItem($aiCards, $lineItems, (string) $item['id'], $used) as $card) {
                    $cards[] = $card;
                    $used[] = (string) $card['id'];
                }

                // karta zamienników tylko, gdy kandydaci mają zatwierdzone zamienniki
                if ($this->hasSubstitutes($products, $subsByProductId)) {
                    $subCard = $this->substituteCardForItem($item, $products, $subsByProductId);
                    $cards[] = $subCard;
                    $used[] = (string) $subCard['id'];
                }
            }
            foreach ($this->aiCardsForItem($aiCards, $lineItems, null, $used) as $card) {
                $cards[] = $card;
                $used[] = (string) $card['id'];
            }

            return $this->appendCommerce($cards, $used, true, false);
        }

        if ($flat !== [] && ! $confidentSingle) {
            $options = [];
            foreach (array_slice($flat, 0, 5) as $product) {
                $options[] = [
                    'id' => 'p:'.$product['id'],
                    'label' => $product['sku'].' · '.$product['name'],
                ];
            }
            $options[] = ['id' => 'check', 'label' => 'Napisz, że sprawdzimy'];
            $cards[] = [
                'id' => 'product',
                'title' => 'Produkt',
                'prompt' => 'Który produkt z katalogu wskazać w odpowiedzi?',
                'options' => $options,
                'allow_custom' => false,
                'kind' => 'global',
            ];
            $used[] = 'product';
        } elseif ($flat === []) {
            $cards[] = [
                'id' => 'missing',
                'title' => 'Brak w katalogu',
                'prompt' => 'Nie znaleziono produktu w katalogu. Jak odpowiedzieć?',
                'options' => [
                    ['id' => 'check', 'label' => 'Sprawdzimy i wrócimy'],
                ],
                'allow_custom' => true,
                'kind' => 'global',
            ];
            $used[] = 'missing';
        }

        foreach ($this->aiCardsForItem($aiCards, [], null, $used) as $card) {
            $cards[] = $card;
            $used[] = (string) $card['id'];
        }

        return $this->appendCommerce($cards, $used, $flat !== [], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function commerceCards(): array
    {
        return [
            [
                'id' => 'price',
                'title' => 'Ceny',
                'prompt' => 'Czy podać cenę w liście? Zawsze w złotych (NBP). Ceny zakupu nigdy nie idą do listu.',
                'options' => [
                    ['id' => 'none', 'label' => 'Bez ceny'],
                    ['id' => 'catalog', 'label' => 'Cena katalogowa (PLN)'],
                    ['id' => 'catalog_margin', 'label' => 'Cena oferty (zakup + marża, PLN)'],
                ],
                'allow_custom' => false,
            ],
            [
                'id' => 'substitutes',
                'title' => 'Zamienniki',
                'prompt' => 'Czy proponować zamienniki?',
                'options' => [
                    ['id' => 'no', 'label' => 'Tylko wskazany produkt'],
                    ['id' => 'yes', 'label' => 'Zaproponuj zamienniki jeśli są'],
                ],
                'allow_custom' => false,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @param  list<string>  $used
     * @return list<array<string, mixed>>
     */
    private function appendCommerce(array $cards, array $used, bool $hasCatalog, bool $includeSubstitutes): array
    {
        if (! $hasCatalog) {
            return array_slice($cards, 0, $this->cardCap());
        }
        foreach ($this->commerceCards() as $card) {
            if (! $includeSubstitutes && ($card['id'] ?? '') === 'substitutes') {
                continue;
            }
            if (in_array($card['id'], $used, true)) {
                continue;
            }
            $card['kind'] = 'global';
            $cards[] = $card;
            $used[] = $card['id'];
            if (count($cards) >= $this->cardCap()) {
                break;
            }
        }

        return array_slice($cards, 0, $this->cardCap());
    }

    /**
     * @param  list<array<string, mixed>>  $fromAi
     * @return list<array<string, mixed>>
     */
    public function resolveLineItems(string $body, array $fromAi, ?string $subjectHint = null): array
    {
        return $this->resolveLineItemsWithOmitted($body, $fromAi, $subjectHint)['items'];
    }

    /**
     * Pozycje zapytania i wiersze maila, które do nich nie weszły (ponad limit pozycji albo
     * o niepewnym pokryciu). Nie mogą zniknąć po cichu (zapytanie #71: szelki i amortyzator
     * przepadły bez śladu) — handlowiec widzi je jako wiersze do dopisania ręcznie.
     *
     * `merged_ids`: pozycja modelu → pozycja, która ją zastąpiła (suma i jej rozmiary),
     * żeby karty modelu wskazujące usuniętą pozycję nie przepadły.
     *
     * @param  list<array<string, mixed>>  $fromAi
     * @param  string|null  $subject  temat maila, który widział ekstraktor — razem z treścią to słowa klienta
     * @return array{
     *     items: list<array<string, mixed>>,
     *     omitted: list<array{quote: string, qty: string|null, unit: string|null, size: string|null}>,
     *     merged_ids: array<string, string>
     * }
     */
    public function resolveLineItemsWithOmitted(string $body, array $fromAi, ?string $subjectHint = null, ?string $subject = null): array
    {
        $parsed = $this->parseLineItemsFromBody($body);
        // Parser przeważa nad modelem, gdy znalazł więcej pozycji — ale liczą się tylko
        // wiersze ze znamionami wyrobu. Inaczej telefon czy urwany wiersz ze stopki
        // przegłosowywały poprawną odpowiedź modelu samą liczbą. Liczymy jak dawniej
        // w granicach limitu: dłuższy mail nie może przez to zabrać pozycji modelowi.
        $credible = count(array_filter(
            array_slice($parsed, 0, $this->maxLineItems()),
            fn (array $item): bool => $this->isCredibleRow($item),
        ));
        $merged = [];
        $unplaced = [];
        if ($parsed !== [] && $credible > count($fromAi)) {
            $items = $this->withAiConflicts($parsed, $fromAi);
        } elseif ($fromAi !== []) {
            $items = $this->withProductRowQuotes($this->quantitiesCheckedAgainstQuote($fromAi), $parsed, $body);
            $items = $this->withTableQuantities($items, $parsed);
            ['items' => $items, 'merged_ids' => $merged, 'unplaced' => $unplaced]
                = $this->withoutDoubledSizeBreakdowns($items, $parsed, count($fromAi) >= $this->maxLineItems());
        } else {
            $items = $parsed;
        }

        $omitted = [];
        foreach ([...array_slice($items, $this->maxLineItems()), ...$unplaced] as $item) {
            $qtyUnit = $this->qtyUnit($item);
            $omitted[] = [
                'quote' => (string) ($this->nullable($item['quote'] ?? null) ?? $item['query'] ?? ''),
                'qty' => $qtyUnit['qty'],
                'unit' => $qtyUnit['unit'],
                'size' => $this->nullable($item['size'] ?? null),
            ];
        }

        return [
            'items' => $this->withoutTableInternals($this->withSearchQueries(
                array_slice($items, 0, $this->maxLineItems()),
                $subjectHint,
                trim(($subject ?? '')."\n".$body),
            )),
            'omitted' => $omitted,
            'merged_ids' => $merged,
        ];
    }

    /**
     * Wiersz z rozbiciem na rozmiary („432 pary / Rozmiar: 8-108par,9-108par,10-216par.”)
     * model podaje raz jako sumę, raz po rozmiarach — a bywa, że oba naraz (zapytanie #71:
     * pozycja 432 pary i trzy pozycje rozmiarów, w ofercie 864 pary). Zostaje jedno z dwóch,
     * nigdy oba. Suma i rozmiary to jeden wiersz, gdy wskazują ten sam wiersz parsera
     * (bez parsera: ten sam cytat), a do tego:
     *
     * - rozmiary modelu sumują się do sumy — wybór postaci niżej;
     * - nie sumują się (model pominął rozmiar), ale rozbicie w samym cytacie sumuje się
     *   do sumy — zostaje suma, bo tę liczbę napisał klient;
     * - poza tym niczego nie ruszamy: to mogą być dwa wyroby w jednym wierszu.
     *
     * Postać wybieramy raz dla całego maila: rozmiary, jeśli wszystkie wiersze mieszczą się
     * wtedy w limicie pozycji, inaczej sumy (z cytatem, w którym widać rozbicie). Cały
     * wiersz maila jest ważniejszy niż rozbicie innego wiersza na rozmiary.
     *
     * Gdy model skończył na limicie pozycji, wiersze parsera za ostatnim zacytowanym
     * dopisujemy — model przestał wypisywać przed końcem maila (#50, #71). Jeśli nie każda
     * pozycja modelu wskazuje swój wiersz, pokrycia nie znamy: takie wiersze idą tylko do
     * pominiętych (`unplaced`), żeby nie powtórzyć pozycji, którą model zacytował inaczej.
     *
     * @param  list<array<string, mixed>>  $items  pozycje modelu po sprawdzeniu ilości i cytatów
     * @param  list<array<string, mixed>>  $parsed  wiersze parsera, w kolejności maila
     * @return array{items: list<array<string, mixed>>, merged_ids: array<string, string>, unplaced: list<array<string, mixed>>}
     */
    private function withoutDoubledSizeBreakdowns(array $items, array $parsed, bool $modelHitLimit): array
    {
        $rows = $this->comparableRows($parsed);

        $rowOf = [];
        $groups = [];
        foreach ($items as $i => $item) {
            $quote = (string) ($item['quote'] ?? '');
            $rowOf[$i] = $this->parsedRowOf($quote, $rows);
            $key = $rowOf[$i] !== null ? 'row:'.$rowOf[$i] : 'quote:'.$this->comparableQuote($quote);
            $groups[$key][] = $i;
        }

        // [indeks sumy, indeksy rozmiarów] — wiersze do zwinięcia albo rozwinięcia
        $splits = [];
        $folds = [];
        foreach ($groups as $members) {
            $totals = array_values(array_filter(
                $members,
                static fn (int $i): bool => trim((string) ($items[$i]['size'] ?? '')) === '',
            ));
            $sized = array_values(array_diff($members, $totals));
            if (count($totals) !== 1 || $sized === []) {
                continue;
            }
            $total = $totals[0];
            $totalQty = $this->numericQty($items[$total]['qty'] ?? null);
            $unit = $this->unitKind($items[$total]['unit'] ?? null);
            if ($totalQty === null) {
                continue;
            }
            $sum = 0.0;
            $complete = true;
            foreach ($sized as $s) {
                $qty = $this->numericQty($items[$s]['qty'] ?? null);
                $complete = $complete && $qty !== null && $this->unitKind($items[$s]['unit'] ?? null) === $unit;
                $sum += $qty ?? 0.0;
            }
            if ($complete && abs($sum - $totalQty) < 0.001) {
                $splits[] = [$total, $sized];

                continue;
            }
            // rozbicie z samego cytatu (co najmniej dwa rozmiary) sumuje się do sumy klienta
            $pairs = $this->sizeBreakdownPairs($this->fullestQuote($items, $total, $sized));
            $pairsSum = 0.0;
            foreach ($pairs as $pair) {
                $pairsSum += $this->unitKind($pair['unit']) === $unit ? (float) $pair['qty'] : NAN;
            }
            if (count($pairs) >= 2 && abs($pairsSum - $totalQty) < 0.001) {
                $folds[] = [$total, $sized];
            }
        }

        $missing = [];
        $unplaced = [];
        $placed = array_filter($rowOf, static fn (?int $r): bool => $r !== null);
        if ($modelHitLimit && $placed !== []) {
            $certain = count($placed) === count($rowOf);
            $lastPlaced = max($placed);
            foreach ($parsed as $r => $row) {
                if ($r > $lastPlaced && $this->isCredibleRow($row)) {
                    if ($certain) {
                        $missing[] = $row;
                    } else {
                        $unplaced[] = $row;
                    }
                }
            }
        }

        // rozmiary zostają, gdy wszystko mieści się w limicie; inaczej każdy taki wiersz to suma
        $foldedCount = count($items) + count($missing);
        foreach ([...$splits, ...$folds] as [$total, $sized]) {
            $foldedCount -= count($sized);
        }
        $expandedCount = $foldedCount;
        foreach ($splits as [$total, $sized]) {
            $expandedCount += count($sized) - 1;
        }
        if ($expandedCount > $this->maxLineItems()) {
            $folds = [...$folds, ...$splits];
            $splits = [];
        }

        $drop = [];
        $merged = [];
        foreach ($splits as [$total, $sized]) {
            $drop[] = $total;
            $merged[(string) $items[$total]['id']] = (string) $items[$sized[0]]['id'];
            foreach ($sized as $s) {
                // uwaga modelu o sprzeczności dotyczy wiersza, więc i każdego rozmiaru
                $items[$s]['conflict'] = $this->nullable($items[$s]['conflict'] ?? null) ?? $this->nullable($items[$total]['conflict'] ?? null);
            }
        }
        foreach ($folds as [$total, $sized]) {
            $items[$total]['quote'] = $this->fullestQuote($items, $total, $sized);
            foreach ($sized as $s) {
                $drop[] = $s;
                $merged[(string) $items[$s]['id']] = (string) $items[$total]['id'];
                $items[$total]['conflict'] = $this->nullable($items[$total]['conflict'] ?? null) ?? $this->nullable($items[$s]['conflict'] ?? null);
            }
        }

        $out = [];
        $next = 0;
        foreach ($items as $i => $item) {
            if (preg_match('/^item_(\d+)$/', (string) ($item['id'] ?? ''), $m) === 1) {
                $next = max($next, (int) $m[1]);
            }
            if (! in_array($i, $drop, true)) {
                $out[] = $item;
            }
        }
        foreach ($missing as $row) {
            // numeracja parsera zderzyłaby się z numeracją modelu (karty modelu wskazują item_N)
            $row['id'] = 'item_'.(++$next);
            $out[] = $row;
        }

        return ['items' => $out, 'merged_ids' => $merged, 'unplaced' => $unplaced];
    }

    /**
     * Wiersz parsera, z którego pochodzi cytat pozycji: cytat obejmuje treść wiersza (bez numeru
     * pozycji) albo sam jest kawałkiem jednego wiersza. Niejednoznacznie — null: lepiej nie
     * łączyć pozycji, niż złączyć dwa różne wyroby.
     *
     * @param  array<int, array{full: string, text: string}>  $rows
     */
    private function parsedRowOf(string $quote, array $rows): ?int
    {
        $quote = $this->comparableQuote($quote);
        if (mb_strlen($quote) < 8) {
            return null;
        }
        // cytat obejmuje wiersz (np. wiersz + linia rozmiarów) — wygrywa najdłuższy objęty wiersz
        $best = null;
        $bestLength = 0;
        $tie = false;
        // cytat jest kawałkiem wiersza — tylko gdy nie ma go w żadnym innym
        $inside = [];
        foreach ($rows as $r => $row) {
            $length = mb_strlen($row['text']);
            if ($length >= 4 && $this->containsWords($quote, $row['text'])) {
                if ($length > $bestLength) {
                    [$best, $bestLength, $tie] = [$r, $length, false];
                } elseif ($length === $bestLength) {
                    $tie = true;
                }
            } elseif ($this->containsWords($row['full'], $quote)) {
                $inside[] = $r;
            }
        }
        if ($best !== null) {
            return $tie ? null : $best;
        }

        return count($inside) === 1 ? $inside[0] : null;
    }

    /**
     * Wiersze parsera do porównania z cytatem modelu (parsedRowOf): cały wiersz i jego treść bez numeru pozycji.
     *
     * @param  list<array<string, mixed>>  $parsed
     * @return array<int, array{full: string, text: string}>
     */
    private function comparableRows(array $parsed): array
    {
        $rows = [];
        foreach ($parsed as $r => $row) {
            $quote = (string) ($row['quote'] ?? '');
            $rows[$r] = [
                'full' => $this->comparableQuote($quote),
                'text' => $this->comparableQuote($this->rowTextWithoutNumber($quote)),
            ];
        }

        return $rows;
    }

    /**
     * Ilość pozycji modelu sprawdzona wierszem tabeli z pliku, który cytuje. Model czyta tabelę jak tekst i bywa, że
     * bierze Lp. za ilość („1 | Kombinezon… | szt. | 275” → 1); `quoteHasNumber` to przepuszczało, bo „1” stoi
     * w cytacie. Przy tabeli z nagłówkiem ilością jest komórka kolumny ilości — także gdy jej nie da się odczytać
     * (wtedy pusta ilość z flagą, nigdy liczba modelu spoza tej kolumny). Bez nagłówka wiemy tylko, która liczba
     * to Lp.: ilość równa Lp. jest odrzucana. Pozycje z rozmiarem zostają — tabela podaje ilość całego wiersza.
     * Trafiona pozycja dostaje za cytat cały wiersz tabeli.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $parsed
     * @return list<array<string, mixed>>
     */
    private function withTableQuantities(array $items, array $parsed): array
    {
        $tableRows = array_filter($parsed, static fn (array $row): bool => ($row['qty_source'] ?? null) === 'table');
        if ($tableRows === []) {
            return $items;
        }
        $rows = $this->comparableRows($parsed);

        foreach ($items as $i => $item) {
            if ($this->nullable($item['size'] ?? null) !== null) {
                continue;
            }
            $quote = (string) ($item['quote'] ?? '');
            $r = $this->parsedRowOf($quote, $rows);
            if ($r === null || ! isset($tableRows[$r])) {
                // cytat skrócony albo sama nazwa: jednoznaczne trafienie po początku nazwy wyrobu z tabeli
                $r = null;
                $comparable = $this->comparableQuote($quote);
                foreach ($tableRows as $t => $row) {
                    $prefix = $this->comparableQuote(mb_substr((string) ($row['table_name'] ?? ''), 0, 40));
                    if (mb_strlen($prefix) >= 12 && str_contains($comparable, $prefix)) {
                        if ($r !== null) {
                            $r = null;

                            break;
                        }
                        $r = $t;
                    }
                }
            }
            if ($r === null) {
                continue;
            }
            $row = $tableRows[$r];
            // cytatem pozycji jest cały wiersz tabeli, jak stoi w pliku — urywek modelu („RUP 502-U … MBS: 20 k”)
            // szedłby do katalogu jako fraza
            $item['quote'] = (string) $row['quote'];
            $modelQty = $this->nullable($item['qty'] ?? null);
            $rowQty = $this->nullable($row['qty'] ?? null);

            if (($row['table_header'] ?? false) === true || $rowQty !== null) {
                if ($rowQty === $modelQty && $rowQty !== null) {
                    $item['unit'] = $this->nullable($row['unit'] ?? null) ?? $item['unit'] ?? null;
                } else {
                    $item['qty'] = $rowQty;
                    $item['unit'] = $rowQty === null ? null : $this->nullable($row['unit'] ?? null);
                    $item['qty_source'] = $rowQty === null ? 'model_unverified' : 'table';
                }
            } elseif ($modelQty !== null && isset($row['table_lp']) && $modelQty === (string) $row['table_lp']) {
                $item['qty'] = null;
                $item['unit'] = null;
                $item['qty_source'] = 'model_unverified';
            }
            $items[$i] = $item;
        }

        return $items;
    }

    /**
     * Pola parsera tabel potrzebne tylko w trakcie rozbioru — nie idą do zapisanej analizy.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function withoutTableInternals(array $items): array
    {
        return array_map(static function (array $item): array {
            unset($item['table_lp'], $item['table_name'], $item['table_header']);

            return $item;
        }, $items);
    }

    /** Czy tekst zawiera fragment w granicach słów („kask” nie trafia w „kaskiem”). */
    private function containsWords(string $haystack, string $needle): bool
    {
        return $needle !== ''
            && preg_match('/(?<![\p{L}\d])'.preg_quote($needle, '/').'(?![\p{L}\d])/u', $haystack) === 1;
    }

    /** Treść wiersza bez numeru pozycji z przodu („2. Rękawice…” → „Rękawice…”, „1 | Kombinezon | 275” → „Kombinezon | 275”). */
    private function rowTextWithoutNumber(string $line): string
    {
        $cells = $this->tableCells($line);
        if ($cells !== null && preg_match(self::BARE_NUMBER_CELL, $cells[0]) === 1) {
            return implode(' | ', array_slice($cells, 1));
        }
        $marked = $this->positionMarker($line);
        if ($marked !== null) {
            return $marked['rest'];
        }

        return preg_match(self::ROW_NUMBER, trim($line), $m) === 1 ? trim($m[3]) : $line;
    }

    /**
     * Cytat sumy, gdy zostaje zamiast rozmiarów: najdłuższy cytat grupy, który go zawiera —
     * wiersz razem z linią rozmiarów, żeby handlowiec widział rozbicie, jak stoi w mailu.
     * Cytat rozmiaru bywa wierszem parsera z numerem pozycji („2. Rękawice…”) — bierzemy go
     * od miejsca, w którym zaczyna się cytat sumy, inaczej numer wiersza szedł do listu
     * (zapytanie #72: „Poz. 2 … 2. Rękawice ochronne…”).
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<int>  $sized
     */
    private function fullestQuote(array $items, int $total, array $sized): string
    {
        $own = (string) ($items[$total]['quote'] ?? '');
        $quote = $own;
        foreach ($sized as $s) {
            $candidate = (string) ($items[$s]['quote'] ?? '');
            $at = $own === '' ? false : mb_strpos($candidate, $own);
            if ($at !== false) {
                $candidate = mb_substr($candidate, $at);
            }
            if (mb_strlen($candidate) > mb_strlen($quote)
                && str_contains($this->comparableQuote($candidate), $this->comparableQuote($own))) {
                $quote = $candidate;
            }
        }

        return $quote;
    }

    private function numericQty(mixed $qty): ?float
    {
        $qty = str_replace(',', '.', trim((string) $qty));

        return is_numeric($qty) ? (float) $qty : null;
    }

    /**
     * Rodzaj jednostki do porównania ilości: „pary” i „par” to jedno, „op.” i „szt.” nie.
     * Brak jednostki to osobny rodzaj — nie zgadujemy, że chodziło o pary.
     */
    private function unitKind(mixed $unit): string
    {
        $letters = preg_replace('/[^\p{L}]+/u', '', mb_strtolower(trim((string) $unit))) ?? '';

        return mb_substr($letters, 0, 2);
    }

    /**
     * Gdy wygrał nasz parser, uwaga modelu o sprzeczności w wierszu nie może zginąć — przypisujemy ją
     * pozycji, której cytat zawiera cytat modelu albo w nim się zawiera. Bez pewnego dopasowania nie
     * przypisujemy: uwaga przy cudzym wierszu byłaby gorsza niż jej brak.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $fromAi
     * @return list<array<string, mixed>>
     */
    private function withAiConflicts(array $items, array $fromAi): array
    {
        $conflicts = [];
        foreach ($fromAi as $row) {
            $text = $this->nullable($row['conflict'] ?? null);
            $quote = $this->comparableQuote((string) ($row['quote'] ?? ''));
            if ($text !== null && mb_strlen($quote) >= 8) {
                $conflicts[] = ['quote' => $quote, 'text' => $text];
            }
        }
        if ($conflicts === []) {
            return $items;
        }
        foreach ($items as $i => $item) {
            $quote = $this->comparableQuote((string) ($item['quote'] ?? ''));
            if (mb_strlen($quote) < 8) {
                continue;
            }
            foreach ($conflicts as $conflict) {
                if (str_contains($quote, $conflict['quote']) || str_contains($conflict['quote'], $quote)) {
                    $items[$i]['conflict'] = $conflict['text'];

                    break;
                }
            }
        }

        return $items;
    }

    private function comparableQuote(string $quote): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $quote)));
    }

    /**
     * Model rozbija wiersz na rozmiary i bywa, że cytuje sam rozmiar („Rozmiar: 8-108par”,
     * „9-108par”) zamiast wiersza wyrobu nad nim (zapytanie #51, 23.09.2026). Cytat bez nazwy
     * wyrobu gubił symbol z maila („ściągaczem-symbol RNITz”): szukanie szło frazą modelu,
     * a handlowiec widział jako prośbę klienta same rozmiary. Taki cytat dostaje wiersz
     * wyrobu z maila, pod którym stoi — dosłownie, bez przepisywania. Ilość i rozmiar
     * zostają z fragmentu, bo to on mówi, ile par którego rozmiaru.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $parsed  wiersze parsera, w kolejności maila
     * @return list<array<string, mixed>>
     */
    private function withProductRowQuotes(array $items, array $parsed, string $body): array
    {
        $rows = [];
        foreach ($parsed as $row) {
            $quote = trim((string) ($row['quote'] ?? ''));
            $at = $quote === '' ? false : mb_strpos($body, $quote);
            if ($at !== false) {
                $rows[] = ['quote' => $quote, 'start' => $at, 'end' => $at + mb_strlen($quote)];
            }
        }
        if ($rows === []) {
            return $items;
        }

        foreach ($items as $i => $item) {
            $quote = trim((string) ($item['quote'] ?? ''));
            if ($quote === '' || InquiryQueryText::namesProduct($quote)) {
                continue;
            }
            $at = mb_strpos($body, $quote);
            // fragment stojący w mailu dwa razy („108 par”) nie mówi, pod którym wierszem stoi
            if ($at === false || mb_substr_count($body, $quote) > 1) {
                continue;
            }
            // ostatni wiersz parsera zaczynający się przed fragmentem — tylko jeśli nazywa wyrób
            $row = null;
            foreach ($rows as $candidate) {
                if ($candidate['start'] <= $at) {
                    $row = $candidate;
                }
            }
            if ($row === null || ! InquiryQueryText::namesProduct($row['quote'])) {
                continue;
            }
            if ($at < $row['end']) {
                $items[$i]['quote'] = $row['quote'];

                continue;
            }
            // fragment z wiersza pod pozycją — cytujemy oba wiersze, jak stoją w mailu
            $lineStart = mb_strrpos(mb_substr($body, 0, $at), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $lineEnd = mb_strpos($body, "\n", $at);
            $line = trim(mb_substr($body, $lineStart, ($lineEnd === false ? mb_strlen($body) : $lineEnd) - $lineStart));
            if ($lineStart <= $row['start']) {
                // ten sam wiersz, tylko za końcem cytatu parsera
                $items[$i]['quote'] = $line;

                continue;
            }
            $between = trim(mb_substr($body, $row['end'], $lineStart - $row['end']));
            // między wierszem wyrobu a fragmentem stoi coś jeszcze — to już nie jego ciąg dalszy
            if ($between !== '') {
                continue;
            }
            $items[$i]['quote'] = $row['quote'].' '.$line;
        }

        return $items;
    }

    /**
     * Wiersz z parsera, który może przegłosować model: nazywa wyrób (słowo albo kod)
     * albo niesie ilość z jednostką czy rozmiar — i nie jest wierszem kontaktowym.
     *
     * @param  array<string, mixed>  $item
     */
    private function isCredibleRow(array $item): bool
    {
        $quote = (string) ($item['quote'] ?? '');
        if (InquiryMailText::isContactLine($quote)) {
            return false;
        }

        return InquiryQueryText::namesProduct($quote) || $this->looksLikeGoodsRow($quote);
    }

    /**
     * Ilość podana przez model sprawdzona cytatem wiersza. Te same reguły co przy własnym
     * rozbiorze maila: „a 100 szt.” to wielkość opakowania (część wyrobu), liczba przy cenie
     * nie jest ilością, a zamawianą ilością jest ta, którą klient wskazał („4 opakowania”).
     * Bez tego kroku mail pisany myślnikami — którego nasz parser nie czyta — omijał wszystkie
     * zabezpieczenia, bo pozycje pochodziły wyłącznie od modelu.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function quantitiesCheckedAgainstQuote(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $quote = trim((string) ($item['quote'] ?? ''));
            $qty = $this->nullable($item['qty'] ?? null);
            if ($quote === '' || $qty === null) {
                $out[] = $item;

                continue;
            }

            $bySize = $this->qtyFromSizeBreakdown($quote, trim((string) ($item['size'] ?? '')));
            if ($bySize !== null) {
                if ($bySize['qty'] !== $qty) {
                    $item['qty_source'] = 'quote';
                }
                $item['qty'] = $bySize['qty'];
                $item['unit'] = $bySize['unit'];
                $out[] = $item;

                continue;
            }

            $found = $this->qtyCandidatesInRow($quote);
            if ($found['taken'] !== null) {
                // cytat wskazuje ilość wprost — nasza reguła zna wielkość opakowania
                if ($found['taken']['qty'] !== $qty) {
                    // jednostka idzie razem z liczbą: model czytał „100 szt.”, a zamówieniem
                    // są „4 opakowania”
                    $item['qty_source'] = 'quote';
                    $item['unit'] = $found['taken']['unit'];
                }
                $item['qty'] = $found['taken']['qty'];
                $out[] = $item;

                continue;
            }

            // Model wziął liczbę, którą my odrzuciliśmy jako wielkość opakowania albo cenę:
            // zostawiamy pustą ilość z flagą, bo w ofercie stanęłaby liczba, której klient
            // nie zamówił. Liczby spoza cytatu też nie potwierdzamy.
            $digits = preg_replace('/[^0-9]/u', '', $qty) ?? $qty;
            $standsAlone = $digits !== '' && $this->quoteHasNumber($quote, $digits);
            $fromRejected = $digits !== '' && in_array($digits, $found['rejected'], true);
            // Liczby zapisanej slownie („cztery sztuki”) nie podwazamy — model czyta tekst
            // lepiej niz wyrazenie regularne. Podwazamy wtedy, gdy wzial liczbe, ktora my
            // odrzucilismy, albo gdy jedyne liczby w cytacie to cena i wielkosc opakowania.
            $unsupported = ! $standsAlone && ($found['rejected'] !== [] || InquiryQueryText::looksLikePrice($quote));
            if ($fromRejected || ($digits !== '' && $unsupported)) {
                $item['qty'] = null;
                $item['qty_source'] = 'model_unverified';
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Ilość rozmiaru z rozbicia w cytacie: „432 pary Rozmiar: 8-108par,9-108par,10-216par.”
     * dla rozmiaru 9 to 108 par. Model rozbija taki wiersz na pozycje rozmiarów, ale bywa,
     * że każdej wpisuje sumę (zapytanie #50, 23.09.2026: trzy pozycje po 432 pary), a nasze
     * sprawdzenie ją potwierdzało, bo 432 stoi w cytacie jako pierwsza liczba z jednostką.
     *
     * Wystarczy jedna para z rozmiarem pozycji: model składa też cytat „wiersz + para tego
     * rozmiaru” („… 432 pary Rozmiar: 8-108par”, zapytanie #52), a przy zwykłym „rozm. 9 - 50 par”
     * para daje tę samą ilość co dotychczasowa reguła.
     *
     * @return array{qty: string, unit: string}|null
     */
    private function qtyFromSizeBreakdown(string $quote, string $size): ?array
    {
        if ($size === '') {
            return null;
        }
        foreach ($this->sizeBreakdownPairs($quote) as $pair) {
            if (mb_strtolower($pair['size']) === mb_strtolower($size)) {
                return ['qty' => $pair['qty'], 'unit' => $pair['unit']];
            }
        }

        return null;
    }

    /**
     * Pary rozmiar–ilość z cytatu, jak stoją: „8-108par,9-108par,10-216par.” → 8/108/par, 9/108/par, 10/216/par.
     *
     * @return list<array{size: string, qty: string, unit: string}>
     */
    private function sizeBreakdownPairs(string $quote): array
    {
        // pary stoją po przecinku („8-108par,9-108par”), ale nie w środku liczby („10,5”)
        $pair = '(?<![\p{L}\d])(?<!\d[.,])([\p{L}\d]{1,4}(?:[.,]\d)?)\s*[-–:=]\s*(\d{1,5})\s*('.self::UNIT_PATTERN.')';
        if (preg_match_all('/'.$pair.'/iu', $quote, $all, PREG_SET_ORDER) < 1) {
            return [];
        }
        $out = [];
        foreach ($all as $match) {
            $digits = ltrim($match[2], '0');
            $out[] = ['size' => $match[1], 'qty' => $this->formatQty($digits === '' ? '0' : $digits), 'unit' => trim($match[3])];
        }

        return $out;
    }

    /**
     * Rozbicie pozycji-sumy na rozmiary z cytatu klienta („432 pary Rozmiar: 8-108par,9-108par,10-216par”).
     * Tylko pozycja bez własnego rozmiaru i co najmniej dwie pary w jednej jednostce — par i kartonów
     * nie dodajemy. Rozmiary i ilości to słowa klienta, nie potwierdzenie z karty.
     *
     * `matches_qty`: czy pary sumują się do ilości pozycji; null, gdy pozycja nie ma ilości do porównania.
     * `total_qty`: ilość klienta, gdy suma się zgadza — inaczej suma par w ich jednostce.
     *
     * @param  array<string, mixed>  $item
     * @return array{rows: list<array{size: string, qty: string, unit: string}>, total_qty: string, matches_qty: bool|null}|null
     */
    private function sizeBreakdownOf(array $item): ?array
    {
        if ($this->nullable($item['size'] ?? null) !== null) {
            return null;
        }
        $pairs = $this->sizeBreakdownPairs((string) ($item['quote'] ?? ''));
        if (count($pairs) < 2) {
            return null;
        }
        $unit = $this->unitKind($pairs[0]['unit']);
        $sum = 0.0;
        foreach ($pairs as $pair) {
            if ($this->unitKind($pair['unit']) !== $unit) {
                return null;
            }
            $sum += (float) $pair['qty'];
        }

        $qu = $this->qtyUnit($item);
        $itemQty = $this->numericQty($qu['qty']);
        // ilość bez jednostki („432”) porównujemy samą liczbą — jednostkę podają pary
        $matches = $itemQty === null
            ? null
            : ($qu['unit'] === null || $this->unitKind($qu['unit']) === $unit) && abs($sum - $itemQty) < 0.001;

        return [
            'rows' => $pairs,
            'total_qty' => $matches === true && $qu['unit'] !== null
                ? $qu['qty'].' '.$qu['unit']
                : $this->formatQty((string) $sum).' '.$pairs[0]['unit'],
            'matches_qty' => $matches,
        ];
    }

    /** Czy taka liczba stoi w cytacie jako osobny zapis (a nie jako część innej liczby). */
    private function quoteHasNumber(string $quote, string $digits): bool
    {
        return preg_match('/(?<![\d,.])0*'.preg_quote($digits, '/').'(?![\d]|[,.]\d)/u', $quote) === 1;
    }

    /**
     * Uzupełnia pozycje o klucz wyszukiwania w katalogu.
     *
     * Wiersz z samym rozmiarem („50x100cm”) dziedziczy nazwę wyrobu z pozycji
     * bezpośrednio wyżej — inaczej do katalogu idzie sam wymiar i wracają
     * przypadkowe wyroby. Dziedziczenie jest ODNOTOWANE (`query_source`),
     * bo to nasz wniosek, a nie treść maila.
     *
     * Wiersz, który nie nazywa wyrobu („r. 11 40-50 par”), a nad nim nie ma pozycji
     * z nazwą, bierze wyrób z tematu maila („11-571”) — `query_source: subject`.
     * O tym decyduje cytat z maila, nie fraza modelu: model potrafi dopisać rodzaj
     * wyrobu („rękawice”), którego klient nie napisał.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  string  $clientText  temat i treść maila, które widział ekstraktor
     * @return list<array<string, mixed>>
     */
    private function withSearchQueries(array $items, ?string $subjectHint = null, string $clientText = ''): array
    {
        $out = [];
        // sama nazwa wyrobu z ostatniej pozycji, która ją miała w mailu
        $previousName = null;

        foreach ($items as $item) {
            $own = $this->catalogSearchQuery(
                (string) ($item['query'] ?? ''),
                (string) ($item['quote'] ?? ''),
                $item,
                $clientText,
            );
            $item['query_source'] = 'mail';

            if ($subjectHint !== null && ! InquiryQueryText::namesProduct((string) ($item['quote'] ?? ''))) {
                if ($previousName === null) {
                    // model mógł już wziąć kod z tematu — wtedy nie dublujemy
                    if (! $this->containsCompact($own, $subjectHint)) {
                        $own = trim($subjectHint.' '.$own);
                    }
                    $item['query_source'] = 'subject';
                } elseif ($this->containsCompact($own, $subjectHint)) {
                    // Kolejny wiersz bez nazwy („rozmiar 10-2 pary”), któremu wyrób z tematu wpisał już model —
                    // to ten sam wniosek, a bez flagi handlowiec widział go tylko przy pierwszej pozycji (#83).
                    $item['query_source'] = 'subject';
                }
            }

            if (InquiryQueryText::hasProductWord($own)) {
                $name = InquiryQueryText::productNameOnly($own);
                $previousName = $name === '' ? $previousName : $name;
            } elseif ($previousName !== null) {
                $own = trim($previousName.' '.$own);
                $item['query_source'] = 'inherited';
            }

            $item['search_query'] = $own;
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Cytat pozycji bez jej ilości i rozmiaru — do frazy katalogowej. Pozycja trzyma je
     * w osobnych polach, a we frazie model brał je za warunek: pod „Rękawice MAxicut
     * 44-3745 10 12 par” (zapytanie #47, 23.09.2026) dopisywał „opakowanie 12 par”
     * i „włókno aramidowe” i oceniał właściwą kartę na 40%. Wycinamy tylko to, co
     * pozycja sama odczytała: ilość zgodną z jej `qty` i rozmiar równy jej `size`.
     * Zawartość opakowania („a 100 szt.”) i kody wyrobów zostają.
     *
     * @param  array<string, mixed>  $item
     */
    private function quoteWithoutQtyAndSize(string $quote, array $item): string
    {
        $qty = $this->nullable($item['qty'] ?? null);
        // „Kalosze 3 pary rozmiar 43, 3 pary rozmiar 46” — ta sama ilość bywa w cytacie kilka razy
        for ($i = 0; $qty !== null && $i < 5; $i++) {
            $taken = $this->qtyInsideRow($quote);
            if ($taken === null || $taken['qty'] !== $this->formatQty(ltrim($qty, '0'))) {
                break;
            }
            $quote = $this->withoutQtyFragment($quote, $taken);
        }

        $size = trim((string) ($item['size'] ?? ''));
        if ($size !== '') {
            $s = preg_quote($size, '/');
            // „r.9”, „r. 9” — skrót, którego queryFromLine (rozmiar/rozm./roz.) nie zna
            $quote = preg_replace('/(?<![\p{L}\d])r\.\s*'.$s.'(?![\p{L}\d])/iu', ' ', $quote) ?? $quote;
            // goła liczba na końcu, gdzie stała przed wyciętą ilością: „44-3745 10 12 par”,
            // razem ze słowem „wzrost”, którego queryFromLine też nie wycina
            $quote = preg_replace('/(?<=\s)(?:wzrost\s*:?\s*)?'.$s.'\s*[-–—,;:]?\s*$/iu', '', $quote) ?? $quote;
        }

        return trim($quote);
    }

    /** „rękawice 11-571” zawiera „11571” — bez wielkości liter, spacji i łączników. */
    private function containsCompact(string $haystack, string $needle): bool
    {
        $compact = static fn (string $s): string => preg_replace('/[^\p{L}\d]+/u', '', mb_strtolower($s)) ?? '';
        $needle = $compact($needle);

        return $needle !== '' && str_contains($compact($haystack), $needle);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parseLineItemsFromBody(string $body): array
    {
        $items = [];
        $index = 1;
        $leadingNumbers = [];
        // Lista żądań informacji („Proszę również o podanie:”) — jej punkty są
        // pytaniami o warunki oferty, nie pozycjami zamówienia. $infoLast trzyma
        // ostatni numer takiego punktu: numeracja od nowa („1.” po „5.”) to już
        // inna lista, a ta bywa listą wyrobów.
        $infoRequest = false;
        $infoLast = 0;
        // ile numerów stało do pozycji z końca limitu — o numeracji decydują, jak dawniej,
        // tylko one: druga lista dalej w mailu nie może oddać numerów wierszy jako ilości
        $numbersAtLimit = null;
        $lines = $this->bodyLines($body);
        // Tabela z pliku klienta (InquiryFileText: wiersz = linia, komórki po „ | ”): nagłówek mówi, która kolumna
        // to ilość, a która Lp. — bez tego „1 | Kombinezon… | szt. | 275” dawało ilość 1 (zapytanie #86).
        $tableSections = $this->fileSectionsWithTables($lines);
        $section = 0;
        $table = null;
        foreach ($lines as $line) {
            if ($line === '') {
                // docx kończy tabelę pustą linią — nagłówek nie przechodzi na dalszy tekst
                $table = null;

                continue;
            }
            $fileMarker = preg_match(self::FILE_MARKER, $line) === 1;
            if ($fileMarker || str_starts_with($line, 'Arkusz: ')) {
                $section += $fileMarker ? 1 : 0;
                $table = null;

                continue;
            }
            if ($numbersAtLimit === null && count($items) >= $this->maxLineItems()) {
                $numbersAtLimit = count($leadingNumbers);
            }
            // Telefon, numer konta i data z przodu wiersza to liczby, ale nie ilości:
            // „600 903 483 <tel:…>” ze stopki wchodziło do oferty jako 600 sztuk.
            if (InquiryMailText::isContactLine($line) || preg_match(self::LEADING_DATE, $line) === 1
                || $this->isPostalAddressLine($line)) {
                continue;
            }
            $cells = $this->tableCells($line);
            if ($cells !== null) {
                $header = $this->tableHeader($cells);
                if ($header !== null) {
                    $table = $header;

                    continue;
                }
                $row = $this->tableRowItem($cells, $table, $line, $index);
                if ($row !== null) {
                    $items[] = $row;
                    $index++;
                }

                continue;
            }
            // W pliku z tabelą zamówienia pozycje stoją w tabeli; numerowane akapity obok („1. Obuwie musi posiadać
            // oznaczenie CE…”) to wymagania i warunki — jako pozycje przegłosowałyby model samą liczbą wierszy.
            if (isset($tableSections[$section])) {
                continue;
            }
            $marked = $this->positionMarker($line);
            $plain = $marked === null && preg_match(self::ROW_NUMBER, $line, $m) === 1;
            $number = $marked !== null ? $marked['number'] : ($plain ? (int) $m[1] : null);

            if ($this->isInfoRequestHeader($line)) {
                $infoRequest = true;
                $infoLast = 0;
                if ($number !== null) {
                    // nagłówek bywa punktem listy („3. Proszę o podanie:”) — jego numer
                    // należy do numeracji tak samo jak numer pominiętego punktu
                    $leadingNumbers[] = $number;
                }

                continue;
            }
            if ($infoRequest) {
                if ($number === null || $number <= $infoLast) {
                    // wiersz spoza numeracji albo numeracja od nowa kończy listę żądań
                    $infoRequest = false;
                } else {
                    $infoLast = $number;
                    $rest = $marked !== null ? $marked['rest'] : trim($m[3]);
                    // Znamiona wyrobu (ilość z jednostką, rozmiar) trzymają wiersz
                    // pozycją nawet pod takim nagłówkiem — mylnie rozpoznany nagłówek
                    // nie może zabrać z zapytania prawdziwego wiersza zamówienia.
                    if (! $this->looksLikeGoodsRow($rest)) {
                        // numer punktu należy do numeracji: pominięty rwał jej ciąg
                        $leadingNumbers[] = $number;

                        continue;
                    }
                }
            }
            if ($marked !== null && $this->isQuestionLine($marked['rest'])) {
                // Ponumerowane pytanie („1) Czy posiadacie…?”) nie jest pozycją zamówienia,
                // ale jego numer należy do numeracji: pominięty rwał ciąg i numery
                // pozostałych wierszy wracały do oferty jako ilości.
                $leadingNumbers[] = $marked['number'];

                continue;
            }
            if ($marked !== null) {
                // Jawny znacznik pozycji: numer z niego nigdy nie jest ilością.
                // Ilość może stać dalej w wierszu — wtedy i tylko wtedy ją bierzemy.
                $rest = $marked['rest'];
                $inside = $this->qtyInsideRow($rest);
                // Numer ze znacznika liczy się przy wykrywaniu numeracji: bez niego
                // w mailu „1) …, 2. …, 3. …” ciąg zaczynał się od dwójki, numeracja
                // przestawała być rozpoznana i numery wierszy wpadały do oferty jako ilości.
                $leadingNumbers[] = $marked['number'];
                $items[] = [
                    'id' => 'item_'.$index,
                    'quote' => $line,
                    'qty' => $inside['qty'] ?? null,
                    'qty_unit_given' => false,
                    'qty_rest' => $rest,
                    'unit' => $inside['unit'] ?? null,
                    'qty_source' => $inside === null ? 'marker' : 'row',
                    'query' => $this->queryFromLine($this->withoutQtyFragment($rest, $inside)),
                    'size' => $this->sizeFromLine($rest),
                ];
                $index++;

                continue;
            }
            if (! $plain) {
                continue;
            }
            $rest = trim($m[3]);
            $leadingNumbers[] = (int) $m[1];
            $size = $this->sizeFromLine($rest);
            $items[] = [
                'id' => 'item_'.$index,
                'quote' => $line,
                'qty' => $m[1],
                'qty_unit_given' => $this->nullable($m[2] ?? null) !== null,
                // treść wiersza po numerze — do szukania ilości, gdy numer był numeracją
                'qty_rest' => $rest,
                // jednostka tylko taka, jaka stoi w mailu — nie dopisujemy „szt.”
                'unit' => $this->nullable($m[2] ?? null),
                'query' => $this->queryFromLine($rest),
                'size' => $size,
            ];
            $index++;
        }

        // Wszystkie wiersze, także ponad limit pozycji — limit liczy resolveLineItemsWithOmitted()
        // i to, co się nie zmieściło, pokazuje handlowcowi zamiast gubić.
        return $this->dropEnumerationQty(
            $items,
            $numbersAtLimit === null ? $leadingNumbers : array_slice($leadingNumbers, 0, $numbersAtLimit),
        );
    }

    /**
     * Części pliku klienta (numer liczony od nagłówka „=== Plik klienta: … ===”, 0 = mail nad nimi), w których
     * stoi tabela z rozpoznanym nagłówkiem.
     *
     * @param  list<string>  $lines
     * @return array<int, true>
     */
    private function fileSectionsWithTables(array $lines): array
    {
        $out = [];
        $section = 0;
        foreach ($lines as $line) {
            if (preg_match(self::FILE_MARKER, $line) === 1) {
                $section++;

                continue;
            }
            $cells = $section > 0 ? $this->tableCells($line) : null;
            if ($cells !== null && $this->tableHeader($cells) !== null) {
                $out[$section] = true;
            }
        }

        return $out;
    }

    /**
     * Komórki wiersza tabeli z pliku („1 | Rękawice |  | 4” → [1, Rękawice, '', 4]) albo null dla zwykłego tekstu.
     * Separator to „|” ze spacją obok — sam znak w środku słowa czy adresu nie dzieli komórek.
     *
     * @return list<string>|null
     */
    private function tableCells(string $line): ?array
    {
        if (preg_match('/\s\||\|\s/u', $line) !== 1) {
            return null;
        }
        $cells = array_map('trim', preg_split('/\|/u', trim($line)) ?: []);

        return count($cells) >= 2 ? $cells : null;
    }

    /**
     * Nagłówek tabeli zamówienia: krótkie komórki bez gołych liczb, wśród nich nazwa wyrobu i ilość. Kolumny ceny,
     * wartości i zawartości opakowania nie są ilością. Dwie kolumny ilości (zamówienie podstawowe i opcja, 2026 i 2027)
     * bez jednej „Razem” — ilość nieznana, bo nie wiadomo, którą klient chce wycenić.
     *
     * @param  list<string>  $cells
     * @return array{count: int, name: int, qty: int|null, unit: int|null, size: int|null, lp: int|null, codes: list<int>, qty_unit: string|null}|null
     */
    private function tableHeader(array $cells): ?array
    {
        $filled = array_filter($cells, static fn (string $cell): bool => $cell !== '');
        if (count($filled) < 2) {
            return null;
        }
        foreach ($filled as $cell) {
            if (mb_strlen($cell) > 60 || preg_match(self::BARE_NUMBER_CELL, $cell) === 1) {
                return null;
            }
        }

        $name = null;
        $described = null;
        $unit = null;
        $size = null;
        $lp = null;
        $qty = [];
        $codes = [];
        foreach ($cells as $i => $cell) {
            $c = mb_strtolower($cell);
            if ($c === '') {
                continue;
            }
            if (preg_match('/^(?:l\.?\s*p\.?|lp\.?|nr\.?|poz\.?|pozycja)$/u', $c) === 1) {
                $lp ??= $i;

                continue;
            }
            $price = preg_match('/(?:cen[aeyęo]|warto[śs][ćc]|kwot|netto|brutto|z[łl](?![\p{L}])|pln|vat|stawk)/u', $c) === 1;
            if (preg_match('/(?:nazwa|przedmiot|asortyment|towar|wyr[óo]b|produkt|wyszczeg[óo]lnieni|artyku[łl])/u', $c) === 1) {
                if (! $price) {
                    $name ??= $i;
                }

                continue;
            }
            if ($price) {
                continue;
            }
            if (preg_match('/(?:kod|indeks|index|symbol|katalog|model|producent|marka)/u', $c) === 1) {
                $codes[] = $i;
            } elseif (preg_match('/^rozm/u', $c) === 1) {
                $size ??= $i;
            } elseif (preg_match('/(?:j\.\s*m|^jm\.?$|jednostk|miar[ay]?(?![\p{L}]))/u', $c) === 1) {
                $unit ??= $i;
            } elseif (preg_match('/(?:ilo[śs][ćc]|razem|liczba|[łl][ąa]cznie|og[óo][łl]em)/u', $c) === 1) {
                if (preg_match('/(?:opak|karton|zbiorcz|w\s+op\.?(?![\p{L}]))/u', $c) !== 1) {
                    $qty[] = $i;
                }
            } elseif (preg_match('/opis/u', $c) === 1) {
                $described ??= $i;
            }
        }
        $name ??= $described;
        if ($name === null || $qty === []) {
            return null;
        }

        $qtyColumn = null;
        if (count($qty) === 1) {
            $qtyColumn = $qty[0];
        } else {
            $totals = array_values(array_filter(
                $qty,
                static fn (int $i): bool => preg_match('/(?:razem|[łl][ąa]cznie|og[óo][łl]em|suma)/iu', $cells[$i]) === 1,
            ));
            $qtyColumn = count($totals) === 1 ? $totals[0] : null;
        }
        // „Ilość (szt.)” — jednostka zapisana przez klienta w nagłówku
        $qtyUnit = $qtyColumn !== null && preg_match('/\(\s*('.self::UNIT_PATTERN.')\s*\)/iu', $cells[$qtyColumn], $m) === 1
            ? trim($m[1])
            : null;

        return [
            'count' => count($cells),
            'name' => $name,
            'qty' => $qtyColumn,
            'unit' => $unit,
            'size' => $size,
            'lp' => $lp,
            'codes' => $codes,
            'qty_unit' => $qtyUnit,
        ];
    }

    /**
     * Pozycja z wiersza tabeli. Przy nagłówku o tej samej liczbie komórek ilość bierzemy wyłącznie z kolumny ilości,
     * jednostkę z kolumny „j.m.” albo z nagłówka „Ilość (szt.)”, frazę z nazwy wyrobu (i kodu, jeśli tabela ma taką
     * kolumnę), rozmiar z kolumny rozmiaru albo z nazwy — nigdy z parametrów, gdzie stoi zakres dostępnych rozmiarów.
     * Bez nagłówka (albo z inną liczbą komórek) kolumn nie znamy: pierwsza goła liczba to Lp., ilość tylko z komórki
     * „10 par”, a przy nagłówku żadna. Wiersz numerów kolumn („1 | 2 | 3”) i „Razem” nie są pozycjami.
     *
     * `table_lp`, `table_name`, `table_header` służą tylko do sprawdzenia ilości modelu (withTableQuantities).
     *
     * @param  list<string>  $cells
     * @param  array{count: int, name: int, qty: int|null, unit: int|null, size: int|null, lp: int|null, codes: list<int>, qty_unit: string|null}|null  $table
     * @return array<string, mixed>|null
     */
    private function tableRowItem(array $cells, ?array $table, string $line, int $index): ?array
    {
        if ($this->isTotalRow($cells)) {
            return null;
        }
        $lp = preg_match(self::BARE_NUMBER_CELL, $cells[0]) === 1 ? (int) $cells[0] : null;

        if ($table !== null && count($cells) === $table['count']) {
            $name = $cells[$table['name']];
            if (! $this->isTableNameCell($name)) {
                return null;
            }
            if ($table['lp'] !== null && preg_match(self::BARE_NUMBER_CELL, $cells[$table['lp']]) === 1) {
                $lp = (int) $cells[$table['lp']];
            }
            $qty = $table['qty'] !== null ? $this->tableQty($cells[$table['qty']]) : null;
            $unitCell = $table['unit'] !== null ? $cells[$table['unit']] : '';
            $unit = $unitCell !== '' && mb_strlen($unitCell) <= 20 ? $unitCell : ($table['qty_unit'] ?? $qty['unit'] ?? null);
            $codes = implode(' ', array_filter(
                array_map(static fn (int $i): string => $cells[$i], $table['codes']),
                static fn (string $cell): bool => $cell !== '',
            ));
            $sizeCell = $table['size'] !== null ? $cells[$table['size']] : '';
            $itemName = $this->tableItemName($name);

            return [
                'id' => 'item_'.$index,
                'quote' => $line,
                'qty' => $qty['qty'] ?? null,
                'unit' => $qty === null ? null : $unit,
                'qty_source' => 'table',
                'query' => $this->queryFromLine(trim($itemName.' '.$codes)),
                'size' => $sizeCell !== '' ? $sizeCell : $this->sizeFromLine($name),
                'table_lp' => $lp,
                'table_name' => $itemName,
                'table_header' => true,
            ];
        }

        $name = null;
        foreach ($cells as $i => $cell) {
            if (($i > 0 || $lp === null) && $this->isTableNameCell($cell)) {
                $name = $cell;

                break;
            }
        }
        if ($name === null) {
            return null;
        }
        $qty = null;
        if ($table === null) {
            $found = [];
            foreach ($cells as $cell) {
                if (preg_match('/^(\d{1,5}(?:[.,]\d{1,3})?)\s*('.self::UNIT_PATTERN.')\.?$/iu', $cell, $m) === 1) {
                    $found[] = ['qty' => $this->formatQty(ltrim($m[1], '0') ?: '0'), 'unit' => trim($m[2])];
                }
            }
            $qty = count($found) === 1 ? $found[0] : null;
        }
        if ($lp === null && $qty === null) {
            return null;
        }
        $itemName = $this->tableItemName($name);

        return [
            'id' => 'item_'.$index,
            'quote' => $line,
            'qty' => $qty['qty'] ?? null,
            'unit' => $qty['unit'] ?? null,
            'qty_source' => 'table',
            'query' => $this->queryFromLine($itemName),
            'size' => $this->sizeFromLine($name),
            'table_lp' => $lp,
            'table_name' => $itemName,
            'table_header' => $table !== null,
        ];
    }

    /** Komórka, która może być nazwą wyrobu: nie pusta, nie sama liczba i nazywa wyrób. */
    private function isTableNameCell(string $cell): bool
    {
        return $cell !== ''
            && preg_match('/^[\d\s.,\/-]+$/u', $cell) !== 1
            && InquiryQueryText::namesProduct($cell);
    }

    /**
     * Wiersz podsumowania tabeli („Razem | 637”, „Suma”, „Ogółem”).
     *
     * @param  list<string>  $cells
     */
    private function isTotalRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== '') {
                return preg_match('/^(?:razem|suma|og[óo][łl]em|[łl][ąa]cznie)(?![\p{L}])/iu', $cell) === 1;
            }
        }

        return false;
    }

    /**
     * Ilość z komórki kolumny ilości: sama liczba („275”, „1 000”, „2,5”), może z jednostką („10 par”). Cokolwiek
     * innego („wg potrzeb”, „2 x 10”) — null: lepiej pusta ilość niż liczba, której klient nie podał.
     *
     * @return array{qty: string, unit: string|null}|null
     */
    private function tableQty(string $cell): ?array
    {
        $pattern = '/^(\d{1,3}(?:[ \x{00A0}]\d{3})+|\d{1,6})(?:[.,](\d{1,3}))?\s*('.self::UNIT_PATTERN.')?\.?$/iu';
        if (preg_match($pattern, trim($cell), $m) !== 1) {
            return null;
        }
        $digits = ltrim(preg_replace('/\D/u', '', $m[1]) ?? $m[1], '0');
        $number = ($digits === '' ? '0' : $digits).(($m[2] ?? '') !== '' ? '.'.$m[2] : '');

        return ['qty' => $this->formatQty($number), 'unit' => $this->nullable($m[3] ?? null)];
    }

    /**
     * Nazwa wyrobu z komórki tabeli do szukania w katalogu: bez wewnętrznego numeru zamawiającego („(nr pozycji
     * magazynowej u Zamawiającego M056649)”) i bez parametrów po „nazwa: parametry” („TM 9-N … PROTEKT: Wysokość…”).
     * Normy z rokiem („EN 388:2016”) nie są cięte — dwukropek bez spacji za nim.
     */
    private function tableItemName(string $cell): string
    {
        $name = preg_replace('/\([^)]*(?:zamawiaj|magazyn)[^)]*\)/iu', ' ', $cell) ?? $cell;
        if (preg_match('/:\s/u', $name, $m, PREG_OFFSET_CAPTURE) === 1 && $m[0][1] >= 3) {
            $name = substr($name, 0, $m[0][1]);
        }

        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }

    /**
     * Adres z kodem pocztowym w stopce albo w piśmie. Miejscowość nie może być nazwą wyrobu („92-605 Rękawice”
     * to kod wyrobu), a wiersz z ilością i jednostką czy rozmiarem zostaje pozycją.
     */
    private function isPostalAddressLine(string $line): bool
    {
        return preg_match(self::POSTAL_ADDRESS_LINE, $line, $m) === 1
            && preg_match('/(?:r[ęe]kawic|but|obuw|trzewik|p[óo][łl]but|kalosz|kask|he[łl]m|okular|gogl|przy[łl]bic|maska|masecz|p[óo][łl]mask|filtr|ochronnik|nauszni|stoper|kombinezon|kurtk|spodni|ogrodniczk|fartuch|kamizel|ubrani|odzie[żz]|czapk|szelk|pas(?![\p{L}])|link|amortyzator|zatrza[śs]nik|sanda[łl]|klap|skarpet|koszul|bluz|polar|p[łl]aszcz|wk[łl]adk)/iu', $m[1]) !== 1
            && ! $this->looksLikeGoodsRow($line);
    }

    /**
     * Wiersze treści gotowe do czytania pozycji. Program pocztowy łamie tekst w stałej
     * szerokości, więc jedna pozycja przychodzi w dwóch wierszach:
     * „2. Płukanka … (nr 725200)  -” i „5szt./kompletów.” — pierwszy dostawał ilość 2
     * (numer punktu), drugi stawał się osobną pozycją. Złamaną pozycję sklejamy spacją,
     * nic poza tym nie zmieniając. Lista bywa też zaczęta w wierszu zapowiedzi
     * („Proszę o ofertę na: 1. Płukanka…”) — wtedy jej pierwszy punkt przepadał.
     *
     * @return list<string>
     */
    private function bodyLines(string $body): array
    {
        $out = [];
        // długość ostatniego fizycznego wiersza maila — o złamaniu świadczy ona,
        // a nie długość sklejonej albo wydzielonej pozycji
        $lastLength = 0;
        foreach (preg_split('/\R/u', $body) ?: [] as $raw) {
            $line = trim((string) $raw);
            $length = mb_strlen($line);
            $last = count($out) - 1;

            if ($line !== '' && $last >= 0 && $out[$last] !== ''
                && $this->continuesWrappedRow($out[$last], $lastLength, $line)) {
                $out[$last] .= ' '.$line;
                $lastLength = $length;

                continue;
            }

            if (preg_match('/^(.*?\S:)\s+(1\s*[.)]\s+\p{L}.*)$/u', $line, $m) === 1
                && preg_match('/(?:prosz[ęe]|prosimy|ofert|wycen|zapytani|zam[óo]wi|potrzeb)/iu', $m[1]) === 1) {
                $out[] = trim($m[1]);
                $out[] = trim($m[2]);
            } else {
                $out[] = $line;
            }
            $lastLength = $length;
        }

        return $out;
    }

    /**
     * Czy wiersz jest dalszym ciągiem złamanej pozycji. Tylko pod wierszem, który
     * pozycję zaczyna (numer albo znacznik), i tylko w dwóch pewnych układach:
     * ilość z jednostką pod wierszem urwanym na myślniku („… (nr 725200) -” / „5szt.”)
     * albo ciąg małą literą pod wierszem długim jak łamanie w stałej szerokości.
     */
    private function continuesWrappedRow(string $previous, int $previousLength, string $line): bool
    {
        if ($this->positionMarker($previous) === null && preg_match(self::ROW_NUMBER, $previous) !== 1) {
            return false;
        }
        if (preg_match('/[-–—]$/u', $previous) === 1
            && preg_match('/^\d{1,5}\s*'.self::UNIT_PATTERN.'/iu', $line) === 1) {
            return true;
        }

        return $previousLength >= self::WRAPPED_LINE_MIN && preg_match('/^\p{Ll}/u', $line) === 1;
    }

    /**
     * Rozmiar z wiersza zapytania. Separator po słowie „rozmiar” bywa dwukropkiem
     * („rozm: 40x60cm”) — tak samo, jak przy wycinaniu rozmiaru z frazy katalogowej
     * (queryFromLine), inaczej rozmiar znikał z frazy i nigdzie się nie zapisywał.
     * „Rozmiar uniwersalny” nie jest rozmiarem, tylko jego brakiem: wpisanie tego
     * słowa do pozycji czytałoby się jak rozmiar podany przez klienta.
     */
    private function sizeFromLine(string $line): ?string
    {
        if (preg_match('/\b(?:rozmiar|rozm\.?|roz\.)(?:[:.\s]+|(?=\d))([\p{L}\d\/,.\-]+)/iu', $line, $m) !== 1) {
            return null;
        }
        $size = trim(trim($m[1]), '.,-?!');

        // Rozmiarem jest liczba („43”, „40-42”, „1/2”, „40x60cm”) albo oznaczenie
        // literowe („M”, „2XL”). „Rozmiar do uzgodnienia”, „rozmiar uniwersalny” czy
        // „rozmiar wg wzoru” to opis, a nie rozmiar — wpisany do nagłówka pozycji
        // czytałby się jak rozmiar podany przez klienta („rozmiar z zapytania: do”).
        $looksLikeSize = preg_match('/^\d/u', $size) === 1
            // „M/8”, „L/9” — oznaczenie literowe z numerem obwodu dłoni
            || preg_match('/^(?:xx?s|s|m|l|xx?x?l|[2-5]xl)(?:\/\d{1,3})?$/iu', $size) === 1;
        if (! $looksLikeSize) {
            return null;
        }

        return $size;
    }

    /**
     * Mail bywa ponumerowaną listą („1.”, „2.”, „3.”) — wtedy wiodąca liczba
     * jest numerem pozycji, nie ilością. Bierzemy ją za ilość tylko wtedy, gdy
     * stoi przy jednostce („10 szt.”) albo gdy numery NIE tworzą ciągu 1,2,3…
     * Wpisanie numeru listy jako ilości byłoby wpisaniem do oferty liczby,
     * której klient nie podał.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<int>  $numbers
     * @return list<array<string, mixed>>
     */
    private function dropEnumerationQty(array $items, array $numbers): array
    {
        // o numeracji decyduje lista w granicach limitu pozycji (parseLineItemsFromBody())
        $unitGiven = array_map(
            static fn (array $item): bool => ($item['qty_unit_given'] ?? false) === true,
            array_slice($items, 0, $this->maxLineItems()),
        );
        // liczy się, ile wierszy było ponumerowanych — pominięte pytanie też było
        if (count($numbers) < 2 || ! $this->looksLikeEnumeration($numbers, $unitGiven)) {
            return array_map(function (array $item): array {
                unset($item['qty_unit_given'], $item['qty_rest']);

                return $item;
            }, $items);
        }

        $out = [];
        foreach ($items as $item) {
            // ilość z kolumny tabeli nie ma nic wspólnego z numeracją listy w mailu
            if (($item['qty_source'] ?? null) !== 'table' && ($item['qty_unit_given'] ?? false) !== true) {
                // „1. 20 szt. Rękawice” — numer pozycji z przodu, ilość dalej w wierszu
                $rest = (string) ($item['qty_rest'] ?? '');
                $inside = $this->qtyInsideRow($rest);
                $item['qty'] = $inside['qty'] ?? null;
                $item['unit'] = $inside['unit'] ?? $item['unit'] ?? null;
                $item['qty_source'] = $inside === null ? 'enumeration' : 'row';
                if ($inside !== null) {
                    $item['query'] = $this->queryFromLine($this->withoutQtyFragment($rest, $inside));
                }
            }
            unset($item['qty_unit_given'], $item['qty_rest']);
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Treść wiersza bez zapisu ilości, którą z niego odczytaliśmy — inaczej „20 szt.”
     * szłoby do katalogu jako część frazy wyrobu.
     *
     * @param  array{qty: string, unit: string, at: int, len: int}|null  $inside
     */
    private function withoutQtyFragment(string $rest, ?array $inside): string
    {
        if ($inside === null) {
            return $rest;
        }

        $out = (string) substr_replace($rest, ' ', $inside['at'], $inside['len']);
        // „Rękawice 20 szt., dostawa” → bez osieroconego przecinka po wyciętej ilości
        $out = preg_replace('/\s+([,;])/u', '$1', $out) ?? $out;

        return trim($out);
    }

    /**
     * Nagłówek listy żądań informacji: „Proszę również o podanie:”, „Prosimy o podanie
     * następujących informacji:”, „Pytania:”. Punkty pod takim nagłówkiem („1. Terminu
     * realizacji”, „2. Warunków oraz kosztów dostawy”) to pytania o warunki oferty —
     * czytane jako pozycje wchodziły do listu zamiast wyrobu z zapytania.
     *
     * Nagłówek, który zapowiada wyroby albo ich ceny („…na poniższe pozycje:”,
     * „Prosimy o podanie cen dla:”), listą żądań NIE jest: tam numerowane wiersze
     * niosą zamówienie i muszą zostać pozycjami.
     */
    private function isInfoRequestHeader(string $line): bool
    {
        // Mail z HTML zostawia znaczniki wyróżnienia wokół nagłówka („*_Proszę również o podanie:_*”) — dwukropek
        // nie stał wtedy na końcu, lista warunków szła jako 5 pozycji i przegłosowała jedyny wyrób (zapytanie #44).
        $line = trim($line, " \t*_");
        // Lista dokumentów do wyrobu („*Dokumenty dotyczące wkładek*”, „Wymagane dokumenty:”) to prośba o papiery,
        // nie pozycje zamówienia — zapytanie #57: deklaracja CE, instrukcja i karta produktu szły do katalogu.
        if (preg_match('/^(?:wymagane\s+|potrzebne\s+)?dokument(?:y|acja|ów)\b[^.:\d]{0,60}:?$/iu', $line) === 1) {
            return true;
        }
        if (! str_ends_with($line, ':')) {
            return false;
        }
        // zapowiedź wyrobów albo ich wyceny — nie lista informacji o warunkach
        if (preg_match('/(?:pozycj|asortyment|wyrob|wyrób|produkt|towar|artyku[łl]|materia[łl]|cen|wycen|ofert|kalkulacj|rabat)/iu', $line) === 1) {
            return false;
        }
        // „…dostępności dla:”, „…oferty na:” — po takim zwrocie idzie lista rzeczy,
        // o które klient pyta, a nie lista informacji do podania
        if (preg_match('/\b(?:dla|na|do|w|przy|dot\.?|dotycz\w*)\s*:$/iu', $line) === 1) {
            return false;
        }
        if (preg_match('/^(?:dodatkowe\s+)?pytania\s*:$/iu', $line) === 1) {
            return true;
        }

        return preg_match('/(?:prosz[ęe]|prosimy|pro[śs]b)/iu', $line) === 1
            && preg_match('/(?:podani[ae]|informacj|wskazani[ae]|okre[śs]leni[ae]|potwierdzeni[ae]|uwzgl[ęe]dnieni[ae]|doprecyzowani[ae])/iu', $line) === 1;
    }

    /**
     * Czy wiersz niesie znamiona wyrobu: ilość z jednostką albo rozmiar. Tyle wystarczy,
     * żeby pod mylnie rozpoznanym nagłówkiem listy żądań („Prosimy o podanie:”) nie
     * przepadła prawdziwa pozycja zamówienia.
     */
    private function looksLikeGoodsRow(string $rest): bool
    {
        return $this->sizeFromLine($rest) !== null
            || preg_match('/\d{1,5}\s*'.self::UNIT_PATTERN.'/iu', $rest) === 1;
    }

    /**
     * Pytanie o ofertę, a nie pozycja zamówienia: „Czy posiadacie…?”, „Jaki jest termin
     * dostawy?”. Rozpoznajemy je po słowie pytającym NA POCZĄTKU wiersza i znaku zapytania
     * na końcu — „Rękawice nitrylowe rozmiar XL?” to pytanie o konkretny wyrób i pozycją
     * jak najbardziej jest.
     */
    private function isQuestionLine(string $rest): bool
    {
        if (! str_ends_with(trim($rest), '?')) {
            return false;
        }
        // „Jak najszybciej potrzebujemy 100 par rękawic?” to zamówienie zapisane jako
        // pytanie — ilość z jednostką znaczy, że wiersz niesie pozycję. Ale „Ile kosztuje
        // 100 par rękawic?” i „Jaka jest cena za 50 szt.?” pytają o warunki, a nie zamawiają:
        // przy tych słowach ilość niczego nie zmienia.
        // O warunkach handlowych („Ile kosztuje 100 par?”, „Jaka jest cena za 50 szt.?”)
        // pyta się słowem pytającym RAZEM ze słowem o cenie albo terminie. Wtedy ilość
        // niczego nie zmienia. Bez takiego słowa („Jakie rękawice 100 par macie?”) wiersz
        // niesie zamówienie i pozycją zostaje.
        $aboutTerms = preg_match('/^(?:ile|jak[aąeęiy]\w*|kt[oó]r\w+|kto|kiedy|gdzie|dlaczego|w\s+jakim)\b/iu', trim($rest)) === 1
            && preg_match('/\b(?:cen|koszt|termin|dostaw|transport|gwarancj|płatnoś|platnos|rabat|upust|faktur|wysyłk|wysylk)/iu', $rest) === 1;
        if (! $aboutTerms && preg_match('/\d{1,5}\s*'.self::UNIT_PATTERN.'/iu', $rest) === 1) {
            return false;
        }

        // Lista zamknięta: każde słowo spoza niej zostawia wiersz pozycją, bo „Co najmniej
        // 100 par rękawic?” to zamówienie, a nie pytanie o ofertę.
        return preg_match(
            '/^(?:czy|jak|jaki|jaka|jaką|jakie|jakiej|jakim|jakich|jakimi|kt[oó]r[aąeęyi]\w*|kto|kiedy|gdzie|ile|dlaczego|w\s+jakim|prosz[ęe]\s+o\s+(?:podanie|informacj\w*)|prosimy\s+o\s+(?:podanie|informacj\w*))\b/iu',
            trim($rest)
        ) === 1;
    }

    /**
     * Jawny znacznik pozycji — „(poz9).”, „poz. 12”, „2)” — i treść wiersza za nim.
     * Wiersze w tym zapisie dotąd w ogóle nie stawały się pozycjami. Numer ze znacznika
     * nie jest ilością: to numer wiersza w zapytaniu klienta.
     */
    private function positionMarker(string $line): ?array
    {
        $patterns = [
            '/^[*\-•\s]*\(\s*poz\.?\s*(\d{1,3})\s*\)[\s.:)\-]*(.+)$/iu',
            '/^[*\-•\s]*poz\.?\s*(\d{1,3})\s*[\s.:)\-]+(.+)$/iu',
            '/^(\d{1,3})\s*\)\s*(.+)$/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, trim($line), $m) === 1) {
                $rest = trim($m[2]);
                if ($rest !== '') {
                    return ['number' => (int) $m[1], 'rest' => $rest];
                }
            }
        }

        return null;
    }

    /**
     * Ilość z wnętrza wiersza, gdy wiodąca liczba okazała się numerem pozycji:
     * „1. 20 szt. Rękawice”. Pomijamy wielkość opakowania („op. 100 szt.”,
     * „100 szt./op.”) — klient chce jedno opakowanie, nie sto sztuk — oraz liczby
     * stojące przy cenie. Brak pewnego trafienia zostawia ilość pustą: brak jest
     * lepszy niż liczba, której klient nie podał.
     *
     * @return array{qty: string, unit: string, at: int, len: int}|null
     */
    private function qtyInsideRow(string $rest): ?array
    {
        return $this->qtyCandidatesInRow($rest)['taken'];
    }

    /**
     * Ilości znalezione w wierszu: ta wzięta i te odrzucone (zawartość opakowania,
     * liczba przy cenie). Odrzucone są potrzebne przy sprawdzaniu ilości podanej przez
     * model — inaczej nie da się odróżnić „zamawiamy 4 opakowania” od „a 100 szt.”.
     *
     * @return array{taken: array{qty: string, unit: string, at: int, len: int}|null, rejected: list<string>}
     */
    private function qtyCandidatesInRow(string $rest): array
    {
        $rejected = [];
        $found = preg_match_all(
            '/(?<![\p{L}\d,.])(\d{1,5})\s*('.self::UNIT_PATTERN.')/iu',
            $rest,
            $all,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );
        if ($found === false || $found === 0) {
            return ['taken' => null, 'rejected' => []];
        }

        foreach ($all as $match) {
            $offset = (int) $match[0][1];
            $before = mb_strtolower(substr($rest, 0, $offset));
            $after = substr($rest, $offset + strlen((string) $match[0][0]));
            // „20 szt. w kartonie - 30 kartonów” zamawia kartony, ale „w kartonie - 100 szt.”
            // to nadal zawartość opakowania: po myślniku liczy się jednostka opakowaniowa.
            $packAfterDash = preg_match('/[-–—]\s*$/u', $before) === 1
                && preg_match('/^(?:karton|opak|opakowa|op\.|zest|kpl|komplet)/iu', trim($match[2][0])) === 1;

            // „op. 100 szt.”, „w opakowaniu 100 szt.”, „a 100 szt.”, „x 100 szt.” —
            // to zawartość opakowania, a klient zamawia opakowania, nie sztuki
            if (preg_match('/(?:op\.|opak\.?|opakowani[ue]|opakowanie zbiorcze|pak\.|zawiera(?:jący|jące)?|po|(?<![\p{L}\d])[ax])\s*[-–—]?\s*$/iu', $before) === 1
                && ! $packAfterDash) {
                $rejected[] = $match[1][0];

                continue;
            }
            // „Rękawice w kartonie 100 szt.” to zawartość opakowania, ale „Karton zbiorczy
            // na odpady 20 szt.” i „nóż do kartonów 10 szt.” to nazwy wyrobów. Karton liczy
            // się jako opakowanie tylko wtedy, gdy nie otwiera wiersza i nie stoi po przyimku.
            if (preg_match('/\S+\s+(?:w\s+)?karton(?:ie|y|ów|ach)?\s*[-–—]?\s*$/iu', $before) === 1
                && preg_match('/(?<![\p{L}])(?:do|na|dla|pod|przy|ze?)\s+karton\w*\s*[-–—]?\s*$/iu', $before) !== 1
                && ! $packAfterDash) {
                $rejected[] = $match[1][0];

                continue;
            }
            // „100 szt./op.”, „100 szt. w opak.”, „20 szt. w kartonie” — tak samo
            if (preg_match('/^\s*(?:\/\s*op|w\s+(?:opak|karton|pud))/iu', $after) === 1) {
                $rejected[] = $match[1][0];

                continue;
            }
            // liczba tuż za słowem o cenie jest ceną albo przelicznikiem ceny
            // („cena netto za 1 szt. 12,50”), a nie zamawianą ilością
            if (preg_match('/(?:cena|cenie|ceny|cenę|c\.|koszt|wartość|stawka|netto|brutto|pln|zł|zl|eur|usd)[:\s]*(?:za\s+)?$/iu', $before) === 1) {
                $rejected[] = $match[1][0];

                continue;
            }

            $digits = ltrim($match[1][0], '0');

            return [
                'taken' => [
                    'qty' => $this->formatQty($digits === '' ? '0' : $digits),
                    'unit' => trim($match[2][0]),
                    'at' => $offset,
                    'len' => strlen((string) $match[0][0]),
                ],
                'rejected' => $rejected,
            ];
        }

        return ['taken' => null, 'rejected' => $rejected];
    }

    /**
     * Ciąg 1,2,3… to numeracja bez dyskusji. Lista bywa też przepisana z luką
     * („1.”, „2.”, „4.”) — wtedy uznajemy numerację tylko, gdy przy żadnej liczbie
     * nie stoi jednostka. Przy jednostce liczba jest ilością i zostaje.
     *
     * @param  list<int>  $numbers
     * @param  list<bool>  $unitGiven
     */
    private function looksLikeEnumeration(array $numbers, array $unitGiven = []): bool
    {
        if (count($numbers) < 2 || $numbers[0] !== 1) {
            return false;
        }

        $exact = true;
        $previous = 0;
        foreach ($numbers as $i => $number) {
            if ($number !== $i + 1) {
                $exact = false;
            }
            // Numer pozycji rośnie. Luka bywa dowolna („wyciąg z SIWZ: poz. 1, 2, 9”),
            // więc progu na wielkość numeru nie stawiamy: liczba bez jednostki, stojąca
            // w rosnącym ciągu od jedynki, jest numerem wiersza, a wpisanie jej do oferty
            // byłoby ilością, której klient nie podał.
            if ($number <= $previous) {
                return false;
            }
            $previous = $number;
        }

        return $exact || ! in_array(true, $unitGiven, true);
    }

    private function queryFromLine(string $rest): string
    {
        // „rozm: 40x60cm” zapisują i z dwukropkiem, i ze spacją; klasa znaków obejmuje
        // polskie litery, bo „rozmiar duży” zostawiał we frazie ogryzek „ży”
        $q = preg_replace('/\b(?:rozmiar|rozm\.?|roz\.)(?:[:.\s]+|(?=\d))[\p{L}\d\/,.\-]+/iu', '', $rest) ?? $rest;
        $q = preg_replace('/^\d+\s*'.self::UNIT_PATTERN.'?[\s.,:–-]+/iu', '', $q) ?? $q;

        // cena i numeracja pozycji nie opisują wyrobu, a przeważają w wyszukiwaniu
        return InquiryQueryText::forCatalog($q);
    }

    /**
     * Do katalogu idzie cytat z warunkiem, nie sama nazwa z ekstraktora („kombinezon”).
     *
     * @param  array<string, mixed>|null  $item  pozycja, której ilość i rozmiar wycinamy z cytatu
     * @param  string  $clientText  temat i treść maila, które widział ekstraktor (withoutInventedAntistaticDemands)
     */
    public function catalogSearchQuery(string $query, string $quote, ?array $item = null, string $clientText = ''): string
    {
        $query = trim($query);
        if ($item !== null) {
            // Stare rekordy (bez $item) liczą klucz grupy po staremu — frazą, jak ją zapisała analiza.
            $query = $this->withoutInventedAntistaticDemands($query, $clientText."\n".$quote);
            $fromTable = $this->tableRowSearchQuery($query, $quote, $item);
            if ($fromTable !== null) {
                return $fromTable;
            }
        }
        $fromQuote = $this->queryFromLine($quote);
        if ($fromQuote === '') {
            return $query;
        }
        if ($query === '' || mb_strlen($fromQuote) > mb_strlen($query)) {
            // Wybór cytat/fraza modelu zostaje jak był — przy krótszym cytacie wygrywałaby
            // fraza modelu, która gubi kody („ARMEN 9007 1010 S1” → bez „1010”).
            // Stare rekordy (bez $item) liczą klucz grupy po staremu.
            $clean = $item === null ? '' : $this->queryFromLine($this->quoteWithoutQtyAndSize($quote, $item));

            return $clean !== '' ? $clean : $fromQuote;
        }

        return $query;
    }

    /**
     * Fraza do katalogu dla pozycji z wiersza tabeli. Zasada „dłuższy cytat wygrywa” dawała tu cały wiersz — nazwę,
     * parametry, normy, jednostkę i ilość po „ | ”, ucięte na 140 znakach (zapytanie #86: „| Kombinezon rybacki…
     * | Kombinezon stanowi połączenie…”). Fraza modelu zostaje, gdy każde jej słowo stoi w wierszu klienta i nie
     * gubi kodu z nazwy wyrobu („kombinezon rybacki z podnoskiem PVC” — PVC z parametrów). Inaczej nazwa wyrobu
     * z tabeli. null — to nie wiersz tabeli albo nie ma w nim nazwy wyrobu.
     *
     * @param  array<string, mixed>  $item
     */
    private function tableRowSearchQuery(string $query, string $quote, array $item): ?string
    {
        $cells = $this->tableCells($quote);
        if ($cells === null) {
            return null;
        }
        $name = null;
        foreach ($cells as $i => $cell) {
            if (($i > 0 || preg_match(self::BARE_NUMBER_CELL, $cells[0]) !== 1) && $this->isTableNameCell($cell)) {
                $name = $this->tableItemName($cell);

                break;
            }
        }
        if ($name === null || $name === '') {
            return null;
        }
        if ($query !== '' && $this->groundedInRow($query, $quote, $name)) {
            return $query;
        }
        $clean = $this->queryFromLine($this->quoteWithoutQtyAndSize($name, $item));

        return $clean !== '' ? $clean : $this->queryFromLine($name);
    }

    /** Każde słowo frazy stoi w wierszu klienta, a kody z nazwy wyrobu („502-U”, „9-N”) są we frazie. */
    private function groundedInRow(string $query, string $quote, string $name): bool
    {
        $row = mb_strtolower($quote);
        preg_match_all('/[\p{L}\d][\p{L}\d\-]*/u', mb_strtolower($query), $words);
        foreach ($words[0] as $word) {
            if ((mb_strlen($word) >= 3 || preg_match('/\d/u', $word) === 1) && ! str_contains($row, $word)) {
                return false;
            }
        }
        preg_match_all('/[\p{L}\d]+(?:-[\p{L}\d]+)*/u', $name, $tokens);
        foreach ($tokens[0] as $token) {
            if (preg_match('/\d/u', $token) === 1 && ! $this->containsCompact($query, $token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fraza ekstraktora bez „ESD” i norm antystatyki (EN 1149, EN 16350, EN 61340), których klient nie napisał.
     *
     * Wyszukiwarka czyta frazę jak słowa klienta: „ESD” albo numer normy robi z „rękawic antystatycznych” żądanie
     * dowodu ESD — karta z samym słowem dostaje najwyżej 60 (decyzja właściciela z 25.09.2026: tylko gdy klient sam
     * żąda ESD albo normy) — a przy wierszu bez antystatyki włącza jej bramkę. Ekstraktor ma w prompcie „Z warunkiem:
     * substancja, norma, typ” i dopisuje je od siebie. Odniesieniem jest to, co model widział (temat i treść maila),
     * a nie sam cytat: wiersz z samym rozmiarem pod wstępem „Proszę o rękawice antystatyczne ESD:” ma warunek tylko we
     * wstępie, a fraza modelu słusznie go przenosi. Reszta frazy zostaje — niesie nazwę wyrobu z nagłówka albo tematu.
     *
     * Fraza zostaje, jak była, gdy wzorzec nie wyciął wszystkiego (zapis, którego nie zna) albo gdy po wycięciu
     * straciłaby antystatykę, o którą klient w mailu prosi: model zapisał wtedy jego słowo jako ESD i bez niego
     * szukanie pominęłoby warunek. Samego słowa („antystatyczne”) nie ruszamy — nie żąda normy.
     */
    private function withoutInventedAntistaticDemands(string $phrase, string $clientText): string
    {
        $written = $this->assortment->strongAntistaticDemands($clientText);
        $invented = array_diff($this->assortment->strongAntistaticDemands($phrase), $written);
        if ($invented === []) {
            return $phrase;
        }
        $clean = $phrase;
        foreach ($invented as $demand) {
            $pattern = $demand === 'esd' ? self::ESD_MENTION : sprintf(self::NORM_MENTION, $demand);
            $clean = preg_replace($pattern, self::CUT_MARK, $clean) ?? $clean;
        }
        // Separatory przy wyciętym zapisie odchodzą razem z nim („ESD/antystatyczne”, „(ESD, EN 1149-5)”), potem pusty
        // nawias i spójnik, za którym nic nie zostało („EN 1149-5 i EN 16350” → „EN 1149-5”).
        $clean = preg_replace('/[\s,;\/+]*'.self::CUT_MARK.'[\s,;\/+]*/u', ' ', $clean) ?? $clean;
        $clean = preg_replace('/[(\[]\s*[)\]]/u', ' ', $clean) ?? $clean;
        $clean = preg_replace('/([(\[])\s+|\s+([)\]])/u', '$1$2', $clean) ?? $clean;
        $clean = preg_replace('/\s+(?:i|oraz|lub|albo|z|wg)\s*(?=[,;)\]]|$)/u', '', $clean) ?? $clean;
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;
        // wyrażeniem, nie trim(): trim() tnie bajty, a „–—” na jego liście psuje „„” i „Ó” na brzegu frazy
        $clean = preg_replace('/^[\s,;:\/+\-–—]+|[\s,;:\/+\-–—]+$/u', '', $clean) ?? $clean;

        if (array_diff($this->assortment->strongAntistaticDemands($clean), $written) !== []
            || (! $this->assortment->requiresAntistatic($clean) && $this->assortment->requiresAntistatic($clientText))) {
            return $phrase;
        }

        return $clean;
    }

    /**
     * @param  list<array<string, mixed>>  $lineItems
     * @param  list<string>  $fallbackQueries
     * @return list<string>
     */
    private function uniqueQueries(array $lineItems, array $fallbackQueries): array
    {
        $seen = [];
        $out = [];
        $subjectQueries = [];
        foreach ($lineItems as $item) {
            // ten sam klucz, który zapisaliśmy przy pozycji — inaczej wynik
            // wyszukiwania nie trafiłby potem do swojej pozycji
            $query = $this->itemSearchKey($item);
            if (($item['query_source'] ?? null) === 'subject') {
                $subjectQueries[] = $query;
            }
            $key = mb_strtolower($query);
            if ($query === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $query;
        }
        foreach ($fallbackQueries as $query) {
            $key = mb_strtolower(trim($query));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            // Fraza modelu, którą pozycja już szuka z wyrobem z tematu, jako osobne szukanie
            // wróciłaby do tej pozycji tylnymi drzwiami (gdy szukanie z tematu nic nie da):
            // w zapytaniu #45 to były przypadkowe rękawice pod „rękawice r. 11”, dopisane
            // przez model. Lepiej „Sprawdzimy i wrócimy” niż wyrób, o który nikt nie pytał.
            foreach ($subjectQueries as $taken) {
                if ($this->containsCompact($taken, $query)) {
                    continue 2;
                }
            }
            $seen[$key] = true;
            $out[] = trim($query);
        }

        return array_slice($out, 0, $this->queryCap(count($lineItems)));
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    private function productCardForItem(array $item, array $products): array
    {
        $qty = $this->qtyLabel($item);
        $size = trim((string) ($item['size'] ?? ''));
        if ($products === []) {
            $options = [
                ['id' => 'check', 'label' => 'Sprawdzimy i wrócimy'],
            ];
            $prompt = 'Nie znaleziono produktu w katalogu. Jak odpowiedzieć na tę pozycję?';
            $allowCustom = true;
        } else {
            $options = [];
            foreach (array_slice($products, 0, 5) as $product) {
                $options[] = [
                    'id' => 'p:'.$product['id'],
                    'label' => $product['sku'].' · '.$product['name'],
                ];
            }
            $options[] = ['id' => 'check', 'label' => 'Napisz, że sprawdzimy'];
            $prompt = 'Który towar z katalogu wskazać na tę pozycję?';
            $allowCustom = false;
        }

        return [
            'id' => 'product:'.(string) $item['id'],
            'title' => 'Towar z katalogu',
            'prompt' => $prompt,
            'options' => $options,
            'allow_custom' => $allowCustom,
            'kind' => 'item',
            'item_id' => (string) $item['id'],
            'quote' => (string) $item['quote'],
            'qty' => $qty,
            'size' => $size !== '' ? $size : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @param  array<int, list<array<string, mixed>>>  $subsByProductId
     */
    private function hasSubstitutes(array $products, array $subsByProductId): bool
    {
        foreach ($products as $product) {
            if (($subsByProductId[(int) ($product['id'] ?? 0)] ?? []) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $products
     * @param  array<int, list<array<string, mixed>>>  $subsByProductId
     * @return array<string, mixed>
     */
    private function substituteCardForItem(array $item, array $products, array $subsByProductId): array
    {
        $options = [
            ['id' => 'no', 'label' => 'Tylko wskazany towar'],
        ];
        $seen = [];
        foreach ($products as $product) {
            $pid = (int) ($product['id'] ?? 0);
            foreach ($subsByProductId[$pid] ?? [] as $sub) {
                $sid = (int) ($sub['id'] ?? 0);
                if ($sid <= 0 || isset($seen[$sid])) {
                    continue;
                }
                $seen[$sid] = true;
                $options[] = [
                    'id' => 'p:'.$sid,
                    'label' => 'Zamiennik: '.$sub['sku'].' · '.$sub['name'],
                ];
                if (count($options) >= 5) {
                    break 2;
                }
            }
        }
        $options[] = ['id' => 'yes', 'label' => 'Zaproponuj zamienniki jeśli są'];

        $size = trim((string) ($item['size'] ?? ''));

        return [
            'id' => 'substitutes:'.(string) $item['id'],
            'title' => 'Zamienniki',
            'prompt' => $seen === []
                ? 'Czy do tej pozycji proponować zamienniki?'
                : 'Który zamiennik dodać przy tej pozycji? Albo zostań przy wskazanym towarze.',
            'options' => $options,
            'allow_custom' => false,
            'kind' => 'item',
            'item_id' => (string) $item['id'],
            'quote' => (string) $item['quote'],
            'qty' => $this->qtyLabel($item),
            'size' => $size !== '' ? $size : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $aiCards
     * @param  list<array<string, mixed>>  $lineItems
     * @param  list<string>  $used
     * @return list<array<string, mixed>>
     */
    private function aiCardsForItem(array $aiCards, array $lineItems, ?string $itemId, array $used): array
    {
        $out = [];
        foreach ($aiCards as $card) {
            $id = (string) ($card['id'] ?? '');
            if ($id === '' || in_array($id, $used, true) || in_array($id, ['product', 'missing'], true)) {
                continue;
            }
            $resolved = trim((string) ($card['item_id'] ?? '')) ?: $this->guessItemId($card, $lineItems);
            if ($itemId === null) {
                if ($resolved !== null && $lineItems !== []) {
                    continue;
                }
                $card['kind'] = 'global';
                $out[] = $card;

                continue;
            }
            if ($resolved !== $itemId) {
                continue;
            }
            $card['item_id'] = $itemId;
            $card['kind'] = 'item';
            $item = $this->lineItemById($lineItems, $itemId);
            if ($item !== null) {
                $card['quote'] = $item['quote'];
                $card['qty'] = $this->qtyLabel($item);
                $card['size'] = $item['size'] ?? null;
            }
            $out[] = $card;
        }

        return $out;
    }

    /**
     * @param  list<array{query: string, products: list<array<string, mixed>>}>  $matches
     * @return array<int, list<array<string, mixed>>>
     */
    public function loadSubstitutes(array $matches): array
    {
        $ids = [];
        foreach ($this->flatProducts($matches) as $product) {
            $id = (int) ($product['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $rows = ProductSubstitute::query()
            ->whereIn('main_product_id', $ids)
            ->where('approval_status', 'zatwierdzony')
            ->with('substituteProduct')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $sub = $row->substituteProduct;
            if ($sub === null) {
                continue;
            }
            $safe = $this->safeProduct([
                'id' => $sub->id,
                'sku' => $sub->sku,
                'name' => $sub->name,
                'manufacturer' => $sub->manufacturer,
                'norms' => $sub->norms,
                'catalog_price_net' => $sub->catalog_price_net,
                'purchase_price' => $sub->purchase_price,
                'currency' => $sub->currency ?? 'PLN',
                'stock' => $sub->stock,
            ]);
            if ($safe === null) {
                continue;
            }
            $mainId = (int) $row->main_product_id;
            $out[$mainId] ??= [];
            $out[$mainId][] = $safe;
        }

        return $out;
    }

    /**
     * @param  list<array{query: string, products: list<array<string, mixed>>}>  $matches
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private function productsForItem(array $matches, array $item): array
    {
        [$first, $second] = $this->groupsForItem($matches, $item);
        $found = $first['products'] ?? [];
        if ($found !== []) {
            return $found;
        }

        return $second['products'] ?? [];
    }

    /**
     * Wyszukiwanie tej pozycji skończyło się awarią modelu, nie pustym katalogiem.
     *
     * @param  list<array<string, mixed>>  $matches
     * @param  array<string, mixed>  $item
     */
    private function searchFailedForItem(array $matches, array $item): bool
    {
        foreach ($this->groupsForItem($matches, $item) as $group) {
            if (($group['model_failed'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Grupa wyników po kluczu wyszukiwania pozycji i grupa po jej frazie (stare rekordy).
     *
     * @param  list<array<string, mixed>>  $matches
     * @param  array<string, mixed>  $item
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function groupsForItem(array $matches, array $item): array
    {
        // zapisany klucz z chwili analizy; stare rekordy go nie mają i liczą po staremu
        $search = $this->itemSearchKey($item);
        // Pozycja bez frazy („proszę o wycenę”) niczego w katalogu nie szukała, więc
        // nie wolno jej podstawić wyników jedynej grupy — byliby to kandydaci, których
        // nikt do tej pozycji nie dopasował. Wiersz z samym wymiarem („3 szt. rozm:
        // 50x100cm”) to co innego: w starych rekordach nie ma dziedziczonej frazy,
        // a wymiar opisuje wyrób z jedynej grupy zapytania.
        $quote = (string) ($item['quote'] ?? '');
        $hasQuery = trim((string) ($item['query'] ?? '')) !== ''
            || ! InquiryQueryText::hasProductWord($quote);

        return [
            $this->groupForQuery($matches, $search, $hasQuery),
            $this->groupForQuery($matches, (string) ($item['query'] ?? ''), $hasQuery),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     * @return array<string, mixed>|null
     */
    private function groupForQuery(array $matches, string $query, bool $allowOnlyGroup = true): ?array
    {
        $key = mb_strtolower(trim($query));
        foreach ($matches as $group) {
            if (mb_strtolower(trim((string) ($group['query'] ?? ''))) === $key) {
                return $group;
            }
        }
        // Stare rekordy trzymały w kluczu grupy cały cytat („rękawice nitrylowe rozmiar 9”),
        // więc przy jednej grupie bierzemy ją mimo innego klucza.
        if ($allowOnlyGroup && count($matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  list<array<string, mixed>>  $lineItems
     */
    private function guessItemId(array $card, array $lineItems): ?string
    {
        if ($lineItems === []) {
            return null;
        }
        $hay = mb_strtolower(((string) ($card['title'] ?? '')).' '.((string) ($card['prompt'] ?? '')));
        $best = null;
        $bestHits = 0;
        foreach ($lineItems as $item) {
            $quote = mb_strtolower((string) ($item['quote'] ?? ''));
            $hits = 0;
            foreach (['kombinezon', 'kalosz', 'rękawic', 'but', 'okular', 'kask', 'hełm', 'fartuch'] as $kw) {
                if (str_contains($hay, $kw) && str_contains($quote, $kw)) {
                    $hits++;
                }
            }
            if ($hits > $bestHits) {
                $bestHits = $hits;
                $best = (string) $item['id'];
            }
        }

        return $bestHits > 0 ? $best : null;
    }

    /**
     * @param  list<array<string, mixed>>  $lineItems
     * @return array<string, mixed>|null
     */
    private function lineItemById(array $lineItems, string $id): ?array
    {
        foreach ($lineItems as $item) {
            if ((string) ($item['id'] ?? '') === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeLineItem(mixed $item, int $index): ?array
    {
        if (! is_array($item)) {
            return null;
        }
        $quote = trim((string) ($item['quote'] ?? ''));
        $query = trim((string) ($item['query'] ?? ''));
        if ($quote === '' && $query === '') {
            return null;
        }
        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '') {
            $id = 'item_'.$index;
        }

        // model może oddać „30 szt.” w qty albo liczbę — rozbijamy tak samo jak stare rekordy
        $qtyUnit = $this->qtyUnit([
            'qty' => is_int($item['qty'] ?? null) || is_float($item['qty'] ?? null)
                ? (string) $item['qty']
                : $this->nullable(is_string($item['qty'] ?? null) ? $item['qty'] : null),
            'unit' => $this->nullable(is_string($item['unit'] ?? null) ? $item['unit'] : null),
        ]);

        return [
            'id' => $id,
            'quote' => $quote !== '' ? $quote : $query,
            'qty' => $qtyUnit['qty'],
            'unit' => $qtyUnit['unit'],
            'query' => $query !== '' ? $query : $quote,
            'size' => $this->nullable($item['size'] ?? null),
            // Sprzeczność wewnątrz wiersza klienta wskazana przez model — wniosek, nie fakt z maila.
            'conflict' => is_string($item['conflict'] ?? null) && trim($item['conflict']) !== ''
                ? mb_substr(trim($item['conflict']), 0, self::CONFLICT_MAX)
                : null,
        ];
    }

    /**
     * @param  array<string, array{option_id: string, custom?: string|null}>  $answers
     * @return array{subject: string, body: string}
     */
    private function writeReply(ClientInquiry $inquiry, array $answers, ?string $extraNote): array
    {
        // Ceny w liście od widoku tego, kto list składa: zapisana analiza bywa z ceną specjalną (autor
        // z uprawnieniem, zapytania sprzed ukrywania), a list bez uprawnienia liczy się od ceny standardowej.
        $inquiry = $this->maskedCopy($inquiry, $this->mask());
        $priceMode = $this->priceModeOf($answers);
        $margin = $this->marginPercent($answers);
        $intro = $this->offerIntro((string) $inquiry->tone);
        $parts = [
            $intro,
            '',
            $this->offerPositionBlocks($inquiry, $answers, $priceMode, $margin),
        ];
        // Jednostka dla samej liczby („7” → „7 dni”) — ta sama w tekście i w tabeli.
        $terms = [];
        foreach ($this->termsOf($inquiry) as $key => $value) {
            $terms[$key] = OfferTermText::forLetter($key, $value);
        }
        if ($terms !== []) {
            // Odpowiedź na pytania, które klient zadał wprost — pod pozycjami, przed dopiskiem.
            $parts[] = '';
            $parts[] = 'Warunki:';
            foreach ($terms as $key => $value) {
                $parts[] = ClientInquiry::OFFER_TERMS[$key].': '.$value;
            }
        }
        $note = $this->nullable($extraNote);
        if ($note !== null) {
            $parts[] = '';
            $parts[] = $note;
        }
        // Bez podpisu „Z poważaniem, Zespół Supon”: każdy handlowiec ma własną
        // stopkę w programie pocztowym, a dwa podpisy pod jednym listem to błąd.
        // Gdy handlowiec wpisał to samo zdanie w dopisku, nie dokładamy drugiego —
        // klient dostawał je dwa razy pod rząd.
        $outro = $note !== null && mb_stripos($note, self::OUTRO) !== false ? [] : [self::OUTRO];
        if ($outro !== []) {
            $parts[] = '';
            foreach ($outro as $line) {
                $parts[] = $line;
            }
        }

        return [
            'subject' => $this->offerSubject($inquiry),
            'body' => implode("\n", $parts),
            // ta sama treść w układzie listu: zapytanie klienta u góry, pod nim nasze pozycje
            'html' => InquiryReplyHtml::render(
                $intro,
                $this->offerRows($inquiry, $answers, $priceMode, $margin),
                $note,
                $outro,
                $this->termsLabelled($terms),
                $this->askedBlock($inquiry),
            ),
        ];
    }

    /**
     * Zapytanie klienta nad ofertą: temat i data. Klient ma od razu widzieć, na co
     * odpowiadamy — zwłaszcza gdy przysłał kilka zapytań tego samego dnia. Pozycje
     * jego słowami stoją w liście przy każdej naszej propozycji.
     *
     * @return array{title: string|null, date: string|null}
     */
    private function askedBlock(ClientInquiry $inquiry): array
    {
        $sent = $inquiry->source_sent_at ?? $inquiry->created_at;

        return [
            'title' => $this->nullable($inquiry->source_subject),
            'date' => $sent?->format('d.m.Y'),
        ];
    }

    /**
     * Warunki dla panelu: zawsze wszystkie klucze, niewypełnione jako null —
     * inaczej pole w formularzu zniknęłoby po skasowaniu treści.
     *
     * @return array<string, string|null>
     */
    private function termsView(ClientInquiry $inquiry): array
    {
        $saved = $this->termsOf($inquiry);
        $out = [];
        foreach (array_keys(ClientInquiry::OFFER_TERMS) as $key) {
            $out[$key] = $saved[$key] ?? null;
        }

        return $out;
    }

    /**
     * Warunki jako pary etykieta–wartość dla tabeli w liście.
     *
     * @param  array<string, string>  $terms
     * @return list<array{label: string, value: string}>
     */
    private function termsLabelled(array $terms): array
    {
        $out = [];
        foreach ($terms as $key => $value) {
            $out[] = ['label' => ClientInquiry::OFFER_TERMS[$key], 'value' => $value];
        }

        return $out;
    }

    /** Wstęp listu: oba oficjalne mówią pełnym zdaniem, pozostałe krótko. */
    private function offerIntro(string $tone): string
    {
        if ($tone === ClientInquiry::TONE_FORMAL || $tone === ClientInquiry::TONE_FORMAL_SHORT) {
            return "Dzień dobry,\n\nw odpowiedzi na przesłane zapytanie przedstawiamy ofertę:";
        }

        return "Dzień dobry,\n\nprzesyłamy ofertę do zapytania.";
    }

    /**
     * Opisy kart wyrobów użytych w liście — jednym zapytaniem do bazy.
     *
     * W `analysis` opisu nie ma (kandydaci trzymają tylko nazwę, SKU, normy
     * i ceny), a szablony oficjalne i „bez SKU” piszą pozycję właśnie z opisu.
     * Czytamy je dopiero przy pisaniu listu i tylko dla wybranych wyrobów.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function cardTexts(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }

        return Product::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'manufacturer', 'model_name', 'description'])
            ->keyBy('id')
            ->map(fn (Product $product): array => [
                'name' => (string) $product->name,
                'manufacturer' => (string) $product->manufacturer,
                'model_name' => $this->nullable($product->model_name),
                'description' => (string) $product->description,
            ])
            ->all();
    }

    private function offerSubject(ClientInquiry $inquiry): string
    {
        $base = $this->nullable($inquiry->source_subject);
        if ($base === null) {
            $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];
            $base = $this->nullable($analysis['subject'] ?? null);
        }
        if ($base === null) {
            return 'Oferta do zapytania';
        }
        if (preg_match('/^oferta\b/iu', $base) === 1) {
            return $base;
        }

        return 'Oferta — '.$base;
    }

    /**
     * @param  array<string, array{option_id: string, custom?: string|null}>  $answers
     */
    private function offerPositionBlocks(ClientInquiry $inquiry, array $answers, string $priceMode, float $margin): string
    {
        $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];
        $matches = $this->matchGroups($analysis);
        $items = $this->lineItemsOf($analysis);

        if ($items === []) {
            return 'Nie dobraliśmy produktu z katalogu — uzupełnimy ofertę po weryfikacji.';
        }

        $out = [];
        foreach ($this->offerRows($inquiry, $answers, $priceMode, $margin) as $row) {
            $lines = array_merge(
                [$row['head']],
                $row['quote'] === null ? [] : [$row['quote']],
                $row['answer'],
            );
            $out[] = implode("\n", $lines);
        }

        return implode("\n\n", $out);
    }

    /**
     * Pozycje oferty jako dane: nagłówek, cytat z zapytania i nasza odpowiedź.
     * Z tego samego zestawu powstaje wersja tekstowa i tabela HTML — inaczej
     * obie wersje listu mogłyby się rozjechać.
     *
     * @param  array<string, array{option_id: string, custom?: string|null}>  $answers
     * @return list<array{head: string, quote: string|null, answer: list<string>, answer_roles: list<string>, facts: array<string, mixed>}>
     */
    private function offerRows(ClientInquiry $inquiry, array $answers, string $priceMode, float $margin): array
    {
        $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];
        $matches = $this->matchGroups($analysis);
        $tone = (string) $inquiry->tone;

        $picked = [];
        foreach ($this->lineItemsOf($analysis) as $index => $item) {
            $candidates = $this->candidatesForItem($matches, $item, $analysis);
            $product = $this->chosenProductForItem($item, $candidates, $answers);
            $substitute = $product === null
                ? null
                : $this->chosenSubstituteForItem($item, $this->substitutesForItem($analysis, $candidates), $answers);
            $picked[] = [
                'n' => $index + 1,
                'item' => $item,
                'product' => $product,
                'substitute' => $substitute,
                'manual_price' => $this->manualPriceFor($item, $product, $answers),
            ];
        }

        // Szablon handlowy pisze się z samego zapytania, więc do bazy nie idziemy.
        $cards = [];
        if ($tone !== ClientInquiry::TONE_HANDLOWY) {
            $ids = [];
            foreach ($picked as $row) {
                foreach (['product', 'substitute'] as $key) {
                    if (is_array($row[$key]) && isset($row[$key]['id'])) {
                        $ids[] = (int) $row[$key]['id'];
                    }
                }
            }
            $cards = $this->cardTexts($ids);
        }

        // Zdjęcie stoi tylko przy wyrobie z pozycji — zamiennik to dopisek pod nią, bez własnego zdjęcia.
        $images = [];
        if (in_array($tone, ClientInquiry::PHOTO_TONES, true)) {
            $images = ProductImage::primaryFor(array_map(
                static fn (array $row): int => is_array($row['product']) ? (int) ($row['product']['id'] ?? 0) : 0,
                $picked,
            ));
        }

        $rows = [];
        foreach ($picked as $row) {
            $image = is_array($row['product']) ? ($images[(int) ($row['product']['id'] ?? 0)] ?? null) : null;
            $rows[] = $this->offerRow(
                $row['n'],
                $row['item'],
                $row['product'],
                $row['substitute'],
                $priceMode,
                $margin,
                $tone,
                $cards,
                $row['manual_price'],
                $image?->squareUrl(),
            );
        }

        return $rows;
    }

    /**
     * Cena wpisana ręcznie przy pozycji — tylko dla wyrobu, przy którym ją wpisano.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $product
     * @param  array<string, mixed>  $answers
     */
    private function manualPriceFor(array $item, ?array $product, array $answers): ?float
    {
        if ($product === null || ! isset($product['id'])) {
            return null;
        }
        $answer = $answers[self::MANUAL_PRICE_PREFIX.(string) ($item['id'] ?? '')] ?? null;
        if (! is_array($answer) || trim((string) ($answer['option_id'] ?? '')) !== 'p:'.(int) $product['id']) {
            return null;
        }

        return OfferPricing::plnFromInput($answer['custom'] ?? null);
    }

    /**
     * Towar z odpowiedzi pracownika albo domyślny (defaultOptionFor) — bez „pierwszego z brzegu”.
     *
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<string, array{option_id: string, custom?: string|null}>  $answers
     * @return array<string, mixed>|null
     */
    private function chosenProductForItem(array $item, array $candidates, array $answers): ?array
    {
        return $this->candidateById($candidates, $this->chosenOptionFor($item, $candidates, $answers));
    }

    /**
     * Zatwierdzony zamiennik wskazany przy pozycji („p:<id>” w substitutes:item_N); „no”/„yes”/brak = nic.
     *
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $substitutes
     * @param  array<string, array{option_id: string, custom?: string|null}>  $answers
     * @return array<string, mixed>|null
     */
    private function chosenSubstituteForItem(array $item, array $substitutes, array $answers): ?array
    {
        $option = trim((string) ($answers['substitutes:'.(string) ($item['id'] ?? '')]['option_id'] ?? ''));

        return $this->candidateById($substitutes, $option);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $product
     * @param  array<string, mixed>|null  $substitute
     * @return array{head: string, quote: string|null, answer: list<string>, answer_roles: list<string>, facts: array<string, mixed>}
     */
    private function offerRow(
        int $n,
        array $item,
        ?array $product,
        ?array $substitute,
        string $priceMode,
        float $margin,
        string $tone = ClientInquiry::TONE_HANDLOWY,
        array $cards = [],
        ?float $manualPln = null,
        ?string $imageUrl = null,
    ): array {
        $size = trim((string) ($item['size'] ?? ''));
        // cytat idzie do klienta — bez ceny z cudzej oferty, reszta słowo w słowo
        $quote = InquiryQueryText::withoutPrice((string) ($item['quote'] ?? ''));
        // „1. 1, rozmiar z zapytania: 44” czytało się jak pomyłka: numer pozycji i ilość
        // stały obok siebie jako dwie gołe jedynki. Numer nazywamy numerem, ilość ilością.
        $head = 'Poz. '.(string) $n;
        // Rozmiar pochodzi z zapytania klienta i nikt go nie sprawdził w naszej karcie: kolumna „Pozycja
        // z zapytania” obok kolumny „Nasza propozycja” czytała się jak zapewnienie, że mamy ten rozmiar.
        // Dopóki karta nie potwierdza rozmiaru, piszemy wprost, skąd on jest.
        $qty = $this->qtyLabel($item);
        $meta = array_values(array_filter([
            $qty === null ? null : 'ilość: '.$qty,
            $size !== '' ? 'rozmiar z zapytania: '.$size : null,
        ]));
        if ($meta !== []) {
            $head .= ' — '.implode(', ', $meta);
        }
        if ($product === null) {
            // bez SKU: nic nie zmyślamy, pozycja czeka na weryfikację pracownika
            return [
                'head' => $head,
                'quote' => $quote === '' ? null : $quote,
                'answer' => ['Pozycję potwierdzimy po weryfikacji dostępności i wrócimy z propozycją.'],
                'answer_roles' => ['note'],
                // ilość i rozmiar klienta — list pokazuje je przy pozycji także bez naszego wyrobu
                'facts' => $this->offerFacts($n, $item, null, $priceMode, $margin, $tone),
            ];
        }

        // Karta bez opisu w szablonie „bez SKU”: pozycję opisują słowa klienta
        // z zapytania — krócej, ale prawdziwie, bez dopisywania czegokolwiek.
        $fallback = $quote === '' ? null : mb_substr($quote, 0, 200);

        $facts = $this->offerFacts($n, $item, $product, $priceMode, $margin, $tone, $manualPln);
        // Zdjęcie tylko w liście HTML — w treści tekstowej nie ma na nie miejsca.
        $facts['image'] = $imageUrl;
        $answer = $this->productLines(
            'Produkt',
            $product,
            $priceMode,
            $margin,
            $tone,
            $cards[(int) $product['id']] ?? [],
            $fallback,
            '',
            $manualPln,
        );
        if ($answer !== []) {
            // bez opisu wyrobu (szablon „bez SKU”) nie ma pod czym wypisać rozmiarów
            $answer = array_merge($answer, $this->sizeLines($facts));
        }
        if ($substitute !== null) {
            // Zamiennika nie opisujemy słowami klienta — pytał o coś innego,
            // a podstawienie jego słów pod nasz zamiennik wprowadzałoby w błąd.
            // Role z przedrostkiem „sub_”: w liście zamiennik ma własne miejsce
            // pod pozycją, a nie miesza się z danymi wyrobu z katalogu.
            $answer = array_merge($answer, $this->productLines(
                'Zamiennik',
                $substitute,
                $priceMode,
                $margin,
                $tone,
                $cards[(int) $substitute['id']] ?? [],
                null,
                'sub_',
            ));
        }

        return [
            'head' => $head,
            'quote' => $quote === '' ? null : $quote,
            'answer' => array_column($answer, 'text'),
            'answer_roles' => array_column($answer, 'role'),
            'facts' => $facts,
        ];
    }

    /**
     * Dane pozycji dla tabeli w liście: numer, rozmiar, ilość, normy, cena jednostkowa
     * i wartość. Każde z nich stoi w liście osobno, więc klient nie musi ich wyławiać
     * ze zdania. Wartość liczymy tylko wtedy, gdy znamy i ilość, i cenę — brak jednego
     * z nich zostawia puste miejsce, a nie wymyśloną liczbę.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $product
     * @return array{no: int, name: string|null, code: string|null, size: string|null, qty: string|null, norms: string|null, price: string|null, total: string|null, total_pln: float|null, sizes: list<array{size: string, qty: string, price: string, total: string}>, sizes_qty: string|null}
     */
    private function offerFacts(
        int $n,
        array $item,
        ?array $product,
        string $priceMode,
        float $margin,
        string $tone,
        ?float $manualPln = null,
    ): array {
        $size = $this->nullable($item['size'] ?? null);
        $qu = $this->qtyUnit($item);
        $qty = $qu['qty'] === null
            ? null
            : trim($qu['qty'].' '.(string) ($qu['unit'] ?? ''));

        if ($product === null) {
            return [
                'no' => $n,
                'name' => null,
                'code' => null,
                'size' => $size,
                'qty' => $qty,
                'norms' => null,
                'price' => null,
                'total' => null,
                'total_pln' => null,
                'sizes' => [],
                'sizes_qty' => null,
            ];
        }

        // Cena ręczna zastępuje wyliczoną, ale „Bez cen” zostaje listem bez cen.
        $unitPln = $priceMode === 'none' || $priceMode === ''
            ? null
            : ($manualPln ?? ($priceMode === 'catalog'
                ? $this->catalogPln($product)
                : ($priceMode === 'catalog_margin' ? $this->offerPln($product, $margin) : null)));
        $pieces = $qu['qty'] === null ? null : (float) str_replace(',', '.', $qu['qty']);
        $totalPln = $unitPln !== null && $pieces !== null && $pieces > 0 ? $unitPln * $pieces : null;

        // Pozycja-suma z rozmiarami w cytacie: wartość każdego rozmiaru osobno, a wartość pozycji to ich
        // suma — także wtedy, gdy rozmiary nie dają ilości klienta (panel ostrzega o tym handlowca).
        // Cena jest jedna, z karty albo ręczna: karta nie ma cen per rozmiar, więc żadnych nie zgadujemy.
        $sizes = [];
        $sizesQty = null;
        $breakdown = $unitPln === null ? null : $this->sizeBreakdownOf($item);
        if ($breakdown !== null) {
            $sum = 0.0;
            foreach ($breakdown['rows'] as $row) {
                $value = round((float) $row['qty'] * $unitPln, 2);
                $sum += $value;
                $sizes[] = [
                    'size' => $row['size'],
                    'qty' => $row['qty'].' '.$row['unit'],
                    'price' => $this->formatPln($unitPln),
                    'total' => $this->formatPln($value),
                ];
            }
            $totalPln = $sum;
            $sizesQty = $breakdown['total_qty'];
        }

        return [
            'no' => $n,
            // Szablon „bez SKU” nie ujawnia nazwy katalogowej ani marki: nagłówek kafelka
            // bierze wtedy zdanie opisowe z treści listu (rola „name”).
            'name' => $tone === ClientInquiry::TONE_NO_SKU
                ? null
                : $this->nullable((string) ($product['name'] ?? '')),
            // Kod wyrobu tylko w szablonie handlowym — dwa pozostałe mają go nie ujawniać.
            'code' => $tone === ClientInquiry::TONE_HANDLOWY
                ? $this->nullable((string) ($product['sku'] ?? ''))
                : null,
            'size' => $size,
            'qty' => $qty,
            'norms' => $this->nullable((string) ($product['norms'] ?? '')),
            'price' => $unitPln === null ? null : $this->formatPln($unitPln),
            'total' => $totalPln === null ? null : $this->formatPln($totalPln),
            'total_pln' => $totalPln,
            'sizes' => $sizes,
            'sizes_qty' => $sizesQty,
        ];
    }

    /**
     * Wycena według rozmiarów w treści listu, pod ceną wyrobu. Rola „sizes”: list HTML
     * pokazuje te same liczby w tabelce, więc nie powtarza tych linii jako opisu.
     *
     * @param  array<string, mixed>  $facts
     * @return list<array{text: string, role: string}>
     */
    private function sizeLines(array $facts): array
    {
        $sizes = is_array($facts['sizes'] ?? null) ? $facts['sizes'] : [];
        if ($sizes === []) {
            return [];
        }
        $out = [['text' => 'Według rozmiarów z zapytania:', 'role' => 'sizes']];
        foreach ($sizes as $size) {
            $out[] = [
                'text' => '– rozm. '.$size['size'].': '.$size['qty'].' × '.$size['price'].' = '.$size['total'].' netto',
                'role' => 'sizes',
            ];
        }
        $out[] = ['text' => 'Razem: '.$facts['sizes_qty'].' – '.$facts['total'].' netto', 'role' => 'sizes'];

        return $out;
    }

    /**
     * Propozycja w jednej pozycji listu. Szablon decyduje, co widzi klient:
     *
     *  - handlowy: nazwa z katalogu, SKU i producent — pełna specyfikacja,
     *  - oficjalny: nazwa i akapit opisu z karty, bez SKU,
     *  - oficjalny krótki: nazwa i dwa–trzy zdania opisu z karty, bez SKU,
     *  - bez SKU: jedno zdanie opisu bez marki i modelu, a gdy karta opisu
     *    nie ma — słowa klienta z zapytania ($fallback).
     *
     * Normy i cena wyglądają tak samo we wszystkich szablonach: to dane z karty
     * i z polityki cenowej, nie element stylu listu.
     *
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>  $card  pola karty wyrobu (opis, model, producent)
     * @return list<array{text: string, role: string}>
     */
    private function productLines(
        string $label,
        array $product,
        string $priceMode,
        float $margin,
        string $tone = ClientInquiry::TONE_HANDLOWY,
        array $card = [],
        ?string $fallback = null,
        string $rolePrefix = '',
        ?float $manualPln = null,
    ): array {
        $lines = $this->productHeadLines($label, $product, $tone, $card, $fallback);
        if ($lines === []) {
            // Nie ma czym opisać pozycji bez ujawnienia modelu — wtedy nie
            // wypisujemy norm i ceny bez nazwy, bo wyszedłby bezgłowy blok.
            return [];
        }

        // Pierwsza linia to nazwa wyrobu, kolejne (akapit opisu) to treść — tabela
        // w liście pisze nazwę wytłuszczeniem, a resztę zwykłym pismem.
        $out = [];
        foreach ($lines as $index => $line) {
            $out[] = ['text' => $line, 'role' => $rolePrefix.($index === 0 ? 'name' : 'body')];
        }

        $norms = trim((string) ($product['norms'] ?? ''));
        if ($norms !== '') {
            $out[] = ['text' => 'Normy: '.$norms, 'role' => $rolePrefix.'meta'];
        }
        if ($priceMode !== '' && $priceMode !== 'none') {
            $price = $manualPln !== null
                ? $this->formatPln($manualPln)
                : $this->letterPrice($product, $priceMode, $margin);
            // Jednostki naszej ceny nie znamy — karta jej nie niesie. Doklejana była jednostka z maila
            // klienta, więc przy zapytaniu „20 op.” cena za sztukę wychodziła jako cena za opakowanie.
            // Ilość i jednostka klienta stoją w nagłówku pozycji i tam jest ich miejsce.
            $out[] = [
                'text' => $price === null ? 'Cena: do potwierdzenia' : 'Cena: '.$price.' netto',
                'role' => $rolePrefix.'price',
            ];
        }

        return $out;
    }

    /**
     * Pierwsze linie propozycji: nazwa albo opis, zależnie od szablonu.
     *
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>  $card
     * @return list<string>
     */
    private function productHeadLines(
        string $label,
        array $product,
        string $tone,
        array $card,
        ?string $fallback,
    ): array {
        $description = (string) ($card['description'] ?? '');

        if ($tone === ClientInquiry::TONE_NO_SKU) {
            $lead = $description === '' ? null : OfferProductText::genericLead(
                $description,
                (string) ($card['manufacturer'] ?? ($product['manufacturer'] ?? '')),
                $this->nullable($card['model_name'] ?? null),
                (string) ($card['name'] ?? ($product['name'] ?? '')),
            );
            $text = $this->nullable($lead ?? $fallback);

            return $text === null ? [] : [$label.': '.$text];
        }

        if ($tone === ClientInquiry::TONE_FORMAL || $tone === ClientInquiry::TONE_FORMAL_SHORT) {
            $lines = [$label.': '.$product['name']];
            $limit = $tone === ClientInquiry::TONE_FORMAL_SHORT
                ? OfferProductText::SHORT_PARAGRAPH_LIMIT
                : OfferProductText::PARAGRAPH_LIMIT;
            $paragraph = $description === '' ? null : OfferProductText::paragraph($description, $limit);
            if ($paragraph !== null) {
                $lines[] = $paragraph;
            }

            return $lines;
        }

        $maker = trim((string) ($product['manufacturer'] ?? ''));

        return [sprintf(
            '%s: %s (SKU %s)%s',
            $label,
            $product['name'],
            $product['sku'],
            $maker !== '' ? ', '.$maker : ''
        )];
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function letterPrice(array $product, string $priceMode, float $margin): ?string
    {
        if ($priceMode === '' || $priceMode === 'none') {
            return null;
        }
        if ($priceMode === 'catalog') {
            $pln = $this->catalogPln($product);

            return $pln === null ? null : $this->formatPln($pln);
        }
        if ($priceMode === 'catalog_margin') {
            $offer = $this->offerPln($product, $margin);

            return $offer === null ? null : $this->formatPln($offer);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function catalogPln(array $product): ?float
    {
        if (isset($product['catalog_pln']) && is_numeric($product['catalog_pln'])) {
            $value = (float) $product['catalog_pln'];

            return $value > 0 ? $value : null;
        }

        return $this->fx->toPlnOrNull($product['catalog_price_net'] ?? null, $product['currency'] ?? 'PLN');
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function offerPln(array $product, float $margin): ?float
    {
        if (isset($product['offer_pln']) && is_numeric($product['offer_pln'])) {
            $stored = (float) $product['offer_pln'];
            if ($stored <= 0) {
                return null;
            }
            $default = OfferPricing::markupPercent();
            if (abs($margin - $default) < 0.05) {
                return $stored;
            }
            $purchase = $stored / OfferPricing::factorFromPercent($default);

            return OfferPricing::fromPurchase($purchase, $margin);
        }

        return null;
    }

    private function formatPln(float $amount): string
    {
        return number_format($amount, 2, ',', ' ').' zł';
    }

    private function lineItemsBlock(ClientInquiry $inquiry): ?string
    {
        $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];
        $items = is_array($analysis['line_items'] ?? null) ? $analysis['line_items'] : [];
        if ($items === []) {
            return null;
        }
        $lines = ['Pozycje z zapytania (odpowiedz na KAŻDĄ osobno):'];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $qty = trim((string) ($item['qty'] ?? ''));
            $size = trim((string) ($item['size'] ?? ''));
            $quote = trim((string) ($item['quote'] ?? ''));
            $meta = implode(', ', array_filter([$qty !== '' ? $qty : null, $size !== '' ? 'rozm. '.$size : null]));
            $lines[] = ($index + 1).'. '.($meta !== '' ? $meta.' — ' : '').'„'.$quote.'”';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, array{option_id: string, custom?: string|null}>  $answers
     */
    private function factsBlock(ClientInquiry $inquiry, array $answers): string
    {
        $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];
        $matches = is_array($analysis['matches'] ?? null) ? $analysis['matches'] : [];
        $cards = is_array($analysis['cards'] ?? null) ? $analysis['cards'] : [];
        $flat = $this->flatProducts($matches);
        $selected = $this->selectedProducts($flat, $answers);
        $perItem = $this->itemFacts($cards, $flat, $answers);
        $body = $perItem !== []
            ? implode("\n", $perItem)
            : ($selected === []
                ? 'Brak potwierdzonego produktu z katalogu. Nie podawaj SKU ani ceny.'
                : implode("\n", array_map(fn (array $p): string => '- '.$this->productFactLine($p), $selected)));

        $pricePolicy = $this->pricePolicyBlock($answers, $selected);

        return $pricePolicy === null ? $body : $body."\n\n".$pricePolicy;
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @param  list<array<string, mixed>>  $flat
     * @param  array<string, array{option_id: string, custom?: string|null}>  $answers
     * @return list<string>
     */
    private function itemFacts(array $cards, array $flat, array $answers): array
    {
        $lines = [];
        foreach ($cards as $card) {
            if (! is_array($card)) {
                continue;
            }
            $id = (string) ($card['id'] ?? '');
            if (! str_starts_with($id, 'product:') && $id !== 'product' && ! str_starts_with($id, 'substitutes:')) {
                continue;
            }
            $quote = trim((string) ($card['quote'] ?? ''));
            $qty = trim((string) ($card['qty'] ?? ''));
            $option = (string) (($answers[$id]['option_id'] ?? ''));
            $prefix = trim(($qty !== '' ? $qty.' ' : '').($quote !== '' ? '„'.$quote.'”' : $id));
            if (str_starts_with($option, 'p:')) {
                $pid = (int) substr($option, 2);
                foreach ($flat as $product) {
                    if ((int) $product['id'] === $pid) {
                        $lines[] = '- '.$prefix.' → '.$this->productFactLine($product);

                        continue 2;
                    }
                }
            }
            if (in_array($option, ['check', 'category'], true)) {
                $lines[] = '- '.$prefix.' → bez SKU (sprawdzimy / ogólnie o kategorii)';

                continue;
            }
            if ($option === 'no') {
                $lines[] = '- '.$prefix.' → bez zamiennika, tylko wskazany towar';

                continue;
            }
            if ($option === 'yes') {
                $lines[] = '- '.$prefix.' → zaproponuj zamienniki jeśli są w katalogu';

                continue;
            }
            if ($quote !== '' && str_starts_with($id, 'product')) {
                $lines[] = '- '.$prefix.' → brak potwierdzonego SKU';
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function productFactLine(array $product): string
    {
        $catalog = $this->catalogPln($product);
        $priceText = $catalog !== null ? $this->formatPln($catalog) : 'brak';

        return sprintf(
            'SKU %s, nazwa: %s, producent: %s, normy: %s, cena katalogowa: %s',
            $product['sku'],
            $product['name'],
            $product['manufacturer'] !== '' ? $product['manufacturer'] : '—',
            $product['norms'] !== '' ? $product['norms'] : '—',
            $priceText
        );
    }

    /**
     * @param  array<string, array{option_id: string, custom?: string|null}>  $answers
     * @param  list<array<string, mixed>>  $products
     */
    public function pricePolicyBlock(array $answers, array $products): ?string
    {
        $option = trim((string) ($answers['price']['option_id'] ?? ''));
        if ($option === '' || $option === 'none') {
            return 'Ceny: nie podawaj w liście.';
        }
        if ($option === 'catalog') {
            return 'Ceny: podaj cenę katalogową z faktów. Nie podawaj ceny zakupu.';
        }
        if ($option !== 'catalog_margin') {
            return null;
        }

        $percent = $this->marginPercent($answers);
        $lines = [
            'Ceny: podaj cenę oferty w PLN = zakup (NBP) + '.$percent.'% marży. Nie podawaj zakupu ani waluty cennika.',
        ];
        foreach ($products as $product) {
            $offer = $this->offerPln($product, $percent);
            if ($offer === null) {
                $lines[] = sprintf(
                    '- %s: brak ceny zakupu → [DO UZUPEŁNIENIA: cena oferty]',
                    $product['sku']
                );

                continue;
            }
            $catalog = $this->catalogPln($product);
            $lines[] = sprintf(
                '- %s: oferta %s (katalog %s)',
                $product['sku'],
                $this->formatPln($offer),
                $catalog !== null ? $this->formatPln($catalog) : 'brak'
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, array{option_id: string, custom?: string|null}>  $answers
     */
    public function marginPercent(array $answers): float
    {
        $value = OfferPricing::percentFromInput($answers['price']['custom'] ?? null);
        if ($value === null) {
            return OfferPricing::markupPercent();
        }
        // Granica jest jedna — ta sama, którą sprawdza walidacja przy zapisie.
        // Twarde 99 zostawiało zapisaną marżę 120% i po cichu liczyło cenę z 99%.
        $max = OfferPricing::marginMax();

        return max(0.0, min($value, $max));
    }

    /**
     * @param  array<string, array{option_id: string, custom?: string|null}>  $answers
     */
    private function decisionsBlock(ClientInquiry $inquiry, array $answers, ?string $extraNote): string
    {
        $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];
        $cards = is_array($analysis['cards'] ?? null) ? $analysis['cards'] : [];
        $byId = [];
        foreach ($cards as $card) {
            if (is_array($card) && isset($card['id'])) {
                $byId[(string) $card['id']] = $card;
            }
        }

        $lines = [];
        foreach ($answers as $cardId => $answer) {
            if (! is_array($answer)) {
                continue;
            }
            $optionId = trim((string) ($answer['option_id'] ?? ''));
            $custom = $this->nullable($answer['custom'] ?? null);
            $card = $byId[$cardId] ?? null;
            $title = is_array($card) ? (string) ($card['title'] ?? $cardId) : (string) $cardId;
            $label = $optionId;
            if (is_array($card) && is_array($card['options'] ?? null)) {
                foreach ($card['options'] as $option) {
                    if (is_array($option) && (string) ($option['id'] ?? '') === $optionId) {
                        $label = (string) ($option['label'] ?? $optionId);
                        break;
                    }
                }
            }
            $line = $title.': '.$label;
            if ($custom !== null) {
                $line .= ' ('.$custom.')';
            }
            $lines[] = '- '.$line;
        }

        $note = $this->nullable($extraNote);
        if ($note !== null) {
            $lines[] = '- Dodatkowy niuans: '.$note;
        }

        return $lines === [] ? 'Brak dodatkowych decyzji.' : implode("\n", $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $flat
     * @param  array<string, array{option_id: string, custom?: string|null}>  $answers
     * @return list<array<string, mixed>>
     */
    private function selectedProducts(array $flat, array $answers): array
    {
        $picked = [];
        $seen = [];
        foreach ($answers as $answer) {
            if (! is_array($answer)) {
                continue;
            }
            $option = trim((string) ($answer['option_id'] ?? ''));
            if (! str_starts_with($option, 'p:')) {
                continue;
            }
            $id = (int) substr($option, 2);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            foreach ($flat as $product) {
                if ((int) $product['id'] === $id) {
                    $seen[$id] = true;
                    $picked[] = $product;
                    break;
                }
            }
        }
        if ($picked !== []) {
            return $picked;
        }

        $option = (string) ($answers['product']['option_id'] ?? '');
        if (in_array($option, ['check', 'category'], true)) {
            return [];
        }
        if (count($flat) === 1) {
            return [$flat[0]];
        }

        return array_slice($flat, 0, 2);
    }

    /**
     * @param  list<array{query: string, products: list<array<string, mixed>>}>  $matches
     * @return list<array<string, mixed>>
     */
    private function flatProducts(array $matches): array
    {
        $seen = [];
        $out = [];
        foreach ($matches as $group) {
            foreach ($group['products'] as $product) {
                $id = (int) ($product['id'] ?? 0);
                if ($id <= 0 || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $out[] = $product;
            }
        }

        usort($out, static fn (array $a, array $b): int => ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0)));

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public function safeProduct(array $row): ?array
    {
        $id = (int) ($row['id'] ?? 0);
        $sku = trim((string) ($row['sku'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        if ($id <= 0 || $sku === '' || $name === '') {
            return null;
        }

        // Cena specjalna konta B2B bez uprawnienia: katalog i oferta od ceny standardowej (widok bieżącej operacji).
        $prices = $this->pricesPln($this->mask()->productRow($row));

        return [
            'id' => $id,
            // skąd wiersz: ocena modelu, wektor, czy wiersz katalogowy bez oceny
            'source' => $this->nullable($row['ai_match_source'] ?? null),
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => trim((string) ($row['manufacturer'] ?? '')),
            'norms' => trim((string) ($row['norms'] ?? '')),
            'catalog_price_net' => $prices['catalog_price_net'],
            'currency' => 'PLN',
            'catalog_pln' => $prices['catalog_pln'],
            'offer_pln' => $prices['offer_pln'],
            'stock' => isset($row['stock']) ? (int) $row['stock'] : null,
            'score' => (int) ($row['ai_match_percent'] ?? $row['score'] ?? 0),
            'reason' => $this->nullable(is_string($row['ai_match_reason'] ?? null) ? $row['ai_match_reason'] : ($row['reason'] ?? null)),
        ];
    }

    /**
     * Ceny wiersza kandydata w PLN (NBP): katalogowa i oferta (zakup + marża domyślna). offerPln() przelicza ją
     * potem na marżę zapytania, dzieląc przez marżę domyślną.
     *
     * @param  array<string, mixed>  $row  wiersz karty (catalog_price_net, purchase_price, currency, opcjonalnie price_pln, purchase_price_pln)
     * @return array{catalog_price_net: string|null, catalog_pln: float|null, offer_pln: float|null}
     */
    private function pricesPln(array $row): array
    {
        $currency = trim((string) ($row['currency'] ?? 'PLN')) ?: 'PLN';
        $catalogPln = isset($row['price_pln']) && is_numeric($row['price_pln'])
            ? $this->fx->toPlnOrNull($row['price_pln'], 'PLN')
            : $this->fx->toPlnOrNull($row['catalog_price_net'] ?? null, $currency);
        $purchasePln = isset($row['purchase_price_pln']) && is_numeric($row['purchase_price_pln'])
            ? $this->fx->toPlnOrNull($row['purchase_price_pln'], 'PLN')
            : $this->fx->toPlnOrNull($row['purchase_price'] ?? null, $currency);

        return [
            'catalog_price_net' => $catalogPln !== null ? number_format($catalogPln, 2, '.', '') : null,
            'catalog_pln' => $catalogPln,
            'offer_pln' => OfferPricing::fromPurchase($purchasePln),
        ];
    }

    /**
     * Zapytanie z analizą w widoku ceny $mask — kopia w pamięci, tylko do widoku i listu (nigdy do zapisu).
     * Zapisane wiersze kandydatów to widok autora z chwili analizy: autor z uprawnieniem zapisał cenę specjalną,
     * a zapytania sprzed ukrywania (30.09.2026) mają ją u każdego. Bez maski albo bez zmiany cen — ta sama instancja.
     */
    private function maskedCopy(ClientInquiry $inquiry, SupplierSpecialMask $mask): ClientInquiry
    {
        if (! $mask->hides() || ! is_array($inquiry->analysis)) {
            return $inquiry;
        }
        $analysis = $inquiry->analysis;
        $masked = $this->remaskAnalysis($analysis, $mask);
        if ($masked === $analysis) {
            return $inquiry;
        }
        $copy = clone $inquiry;
        $copy->forceFill(['analysis' => $masked]);

        return $copy;
    }

    /**
     * Wiersze kandydatów (kształt safeProduct(): „id” i offer_pln/catalog_pln) w całej analizie — wyniki szukania,
     * zamienniki, karty z linków i dobrane ręcznie — dla kart ze slotem B2B z oceną dostają ceny z bieżącego stanu
     * karty w widoku maskowanym (cena specjalna → standardowa). Inne karty zostają, jakie były w chwili analizy.
     *
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function remaskAnalysis(array $analysis, SupplierSpecialMask $mask): array
    {
        $ids = [];
        $this->collectPricedIds($analysis, $ids);
        if ($ids === []) {
            return $analysis;
        }
        $mask->preload(array_keys($ids));
        // Karty ze slotem B2B z oceną (jak historia cen, decyzja D1): zapisana cena mogła być specjalna, choć dziś
        // karta ma inną — bierzemy bieżącą cenę karty w widoku maskowanym. Pozostałe karty bez zmian.
        $evaluable = array_values(array_filter(array_keys($ids), static fn (int $id): bool => $mask->hidesHistory($id, null)));
        if ($evaluable === []) {
            return $analysis;
        }
        $prices = [];
        foreach (Product::query()->whereIn('id', $evaluable)->get(['id', 'catalog_price_net', 'purchase_price', 'discount_percent', 'currency']) as $card) {
            $prices[(int) $card->id] = $this->pricesPln($mask->productRow([
                'id' => (int) $card->id,
                'catalog_price_net' => $card->catalog_price_net,
                'purchase_price' => $card->purchase_price,
                'discount_percent' => $card->discount_percent,
                'currency' => $card->currency ?? 'PLN',
            ]));
        }

        return $this->applyPrices($analysis, $prices);
    }

    /**
     * @param  array<mixed>  $node
     * @param  array<int, true>  $ids
     */
    private function collectPricedIds(array $node, array &$ids): void
    {
        if (self::isPricedRow($node)) {
            $ids[(int) $node['id']] = true;
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectPricedIds($value, $ids);
            }
        }
    }

    /**
     * @param  array<mixed>  $node
     * @param  array<int, array{catalog_price_net: string|null, catalog_pln: float|null, offer_pln: float|null}>  $prices
     * @return array<mixed>
     */
    private function applyPrices(array $node, array $prices): array
    {
        if (self::isPricedRow($node) && isset($prices[(int) $node['id']])) {
            foreach ($prices[(int) $node['id']] as $key => $value) {
                if (array_key_exists($key, $node)) {
                    $node[$key] = $value;
                }
            }
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->applyPrices($value, $prices);
            }
        }

        return $node;
    }

    /**
     * Wiersz kandydata z ceną (safeProduct()); pozycje i karty pytań mają id tekstowe.
     *
     * @param  array<mixed>  $row
     */
    private static function isPricedRow(array $row): bool
    {
        return isset($row['id']) && is_numeric($row['id']) && (int) $row['id'] > 0
            && (array_key_exists('offer_pln', $row) || array_key_exists('catalog_pln', $row));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeCard(mixed $card): ?array
    {
        if (! is_array($card)) {
            return null;
        }
        $id = trim((string) ($card['id'] ?? ''));
        $title = trim((string) ($card['title'] ?? ''));
        $prompt = trim((string) ($card['prompt'] ?? ''));
        if ($id === '' || $title === '' || $prompt === '') {
            return null;
        }
        $options = [];
        foreach ($card['options'] ?? [] as $option) {
            if (! is_array($option)) {
                continue;
            }
            $oid = trim((string) ($option['id'] ?? ''));
            $label = trim((string) ($option['label'] ?? ''));
            if ($oid !== '' && $label !== '') {
                $options[] = ['id' => $oid, 'label' => $label];
            }
        }
        if (count($options) < 2) {
            return null;
        }

        $normalized = [
            'id' => $id,
            'title' => $title,
            'prompt' => $prompt,
            'options' => array_slice($options, 0, 4),
            'allow_custom' => (bool) ($card['allow_custom'] ?? false),
        ];
        $itemId = trim((string) ($card['item_id'] ?? ''));
        if ($itemId !== '') {
            $normalized['item_id'] = $itemId;
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && mb_strlen(trim($item)) >= 2) {
                $out[] = trim($item);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Data wysłania maila — ISO 8601 albo RFC 2822 z nagłówka Date.
     * Nieczytelnej daty nie zgadujemy, zostaje null.
     *
     * Kolumna nie przechowuje strefy, więc przesunięcie z maila („+0200”)
     * przeliczamy na strefę aplikacji — inaczej zapisalibyśmy inny moment.
     */
    private function parseSentAt(mixed $value): ?CarbonImmutable
    {
        $raw = $this->nullable($value);
        if ($raw === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw)->setTimezone(config('app.timezone') ?: 'UTC');
        } catch (Throwable) {
            return null;
        }
    }

    private function nullable(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trim = trim($value);

        return $trim === '' ? null : $trim;
    }
}
