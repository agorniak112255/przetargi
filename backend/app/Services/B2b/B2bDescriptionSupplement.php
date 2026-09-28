<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Jobs\SupplementB2bDescriptionJob;
use App\Models\B2bAccount;
use App\Models\B2bDescriptionSupplementAttempt;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Support\ProductDescriptionText;
use RuntimeException;

/**
 * Uzupełnianie krótkiego opisu B2B ze stron konta (decyzja użytkownika 28.09.2026). Karta konta z hostami „z opisami”,
 * której opis wciąż jest tekstem z B2B (albo jego tłumaczeniem) i jest krótszy niż próg konta, idzie do
 * SupplementB2bDescriptionJob: model pisze opis z tekstu B2B i stron znalezionych najpierw na hostach konta.
 *
 * Karta kwalifikuje się (context) tylko wtedy, gdy:
 * - wszystkie powiązania konta z kartą mają ten sam description_hash = sha1(obecnego opisu) i ten sam
 *   source_description_hash — ta sama reguła „opis z B2B”, co B2bDescriptionSource, zawężona do jednego konta;
 * - opis jest opisem (Product::isDescriptionText) i jego tekst (ProductDescriptionText::plain) jest krótszy niż próg;
 * - opis producenta nie jest wyłączony w oknie „Producenci” (B2bManufacturerRules, producent jak w descriptionAllowed);
 * - karty nie opisuje już inny mechanizm: łącznik B2bDescribesFromDatasheet (opis z karty katalogowej — Tegro,
 *   Polstar, ARTRA) i łącznik B2bForeignLanguageSource bez source_description_hash (tłumaczenie w toku albo
 *   odrzucone — tekst obcojęzyczny nie jest jeszcze opisem karty), tak samo obcojęzyczny tekst karty łącznika
 *   B2bForeignTextCards bez tłumaczenia; stan wzbogacania manual/queued/running;
 * - opis nie jest wynikiem samego uzupełniania (isSupplementResult) — hashe takiej karty też „pasują”, ale jej opis
 *   to już tekst modelu, nie dostawcy.
 * Kilka kont z hostami pasuje do karty → konto o najniższym id (candidateIds innych kont kartę pomija).
 *
 * candidateIds czyta wiersze bez modeli Eloquent: najpierw powiązania konta (same hashe), potem opisy tylko kart
 * z hashami zgodnymi między powiązaniami, porcjami — konto ma do kilku tysięcy powiązań.
 */
final class B2bDescriptionSupplement
{
    public const DEFAULT_MIN_CHARS = 1000;

    /** Stany wzbogacania, przy których karty nie ruszamy: opis ręczny albo wzbogacanie w toku. */
    private const BUSY_STATUSES = [Product::ENRICHMENT_MANUAL, Product::ENRICHMENT_QUEUED, Product::ENRICHMENT_RUNNING];

    private const CHUNK = 500;

    /** @var array<int, array{hosts: list<string>, hosts_sha1: string, min_chars: int, connector_host: string, foreign: bool, foreign_cards: bool, rules: array<string, array{price: bool, description: bool}>}|null> */
    private array $accounts = [];

    public function __construct(
        private readonly B2bConnectorRegistry $registry,
        private readonly B2bManufacturerRules $rules,
    ) {}

    public static function plainLength(?string $text): int
    {
        return mb_strlen(ProductDescriptionText::plain($text));
    }

    /**
     * Obecny opis karty napisało uzupełnianie (SupplementB2bDescriptionJob): ślad b2b_supplement z odciskiem tego
     * opisu. Taki opis nie jest już tekstem z B2B — ponowne uzupełnienie wzięłoby tekst modelu za tekst dostawcy
     * (zmiana listy stron albo „także karty już próbowane”). Nowy tekst u dostawcy i tak wraca na kartę
     * synchronizacją, a wtedy odcisk przestaje pasować.
     */
    public static function isSupplementResult(string $description, mixed $payload): bool
    {
        $resultSha1 = is_array($payload) && is_array($payload['b2b_supplement'] ?? null)
            ? ($payload['b2b_supplement']['result_sha1'] ?? null)
            : null;

        return is_string($resultSha1) && $resultSha1 !== '' && hash_equals($resultSha1, sha1($description));
    }

