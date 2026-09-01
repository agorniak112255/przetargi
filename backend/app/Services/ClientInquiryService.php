<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ClientInquiry;
use App\Models\ProductSubstitute;
use App\Models\User;
use App\Support\OfferPricing;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use RuntimeException;
use Throwable;

final class ClientInquiryService
{
    private const MAX_PRODUCT_QUERIES = 10;

    private const MAX_MATCHES_PER_QUERY = 3;

    private const MAX_LINE_ITEMS = 8;

    private const MAX_CARDS = 28;

    public function __construct(
        private readonly OpenAiCompatibleClient $llm,
        private readonly ProductInquirySearch $search,
    ) {}

    public function analyze(
        User $user,
        string $body,
        string $tone,
        ?int $clientId,
        ?string $subject,
    ): ClientInquiry {
        $extracted = $this->extract($body);
        $lineItems = $this->resolveLineItems($body, $extracted['line_items']);
        $queries = $this->uniqueQueries($lineItems, $extracted['product_queries']);
        $matches = $this->matchProducts($queries);
        $cards = $this->buildCards($extracted['cards'], $matches, $lineItems, $this->loadSubstitutes($matches));

        return ClientInquiry::query()->create([
            'user_id' => $user->id,
            'client_id' => $clientId,
            'tone' => $tone,
            'source_subject' => $this->nullable($subject) ?? $extracted['subject'],
            'source_body' => $body,
            'analysis' => [
                'subject' => $extracted['subject'],
                'questions' => $extracted['questions'],
                'product_queries' => $queries,
                'line_items' => $lineItems,
                'matches' => $matches,
                'cards' => $cards,
            ],
        ])->load('client');
    }

