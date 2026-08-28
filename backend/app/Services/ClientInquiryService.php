<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ClientInquiry;
use App\Models\User;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use RuntimeException;
use Throwable;

final class ClientInquiryService
{
    private const MAX_PRODUCT_QUERIES = 3;

    private const MAX_MATCHES_PER_QUERY = 3;

    private const MAX_CARDS = 5;

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
        $matches = $this->matchProducts($extracted['product_queries']);
        $cards = $this->buildCards($extracted['cards'], $matches);

        return ClientInquiry::query()->create([
            'user_id' => $user->id,
            'client_id' => $clientId,
            'tone' => $tone,
            'source_subject' => $this->nullable($subject) ?? $extracted['subject'],
            'source_body' => $body,
            'analysis' => [
                'subject' => $extracted['subject'],
                'questions' => $extracted['questions'],
                'product_queries' => $extracted['product_queries'],
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
                        .'product_queries: 1-3 krótkie frazy do wyszukania w katalogu (nazwa/model/norma). '
                        .'cards: max 4 karty doprecyzowania — TYLKO prawdziwe niejasności '
                        .'(rozmiar, ilość, termin, ton, czy dopytać). Nie pytaj o oczywistości. '
                        .'Każda karta: id (snake), title, prompt, options[{id,label}] (2-4), allow_custom (bool). '
                        .'JSON: {"subject":"","questions":[],"product_queries":[],"cards":[]}.',
                ],
                [
                    'role' => 'user',
                    'content' => $body,
                ],
            ], 0.1, 2500, null, AiTask::ClientInquiry);
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

        return [
            'subject' => $this->nullable($raw['subject'] ?? null),
            'questions' => $this->stringList($raw['questions'] ?? null),
            'product_queries' => $this->stringList($raw['product_queries'] ?? null),
            'cards' => $cards,
        ];
    }

    /**
     * @param  list<string>  $queries
     * @return list<array{query: string, products: list<array<string, mixed>>}>
     */
    private function matchProducts(array $queries): array
    {
        $groups = [];
        foreach (array_slice($queries, 0, self::MAX_PRODUCT_QUERIES) as $query) {
            try {
                $result = $this->search->find($query, self::MAX_MATCHES_PER_QUERY);
            } catch (Throwable) {
                $groups[] = ['query' => $query, 'products' => []];

                continue;
            }

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
     * @return list<array<string, mixed>>
     */
    public function buildCards(array $aiCards, array $matches): array
    {
        $flat = $this->flatProducts($matches);
        $best = $flat[0] ?? null;
        $confidentSingle = count($flat) === 1
            && $best !== null
            && (int) ($best['score'] ?? 0) >= 80;

        if ($confidentSingle && $aiCards === []) {
            return [];
        }

        $cards = [];
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
            ];
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
            ];
        }

        $used = array_column($cards, 'id');
        foreach ($aiCards as $card) {
            $id = (string) ($card['id'] ?? '');
            if ($id === '' || in_array($id, $used, true) || in_array($id, ['product', 'missing'], true)) {
                continue;
            }
            $cards[] = $card;
            $used[] = $id;
            if (count($cards) >= self::MAX_CARDS) {
                return array_slice($cards, 0, self::MAX_CARDS);
            }
        }

        if ($flat !== []) {
            foreach ($this->commerceCards() as $card) {
                if (in_array($card['id'], $used, true)) {
                    continue;
                }
                $cards[] = $card;
                $used[] = $card['id'];
                if (count($cards) >= self::MAX_CARDS) {
                    break;
                }
            }
        }

        return array_slice($cards, 0, self::MAX_CARDS);
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
                'prompt' => 'Czy podać cenę katalogową? Ceny zakupu nigdy nie idą do listu.',
                'options' => [
                    ['id' => 'none', 'label' => 'Bez ceny'],
                    ['id' => 'catalog', 'label' => 'Cena katalogowa'],
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
                        .'Nie wymyślaj SKU, norm, stanów ani cen. '
                        .'Ceny zakupu są zakazane. Cenę katalogową podaj tylko gdy decyzja to pozwala. '
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

    /**
     * @param  array<string, array{option_id: string, custom?: string|null}>  $answers
     */
    private function factsBlock(ClientInquiry $inquiry, array $answers): string
    {
        $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];
        $matches = is_array($analysis['matches'] ?? null) ? $analysis['matches'] : [];
        $flat = $this->flatProducts($matches);
        $selected = $this->selectedProducts($flat, $answers);

        if ($selected === []) {
            return 'Brak potwierdzonego produktu z katalogu. Nie podawaj SKU ani ceny.';
        }

        $lines = [];
        foreach ($selected as $product) {
            $price = $product['catalog_price_net'];
            $priceText = $price !== null && $price !== ''
                ? $price.' '.($product['currency'] ?? 'PLN')
                : 'brak';
            $lines[] = sprintf(
                '- SKU %s, nazwa: %s, producent: %s, normy: %s, cena katalogowa: %s, stan: %s',
                $product['sku'],
                $product['name'],
                $product['manufacturer'] !== '' ? $product['manufacturer'] : '—',
                $product['norms'] !== '' ? $product['norms'] : '—',
                $priceText,
                $product['stock'] !== null ? (string) $product['stock'] : 'brak'
            );
        }

        return implode("\n", $lines);
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
        $option = (string) ($answers['product']['option_id'] ?? '');
        if (str_starts_with($option, 'p:')) {
            $id = (int) substr($option, 2);
            foreach ($flat as $product) {
                if ((int) $product['id'] === $id) {
                    return [$product];
                }
            }
        }
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

        return [
            'id' => $id,
            'title' => $title,
            'prompt' => $prompt,
            'options' => array_slice($options, 0, 4),
            'allow_custom' => (bool) ($card['allow_custom'] ?? false),
        ];
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