    /**
     * Stan karty do uzupełnienia opisu — tylko dla karty kwalifikującej się teraz. $account = null: konta z hostami
     * powiązane z kartą, pierwsze pasujące wg id; podane konto — tylko ono.
     */
    public function context(Product $product, ?B2bAccount $account = null): ?B2bSupplementContext
    {
        $query = B2bProductLink::query()
            ->where('product_id', $product->id)
            ->orderBy('id');
        if ($account !== null) {
            $query->where('b2b_account_id', $account->id);
        }
        $byAccount = [];
        foreach ($query->toBase()->get(['id', 'b2b_account_id', 'description_hash', 'source_description_hash', 'manufacturer']) as $row) {
            $byAccount[(int) $row->b2b_account_id][] = $row;
        }
        ksort($byAccount);

        $description = (string) ($product->description ?? '');
        if (self::isSupplementResult($description, $product->enrichment_payload)) {
            return null;
        }
        foreach ($byAccount as $accountId => $links) {
            $candidate = $account ?? B2bAccount::query()->find($accountId);
            if ($candidate === null) {
                continue;
            }
            $info = $this->accountInfo($candidate);
            if ($info === null) {
                continue;
            }
            $match = $this->evaluate(
                $info,
                $description,
                $product->enrichment_status,
                (string) ($product->manufacturer ?? ''),
                $links,
            );
            if ($match === null) {
                continue;
            }

            return new B2bSupplementContext(
                accountId: (int) $candidate->id,
                linkIds: $match['link_ids'],
                descriptionHash: $match['description_hash'],
                sourceDescriptionHash: $match['source_description_hash'],
                sourceSha1: $match['source_sha1'],
                b2bText: $description,
                b2bUrl: self::onHost((string) ($product->shop_source_url ?? ''), $info['connector_host'])
                    ? trim((string) $product->shop_source_url)
                    : '',
                hosts: $info['hosts'],
                hostsSha1: $info['hosts_sha1'],
                minChars: $info['min_chars'],
                productDescription: $description,
            );
        }

        return null;
    }

    /**
     * @return list<int>
     */
    public function candidateIds(B2bAccount $account, bool $onlyUntried = true): array
    {
        return array_keys($this->candidates($account, null, $onlyUntried));
    }

