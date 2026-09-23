<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\Product;
use App\Models\ProductSubstitute;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Support\InquiryMailText;
use App\Support\InquiryQueryText;
use App\Support\InquiryReplyHtml;
use App\Support\InquiryRequirements;
use App\Support\InquirySignature;
use App\Support\OfferPricing;
use App\Support\OfferProductText;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class ClientInquiryService
{
    private const MAX_PRODUCT_QUERIES = 10;

    private const MAX_MATCHES_PER_QUERY = 3;

    private const MAX_LINE_ITEMS = 8;

    /** Ile wyrobów dobranych ręcznie trzymamy przy jednej pozycji. */
    private const MAX_MANUAL_CANDIDATES = 3;

    private const MAX_CARDS = 28;

    /** Od tego wyniku najlepszy kandydat jest „pewny” (jeśli drugi nie depcze mu po piętach). */
    private const CONFIDENT_SCORE = 80;

    /** Drugi kandydat bliżej niż tyle punktów = pozycja niejednoznaczna. */
    private const AMBIGUOUS_GAP = 11;

    private const PRICE_MODES = ['none', 'catalog', 'catalog_margin'];

    /** Zdanie zamykające list; podpis zostawiamy stopce handlowca w poczcie. */
    private const OUTRO = 'W razie pytań zapraszamy do kontaktu.';

    /** Jednostki z maila, które umiemy oddzielić od liczby („30szt”, „4 pary”, „2 op.”). */
    private const UNIT_PATTERN = '(?:(?:sztuk[ai]?|szt\.?|pcs\.?|par[ay]?|opakowa[nń][a-z]*|opak\.?|op\.?|komplet[a-zóy]*|kpl\.?|zestaw[a-zóy]*|zest\.?|karton(?:y|ów|ow|ami|ach|em|ie|om|a|u)?)(?![\p{L}]))';

    /** Wiersz otwarty liczbą („1. Buty robocze”, „30 szt. Rękawice”) — liczba, jednostka, reszta. */
    private const ROW_NUMBER = '/^(\d+)\s*('.self::UNIT_PATTERN.')?[\s.,:–-]+(.+)$/iu';

    /** Data na początku wiersza („24.07.2026 płatności…”) — nie numer pozycji ani ilość. */
    private const LEADING_DATE = '/^\d{1,2}[.\-\/]\d{1,2}[.\-\/]\d{2,4}(?!\d)/u';

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

    public function __construct(
        private readonly OpenAiCompatibleClient $llm,
        private readonly ProductInquirySearch $search,
        private readonly NbpExchangeRateService $fx,
        private readonly AiSettingsService $aiSettings,
    ) {}

    /**
     * @param  array{message_id?: string|null, channel?: string|null, from?: string|null, sent_at?: string|null}  $source
     */
    public function analyze(
        User $user,
        string $body,
        string $tone,
        ?int $clientId,
        ?string $subject,
        array $source = [],
    ): ClientInquiry {
        // Do bazy trafia cały mail; model i parser pozycji dostają wersję bez
        // cytatu, nagłówka przekazania i stopki — inaczej adres albo telefon
        // z podpisu stają się pozycjami zamówienia.
        $analysisBody = InquiryMailText::forAnalysis($body);
        $fingerprints = $this->fingerprints($analysisBody);
        // Klient bywa pisze model w temacie („11-571”), a w treści tylko ilość i rozmiar.
        // Najpierw temat nadany przez klienta (z nagłówka przekazania), potem temat maila.
        $forwardedSubject = InquiryMailText::forwardedSubject($body);
        $subjectHint = InquiryQueryText::subjectProductHint($forwardedSubject)
            ?? InquiryQueryText::subjectProductHint($subject);
        $extracted = $this->extract($analysisBody, $forwardedSubject ?? $this->nullable($subject));
        $lineItems = $this->resolveLineItems($analysisBody, $extracted['line_items'], $subjectHint);
        $queries = $this->uniqueQueries(
            $lineItems,
            // mail bez żadnej pozycji („Proszę o ofertę”) — szukamy przynajmniej wyrobu z tematu
            $lineItems === [] && $extracted['product_queries'] === [] && $subjectHint !== null
                ? [$subjectHint]
                : $extracted['product_queries']
        );
        $matches = $this->matchProducts($queries);
        $substitutes = $this->loadSubstitutes($matches);
        $cards = $this->buildCards($extracted['cards'], $matches, $lineItems, $substitutes);
        $preferences = $this->lastPreferences($user);

        // Nadawca z nagłówka From i kontakt z odciętej stopki — obie rzeczy
        // pochodzą wprost z maila, nic tu nie jest domyślane.
        $sender = InquirySignature::splitFrom($this->nullable($source['from'] ?? null));
        $contact = InquirySignature::extract($body, $sender['email']);

        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'client_id' => $clientId,
            'tone' => $tone,
            'source_channel' => $this->nullable($source['channel'] ?? null) ?? 'web',
            'source_subject' => $this->nullable($subject) ?? $extracted['subject'],
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
            'analysis' => [
                'subject' => $extracted['subject'],
                // Ślad audytowy: co dokładnie poszło do modelu, gdy mail był cięty.
                'analyzed_body' => $analysisBody === $body ? null : $analysisBody,
                // wyrób z tematu maila, dopisany do szukania pozycji bez nazwy (query_source: subject)
                'subject_hint' => $subjectHint,
                'questions' => $extracted['questions'],
                'product_queries' => $queries,
                'line_items' => $lineItems,
                'matches' => $matches,
                'substitutes' => $substitutes,
                'cards' => $cards,
                'margin_used' => $preferences['margin'],
            ],
        ]);

        // Pracownik ma od razu zobaczyć gotowy list: domyślne decyzje + szkic.
        $answers = $this->defaultAnswers($inquiry, $preferences['price_mode'], $preferences['margin']);

        return $this->saveReply($inquiry, $answers, null);
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
     * @throws RuntimeException gdy pozycji albo wyrobu nie ma
     */
    public function pickProduct(
        ClientInquiry $inquiry,
        string $itemId,
        int $productId,
        string|false|null $extraNote = false,
        array|false $terms = false,
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
     * @return array<string, mixed>
     */
    public function present(ClientInquiry $inquiry): array
    {
        $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];
        $answers = is_array($inquiry->answers) ? $inquiry->answers : [];
        $client = $inquiry->relationLoaded('client') ? $inquiry->client : null;
        // Jedno zapytanie do bazy i tylko wtedy, gdy autor nie był wcześniej wczytany.
        $author = $inquiry->loadMissing('user')->user;
        $items = $this->itemsView($inquiry);

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
            'contact' => is_array($inquiry->contact) ? $inquiry->contact : null,
            'user' => $author instanceof User
                ? ['id' => $author->id, 'name' => $author->name]
                : null,
            'source_body' => (string) $inquiry->source_body,
            'questions' => $this->stringList($analysis['questions'] ?? null),
            'attention_count' => $this->countAttention($items),
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
            'global_cards' => $this->globalCards($analysis),
            'cards' => $this->storedCards($analysis),
            'answers' => $answers,
            'extra_note' => $inquiry->extra_note,
            'terms' => $this->termsView($inquiry),
            'reply_subject' => $inquiry->reply_subject,
            'reply_body' => $inquiry->reply_body,
            'reply_html' => $this->replyHtmlFor($inquiry),
            'created_at' => $inquiry->created_at?->toIso8601String(),
        ];
    }

    /** Liczba pozycji do sprawdzenia — liczona w PHP z zapisanego analysis + answers. */
    public function attentionCount(ClientInquiry $inquiry): int
    {
        return $this->countAttention($this->itemsView($inquiry));
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
            if ($confidence === 'none' && $candidates !== []) {
                $flags[] = 'low_score';
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
            if ($product !== null && $priceMode !== 'none' && $this->letterPrice($product, $priceMode, $margin) === null) {
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
                // Fraza, którą ta pozycja szukała w katalogu — podpowiedź dla
                // ręcznego wyszukiwania przy pozycji, nie nowe źródło danych.
                'query' => $this->nullable($item['search_query'] ?? null)
                    ?? $this->nullable($item['query'] ?? null),
                'answer_key' => 'product:'.$itemId,
                'substitute_key' => $substitutes !== [] ? 'substitutes:'.$itemId : null,
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

        return $out;
    }

    /**
     * Jedna funkcja domyślnego wyboru: dla analyze() (zapis answers) i compose() (brak odpowiedzi).
     *
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $candidates  posortowani malejąco po score
     */
    public function defaultOptionFor(array $item, array $candidates): string
    {
        if ($this->confidenceFor($candidates, $item) === 'none') {
            return 'check';
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
            $out[] = ['query' => (string) ($group['query'] ?? ''), 'products' => $products];
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

        // Kod z maila na czoło — przy równych wynikach wariantów to on jest domyślny.
        // Ale wiersz oceniony poniżej progu zostaje na swoim miejscu: zgodny bywa sam
        // ciąg znaków (indeks filtra „2820” przy okularach 3M), a przesunięcie go na
        // czoło odbierało wybór domyślny kandydatowi, którego model ocenił wysoko.
        $quoted = $this->skuQuotedIndex($item, $products);
        if ($quoted !== null && $quoted > 0 && (int) ($products[$quoted]['score'] ?? 0) >= $this->minMatchScore()) {
            [$hit] = array_splice($products, $quoted, 1);
            array_unshift($products, $hit);
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
                        .'Nie łącz „rękawice 9” i „rękawice 10” w jedną. Max 8. '
                        .'Każda pozycja: id (item_1…), quote (DOKŁADNY cytat wiersza z maila), '
                        .'qty (SAMA liczba jako string, np. „30”; brak → null), unit (jednostka DOKŁADNIE jak w mailu: „szt.”, „par”, „op.”; brak → null), '
                        .'query (fraza do katalogu BEZ rozmiaru, Z warunkiem: substancja, norma, typ), size (lub null). '
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
            ], 0.1, 3500, null, AiTask::ClientInquiry);
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

        $lineItems = [];
        $index = 1;
        foreach ($raw['line_items'] ?? [] as $row) {
            $normalized = $this->normalizeLineItem($row, $index);
            if ($normalized !== null) {
                $lineItems[] = $normalized;
                $index++;
            }
            if (count($lineItems) >= self::MAX_LINE_ITEMS) {
                break;
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
     * @param  list<string>  $queries
     * @return list<array{query: string, products: list<array<string, mixed>>}>
     */
    private function matchProducts(array $queries): array
    {
        $sliced = array_values(array_slice($queries, 0, self::MAX_PRODUCT_QUERIES));
        try {
            // z zapasem: wiersze nieocenione odsiewamy dopiero przy pokazywaniu,
            // więc przycięcie do trójki przed odsiewem zabrałoby dobre trafienia
            $rawGroups = $this->search->findMany($sliced, self::MAX_MATCHES_PER_QUERY * 3);
        } catch (Throwable) {
            $rawGroups = [];
            foreach ($sliced as $query) {
                $rawGroups[] = ['query' => $query, 'products' => []];
            }
        }

        $groups = [];
        foreach ($rawGroups as $i => $result) {
            $query = (string) ($result['query'] ?? $sliced[$i] ?? '');
            $products = [];
            foreach ($result['products'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $safe = $this->safeProduct($row);
                if ($safe !== null) {
                    $products[] = $safe;
                }
            }
            $groups[] = ['query' => $query, 'products' => $products];
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
            return array_slice($cards, 0, self::MAX_CARDS);
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
            if (count($cards) >= self::MAX_CARDS) {
                break;
            }
        }

        return array_slice($cards, 0, self::MAX_CARDS);
    }

    /**
     * @param  list<array<string, mixed>>  $fromAi
     * @return list<array<string, mixed>>
     */
    public function resolveLineItems(string $body, array $fromAi, ?string $subjectHint = null): array
    {
        $parsed = $this->parseLineItemsFromBody($body);
        // Parser przeważa nad modelem, gdy znalazł więcej pozycji — ale liczą się tylko
        // wiersze ze znamionami wyrobu. Inaczej telefon czy urwany wiersz ze stopki
        // przegłosowywały poprawną odpowiedź modelu samą liczbą.
        $credible = count(array_filter($parsed, fn (array $item): bool => $this->isCredibleRow($item)));
        $items = $parsed !== [] && $credible > count($fromAi)
            ? $parsed
            : ($fromAi !== []
                ? $this->withProductRowQuotes($this->quantitiesCheckedAgainstQuote($fromAi), $parsed, $body)
                : $parsed);

        return $this->withSearchQueries($items, $subjectHint);
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
        // pary stoją po przecinku („8-108par,9-108par”), ale nie w środku liczby („10,5”)
        $pair = '(?<![\p{L}\d])(?<!\d[.,])([\p{L}\d]{1,4}(?:[.,]\d)?)\s*[-–:=]\s*(\d{1,5})\s*('.self::UNIT_PATTERN.')';
        if (preg_match_all('/'.$pair.'/iu', $quote, $all, PREG_SET_ORDER) < 1) {
            return null;
        }
        foreach ($all as $match) {
            if (mb_strtolower($match[1]) === mb_strtolower($size)) {
                $digits = ltrim($match[2], '0');

                return ['qty' => $this->formatQty($digits === '' ? '0' : $digits), 'unit' => trim($match[3])];
            }
        }

        return null;
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
     * @return list<array<string, mixed>>
     */
    private function withSearchQueries(array $items, ?string $subjectHint = null): array
    {
        $out = [];
        // sama nazwa wyrobu z ostatniej pozycji, która ją miała w mailu
        $previousName = null;

        foreach ($items as $item) {
            $own = $this->catalogSearchQuery(
                (string) ($item['query'] ?? ''),
                (string) ($item['quote'] ?? ''),
                $item
            );
            $item['query_source'] = 'mail';

            if ($subjectHint !== null
                && $previousName === null
                && ! InquiryQueryText::namesProduct((string) ($item['quote'] ?? ''))) {
                // model mógł już wziąć kod z tematu — wtedy nie dublujemy
                if (! $this->containsCompact($own, $subjectHint)) {
                    $own = trim($subjectHint.' '.$own);
                }
                $item['query_source'] = 'subject';
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
        foreach ($this->bodyLines($body) as $line) {
            if ($line === '') {
                continue;
            }
            // Telefon, numer konta i data z przodu wiersza to liczby, ale nie ilości:
            // „600 903 483 <tel:…>” ze stopki wchodziło do oferty jako 600 sztuk.
            if (InquiryMailText::isContactLine($line) || preg_match(self::LEADING_DATE, $line) === 1) {
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
                if (count($items) >= self::MAX_LINE_ITEMS) {
                    break;
                }

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
            if (count($items) >= self::MAX_LINE_ITEMS) {
                break;
            }
        }

        return $this->dropEnumerationQty($items, $leadingNumbers);
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
        $unitGiven = array_map(static fn (array $item): bool => ($item['qty_unit_given'] ?? false) === true, $items);
        // liczy się, ile wierszy było ponumerowanych — pominięte pytanie też było
        if (count($numbers) < 2 || ! $this->looksLikeEnumeration($numbers, $unitGiven)) {
            return array_map(function (array $item): array {
                unset($item['qty_unit_given'], $item['qty_rest']);

                return $item;
            }, $items);
        }

        $out = [];
        foreach ($items as $item) {
            if (($item['qty_unit_given'] ?? false) !== true) {
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
        $line = trim($line);
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
     */
    public function catalogSearchQuery(string $query, string $quote, ?array $item = null): string
    {
        $query = trim($query);
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
            $query = $this->nullable($item['search_query'] ?? null) ?? $this->catalogSearchQuery(
                (string) ($item['query'] ?? ''),
                (string) ($item['quote'] ?? '')
            );
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

        return array_slice($out, 0, self::MAX_PRODUCT_QUERIES);
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
        // zapisany klucz z chwili analizy; stare rekordy go nie mają i liczą po staremu
        $search = $this->nullable($item['search_query'] ?? null) ?? $this->catalogSearchQuery(
            (string) ($item['query'] ?? ''),
            (string) ($item['quote'] ?? '')
        );
        // Pozycja bez frazy („proszę o wycenę”) niczego w katalogu nie szukała, więc
        // nie wolno jej podstawić wyników jedynej grupy — byliby to kandydaci, których
        // nikt do tej pozycji nie dopasował. Wiersz z samym wymiarem („3 szt. rozm:
        // 50x100cm”) to co innego: w starych rekordach nie ma dziedziczonej frazy,
        // a wymiar opisuje wyrób z jedynej grupy zapytania.
        $quote = (string) ($item['quote'] ?? '');
        $hasQuery = trim((string) ($item['query'] ?? '')) !== ''
            || ! InquiryQueryText::hasProductWord($quote);
        $found = $this->productsForQuery($matches, $search, $hasQuery);
        if ($found !== []) {
            return $found;
        }

        return $this->productsForQuery($matches, (string) ($item['query'] ?? ''), $hasQuery);
    }

    /**
     * @param  list<array{query: string, products: list<array<string, mixed>>}>  $matches
     * @return list<array<string, mixed>>
     */
    private function productsForQuery(array $matches, string $query, bool $allowOnlyGroup = true): array
    {
        $key = mb_strtolower(trim($query));
        foreach ($matches as $group) {
            if (mb_strtolower(trim((string) ($group['query'] ?? ''))) === $key) {
                return $group['products'];
            }
        }
        // Stare rekordy trzymały w kluczu grupy cały cytat („rękawice nitrylowe rozmiar 9”),
        // więc przy jednej grupie bierzemy ją mimo innego klucza.
        if ($allowOnlyGroup && count($matches) === 1) {
            return $matches[0]['products'];
        }

        return [];
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
        ];
    }

    /**
     * @param  array<string, array{option_id: string, custom?: string|null}>  $answers
     * @return array{subject: string, body: string}
     */
    private function writeReply(ClientInquiry $inquiry, array $answers, ?string $extraNote): array
    {
        $priceMode = $this->priceModeOf($answers);
        $margin = $this->marginPercent($answers);
        $intro = $this->offerIntro((string) $inquiry->tone);
        $parts = [
            $intro,
            '',
            $this->offerPositionBlocks($inquiry, $answers, $priceMode, $margin),
        ];
        $terms = $this->termsOf($inquiry);
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
     * Zapytanie klienta nad ofertą: numer sprawy, data i pozycje jego słowami.
     * Klient ma od razu widzieć, na co odpowiadamy — zwłaszcza gdy przysłał
     * kilka zapytań tego samego dnia.
     *
     * @return array{title: string|null, date: string|null, lines: list<string>}
     */
    private function askedBlock(ClientInquiry $inquiry): array
    {
        $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];
        $sent = $inquiry->source_sent_at ?? $inquiry->created_at;

        $lines = [];
        foreach ($this->lineItemsOf($analysis) as $index => $item) {
            $quote = trim(InquiryQueryText::withoutPrice((string) ($item['quote'] ?? '')));
            if ($quote === '') {
                $quote = trim((string) ($item['query'] ?? ''));
            }
            if ($quote === '') {
                continue;
            }
            $qty = $this->qtyLabel($item);
            $lines[] = ($index + 1).'. '.$quote.($qty === null ? '' : ' — '.$qty);
        }

        return [
            'title' => $this->nullable($inquiry->source_subject),
            'date' => $sent?->format('d.m.Y'),
            'lines' => $lines,
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

    /** Wstęp listu: oficjalny mówi pełnym zdaniem, dwa pozostałe krótko. */
    private function offerIntro(string $tone): string
    {
        if ($tone === ClientInquiry::TONE_FORMAL) {
            return "Dzień dobry,\n\nw odpowiedzi na przesłane zapytanie przedstawiamy ofertę:";
        }

        return "Dzień dobry,\n\nprzesyłamy ofertę do zapytania.";
    }

    /**
     * Opisy kart wyrobów użytych w liście — jednym zapytaniem do bazy.
     *
     * W `analysis` opisu nie ma (kandydaci trzymają tylko nazwę, SKU, normy
     * i ceny), a szablon oficjalny i „bez SKU” piszą pozycję właśnie z opisu.
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
     * @return list<array{head: string, quote: string|null, answer: list<string>, answer_roles: list<string>}>
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
            $picked[] = ['n' => $index + 1, 'item' => $item, 'product' => $product, 'substitute' => $substitute];
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

        $rows = [];
        foreach ($picked as $row) {
            $rows[] = $this->offerRow(
                $row['n'],
                $row['item'],
                $row['product'],
                $row['substitute'],
                $priceMode,
                $margin,
                $tone,
                $cards,
            );
        }

        return $rows;
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
     * @return array{head: string, quote: string|null, answer: list<string>, answer_roles: list<string>}
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
            ];
        }

        // Karta bez opisu w szablonie „bez SKU”: pozycję opisują słowa klienta
        // z zapytania — krócej, ale prawdziwie, bez dopisywania czegokolwiek.
        $fallback = $quote === '' ? null : mb_substr($quote, 0, 200);

        $answer = $this->productLines(
            'Produkt',
            $product,
            $priceMode,
            $margin,
            $tone,
            $cards[(int) $product['id']] ?? [],
            $fallback,
        );
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
            'facts' => $this->offerFacts($n, $item, $product, $priceMode, $margin, $tone),
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
     * @return array{no: int, name: string|null, code: string|null, size: string|null, qty: string|null, norms: string|null, price: string|null, total: string|null, total_pln: float|null}
     */
    private function offerFacts(
        int $n,
        array $item,
        ?array $product,
        string $priceMode,
        float $margin,
        string $tone,
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
            ];
        }

        $unitPln = $priceMode === 'catalog'
            ? $this->catalogPln($product)
            : ($priceMode === 'catalog_margin' ? $this->offerPln($product, $margin) : null);
        $pieces = $qu['qty'] === null ? null : (float) str_replace(',', '.', $qu['qty']);
        $totalPln = $unitPln !== null && $pieces !== null && $pieces > 0 ? $unitPln * $pieces : null;

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
        ];
    }

    /**
     * Propozycja w jednej pozycji listu. Szablon decyduje, co widzi klient:
     *
     *  - handlowy: nazwa z katalogu, SKU i producent — pełna specyfikacja,
     *  - oficjalny: nazwa i akapit opisu z karty, bez SKU,
     *  - bez SKU: jedno zdanie opisu bez marki i modelu, a gdy karta opisu
     *    nie ma — słowa klienta z zapytania ($fallback).
     *
     * Normy i cena wyglądają tak samo we wszystkich trzech: to dane z karty
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
            $price = $this->letterPrice($product, $priceMode, $margin);
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

        if ($tone === ClientInquiry::TONE_FORMAL) {
            $lines = [$label.': '.$product['name']];
            $paragraph = $description === '' ? null : OfferProductText::paragraph($description);
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

        $price = $row['catalog_price_net'] ?? null;
        $currency = trim((string) ($row['currency'] ?? 'PLN')) ?: 'PLN';
        $catalogPln = isset($row['price_pln']) && is_numeric($row['price_pln'])
            ? $this->fx->toPlnOrNull($row['price_pln'], 'PLN')
            : $this->fx->toPlnOrNull($price, $currency);
        $purchasePln = isset($row['purchase_price_pln']) && is_numeric($row['purchase_price_pln'])
            ? $this->fx->toPlnOrNull($row['purchase_price_pln'], 'PLN')
            : $this->fx->toPlnOrNull($row['purchase_price'] ?? null, $currency);
        $offerPln = OfferPricing::fromPurchase($purchasePln);

        return [
            'id' => $id,
            // skąd wiersz: ocena modelu, wektor, czy wiersz katalogowy bez oceny
            'source' => $this->nullable($row['ai_match_source'] ?? null),
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => trim((string) ($row['manufacturer'] ?? '')),
            'norms' => trim((string) ($row['norms'] ?? '')),
            'catalog_price_net' => $catalogPln !== null ? number_format($catalogPln, 2, '.', '') : null,
            'currency' => 'PLN',
            'catalog_pln' => $catalogPln,
            'offer_pln' => $offerPln,
            'stock' => isset($row['stock']) ? (int) $row['stock'] : null,
            'score' => (int) ($row['ai_match_percent'] ?? $row['score'] ?? 0),
            'reason' => $this->nullable(is_string($row['ai_match_reason'] ?? null) ? $row['ai_match_reason'] : ($row['reason'] ?? null)),
        ];
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
