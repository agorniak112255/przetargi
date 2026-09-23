<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Services\B2b\B2bDocumentText;
use App\Services\B2b\B2bManufacturerRules;
use App\Services\Enrichment\B2bSourcesDescriptionRejected;
use App\Services\Enrichment\EnrichmentSlots;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Support\BhpAttributeNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Opis karty z dwóch źródeł — opisu ze sklepu i karty katalogowej PDF zapisanej przy karcie przez import B2B
 * (łącznik B2bDescribesFromDatasheet; decyzja użytkownika 21.09.2026). Nic z internetu: model dostaje tylko te dwa
 * teksty (ProductEnrichmentService::describeFromB2bSources), a pochodzenie trafia do enrichment_payload.b2b_sources.
 *
 * Hashe powiązania jak przy tłumaczeniu (TranslateB2bProductTextJob): description_hash = sha1(opisu na karcie),
 * source_description_hash = sha1(tekstu sklepu, z którego opis powstał). Dzięki temu:
 * - import z tym samym tekstem sklepu zostawia opis (B2bCatalogSync::keepsTranslation),
 * - nowy tekst u dostawcy wraca na kartę (stary opis w enrichment_payload.replaced_description) i karta dostaje
 *   nowe zlecenie,
 * - zbiorcze uzupełnianie z internetu kartę omija (B2bDescriptionSource: opis wciąż „z B2B”).
 * Zmiana samego PDF-u nie zleca nowego opisu: import nie pobiera ponownie pliku spod znanego adresu, a plik pod nowym
 * adresem staje za starym — sha1 użytej karty zostaje w b2b_sources jako ślad, z czego opis powstał.
 *
 * Łącznik B2bDatasheetOnlyDescription (ARTRA, decyzja użytkownika 22.09.2026; $datasheetOnly) nie ma opisu sklepu:
 * źródłem jest sama karta katalogowa i tabelka ze strony (shop_fields_summary), a tekst obecny na karcie (slogan marki
 * zapisany przed tą decyzją) do modelu nie trafia. Opisujemy kartę bez opisu albo z tekstem zapisanym przez
 * synchronizację i nietkniętym od tamtej pory (description_hash = sha1 opisu); opis spoza synchronizacji (przywrócony
 * opis AI, poprawka człowieka) zostaje — tak samo, jak przy Tegro zostaje karta, której opis ktoś zmienił.
 * source_description_hash = sha1('') oznacza opis już napisany z karty katalogowej — kolejne przebiegi go nie zlecają.
 * Tabelka ze strony idzie do modelu przesiana (shopFieldsForModel — artra.pl ma tabelki zamienione między wariantami),
 * a opis z klasą obuwia innego wariantu niż klasa z nazwy karty jest odrzucany (foreignFootwearClasses).
 *
 * Odrzucony opis zostawia ślad w enrichment_payload.b2b_sources_rejected (odcisk źródeł, licznik prób, powód) — dla
 * tych samych źródeł sources() zleca go ponownie do MAX_REJECTED_ATTEMPTS odrzuceń (odpowiedź modelu bywa losowa),
 * a wcale, gdy odrzucenie jest trwałe (PDF opisuje wyłącznie inny wariant obuwia); nowy PDF, inna tabelka albo nazwa
 * karty liczą od nowa.
 *
 * $redo (komenda b2b:redescribe-from-datasheets, 22.09.2026 — po zaostrzeniu polecenia i kontroli twierdzeń
 * SourceClaimGuard) opisuje ponownie kartę, której obecny opis napisał ten job (describedByThisJob), mimo ustawionego
 * source_description_hash. Opis wpisany ręcznie albo przywrócony ma niezgodny odcisk i nie jest nadpisywany; ślad
 * odrzuceń liczy się jak zwykle, a odrzucony ponowny opis zostawia na karcie opis dotychczasowy.
 *
 * Slot z EnrichmentSlots i compare-and-set jak w TranslateB2bProductTextJob: model odpowiada nawet minutę, w tym czasie
 * kartę mógł zmienić import albo człowiek — wtedy wynik przepada, karta zostaje nietknięta.
 */