    /**
     * Zleca uzupełnienie kartom kwalifikującym się teraz ($productIds = null: wszystkim kartom konta). Próba karty
     * dostaje stan queued z odciskiem wejścia (tekst źródła, lista stron) — ślad zostaje, nawet gdy job nic nie zapisze.
     *
     * @param  list<int>|null  $productIds
     * @return array{candidates: int, queued: int}
     */
    public function queue(B2bAccount $account, ?array $productIds = null, bool $onlyUntried = true): array
    {
        $candidates = $this->candidates($account, $productIds, $onlyUntried);
        if ($candidates === []) {
            return ['candidates' => 0, 'queued' => 0];
        }
        $hostsSha1 = $account->enrichmentHostsSha1();
        $now = now();
        foreach (array_chunk($candidates, self::CHUNK, true) as $chunk) {
            $rows = [];
            foreach ($chunk as $productId => $sourceSha1) {
                $rows[] = [
                    'product_id' => $productId,
                    'b2b_account_id' => (int) $account->id,
                    'source_sha1' => $sourceSha1,
                    'hosts_sha1' => $hostsSha1,
                    'status' => B2bDescriptionSupplementAttempt::STATUS_QUEUED,
                    'attempts' => 0,
                    'message' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            // licznik prób, wynik i źródła poprzedniej próby zostają — zmienia się stan i odcisk wejścia
            B2bDescriptionSupplementAttempt::query()->upsert(
                $rows,
                ['product_id', 'b2b_account_id'],
                ['source_sha1', 'hosts_sha1', 'status', 'message', 'updated_at'],
            );
        }
        $queued = 0;
        foreach (array_keys($candidates) as $productId) {
            SupplementB2bDescriptionJob::dispatch($productId, (int) $account->id);
            $queued++;
        }

        return ['candidates' => count($candidates), 'queued' => $queued];
    }

    /**
     * Karty konta kwalifikujące się teraz: id karty => odcisk tekstu źródła (source_sha1).
     *
     * @param  list<int>|null  $productIds
     * @return array<int, string>
     */
    private function candidates(B2bAccount $account, ?array $productIds, bool $onlyUntried): array
    {
        $info = $this->accountInfo($account);
        if ($info === null) {
            return [];
        }
        $links = $this->linkRows([(int) $account->id], $productIds);
        $groups = $links[(int) $account->id] ?? [];
        $groups = array_filter($groups, fn (array $rows): bool => $this->consistentHashes($info, $rows) !== null);
        if ($groups === []) {
            return [];
        }
        $tried = $onlyUntried ? $this->finalAttempts((int) $account->id, $info['hosts_sha1']) : [];
        $lower = $this->lowerHostAccounts($account);

        $out = [];
        foreach (array_chunk(array_keys($groups), self::CHUNK) as $chunk) {
            $products = $this->productRows($chunk);
            $matched = [];
            foreach ($products as $id => $row) {
                $match = $this->evaluate($info, (string) ($row->description ?? ''), $row->enrichment_status, (string) ($row->manufacturer ?? ''), $groups[$id]);
                if ($match === null) {
                    continue;
                }
                if (isset($tried[$id]) && hash_equals($tried[$id], $match['source_sha1'])) {
                    continue;
                }
                $matched[$id] = $match['source_sha1'];
            }
            // opis napisany przez samo uzupełnianie — payload czytany tylko dla kart, które poza tym pasują
            if ($matched !== []) {
                $payloads = Product::query()->whereIn('id', array_keys($matched))->toBase()->pluck('enrichment_payload', 'id');
                foreach ($payloads as $id => $json) {
                    $payload = is_string($json) ? json_decode($json, true) : null;
                    if (self::isSupplementResult((string) ($products[(int) $id]->description ?? ''), $payload)) {
                        unset($matched[(int) $id]);
                    }
                }
            }
            // karta pasująca też do konta z hostami o niższym id należy do tamtego konta (context bez konta)
            if ($matched !== [] && $lower !== []) {
                $lowerLinks = $this->linkRows(array_keys($lower), array_keys($matched));
                foreach ($lowerLinks as $lowerId => $byProduct) {
                    foreach ($byProduct as $id => $rows) {
                        if (isset($matched[$id]) && $this->evaluate(
                            $lower[$lowerId],
                            (string) ($products[$id]->description ?? ''),
                            $products[$id]->enrichment_status,
                            (string) ($products[$id]->manufacturer ?? ''),
                            $rows,
                        ) !== null) {
                            unset($matched[$id]);
                        }
                    }
                }
            }
            $out += $matched;
        }
        ksort($out);

        return $out;
    }

    /**
     * Powiązania kont z kartami bez modeli: id konta => id karty => wiersze (wg id powiązania).
     *
     * @param  list<int>  $accountIds
     * @param  list<int>|null  $productIds
     * @return array<int, array<int, list<object>>>
     */
    private function linkRows(array $accountIds, ?array $productIds): array
    {
        $out = [];
        $read = function (?array $ids) use ($accountIds, &$out): void {
            $query = B2bProductLink::query()
                ->whereIn('b2b_account_id', $accountIds)
                ->whereNotNull('product_id')
                ->orderBy('id');
            if ($ids !== null) {
                $query->whereIn('product_id', $ids);
            }
            foreach ($query->toBase()->get(['id', 'b2b_account_id', 'product_id', 'description_hash', 'source_description_hash', 'manufacturer']) as $row) {
                $out[(int) $row->b2b_account_id][(int) $row->product_id][] = $row;
            }
        };
        if ($productIds === null) {
            $read(null);
        } else {
            $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
            foreach (array_chunk($ids, 1000) as $chunk) {
                $read($chunk);
            }
        }

        return $out;
    }

    /**
     * Opisy kart porcji (bez modeli) — tylko karty, których stan wzbogacania pozwala na uzupełnienie.
     *
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    private function productRows(array $ids): array
    {
        $out = [];
        $rows = Product::query()
            ->whereIn('id', $ids)
            ->where(static fn ($q) => $q->whereNull('enrichment_status')->orWhereNotIn('enrichment_status', self::BUSY_STATUSES))
            ->toBase()
            ->get(['id', 'description', 'manufacturer', 'enrichment_status']);
        foreach ($rows as $row) {
            $out[(int) $row->id] = $row;
        }

        return $out;
    }

    /**
     * Próby z wynikiem ostatecznym dla obecnej listy stron: id karty => source_sha1 tamtej próby.
     *
     * @return array<int, string>
     */
    private function finalAttempts(int $accountId, string $hostsSha1): array
    {
        $out = [];
        $rows = B2bDescriptionSupplementAttempt::query()
            ->where('b2b_account_id', $accountId)
            // błąd powtarzający się dla tego samego wejścia (model, strona) też kończy ponowienia przy synchronizacji
            ->where(static fn ($q) => $q->whereIn('status', B2bDescriptionSupplementAttempt::FINAL_STATUSES)
                ->orWhere(static fn ($f) => $f->where('status', B2bDescriptionSupplementAttempt::STATUS_FAILED)
                    ->where('attempts', '>=', B2bDescriptionSupplementAttempt::MAX_FAILED_ATTEMPTS)))
            ->where('hosts_sha1', $hostsSha1)
            ->toBase()
            ->get(['product_id', 'source_sha1']);
        foreach ($rows as $row) {
            $out[(int) $row->product_id] = (string) $row->source_sha1;
        }

        return $out;
    }

    /**
     * Konta z hostami o niższym id niż $account (z działającym łącznikiem, który nie wyklucza uzupełniania).
     *
     * @return array<int, array{hosts: list<string>, hosts_sha1: string, min_chars: int, connector_host: string, foreign: bool, foreign_cards: bool, rules: array<string, array{price: bool, description: bool}>}>
     */
    private function lowerHostAccounts(B2bAccount $account): array
    {
        $out = [];
        $accounts = B2bAccount::query()
            ->where('id', '<', $account->id)
            ->whereNotNull('enrichment_sites')
            ->orderBy('id')
            ->get();
        foreach ($accounts as $other) {
            $info = $this->accountInfo($other);
            if ($info !== null) {
                $out[(int) $other->id] = $info;
            }
        }

        return $out;
    }

    /**
     * Konto, które może uzupełniać opisy: hosty, próg, host łącznika, cechy łącznika i reguły producentów.
     * null = brak hostów, brak łącznika albo łącznik opisujący karty z karty katalogowej.
     *
     * @return array{hosts: list<string>, hosts_sha1: string, min_chars: int, connector_host: string, foreign: bool, foreign_cards: bool, rules: array<string, array{price: bool, description: bool}>}|null
     */
    private function accountInfo(B2bAccount $account): ?array
    {
        $id = (int) $account->id;
        if (array_key_exists($id, $this->accounts)) {
            return $this->accounts[$id];
        }
        $hosts = $account->enrichmentHosts();
        $info = null;
        if ($hosts !== []) {
            try {
                $connector = $this->registry->make($account, 0);
            } catch (RuntimeException) {
                $connector = null;
            }
            // opis takiej karty pisze model z karty katalogowej (DescribeB2bProductFromDatasheetJob), nie ze stron
            if ($connector !== null && ! $connector instanceof B2bDescribesFromDatasheet) {
                $info = [
                    'hosts' => $hosts,
                    'hosts_sha1' => $account->enrichmentHostsSha1(),
                    'min_chars' => $account->enrichmentMinChars(),
                    'connector_host' => mb_strtolower(trim($connector::host(), '.')),
                    'foreign' => $connector instanceof B2bForeignLanguageSource,
                    'foreign_cards' => $connector instanceof B2bForeignTextCards,
                    'rules' => $this->rules->forAccount($id),
                ];
            }
        }

        return $this->accounts[$id] = $info;
    }

    /**
     * Wspólne hashe wszystkich powiązań konta z kartą; null = hashe różne albo brak odcisku opisu, albo tekst
     * obcojęzyczny przed tłumaczeniem (łącznik B2bForeignLanguageSource bez source_description_hash).
     *
     * @param  array{foreign: bool}  $info
     * @param  list<object>  $links
     * @return array{description_hash: string, source_description_hash: string|null}|null
     */
    private function consistentHashes(array $info, array $links): ?array
    {
        if ($links === []) {
            return null;
        }
        $description = $links[0]->description_hash;
        $source = $links[0]->source_description_hash;
        if ($description === null || $description === '') {
            return null;
        }
        foreach ($links as $link) {
            if ($link->description_hash !== $description || $link->source_description_hash !== $source) {
                return null;
            }
        }
        if ($info['foreign'] && $source === null) {
            return null;
        }

        return ['description_hash' => (string) $description, 'source_description_hash' => $source !== null ? (string) $source : null];
    }

    /**
     * @param  array{hosts: list<string>, hosts_sha1: string, min_chars: int, connector_host: string, foreign: bool, foreign_cards: bool, rules: array<string, array{price: bool, description: bool}>}  $info
     * @param  list<object>  $links  powiązania konta z kartą wg id
     * @return array{link_ids: list<int>, description_hash: string, source_description_hash: string|null, source_sha1: string}|null
     */
    private function evaluate(array $info, string $description, ?string $status, string $productManufacturer, array $links): ?array
    {
        $hashes = $this->consistentHashes($info, $links);
        if ($hashes === null
            || in_array($status, self::BUSY_STATUSES, true)
            || ! hash_equals($hashes['description_hash'], sha1($description))
            || ! Product::isDescriptionText($description)
            || self::plainLength($description) >= $info['min_chars']) {
            return null;
        }
        // Łącznik z obcojęzycznymi pojedynczymi kartami (B2bForeignTextCards: Ardon po czesku/słowacku, UVEX po
        // angielsku): karta bez source_description_hash może jeszcze czekać na tłumaczenie — obcy tekst to nie opis
        // karty, a model wziąłby go za tekst dostawcy. Polski tekst takiego konta (bez tłumaczenia) się kwalifikuje.
        if ($info['foreign_cards'] && $hashes['source_description_hash'] === null && self::looksUntranslated($description)) {
            return null;
        }
        // producent jak w B2bManufacturerRules::descriptionAllowed: w brzmieniu konta (pierwsze powiązanie), inaczej z karty
        $manufacturer = (string) ($links[0]->manufacturer ?? $productManufacturer);
        if (! ($info['rules'][B2bManufacturerRules::key($manufacturer)]['description'] ?? true)) {
            return null;
        }

        return [
            'link_ids' => array_map(static fn (object $link): int => (int) $link->id, $links),
            'description_hash' => $hashes['description_hash'],
            'source_description_hash' => $hashes['source_description_hash'],
            'source_sha1' => $hashes['source_description_hash'] ?? $hashes['description_hash'],
        ];
    }

    /**
     * Tekst po czesku/słowacku (ArdonB2bConnector::isForeignText) albo po angielsku: długi — reguła zapisu opisów
     * (ProductDescriptionText::looksLikeForeignOrPartsTableDump), krótki — angielskie słowa funkcyjne bez żadnej
     * polskiej litery (krótkiego tekstu tamta reguła nie ocenia).
     */
    private static function looksUntranslated(string $description): bool
    {
        $plain = ProductDescriptionText::plain($description);
        if (ArdonB2bConnector::isForeignText($plain) || ProductDescriptionText::looksLikeForeignOrPartsTableDump($plain)) {
            return true;
        }
        $low = mb_strtolower($plain);

        return preg_match('/[ąćęłńóśźż]/u', $low) !== 1
            && preg_match_all('/\b(?:the|and|with|for|of|is|are|from|your|you|by|this|that|which|can)\b/u', $low) >= 3;
    }

    /** Adres leży na witrynie łącznika konta (host łącznika albo jego subdomena). */
    private static function onHost(string $url, string $connectorHost): bool
    {
        $host = mb_strtolower(trim((string) parse_url(trim($url), PHP_URL_HOST), '.'));

        return $host !== '' && $connectorHost !== ''
            && ($host === $connectorHost || str_ends_with($host, '.'.$connectorHost));
    }
}
