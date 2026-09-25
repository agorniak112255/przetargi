<?php

declare(strict_types=1);

namespace App\Services\Vector;

use App\Models\Product;
use App\Models\ProductDocument;
use App\Services\Ai\AiSettingsService;
use App\Services\B2b\B2bDocumentText;
use App\Support\BhpAttributeNormalizer;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProductEmbeddingIndexer
{
    /** Ile znaków karty technicznej wchodzi do dokumentu wyrobu (zob. datasheetText). */
    private const DATASHEET_LIMIT = 2000;

    /** Ile wektorów kasuje jedno żądanie do Qdrant (deleteMany). */
    private const DELETE_CHUNK = 500;

    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly EmbeddingClient $embeddings,
        private readonly QdrantClient $qdrant,
        private readonly BhpAttributeNormalizer $bhpAttributes,
    ) {}

    public function shouldIndex(): bool
    {
        return $this->qdrant->isConfigured();
    }

    public function index(Product $product, bool $force = false): bool
    {
        if (! $this->shouldIndex()) {
            return false;
        }

        $text = $this->documentText($product);
        $hash = $this->documentHash($text);

        if (! $force && $product->embedding_hash === $hash && $product->embedding_synced_at !== null) {
            return false;
        }

        try {
            $vector = $this->embeddings->embed($text);
            $this->qdrant->upsert($product->id, $vector, [
                'sku' => (string) $product->sku,
                'name' => mb_substr((string) $product->name, 0, 255),
                'manufacturer' => (string) ($product->manufacturer ?? ''),
            ]);
            $product->forceFill([
                'embedding_hash' => $hash,
                'embedding_synced_at' => now(),
            ])->save();

            return true;
        } catch (Throwable $e) {
            Log::warning('Product embedding index failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function delete(int $productId): void
    {
        if (! $this->shouldIndex()) {
            return;
        }

        try {
            $this->qdrant->delete($productId);
        } catch (Throwable $e) {
            Log::warning('Product embedding delete failed', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Wektory usuniętych kart porcjami po DELETE_CHUNK. Nie rzuca: błąd porcji trafia do logu, następne porcje idą
     * dalej — sprzątanie wektorów nie może cofnąć usunięcia kart. Resztki zbiera products:prune-orphan-vectors.
     *
     * @param  list<int>  $productIds
     */
    public function deleteMany(array $productIds): void
    {
        try {
            if ($productIds === [] || ! $this->shouldIndex()) {
                return;
            }
        } catch (Throwable $e) {
            Log::warning('Product embeddings delete skipped', ['error' => $e->getMessage()]);

            return;
        }

        foreach (array_chunk(array_values(array_unique($productIds)), self::DELETE_CHUNK) as $chunk) {
            try {
                $this->qdrant->deleteMany($chunk);
            } catch (Throwable $e) {
                Log::warning('Product embeddings delete failed', [
                    'product_ids' => $chunk,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Hash obejmuje model embeddingów — po jego zmianie wektory z poprzedniego
     * modelu leżą w innej przestrzeni, więc muszą zostać policzone od nowa,
     * nawet gdy tekst karty się nie zmienił.
     */
    public function documentHash(string $text): string
    {
        return hash('sha256', $this->embeddingModelTag().'|'.$text);
    }

    private function embeddingModelTag(): string
    {
        try {
            $profile = $this->settings->embeddingProfile();

            return trim((string) ($profile['provider'] ?? '')).':'.trim((string) ($profile['model'] ?? ''));
        } catch (Throwable) {
            return '';
        }
    }

    public function documentText(Product $product): string
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $parts = [
            (string) $product->sku,
            (string) $product->name,
            (string) ($product->manufacturer ?? ''),
            (string) ($product->category ?? ''),
            (string) ($product->norms ?? ''),
            // tabelka z karty wyrobu u dostawcy — przed opisem, bo dokument jest ucinany od końca, a te dane są
            // krótkie i gęste (normy, parametry); jak coś ma wypaść z limitu, to proza opisu
            (string) ($product->shop_fields_summary ?? ''),
            (string) ($product->description ?? ''),
            // formaty/podłoża wersji (bez cen — zmiana samej ceny wersji nie zmienia dokumentu)
            (string) ($product->variant_summary ?? ''),
            // tekst karty technicznej dostawcy — na końcu i przycięty, bo jest długi i luźny; do 20.09.2026
            // wchodził tu okrężnie, bo synchronizacja doklejała go do opisu karty (B2bCatalogSync). Opis wrócił
            // do samej prozy, a wyszukiwanie ma widzieć to, co w karcie technicznej naprawdę jest
            $this->datasheetText($product),
            $this->joinList($payload['materials'] ?? null),
            $this->joinList($payload['features'] ?? null),
            $this->joinList($payload['use_cases'] ?? null),
            $this->joinList($payload['norms'] ?? null),
            $this->bhpAttributes->toSearchText($this->bhpAttributes->forProduct($product)),
        ];

        $text = implode(' | ', array_values(array_filter(
            array_map(static fn (string $s): string => trim($s), $parts),
            static fn (string $s): bool => $s !== ''
        )));

        if (mb_strlen($text) > 8000) {
            $text = mb_substr($text, 0, 8000);
        }

        return $text !== '' ? $text : ('product:'.$product->id);
    }

    /**
     * Tekst pierwszej karty technicznej przy wyrobie. Przycinamy go do DATASHEET_LIMIT: cały dokument
     * (bywa kilkanaście tysięcy znaków) wypchnąłby z ośmiotysięcznego limitu opis i parametry, a to one
     * odpowiadają na zapytanie klienta.
     */
    private function datasheetText(Product $product): string
    {
        $raw = (string) ($product->documents()
            ->whereIn('kind', [ProductDocument::KIND_DATASHEET, ProductDocument::KIND_MANUAL])
            ->whereNotNull('text')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('text') ?? '');

        // to samo czyszczenie, które przechodził tekst doklejany kiedyś do opisu: bez stopki firmowej
        // i bez cennika rozmiarów, bo one o wyrobie nic nie mówią
        return mb_substr(B2bDocumentText::forCard($raw), 0, self::DATASHEET_LIMIT);
    }

    private function joinList(mixed $value): string
    {
        if (! is_array($value)) {
            return is_string($value) ? $value : '';
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return implode(', ', $items);
    }
}