class DescribeB2bProductFromDatasheetJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const QUEUE = 'enrich';

    public const PRIMARY_SOURCE_KIND = 'b2b_datasheet';

    /** Tyle odrzuceń dla tych samych źródeł, po których sources() przestaje zlecać opis (patrz storeRejection). */
    public const MAX_REJECTED_ATTEMPTS = 3;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 90, 180];

    // Klient AI potrafi czekać ~2 min na przeciążony model; do tego do 2 min czekania na slot.
    public int $timeout = 300;

    public int $uniqueFor = 3600;

    /**
     * Zwykłe pole z wartością domyślną, nie parametr promowany: job zapisany w kolejce przed dodaniem pola nie ma go
     * w danych, a Laravel odtwarza joba bez konstruktora — pole promowane zostałoby niezainicjowane i job by padł.
     */
    public bool $redo = false;

    public function __construct(
        public readonly int $productId,
        public readonly int $b2bAccountId,
        public readonly bool $datasheetOnly = false,
        bool $redo = false,
    ) {
        $this->redo = $redo;
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return $this->productId.':'.$this->b2bAccountId;
    }

    public function handle(ProductEnrichmentService $enrichment, EnrichmentSlots $slots): void
    {
        $product = Product::query()->find($this->productId);
        $link = $product !== null ? self::link($this->productId, $this->b2bAccountId) : null;
        if ($product === null || $link === null) {
            return;
        }
        // opis producenta wyłączony w oknie „Producenci” po zleceniu — job nie może go już zapisać
        if (! app(B2bManufacturerRules::class)->descriptionAllowed($link, $product)) {
            return;
        }
        $start = self::sources($product, $link, self::datasheet($this->productId, $this->b2bAccountId), $this->datasheetOnly, $this->redo);
        if ($start === null) {
            return;
        }

        $slot = $slots->acquire(
            $this->timeout + 60,
            (float) config('ai.enrichment_slot_wait_seconds', 120)
        );
        if ($slot === null) {
            // Limit z Ustawień AI obłożony — karta wraca do kolejki bez zużycia próby.
            self::dispatch($this->productId, $this->b2bAccountId, $this->datasheetOnly, $this->redo)->delay(now()->addSeconds(10));
            $this->delete();

            return;
        }

        // ARTRA ma tabelki zamienione między wariantami modelu — do modelu idą tylko wiersze zgodne z klasą z nazwy
        $shopFields = $this->datasheetOnly
            ? self::shopFieldsForModel($product)
            : ['text' => (string) ($product->shop_fields_summary ?? ''), 'dropped' => []];
        try {
            $result = $enrichment->describeFromB2bSources(
                $product,
                $start['shop_text'],
                (string) ($product->shop_source_url ?? ''),
                $start['sheet_text'],
                $start['sheet_url'],
                $shopFields['text'],
            );
            $foreign = $this->datasheetOnly ? self::foreignFootwearClasses($product, $result) : [];
            if ($foreign !== []) {
                throw new B2bSourcesDescriptionRejected(
                    'klasa obuwia spoza wariantu karty ('.(self::cardFootwearClass($product) ?? '—').'): '.implode(', ', $foreign),
                    self::foreignClassComesFromSources($product, $foreign, $start['sheet_text'], $shopFields['text']),
                );
            }
        } catch (B2bSourcesDescriptionRejected $e) {
            Log::warning('Opis z karty katalogowej B2B odrzucony — karta zostaje z opisem ze sklepu', [
                'product_id' => $this->productId,
                'b2b_account_id' => $this->b2bAccountId,
                'sku' => $product->sku,
                'reason' => $e->getMessage(),
                'shop_fields_dropped' => $shopFields['dropped'],
            ]);
            $this->storeRejection(self::rejectionKey($product, $start), $start, $e->getMessage(), $e->permanent);

            return;
        } finally {
            $slot->release();
        }

        $skipReason = $this->store($start, $result, $shopFields['dropped']);
        Log::info($skipReason === null
            ? 'Opis karty napisany z opisu sklepu i karty katalogowej B2B'
            : 'Opis z karty katalogowej B2B niezapisany — karta zmieniła się w trakcie', [
                'product_id' => $this->productId,
                'sku' => $product->sku,
                'reason' => $skipReason,
                'dropped_levels' => $result['dropped'],
                'dropped_claims' => $result['dropped_claims'],
                'shop_fields_dropped' => $shopFields['dropped'],
                'redo' => $this->redo,
            ]);
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('Opis z karty katalogowej B2B nie powiódł się', [
            'product_id' => $this->productId,
            'b2b_account_id' => $this->b2bAccountId,
            'error' => $e?->getMessage(),
        ]);
    }

    /** Pierwsze powiązanie konta z kartą (grupa rozmiarów ma ich kilka, wszystkie z tymi samymi hashami). */
    public static function link(int $productId, int $b2bAccountId): ?B2bProductLink
    {
        return B2bProductLink::query()
            ->where('b2b_account_id', $b2bAccountId)
            ->where('product_id', $productId)
            ->orderBy('id')
            ->first();
    }

    /**
     * Karta katalogowa od tego konta z odczytanym tekstem — pierwsza wg kolejności ze sklepu (łącznik stawia polską
     * kartę na początku), a gdy żadnej nie ma, instrukcja.
     */
    public static function datasheet(int $productId, int $b2bAccountId): ?ProductDocument
    {
        foreach ([ProductDocument::KIND_DATASHEET, ProductDocument::KIND_MANUAL] as $kind) {
            $document = ProductDocument::query()
                ->where('product_id', $productId)
                ->where('b2b_account_id', $b2bAccountId)
                ->where('kind', $kind)
                ->whereNotNull('text')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->first(static fn (ProductDocument $d): bool => B2bDocumentText::forCard((string) $d->text) !== '');
            if ($document !== null) {
                return $document;
            }
        }

        return null;
    }

    /**
     * Co opisać i stan, który musi przetrwać do zapisu; null = nic do zrobienia. Wspólne dla joba i importu
     * (zaległe karty przy każdym przebiegu). $datasheetOnly — łącznik B2bDatasheetOnlyDescription (opis bez tekstu
     * sklepu, patrz opis klasy).
     *
     * Opis odrzucony dla tych samych źródeł (b2b_sources_rejected, patrz rejectionKey) nie jest zlecany ponownie, gdy
     * odrzucenie było trwałe albo zdarzyło się MAX_REJECTED_ATTEMPTS razy — inaczej import pytałby przy każdym przebiegu.
     *
     * $redo — ponowny opis karty, której obecny opis napisał ten job (describedByThisJob; komenda
     * b2b:redescribe-from-datasheets po zaostrzeniu kontroli twierdzeń 22.09.2026). Karta bez takiego opisu idzie
     * zwykłą ścieżką; opis wpisany ręcznie albo przywrócony (odcisk niezgodny) nie jest nadpisywany nigdy.
     *
     * @return array{shop_text: string, sheet_text: string, sheet_url: string, document_id: int, product_description: string, description_hash: string|null, source_description_hash: string|null}|null
     */
    public static function sources(Product $product, B2bProductLink $link, ?ProductDocument $sheet, bool $datasheetOnly = false, bool $redo = false): ?array
    {
        $sheetText = $sheet !== null ? B2bDocumentText::forCard((string) $sheet->text) : '';
        $current = (string) ($product->description ?? '');
        $start = $redo && $link->source_description_hash !== null
            ? self::redoStartFor($product, $link, $sheet, $sheetText, $current, $datasheetOnly)
            : self::startFor($product, $link, $sheet, $sheetText, $current, $datasheetOnly);
        if ($start === null) {
            return null;
        }
        $rejected = self::rejectionFor($product, (int) $link->b2b_account_id, self::rejectionKey($product, $start));
        if ($rejected !== null
            && (($rejected['permanent'] ?? false) === true || self::rejectedAttempts($rejected) >= self::MAX_REJECTED_ATTEMPTS)) {
            return null;
        }

        return $start;
    }

    /**
     * Ślad odrzucenia (b2b_sources_rejected) dla tego konta i tego odcisku źródeł; null = brak albo inne źródła.
     *
     * @return array<string, mixed>|null
     */
    private static function rejectionFor(Product $product, int $b2bAccountId, string $key): ?array
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $rejected = is_array($payload['b2b_sources_rejected'] ?? null) ? $payload['b2b_sources_rejected'] : [];

        return (int) ($rejected['b2b_account_id'] ?? 0) === $b2bAccountId && ($rejected['key'] ?? null) === $key
            ? $rejected
            : null;
    }

    /**
     * Liczba odrzuceń dla tych samych źródeł. Ślad sprzed licznika (bez „attempts”, zapisywany jako trwały po każdym
     * odrzuceniu) liczy się jako jedna próba — karta dostaje kolejne.
     *
     * @param  array<string, mixed>  $rejected
     */
    private static function rejectedAttempts(array $rejected): int
    {
        return max(1, (int) ($rejected['attempts'] ?? 1));
    }

    /**
     * Odcisk wejścia modelu: tekst karty katalogowej, tekst sklepu i tabelka ze strony (w całości — przesiew zależy
     * od nazwy, a ta wchodzi przez sha1 nazwy). Nowy PDF (inny sha1), poprawiona tabelka u dostawcy albo zmiana nazwy
     * karty dają nowy odcisk i nowe zlecenie.
     *
     * @param  array{shop_text: string, sheet_text: string}  $start
     */
    private static function rejectionKey(Product $product, array $start): string
    {
        return sha1(implode("\0", [
            sha1($start['sheet_text']),
            sha1($start['shop_text']),
            sha1((string) ($product->shop_fields_summary ?? '')),
            sha1((string) $product->name),
        ]));
    }

    /**
     * @return array{shop_text: string, sheet_text: string, sheet_url: string, document_id: int, product_description: string, description_hash: string|null, source_description_hash: string|null}|null
     */
    private static function startFor(Product $product, B2bProductLink $link, ?ProductDocument $sheet, string $sheetText, string $current, bool $datasheetOnly): ?array
    {
        if ($datasheetOnly) {
            // opis już napisany z karty katalogowej (source hash) albo brak czego czytać
            if ($sheet === null || $sheetText === '' || $link->source_description_hash !== null) {
                return null;
            }
            $fromSync = $link->description_hash !== null && hash_equals($link->description_hash, sha1($current));
            // opis spoza synchronizacji (przywrócony opis AI, poprawka człowieka) nie ustępuje opisowi z PDF
            if ($product->hasDescriptionText() && ! $fromSync) {
                return null;
            }

            return [
                // tekst na karcie (slogan marki) nie jest źródłem — model dostaje tylko PDF i tabelkę ze strony
                'shop_text' => '',
                'sheet_text' => $sheetText,
                'sheet_url' => (string) $sheet->source_url,
                'document_id' => (int) $sheet->id,
                'product_description' => $current,
                'description_hash' => $link->description_hash,
                'source_description_hash' => null,
            ];
        }
        // na karcie jest tekst sklepu zapisany przez import i nietknięty od tamtej pory (opis pochodny ma source hash)
        if ($sheet === null || $sheetText === '' || trim($current) === '' || $link->source_description_hash !== null
            || $link->description_hash === null || ! hash_equals($link->description_hash, sha1($current))) {
            return null;
        }

        return [
            'shop_text' => $current,
            'sheet_text' => $sheetText,
            'sheet_url' => (string) $sheet->source_url,
            'document_id' => (int) $sheet->id,
            'product_description' => $current,
            'description_hash' => $link->description_hash,
            'source_description_hash' => $link->source_description_hash,
        ];
    }

    /**
     * Obecny opis karty napisał ten job dla tego konta: jest ślad b2b_sources z described_at, a odcisk powiązania
     * (description_hash) to sha1 obecnego opisu — nikt go od tamtej pory nie zmienił ani nie przywrócił innego.
     */
    public static function describedByThisJob(Product $product, B2bProductLink $link): bool
    {
        $current = (string) ($product->description ?? '');
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $trace = is_array($payload['b2b_sources'] ?? null) ? $payload['b2b_sources'] : [];

        return trim($current) !== ''
            && trim((string) ($trace['described_at'] ?? '')) !== ''
            && (int) ($trace['b2b_account_id'] ?? 0) === (int) $link->b2b_account_id
            && $link->source_description_hash !== null
            && $link->description_hash !== null
            && hash_equals($link->description_hash, sha1($current));
    }

    /**
     * Start ponownego opisu (sources z $redo) karty opisanej już przez ten job. Źródło sklepu to tekst zapisany
     * w śladzie b2b_sources.shop_text, o ile jego sha1 jest odciskiem źródła na powiązaniu (ARTRA: sha1('') — tekst
     * sklepu nie jest źródłem); source_description_hash zostaje w stanie startu dla compare-and-set w store().
     *
     * @return array{shop_text: string, sheet_text: string, sheet_url: string, document_id: int, product_description: string, description_hash: string|null, source_description_hash: string|null}|null
     */
    private static function redoStartFor(Product $product, B2bProductLink $link, ?ProductDocument $sheet, string $sheetText, string $current, bool $datasheetOnly): ?array
    {
        if ($sheet === null || $sheetText === '' || ! self::describedByThisJob($product, $link)) {
            return null;
        }
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $shopText = $datasheetOnly ? '' : (string) ($payload['b2b_sources']['shop_text'] ?? '');
        if (! $datasheetOnly && trim($shopText) === '') {
            return null;
        }
        if (! hash_equals((string) $link->source_description_hash, sha1($shopText))) {
            return null;
        }

        return [
            'shop_text' => $shopText,
            'sheet_text' => $sheetText,
            'sheet_url' => (string) $sheet->source_url,
            'document_id' => (int) $sheet->id,
            'product_description' => $current,
            'description_hash' => $link->description_hash,
            'source_description_hash' => $link->source_description_hash,
        ];
    }

    /**
     * Compare-and-set. Zwraca powód pominięcia albo null, gdy zapisano.
     *
     * @param  array{shop_text: string, sheet_text: string, sheet_url: string, document_id: int, product_description: string, description_hash: string|null, source_description_hash: string|null}  $start
     * @param  array{description: string, payload: array<string, mixed>, norms: string|null, packaging: string|null, dropped: list<string>, dropped_claims: list<string>}  $result
     * @param  list<string>  $shopFieldsDropped  wiersze tabelki ze strony, których model nie dostał (shopFieldsForModel)
     */
    private function store(array $start, array $result, array $shopFieldsDropped = []): ?string
    {
        return DB::transaction(function () use ($start, $result, $shopFieldsDropped): ?string {
            $product = Product::query()->lockForUpdate()->find($this->productId);
            $links = B2bProductLink::query()
                ->where('b2b_account_id', $this->b2bAccountId)
                ->where('product_id', $this->productId)
                ->lockForUpdate()
                ->get();
            if ($product === null || $links->isEmpty()) {
                return 'karta albo powiązanie usunięte';
            }
            foreach ($links as $link) {
                if ($link->description_hash !== $start['description_hash']
                    || $link->source_description_hash !== $start['source_description_hash']) {
                    return 'import zapisał nowy opis';
                }
            }
            if ((string) $product->description !== $start['product_description']) {
                return 'opis karty zmieniony';
            }

            $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
            $previousTrace = is_array($payload['b2b_sources'] ?? null) ? $payload['b2b_sources'] : [];
            $payload = [
                ...$payload,
                ...$result['payload'],
                'b2b_sources' => [
                    'b2b_account_id' => $this->b2bAccountId,
                    'document_id' => $start['document_id'],
                    'datasheet_url' => $start['sheet_url'],
                    'datasheet_sha1' => sha1($start['sheet_text']),
                    'shop_text' => $start['shop_text'],
                    'shop_sha1' => sha1($start['shop_text']),
                    'dropped_levels' => $result['dropped'],
                    'dropped_claims' => $result['dropped_claims'],
                    'shop_fields_dropped' => $shopFieldsDropped,
                    'described_at' => now()->toIso8601String(),
                ],
            ];
            unset($payload['b2b_sources_rejected']);
            if ($start['source_description_hash'] !== null) {
                // ponowny opis (redo): poprzedni opis tego joba zostaje w śladzie, a tekst zastąpiony przy pierwszym
                // opisie (slogan) i replaced_description — bez zmian; to wciąż ten sam jeden poziom historii
                $payload['b2b_sources']['previous_description'] = mb_substr($start['product_description'], 0, 10000);
                if (isset($previousTrace['replaced_text'])) {
                    $payload['b2b_sources']['replaced_text'] = $previousTrace['replaced_text'];
                }
            } elseif ($this->datasheetOnly && Product::isDescriptionText($start['product_description'])) {
                // Zastąpiony tekst (slogan zapisany przez synchronizację) zostaje przy opisie. Do
                // replaced_description tylko wtedy, gdy to miejsce jest wolne: jest jeden poziom historii,
                // a przed sloganem stał tam opis karty, którego nie da się odtworzyć ze sklepu — slogan da się.
                $payload['b2b_sources']['replaced_text'] = mb_substr($start['product_description'], 0, 10000);
                if (trim((string) ($payload['replaced_description'] ?? '')) === '') {
                    $payload['replaced_description'] = mb_substr($start['product_description'], 0, 10000);
                    $payload['replaced_description_at'] = now()->toIso8601String();
                    $payload['replaced_description_hash'] = sha1($result['description']);
                }
            }
            $product->description = $result['description'];
            $product->enrichment_payload = $payload;
            $product->enrichment_status = Product::ENRICHMENT_DONE;
            $product->enriched_at = now();
            $product->enrichment_error = null;
            if ($result['norms'] !== null && trim((string) ($product->norms ?? '')) === '') {
                $product->norms = $result['norms'];
            }
            if ($result['packaging'] !== null) {
                $product->packaging = $result['packaging'];
            }
            // haki modelu przebudują search_blob i zlecą reindeks embeddingu
            $product->save();
            foreach ($links as $link) {
                $link->description_hash = sha1($result['description']);
                $link->source_description_hash = sha1($start['shop_text']);
                $link->save();
            }

            return null;
        });
    }

    /**
     * Ślad odrzucenia dla tych samych źródeł (enrichment_payload.b2b_sources_rejected). Licznik prób rośnie tylko
     * przy tym samym odcisku źródeł (inne źródła = od nowa); sources() przestaje zlecać po MAX_REJECTED_ATTEMPTS
     * odrzuceniach albo od razu, gdy odrzucenie jest trwałe (wejście samo prowadzi do tego wyniku). Bez limitu karta
     * dostawałaby nowe zlecenie przy każdej synchronizacji; bez ponowień losowa odpowiedź modelu (ucięty JSON)
     * zostawiałaby kartę bez opisu na zawsze. Opis karty zostaje nietknięty; zapis bez haków modelu — ślad odrzucenia
     * nie zmienia niczego, co wyszukiwarka czyta, więc nie ma po co przeliczać search_blob ani reindeksować embeddingu.
     *
     * @param  array{sheet_text: string, document_id: int, sheet_url: string}  $start
     */
    private function storeRejection(string $key, array $start, string $reason, bool $permanent): void
    {
        DB::transaction(function () use ($key, $start, $reason, $permanent): void {
            $product = Product::query()->lockForUpdate()->find($this->productId);
            if ($product === null) {
                return;
            }
            $previous = self::rejectionFor($product, $this->b2bAccountId, $key);
            $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
            $payload['b2b_sources_rejected'] = [
                'b2b_account_id' => $this->b2bAccountId,
                'key' => $key,
                'attempts' => $previous !== null ? self::rejectedAttempts($previous) + 1 : 1,
                'permanent' => $permanent,
                'document_id' => $start['document_id'],
                'datasheet_url' => $start['sheet_url'],
                'datasheet_sha1' => sha1($start['sheet_text']),
                'reason' => mb_substr($reason, 0, 500),
                'rejected_at' => now()->toIso8601String(),
            ];
            $product->enrichment_payload = $payload;
            $product->saveQuietly();
        });
    }

    /**
     * Tabelka ze strony (shop_fields_summary) przesiana dla modelu — łącznik B2bDatasheetOnlyDescription. artra.pl ma
     * tabelki zamienione między wariantami modelu: „ARMEN 900 6060 O1 FO” pokazuje tabelkę wariantu S1 P („podnosek:
     * stalowy”, „norma: EN ISO 20345:2011 S1 P SRC”), a karta PDF jest poprawna. Wiersz sprzeczny z klasą z nazwy karty
     * to błąd dostawcy, nie fakt o wyrobie, więc model go nie dostaje:
     * - wiersz z klasą obuwia, z których żadna nie jest wariantem klasy karty (lista wariantów zostaje);
     * - wiersz z normą 2034x bez klasy, gdy rodzina normy (20345 = S, 20347 = O) przeczy rodzinie klasy karty;
     * - wiersz „podnosek”, gdy przeczy rodzinie: obuwie zawodowe (O) nie ma podnoska, bezpieczne (S) go ma.
     * Karta bez jednoznacznej klasy w nazwie (albo kodzie) — tabelka bez zmian.
     *
     * @return array{text: string, dropped: list<string>}
     */
    public static function shopFieldsForModel(Product $product): array
    {
        $summary = (string) ($product->shop_fields_summary ?? '');
        $cardClass = self::cardFootwearClass($product);
        if ($cardClass === null || trim($summary) === '') {
            return ['text' => $summary, 'dropped' => []];
        }
        $family = self::normalizer()->footwearClassBase((string) self::normalizer()->footwearClass($cardClass))[0];
        $kept = [];
        $dropped = [];
        foreach (preg_split('/\R/u', $summary) ?: [] as $line) {
            $records = self::classRecords($line);
            if ($records !== []) {
                $foreign = array_filter($records, static fn (string $record): bool => ! self::sameVariant($cardClass, $record));
                $drop = count($foreign) === count($records);
            } elseif (preg_match_all('/(?<!\d)2034([57])(?!\d)/u', $line, $m) > 0) {
                $families = array_unique(array_map(static fn (string $d): string => $d === '5' ? 'S' : 'O', $m[1]));
                $drop = ! in_array($family, $families, true);
            } elseif (preg_match('/^\s*podnosek\b/iu', $line) === 1) {
                // „bez ochrony palców”, „brak”, „nie”, „nie posiada”
                $none = preg_match('/\b(brak|bez|nie)\b/iu', $line) === 1;
                $drop = $family === 'O' ? ! $none : $none;
            } else {
                $drop = false;
            }
            if ($drop) {
                $dropped[] = trim($line);
            } else {
                $kept[] = $line;
            }
        }

        return ['text' => implode("\n", $kept), 'dropped' => $dropped];
    }

    /**
     * Klasy obuwia z odpowiedzi modelu (opis, klasa w atrybutach, lista norm), które nie są wariantem klasy z nazwy
     * karty — taki opis odrzucamy w całości: pisze o innym wyrobie (wariant z zamienionej tabelki albo z PDF-u serii).
     *
     * @param  array{description: string, payload: array<string, mixed>}  $result
     * @return list<string>
     */
    public static function foreignFootwearClasses(Product $product, array $result): array
    {
        $cardClass = self::cardFootwearClass($product);
        if ($cardClass === null) {
            return [];
        }
        $payload = $result['payload'];
        $attributes = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
        $texts = [is_string($attributes['klasa_ochrony'] ?? null) ? $attributes['klasa_ochrony'] : ''];
        foreach ((array) ($payload['norms'] ?? []) as $norm) {
            $texts[] = is_string($norm) ? $norm : '';
        }
        $normalizer = self::normalizer();
        $foreign = [];
        foreach ($texts as $text) {
            foreach (self::classRecords($text) as $record) {
                if (! self::sameVariant($cardClass, $record)) {
                    $foreign[(string) $normalizer->footwearClass($record)] = true;
                }
            }
        }
        // W prozie opis może objaśniać klasę: „S1 PL spełnia wymagania SB”, „S1 + wkładka PL”, „to nie jest obuwie
        // bezpieczne S1”. Obca jest tu klasa, której karta nie spełnia (O1 wobec „w klasie S1 P”, S1PL wobec S3),
        // i nie w zdaniu przeczącym — klasy niższe w hierarchii tej samej rodziny to objaśnienie, nie inny wyrób.
        $have = (string) $normalizer->footwearClass($cardClass);
        foreach (preg_split('/(?<=[.;!?])\s+|\R+/u', $result['description']) ?: [] as $sentence) {
            foreach (self::classRecords($sentence) as $record) {
                $class = (string) $normalizer->footwearClass($record);
                if ($class === '' || self::sameVariant($cardClass, $record) || $normalizer->footwearClassMeets($class, $have)) {
                    continue;
                }
                $before = mb_substr($sentence, 0, max(0, (int) mb_strpos($sentence, $record)));
                if (preg_match('/\b(nie|bez|zamiast)\b(?:\W+\w+){0,4}\W*$/iu', $before) === 1) {
                    continue;
                }
                $foreign[$class] = true;
            }
        }

        return array_keys($foreign);
    }

    /**
     * Czy obca klasa z odpowiedzi modelu pochodzi z wejścia, a nie z losowości modelu — wtedy odrzucenie jest trwałe:
     * karta PDF podaje klasę obuwia wyłącznie w zapisach innego wariantu niż karta (PDF innego wyrobu albo serii bez
     * wariantu karty), a przynajmniej jedna obca klasa z odpowiedzi stoi w tekście, który model dostał (PDF albo
     * przesiana tabelka). PDF z listą wariantów obejmującą klasę karty albo czyste wejście — odrzucenie zależne od
     * odpowiedzi modelu, ponawiane do limitu prób.
     *
     * @param  list<string>  $foreign  obce klasy z odpowiedzi modelu (foreignFootwearClasses)
     */
    private static function foreignClassComesFromSources(Product $product, array $foreign, string $sheetText, string $shopFieldsText): bool
    {
        $cardClass = self::cardFootwearClass($product);
        $sheetRecords = self::classRecords($sheetText);
        if ($cardClass === null || $foreign === [] || $sheetRecords === []) {
            return false;
        }
        foreach ($sheetRecords as $record) {
            if (self::sameVariant($cardClass, $record)) {
                return false;
            }
        }
        foreach ([...$sheetRecords, ...self::classRecords($shopFieldsText)] as $record) {
            if (self::sameVariant($cardClass, $record)) {
                continue;
            }
            foreach ($foreign as $class) {
                if (self::sameVariant($record, $class)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Zapis klasy obuwia z nazwy karty (a gdy nazwa milczy — z kodu), od tokenu klasy z oznaczeniami za nim
     * („S3 WR SRC”: WR przy S3 to w wydaniu 2022 klasa S7). null = brak klasy albo klasy o różnych bazach.
     * Tylko wielkie litery — „sb”, „s2”, „o2” trafiają się w losowych identyfikatorach.
     */
    public static function cardFootwearClass(Product $product): ?string
    {
        foreach ([(string) $product->name, (string) $product->sku] as $text) {
            $records = self::classRecords($text);
            if ($records === []) {
                continue;
            }
            $bases = array_unique(array_map(
                static fn (string $record): string => self::normalizer()->footwearClassBase((string) self::normalizer()->footwearClass($record)),
                $records,
            ));

            return count($bases) === 1 ? $records[0] : null;
        }

        return null;
    }

    /**
     * Każde wystąpienie klasy obuwia (wielkimi literami) z do trzech tokenów za nią — tam stoją oznaczenia (WR, SRC).
     * Łącznik „-”/„_” jak spacja: „S1-P” to S1P.
     *
     * @return list<string>
     */
    private static function classRecords(string $text): array
    {
        $text = str_replace(['-', '_'], ' ', $text);
        if (preg_match_all(
            '/(?<![\p{L}\d])('.BhpAttributeNormalizer::FOOTWEAR_CLASS.')(?![\p{L}\d])(?=((?:\h+[\p{L}\d]+){0,3}))/u',
            $text,
            $m,
            PREG_SET_ORDER,
        ) < 1) {
            return [];
        }

        return array_map(static fn (array $hit): string => $hit[1].$hit[2], $m);
    }

    /** Ten sam wariant: ta sama baza klasy (S1P = S1PL, S3 = S3L) albo równoważność WR z wydania 2022 (S3 WR = S7). */
    private static function sameVariant(string $cardRecord, string $record): bool
    {
        $normalizer = self::normalizer();
        $card = $normalizer->footwearClass($cardRecord);
        $other = $normalizer->footwearClass($record);

        return $card !== null && $other !== null
            && ($normalizer->footwearClassBase($card) === $normalizer->footwearClassBase($other)
                || $normalizer->sameFootwearVariantClass($cardRecord, $record));
    }

    private static function normalizer(): BhpAttributeNormalizer
    {
        return app(BhpAttributeNormalizer::class);
    }
}
