<?php

declare(strict_types=1);

namespace App\Services\Substitutes;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSubstitute;
use App\Services\NbpExchangeRateService;
use App\Services\Pricing\SupplierSpecialMask;
use App\Support\PpeAssortment;
use Illuminate\Support\Collection;

/**
 * Widok zamienników dla ekranu „Zamienniki” i karty produktu: skrót karty (CardLite), wiersz pary (SubLite),
 * porównanie cen i skrót dowodów automatu. Ceny w widoku widza (SupplierSpecialMask) i w PLN (kurs NBP).
 *
 * Karty rejestruje się hurtem (register/load) — maska, miniatury i ceny liczone raz na kartę, bez zapytań na wiersz.
 */
final class SubstituteBoardPresenter
{
    /** Etykiety rodzin ŚOI (products.ppe_family); karta bez rodziny to „Inne”. */
    public const FAMILY_LABELS = [
        PpeAssortment::FAMILY_GLOVES => 'Rękawice',
        PpeAssortment::FAMILY_FOOTWEAR => 'Obuwie',
        PpeAssortment::FAMILY_RESPIRATORY => 'Drogi oddechowe',
        PpeAssortment::FAMILY_HEARING => 'Ochrona słuchu',
        PpeAssortment::FAMILY_EYES => 'Ochrona oczu',
        PpeAssortment::FAMILY_HEAD => 'Ochrona głowy',
        PpeAssortment::FAMILY_FACE => 'Ochrona twarzy',
        PpeAssortment::FAMILY_APPAREL => 'Odzież',
        PpeAssortment::FAMILY_FALL => 'Praca na wysokości',
        PpeAssortment::FAMILY_KNEE => 'Nakolanniki',
    ];

    public const OTHER_FAMILY_LABEL = 'Inne';

    /** Kolumny kart potrzebne do skrótu, maski cen i opisu — bez ciężkich pól (payload, blob wyszukiwania). */
    public const CARD_COLUMNS = [
        'id', 'sku', 'name', 'manufacturer', 'ppe_family', 'catalog_price_net', 'purchase_price', 'discount_percent',
        'currency', 'description', 'enrichment_status',
    ];

    public const NOTE_UNITS = 'Różne lub nieznane jednostki sprzedaży — porównaj ceny ręcznie.';

    public const NOTE_NO_PRICE = 'Brak ceny.';

    private const STATUS_ORDER = ['zatwierdzony' => 0, 'oczekuje' => 1, 'odrzucony' => 2];

    private const TYPE_ORDER = ['preferowany' => 0, 'premium' => 1, 'tanszy' => 2, 'awaryjny' => 3];

    /** Iloraz cen poza tym przedziałem to zwykle inna jednostka sprzedaży (para vs karton), nie różnica ceny. */
    private const RATIO_MIN = 0.4;

    private const RATIO_MAX = 2.5;

    /**
     * Ilość w opakowaniu z nazwy karty: „op. 12 par”, „100 szt”, „200 par”, „pack of 12”. Pojedyncza sztuka/para
     * („1 para”) nie jest opakowaniem zbiorczym.
     */
    private const PACK_PATTERN = '/\bpack\s+of\s+(\d+)\b|(?<![\d,\/])(?<!\d\.)(\d+)\s*(?:x\s*)?(szt(?:uk[ia]?)?|pcs|par[ay]?|pairs?)(?![\p{L}\d])/iu';

    private const CHIPS_MAX = 4;

    /** @var array<int, Product> */
    private array $products = [];

    /** @var array<int, string|null> */
    private array $thumbs = [];

    /** @var array<int, float|null> */
    private array $pricePln = [];

    private readonly NbpExchangeRateService $fx;

    private readonly PpeAssortment $assortment;

    public function __construct(
        private readonly SupplierSpecialMask $mask,
        ?NbpExchangeRateService $fx = null,
        ?PpeAssortment $assortment = null,
    ) {
        $this->fx = $fx ?? app(NbpExchangeRateService::class);
        $this->assortment = $assortment ?? new PpeAssortment;
    }

    public static function familyLabel(?string $family): string
    {
        return $family !== null && $family !== ''
            ? (self::FAMILY_LABELS[$family] ?? $family)
            : self::OTHER_FAMILY_LABEL;
    }

