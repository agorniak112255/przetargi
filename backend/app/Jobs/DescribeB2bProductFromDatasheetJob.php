<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Services\B2b\B2bDocumentText;
use App\Services\Enrichment\B2bSourcesDescriptionRejected;
use App\Services\Enrichment\EnrichmentSlots;
use App\Services\Enrichment\ProductEnrichmentService;
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

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 90, 180];

    // Klient AI potrafi czekać ~2 min na przeciążony model; do tego do 2 min czekania na slot.
    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $productId,
        public readonly int $b2bAccountId,
    ) {
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
        $start = self::sources($product, $link, self::datasheet($this->productId, $this->b2bAccountId));
        if ($start === null) {
            return;
        }

        $slot = $slots->acquire(
            $this->timeout + 60,
            (float) config('ai.enrichment_slot_wait_seconds', 120)
        );
        if ($slot === null) {
            // Limit z Ustawień AI obłożony — karta wraca do kolejki bez zużycia próby.
            self::dispatch($this->productId, $this->b2bAccountId)->delay(now()->addSeconds(10));
            $this->delete();

            return;
        }

        try {
            $result = $enrichment->describeFromB2bSources(
                $product,
                $start['shop_text'],
                (string) ($product->shop_source_url ?? ''),
                $start['sheet_text'],
                $start['sheet_url'],
                (string) ($product->shop_fields_summary ?? ''),
            );
        } catch (B2bSourcesDescriptionRejected $e) {
            Log::warning('Opis z karty katalogowej B2B odrzucony — karta zostaje z opisem ze sklepu', [
                'product_id' => $this->productId,
                'b2b_account_id' => $this->b2bAccountId,
                'sku' => $product->sku,
                'reason' => $e->getMessage(),
            ]);

            return;
        } finally {
            $slot->release();
        }

        $skipReason = $this->store($start, $result);
        Log::info($skipReason === null
            ? 'Opis karty napisany z opisu sklepu i karty katalogowej B2B'
            : 'Opis z karty katalogowej B2B niezapisany — karta zmieniła się w trakcie', [
                'product_id' => $this->productId,
                'sku' => $product->sku,
                'reason' => $skipReason,
                'dropped_levels' => $result['dropped'],
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
     * (zaległe karty przy każdym przebiegu).
     *
     * @return array{shop_text: string, sheet_text: string, sheet_url: string, document_id: int, product_description: string, description_hash: string, source_description_hash: string|null}|null
     */
    public static function sources(Product $product, B2bProductLink $link, ?ProductDocument $sheet): ?array
    {
        $sheetText = $sheet !== null ? B2bDocumentText::forCard((string) $sheet->text) : '';
        $current = (string) ($product->description ?? '');
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
     * Compare-and-set. Zwraca powód pominięcia albo null, gdy zapisano.
     *
     * @param  array{shop_text: string, sheet_text: string, sheet_url: string, document_id: int, product_description: string, description_hash: string, source_description_hash: string|null}  $start
     * @param  array{description: string, payload: array<string, mixed>, norms: string|null, packaging: string|null, dropped: list<string>}  $result
     */
    private function store(array $start, array $result): ?string
    {
        return DB::transaction(function () use ($start, $result): ?string {
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
                    'described_at' => now()->toIso8601String(),
                ],
            ];
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
}