    /**
     * @param  array<string, array{option_id: string, custom?: string|null}>  $answers
     */
    public function compose(ClientInquiry $inquiry, array $answers, ?string $extraNote): ClientInquiry
    {
        $inquiry->forceFill([
            'answers' => $answers,
            'extra_note' => $this->nullable($extraNote),
        ])->save();

        $draft = $this->writeReply($inquiry, $answers, $extraNote);

        $inquiry->forceFill([
            'reply_subject' => $draft['subject'],
            'reply_body' => $draft['body'],
        ])->save();

        return $inquiry->fresh(['client']) ?? $inquiry;
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
                        .'qty (np. „30 szt.”), query (fraza do katalogu BEZ rozmiaru, Z warunkiem: substancja, norma, typ), size (lub null). '
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

                if ($products !== []) {
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
                    ['id' => 'category', 'label' => 'Ogólnie o kategorii, bez SKU'],
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
                'prompt' => 'Czy podać cenę w liście? Ceny zakupu nigdy nie idą do listu.',
                'options' => [
                    ['id' => 'none', 'label' => 'Bez ceny'],
                    ['id' => 'catalog', 'label' => 'Cena katalogowa'],
                    ['id' => 'catalog_margin', 'label' => 'Cena katalogowa + marża'],
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
            if (preg_match('/^(\d+)\s*(?:szt\.?|sztuk|pcs\.?)?[\s.,:–-]+(.+)$/iu', $line, $m) !== 1) {
                continue;
            }
            $rest = trim($m[2]);
            $size = null;
            if (preg_match('/\b(?:rozmiar|rozm\.?)\s+([a-z0-9\/,.\-]+)/iu', $rest, $sizeMatch) === 1) {
                $size = trim($sizeMatch[1]);
            }
            $items[] = [
                'id' => 'item_'.$index,
                'quote' => $line,
                'qty' => $m[1].' szt.',
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
        $q = preg_replace('/^\d+\s*(?:szt\.?|sztuk|pcs\.?)?[\s.,:–-]+/iu', '', $q) ?? $q;
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
        $qty = trim((string) ($item['qty'] ?? ''));
        $size = trim((string) ($item['size'] ?? ''));
        if ($products === []) {
            $options = [
                ['id' => 'check', 'label' => 'Sprawdzimy i wrócimy'],
                ['id' => 'category', 'label' => 'Ogólnie o kategorii, bez SKU'],
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
            'qty' => $qty !== '' ? $qty : null,
            'size' => $size !== '' ? $size : null,
        ];
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

        $qty = trim((string) ($item['qty'] ?? ''));
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
            'qty' => $qty !== '' ? $qty : null,
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
                $card['qty'] = $item['qty'];
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

        return [
            'id' => $id,
            'quote' => $quote !== '' ? $quote : $query,
            'qty' => $this->nullable($item['qty'] ?? null),
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
        $facts = $this->factsBlock($inquiry, $answers);
        $decisions = $this->decisionsBlock($inquiry, $answers, $extraNote);
        $clientName = $inquiry->client?->name;

        try {
            $raw = $this->llm->chatJson([
                [
                    'role' => 'system',
                    'content' => 'Piszesz odpowiedź e-mail po polsku dla handlowca BHP/PPE (Supon). '
                        .'Używaj WYŁĄCZNIE faktów z bloku katalogu i decyzji handlowca. '
                        .'Odpowiedz na KAŻDĄ pozycję osobno: cytat/ilość, wybrany towar, ewentualny zamiennik. '
                        .'Nie wymyślaj SKU, norm, stanów ani cen. '
                        .'Ceny zakupu są zakazane. Cenę katalogową albo ofertę (katalog + marża) podaj tylko gdy decyzja to pozwala. '
                        .'Brak faktu → wstaw [DO UZUPEŁNIENIA: …], nie zgaduj. '
                        .'Ton: '.($inquiry->tone === 'handlowy' ? 'luźniejszy handlowy, nadal rzeczowy' : 'formalny, uprzejmy').'. '
                        .'Bez markdown. Zwykły tekst maila: powitanie, treść, pozdrowienia (zespół Supon). '
                        .'JSON: {"subject":"temat bez Re:","body":"pełna treść"}.',
                ],
                [
                    'role' => 'user',
                    'content' => implode("\n\n", array_filter([
                        $clientName !== null && $clientName !== '' ? 'Klient: '.$clientName : null,
                        $inquiry->source_subject ? 'Temat zapytania: '.$inquiry->source_subject : null,
                        "Zapytanie klienta:\n".$inquiry->source_body,
                        $this->lineItemsBlock($inquiry),
                        "Fakty z katalogu:\n".$facts,
                        "Decyzje handlowca:\n".$decisions,
                    ])),
                ],
            ], 0.2, 2500, null, AiTask::ClientInquiry);
        } catch (Throwable $e) {
            throw new RuntimeException('Nie udało się napisać odpowiedzi: '.$e->getMessage(), 0, $e);
        }

        $subject = $this->nullable($raw['subject'] ?? null)
            ?? $inquiry->source_subject
            ?? 'Odpowiedź na zapytanie';
        $body = $this->nullable($raw['body'] ?? null);
        if ($body === null) {
            throw new RuntimeException('Model nie zwrócił treści listu.');
        }

        return ['subject' => $subject, 'body' => $body];
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
        $price = $product['catalog_price_net'] ?? null;
        $priceText = $price !== null && $price !== ''
            ? $price.' '.($product['currency'] ?? 'PLN')
            : 'brak';

        return sprintf(
            'SKU %s, nazwa: %s, producent: %s, normy: %s, cena katalogowa: %s, stan: %s',
            $product['sku'],
            $product['name'],
            $product['manufacturer'] !== '' ? $product['manufacturer'] : '—',
            $product['norms'] !== '' ? $product['norms'] : '—',
            $priceText,
            $product['stock'] !== null ? (string) $product['stock'] : 'brak'
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
            'Ceny: podaj cenę oferty = katalog + '.$percent.'% marży. Nie podawaj zakupu ani samej ceny katalogowej.',
        ];
        foreach ($products as $product) {
            $offer = OfferPricing::fromPurchase($product['catalog_price_net'] ?? null, $percent);
            if ($offer === null) {
                $lines[] = sprintf(
                    '- %s: brak ceny katalogowej → [DO UZUPEŁNIENIA: cena oferty]',
                    $product['sku']
                );
                continue;
            }
            $lines[] = sprintf(
                '- %s: oferta %s %s (katalog %s + %s%%)',
                $product['sku'],
                number_format($offer, 2, '.', ''),
                $product['currency'] ?? 'PLN',
                (string) ($product['catalog_price_net'] ?? ''),
                rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.')
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

        return [
            'id' => $id,
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => trim((string) ($row['manufacturer'] ?? '')),
            'norms' => trim((string) ($row['norms'] ?? '')),
            'catalog_price_net' => $price !== null && $price !== '' ? (string) $price : null,
            'currency' => trim((string) ($row['currency'] ?? 'PLN')) ?: 'PLN',
            'stock' => isset($row['stock']) ? (int) $row['stock'] : null,
            'score' => (int) ($row['ai_match_percent'] ?? $row['score'] ?? 0),
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

    private function nullable(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trim = trim($value);

        return $trim === '' ? null : $trim;
    }
}