    /**
     * Wczytuje karty po id (jedno zapytanie) i rejestruje je hurtem.
     *
     * @param  iterable<int>  $ids
     */
    public function load(iterable $ids): void
    {
        $missing = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && ! isset($this->products[$id])) {
                $missing[$id] = $id;
            }
        }
        if ($missing === []) {
            return;
        }
        $this->register(Product::query()->whereIn('id', array_values($missing))->get(self::CARD_COLUMNS));
    }

    /**
     * Rejestruje wczytane już karty: maska cen hurtem (preload) i zdjęcia główne jednym zapytaniem.
     *
     * @param  iterable<Product>  $products
     */
    public function register(iterable $products): void
    {
        $new = [];
        foreach ($products as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            $id = (int) $product->id;
            if ($id > 0 && ! isset($this->products[$id])) {
                $this->products[$id] = $product;
                $new[] = $id;
            }
        }
        if ($new === []) {
            return;
        }
        $this->mask->preload($new);
        $images = ProductImage::primaryFor($new);
        foreach ($new as $id) {
            $this->thumbs[$id] = isset($images[$id]) ? $images[$id]->thumbUrl() : null;
        }
    }

    public function product(int $id): ?Product
    {
        return $this->products[$id] ?? null;
    }

    /**
     * CardLite: skrót karty z ceną katalogową w PLN w widoku widza (null — karta bez ceny).
     *
     * @return array{id: int, sku: string, name: string, manufacturer: string|null, family: string|null, family_label: string, thumb_url: string|null, price_pln: float|null, currency: string, has_description: bool}|null
     */
    public function card(int $id): ?array
    {
        $product = $this->products[$id] ?? null;
        if ($product === null) {
            return null;
        }
        $family = $this->family($product);

        return [
            'id' => $id,
            'sku' => (string) $product->sku,
            'name' => (string) $product->name,
            'manufacturer' => $product->manufacturer !== null && $product->manufacturer !== '' ? (string) $product->manufacturer : null,
            'family' => $family,
            'family_label' => self::familyLabel($family),
            'thumb_url' => $this->thumbs[$id] ?? null,
            'price_pln' => $this->pricePln($id),
            'currency' => strtoupper(trim((string) ($product->currency ?? ''))) ?: 'PLN',
            'has_description' => $product->hasUsableDescription(),
        ];
    }

    /** Cena katalogowa karty w PLN w widoku widza (maska cen specjalnych, potem kurs NBP). */
    public function pricePln(int $id): ?float
    {
        if (array_key_exists($id, $this->pricePln)) {
            return $this->pricePln[$id];
        }
        $product = $this->products[$id] ?? null;

        return $this->pricePln[$id] = $product === null
            ? null
            : $this->fx->catalogPln($this->mask->maskProduct($product));
    }

    /**
     * SubLite: wiersz pary dla ekranu zamienników (karta zamiennika jako CardLite).
     *
     * @return array<string, mixed>
     */
    public function row(ProductSubstitute $row): array
    {
        $approver = $row->relationLoaded('approver') ? $row->getRelation('approver') : null;

        return [
            'id' => (int) $row->id,
            'type' => $row->type,
            'approval_status' => $row->approval_status,
            'source' => $row->source ?? ProductSubstitute::SOURCE_MANUAL,
            'reason' => $row->reason,
            'decision_note' => $row->decision_note,
            'approver' => $approver !== null ? ['id' => (int) $approver->id, 'name' => (string) $approver->name] : null,
            'generated_at' => $row->generated_at?->toIso8601String(),
            'stale' => $this->isStale($row),
            'product' => $this->card((int) $row->substitute_product_id),
            'price' => $this->price((int) $row->main_product_id, (int) $row->substitute_product_id),
            'summary' => $this->summary($row->evidence),
        ];
    }

    /**
     * Porównanie cen pary w widoku widza. Różnica w % tylko tam, gdzie jednostka sprzedaży jest ta sama: obuwie,
     * rękawice wielorazowe i nauszniki, bez ilości opakowania w nazwie (albo z tą samą) i z ilorazem w 0,4–2,5.
     *
     * @return array{main_pln: float|null, sub_pln: float|null, diff_percent: float|null, comparable: bool, note: string|null}
     */
    public function price(int $mainId, int $subId): array
    {
        $mainPln = $this->pricePln($mainId);
        $subPln = $this->pricePln($subId);
        if ($mainPln === null || $subPln === null) {
            return ['main_pln' => $mainPln, 'sub_pln' => $subPln, 'diff_percent' => null, 'comparable' => false, 'note' => self::NOTE_NO_PRICE];
        }
        $main = $this->products[$mainId];
        $sub = $this->products[$subId];
        $ratio = $subPln / $mainPln;
        $comparable = $ratio >= self::RATIO_MIN && $ratio <= self::RATIO_MAX
            && $this->sameSalesUnit($main, $sub);

        return [
            'main_pln' => $mainPln,
            'sub_pln' => $subPln,
            'diff_percent' => $comparable ? round(($subPln - $mainPln) / $mainPln * 100, 1) : null,
            'comparable' => $comparable,
            'note' => $comparable ? null : self::NOTE_UNITS,
        ];
    }

    /**
     * Skrót dowodów automatu: ile parametrów porównano, ile równych i ile wyżej u zamiennika.
     *
     * @return array{params: int, equal: int, higher: int}
     */
    public function summary(mixed $evidence): array
    {
        $params = $this->params($evidence);
        $equal = 0;
        $higher = 0;
        foreach ($params as $param) {
            $relation = $param['relation'] ?? null;
            if ($relation === 'equal') {
                $equal++;
            } elseif ($relation === 'higher') {
                $higher++;
            }
        }

        return ['params' => count($params), 'equal' => $equal, 'higher' => $higher];
    }

    /**
     * Parametry karty głównej do nagłówka grupy — z pierwszego wiersza z dowodami (bez rodzaju wyrobu), najwyżej 4.
     *
     * @param  iterable<ProductSubstitute>  $rows  wiersze grupy w kolejności wyświetlania
     * @return list<string>
     */
    public function chips(iterable $rows): array
    {
        foreach ($rows as $row) {
            $params = $this->params($row->evidence);
            if ($params === []) {
                continue;
            }
            $chips = [];
            foreach ($params as $param) {
                if (($param['key'] ?? null) === 'article_type') {
                    continue;
                }
                $text = is_array($param['main'] ?? null) ? trim((string) ($param['main']['text'] ?? '')) : '';
                if ($text !== '' && ! in_array($text, $chips, true)) {
                    $chips[] = $text;
                }
                if (count($chips) >= self::CHIPS_MAX) {
                    break;
                }
            }

            return $chips;
        }

        return [];
    }

    /**
     * Kolejność wierszy w grupie: zatwierdzony, oczekuje, odrzucony; w statusie preferowany, premium, reszta;
     * potem cena zamiennika w PLN (bez ceny na końcu), na końcu id.
     *
     * @param  Collection<int, ProductSubstitute>  $rows
     * @return Collection<int, ProductSubstitute>
     */
    public function sortRows(Collection $rows): Collection
    {
        return $rows->sort(function (ProductSubstitute $a, ProductSubstitute $b): int {
            $priceA = $this->pricePln((int) $a->substitute_product_id);
            $priceB = $this->pricePln((int) $b->substitute_product_id);

            return [
                self::STATUS_ORDER[$a->approval_status] ?? 9,
                self::TYPE_ORDER[$a->type] ?? 9,
                $priceA === null ? 1 : 0,
                $priceA ?? 0.0,
                (int) $a->id,
            ] <=> [
                self::STATUS_ORDER[$b->approval_status] ?? 9,
                self::TYPE_ORDER[$b->type] ?? 9,
                $priceB === null ? 1 : 0,
                $priceB ?? 0.0,
                (int) $b->id,
            ];
        })->values();
    }

    public function isStale(ProductSubstitute $row): bool
    {
        $evidence = $row->evidence;

        return is_array($evidence) && ($evidence['stale'] ?? null) !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function params(mixed $evidence): array
    {
        if (! is_array($evidence) || ! is_array($evidence['params'] ?? null)) {
            return [];
        }

        return array_values(array_filter($evidence['params'], 'is_array'));
    }

    private function family(Product $product): ?string
    {
        $family = $product->ppe_family;

        return $family !== null && $family !== '' ? (string) $family : null;
    }

    /**
     * Ta sama jednostka sprzedaży: rodzina karty głównej sprzedawana na parę/sztukę (obuwie, rękawice poza
     * jednorazowymi, nauszniki), zamiennik z tej samej rodziny (albo bez rodziny) i tego samego rodzaju,
     * a ilość opakowania z nazwy — brak w obu albo ta sama.
     */
    private function sameSalesUnit(Product $main, Product $sub): bool
    {
        $family = $this->family($main);
        if ($family === null || ! $this->soldPerUnit($main, $family)) {
            return false;
        }
        $subFamily = $this->family($sub);
        if ($subFamily !== null && $subFamily !== $family) {
            return false;
        }
        if (! $this->soldPerUnit($sub, $family)) {
            return false;
        }

        return $this->packQuantity((string) $main->name) === $this->packQuantity((string) $sub->name);
    }

    private function soldPerUnit(Product $product, string $family): bool
    {
        return match ($family) {
            PpeAssortment::FAMILY_FOOTWEAR => true,
            PpeAssortment::FAMILY_GLOVES => $this->assortment->articleType((string) $product->name, $family) !== 'disposable',
            PpeAssortment::FAMILY_HEARING => $this->assortment->articleType((string) $product->name, $family) === 'earmuff',
            default => false,
        };
    }

    /** Ilość opakowania z nazwy jako „12:par” / „100:szt”; null — nazwa jej nie podaje (albo podaje 1). */
    private function packQuantity(string $name): ?string
    {
        if (preg_match_all(self::PACK_PATTERN, $name, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }
        foreach ($matches as $m) {
            if (($m[1] ?? '') !== '') {
                $count = (int) $m[1];
                $unit = 'szt';
            } else {
                $count = (int) ($m[2] ?? 0);
                $unit = str_starts_with(mb_strtolower((string) ($m[3] ?? '')), 'p') && ! str_starts_with(mb_strtolower((string) $m[3]), 'pc')
                    ? 'par'
                    : 'szt';
            }
            if ($count >= 2) {
                return $count.':'.$unit;
            }
        }

        return null;
    }
}
