<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\ProductSubstitute;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Support\InquiryMailText;
use App\Support\InquiryReplyHtml;
use App\Support\InquirySignature;
use App\Support\OfferPricing;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class ClientInquiryService
{
    private const MAX_PRODUCT_QUERIES = 10;

    private const MAX_MATCHES_PER_QUERY = 3;

    private const MAX_LINE_ITEMS = 8;

    private const MAX_CARDS = 28;

    /** Od tego wyniku najlepszy kandydat jest „pewny” (jeśli drugi nie depcze mu po piętach). */
    private const CONFIDENT_SCORE = 80;

    /** Drugi kandydat bliżej niż tyle punktów = pozycja niejednoznaczna. */
    private const AMBIGUOUS_GAP = 11;

    private const PRICE_MODES = ['none', 'catalog', 'catalog_margin'];

    /** Jednostki z maila, które umiemy oddzielić od liczby („30szt”, „4 pary”, „2 op.”). */
    private const UNIT_PATTERN = '(?:szt\.?|sztuk|pcs\.?|par[ay]?|op\.?|opak\.?|opakowa[nń][a-z]*|kpl\.?|komplet[a-zóy]*|zest\.?|zestaw[a-zóy]*)';

    private ?int $minMatchScore = null;

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
        $extracted = $this->extract($analysisBody);
        $lineItems = $this->resolveLineItems($analysisBody, $extracted['line_items']);
        $queries = $this->uniqueQueries($lineItems, $extracted['product_queries']);
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
            'source_from_name' => $sender['name'],
            'source_from_email' => $sender['email'],
            'source_sent_at' => $this->parseSentAt($source['sent_at'] ?? null),
            'contact' => $contact,
            'source_body' => $body,
            'analysis' => [
                'subject' => $extracted['subject'],
                // Ślad audytowy: co dokładnie poszło do modelu, gdy mail był cięty.
                'analyzed_body' => $analysisBody === $body ? null : $analysisBody,
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
    public function compose(ClientInquiry $inquiry, array $answers, string|false|null $extraNote): ClientInquiry
    {
        $saved = is_array($inquiry->answers) ? $inquiry->answers : [];
        $merged = array_merge($saved, $answers);
        foreach ($this->defaultAnswers($inquiry, $this->priceModeOf($merged), $this->marginPercent($merged)) as $key => $answer) {
            $merged[$key] ??= $answer;
        }

        return $this->saveReply(
            $inquiry,
            $merged,
            $extraNote === false ? $this->nullable($inquiry->extra_note) : $this->nullable($extraNote),
        );
    }

    /**
     * @param  array<string, array{option_id: string, custom?: string|null}>  $answers
     */
    private function saveReply(ClientInquiry $inquiry, array $answers, ?string $extraNote): ClientInquiry
    {
        $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];
        $analysis['margin_used'] = $this->marginPercent($answers);

        $inquiry->forceFill([
            'analysis' => $analysis,
            'answers' => $answers,
            'extra_note' => $extraNote,
        ]);

        $draft = $this->writeReply($inquiry, $answers, $extraNote);

        $inquiry->forceFill([
            'reply_subject' => $draft['subject'],
            'reply_body' => $draft['body'],
            'reply_html' => $draft['html'],
        ])->save();

        return $inquiry->fresh(['client']) ?? $inquiry;
    }

    /**
     * Ton, tryb ceny i marża z ostatniego zapytania użytkownika (bez osobnej tabeli ustawień).
     *
     * @return array{tone: string, price_mode: string, margin: float}
     */
    public function lastPreferences(User $user): array
    {
        $last = ClientInquiry::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();
        $answers = $last !== null && is_array($last->answers) ? $last->answers : [];
        $tone = $last !== null && in_array($last->tone, ['formal', 'handlowy'], true) ? (string) $last->tone : 'formal';

        return [
            'tone' => $tone,
            'price_mode' => $this->priceModeOf($answers),
            'margin' => $this->marginPercent($answers),
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
            'send_requested_at' => $inquiry->send_requested_at?->toIso8601String(),
            'price' => [
                'answer_key' => 'price',
                'mode' => $this->priceModeOf($answers),
                'margin' => $this->marginPercent($answers),
            ],
            'items' => $items,
            'global_cards' => $this->globalCards($analysis),
            'cards' => $this->storedCards($analysis),
            'answers' => $answers,
            'extra_note' => $inquiry->extra_note,
            'reply_subject' => $inquiry->reply_subject,
            'reply_body' => $inquiry->reply_body,
            'reply_html' => $inquiry->reply_html,
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
            $candidates = $this->candidatesForItem($matches, $item);
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
            $product = $this->candidateById($candidates, $chosen);
            if ($product !== null && $priceMode !== 'none' && $this->letterPrice($product, $priceMode, $margin) === null) {
                $flags[] = 'no_price';
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
                'answer_key' => 'product:'.$itemId,
                'substitute_key' => $substitutes !== [] ? 'substitutes:'.$itemId : null,
                'confidence' => $confidence,
                'chosen' => $chosen,
                'flags' => $flags,
                'candidates' => array_map(fn (array $p): array => $this->candidateView($p), $candidates),
                'substitutes' => array_map(
                    fn (array $p): array => array_merge($this->candidateView($p), ['score' => null, 'reason' => null]),
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

        return 'p:'.(int) $candidates[0]['id'];
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
        // Klient wpisał dokładny kod z katalogu — to nie jest zgadywanie modelu.
        if ($this->skuQuotedIndex($item, $candidates) === 0) {
            return 'high';
        }
        $score = (int) ($best['score'] ?? 0);
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
            $candidates = $this->candidatesForItem($matches, $item);
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
            if (($item['confidence'] ?? 'none') !== 'high' || ($item['flags'] ?? []) !== []) {
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
     *
     * @param  list<array{query: string, products: list<array<string, mixed>>}>  $matches
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private function candidatesForItem(array $matches, array $item): array
    {
        $products = $this->productsForItem($matches, $item);
        usort($products, static fn (array $a, array $b): int => ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0)));
        $products = array_values($products);

        // kod z maila na czoło — przy równych wynikach wariantów to on jest domyślny
        $quoted = $this->skuQuotedIndex($item, $products);
        if ($quoted !== null && $quoted > 0) {
            [$hit] = array_splice($products, $quoted, 1);
            array_unshift($products, $hit);
        }

        return $products;
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
    private function candidateView(array $product): array
    {
        return [
            'id' => (int) $product['id'],
            'sku' => (string) ($product['sku'] ?? ''),
            'name' => (string) ($product['name'] ?? ''),
            'manufacturer' => (string) ($product['manufacturer'] ?? ''),
            'norms' => (string) ($product['norms'] ?? ''),
            'catalog_pln' => is_numeric($product['catalog_pln'] ?? null) ? (float) $product['catalog_pln'] : null,
            'offer_pln' => is_numeric($product['offer_pln'] ?? null) ? (float) $product['offer_pln'] : null,
            'stock' => isset($product['stock']) && is_numeric($product['stock']) ? (int) $product['stock'] : null,
            'score' => (int) ($product['score'] ?? 0),
            'reason' => $this->nullable($product['reason'] ?? null),
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
    private function extract(string $body): array
    {
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
                        .'product_queries: unikalne query z line_items. '
                        .'cards: max 4 — TYLKO prawdziwe niejasności (rozmiar, wariant, termin). '
                        .'Nie pytaj o oczywistości. item_id jeśli karta dotyczy jednej pozycji. '
                        .'Każda karta: id (snake), title, prompt, options[{id,label}] (2-4), allow_custom (bool), item_id. '
                        .'JSON: {"subject":"","questions":[],"product_queries":[],"line_items":[],"cards":[]}.',
                ],
                [
                    'role' => 'user',
                    'content' => $body,
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
            $rawGroups = $this->search->findMany($sliced, self::MAX_MATCHES_PER_QUERY);
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
    public function resolveLineItems(string $body, array $fromAi): array
    {
        $parsed = $this->parseLineItemsFromBody($body);
        if ($parsed !== [] && count($parsed) > count($fromAi)) {
            return $parsed;
        }

        return $fromAi !== [] ? $fromAi : $parsed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parseLineItemsFromBody(string $body): array
    {
        $items = [];
        $index = 1;
        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(\d+)\s*('.self::UNIT_PATTERN.')?[\s.,:–-]+(.+)$/iu', $line, $m) !== 1) {
                continue;
            }
            $rest = trim($m[3]);
            $size = null;
            if (preg_match('/\b(?:rozmiar|rozm\.?)\s+([a-z0-9\/,.\-]+)/iu', $rest, $sizeMatch) === 1) {
                $size = trim($sizeMatch[1]);
            }
            $items[] = [
                'id' => 'item_'.$index,
                'quote' => $line,
                'qty' => $m[1],
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

        return $items;
    }

    private function queryFromLine(string $rest): string
    {
        $q = preg_replace('/\b(?:rozmiar|rozm\.?)\s+[a-z0-9\/,.\-]+/iu', '', $rest) ?? $rest;
        $q = preg_replace('/^\d+\s*'.self::UNIT_PATTERN.'?[\s.,:–-]+/iu', '', $q) ?? $q;
        $q = preg_replace('/\s+/u', ' ', $q) ?? $q;

        return mb_substr(trim($q), 0, 140);
    }

    /**
     * Do katalogu idzie cytat z warunkiem, nie sama nazwa z ekstraktora („kombinezon”).
     */
    public function catalogSearchQuery(string $query, string $quote): string
    {
        $query = trim($query);
        $fromQuote = $this->queryFromLine($quote);
        if ($fromQuote === '') {
            return $query;
        }
        if ($query === '' || mb_strlen($fromQuote) > mb_strlen($query)) {
            return $fromQuote;
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
        foreach ($lineItems as $item) {
            $query = $this->catalogSearchQuery(
                (string) ($item['query'] ?? ''),
                (string) ($item['quote'] ?? '')
            );
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
        $search = $this->catalogSearchQuery(
            (string) ($item['query'] ?? ''),
            (string) ($item['quote'] ?? '')
        );
        $found = $this->productsForQuery($matches, $search);
        if ($found !== []) {
            return $found;
        }

        return $this->productsForQuery($matches, (string) ($item['query'] ?? ''));
    }

    /**
     * @param  list<array{query: string, products: list<array<string, mixed>>}>  $matches
     * @return list<array<string, mixed>>
     */
    private function productsForQuery(array $matches, string $query): array
    {
        $key = mb_strtolower(trim($query));
        foreach ($matches as $group) {
            if (mb_strtolower(trim((string) ($group['query'] ?? ''))) === $key) {
                return $group['products'];
            }
        }
        if (count($matches) === 1) {
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
        $intro = $inquiry->tone === 'handlowy'
            ? "Dzień dobry,\n\nprzesyłamy ofertę do zapytania."
            : "Dzień dobry,\n\nw odpowiedzi na przesłane zapytanie przedstawiamy ofertę:";
        $parts = [
            $intro,
            '',
            $this->offerPositionBlocks($inquiry, $answers, $priceMode, $margin),
        ];
        $note = $this->nullable($extraNote);
        if ($note !== null) {
            $parts[] = '';
            $parts[] = $note;
        }
        $outro = ['W razie pytań zapraszamy do kontaktu.', '', 'Z poważaniem,', 'Zespół Supon'];
        $parts[] = '';
        foreach ($outro as $line) {
            $parts[] = $line;
        }

        return [
            'subject' => $this->offerSubject($inquiry),
            'body' => implode("\n", $parts),
            // ta sama treść w tabeli: po lewej zapytanie klienta, po prawej nasza odpowiedź
            'html' => InquiryReplyHtml::render(
                $intro,
                $this->offerRows($inquiry, $answers, $priceMode, $margin),
                $note,
                $outro,
            ),
        ];
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
     * @return list<array{head: string, quote: string|null, answer: list<string>}>
     */
    private function offerRows(ClientInquiry $inquiry, array $answers, string $priceMode, float $margin): array
    {
        $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];
        $matches = $this->matchGroups($analysis);

        $rows = [];
        foreach ($this->lineItemsOf($analysis) as $index => $item) {
            $candidates = $this->candidatesForItem($matches, $item);
            $product = $this->chosenProductForItem($item, $candidates, $answers);
            $substitute = $product === null
                ? null
                : $this->chosenSubstituteForItem($item, $this->substitutesForItem($analysis, $candidates), $answers);
            $rows[] = $this->offerRow($index + 1, $item, $product, $substitute, $priceMode, $margin);
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
     * @return array{head: string, quote: string|null, answer: list<string>}
     */
    private function offerRow(int $n, array $item, ?array $product, ?array $substitute, string $priceMode, float $margin): array
    {
        $qtyUnit = $this->qtyUnit($item);
        $size = trim((string) ($item['size'] ?? ''));
        $quote = trim((string) ($item['quote'] ?? ''));
        $head = (string) $n.'.';
        $meta = array_values(array_filter([
            $this->qtyLabel($item),
            $size !== '' ? 'rozmiar '.$size : null,
        ]));
        if ($meta !== []) {
            $head .= ' '.implode(', ', $meta);
        }
        if ($product === null) {
            // bez SKU: nic nie zmyślamy, pozycja czeka na weryfikację pracownika
            return [
                'head' => $head,
                'quote' => $quote === '' ? null : $quote,
                'answer' => ['Pozycję potwierdzimy po weryfikacji dostępności i wrócimy z propozycją.'],
            ];
        }

        $answer = $this->productLines('Produkt', $product, $priceMode, $margin, $qtyUnit['unit']);
        if ($substitute !== null) {
            $answer = array_merge($answer, $this->productLines('Zamiennik', $substitute, $priceMode, $margin, $qtyUnit['unit']));
        }

        return [
            'head' => $head,
            'quote' => $quote === '' ? null : $quote,
            'answer' => $answer,
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return list<string>
     */
    private function productLines(string $label, array $product, string $priceMode, float $margin, ?string $unit): array
    {
        $maker = trim((string) ($product['manufacturer'] ?? ''));
        $lines = [sprintf(
            '%s: %s (SKU %s)%s',
            $label,
            $product['name'],
            $product['sku'],
            $maker !== '' ? ', '.$maker : ''
        )];
        $norms = trim((string) ($product['norms'] ?? ''));
        if ($norms !== '') {
            $lines[] = 'Normy: '.$norms;
        }
        if ($priceMode !== '' && $priceMode !== 'none') {
            $price = $this->letterPrice($product, $priceMode, $margin);
            // jednostka tylko z maila — bez niej samo „netto”
            $lines[] = $price === null
                ? 'Cena: do potwierdzenia'
                : 'Cena: '.$price.' netto'.($unit !== null ? ' / '.$unit : '');
        }

        return $lines;
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
        $raw = trim((string) ($answers['price']['custom'] ?? ''));
        $raw = str_replace([',', '%', ' '], ['.', '', ''], $raw);
        if ($raw === '' || ! is_numeric($raw)) {
            return OfferPricing::markupPercent();
        }
        $value = (float) $raw;
        if ($value < 0) {
            return 0.0;
        }
        if ($value > 99) {
            return 99.0;
        }

        return $value;
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
