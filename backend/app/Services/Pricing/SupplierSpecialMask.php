<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\NbpExchangeRateService;
use App\Support\ProductPriceChangeResolver;
use App\Support\SupplierSpecialPrice;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Widok cen dla użytkownika bez uprawnienia prices.supplier_special.view (decyzja właściciela 30.09.2026): karta
 * z ceną specjalną konta B2B pokazuje cenę standardową (cennik bazowy − rabat standardowy kategorii) i od niej
 * liczą się oferty. Tak samo slot cennika z pliku z oceną (SECURA: „40% s.dystryb.” = cena specjalna, „21%” = cena
 * normalna, decyzja właściciela 10.10.2026) — w slotach ma konto 0. Inne ceny zakupu bez zmian.
 *
 * Maska „odsłaniająca” (uprawnieni, CLI, marże rzeczywiste) nie robi nic i nie pyta bazy. Maska „ukrywająca”
 * czyta karty i sloty z oceną hurtem (preload: 2 zapytania na 1000 kart), a brakujące karty doczytuje sama —
 * poprawność nie zależy od tego, czy ktoś pamiętał o preload(). Nigdy nie zmienia modeli wejściowych: zwraca
 * klony z priceMasked = true, których nie da się zapisać ani skasować (strażnik w modelach).
 */
final class SupplierSpecialMask
{
    public const PERMISSION = 'prices.supplier_special.view';

    private const CHUNK = 1000;

    /** @var array<int, array{purchase: float|null, currency: string}|null> id karty => cena i waluta karty (null = brak karty) */
    private array $cards = [];

    /** @var array<int, list<array{id: int, source_key: string, account_id: int, purchase: float|null, currency: string|null, base: float|null, std: float|null, category: string|null}>> */
    private array $slots = [];

    /** @var array<int, MaskedPrice|null> */
    private array $cardMemo = [];

    /** @var array<string, MaskedPrice|null> „karta:konto” */
    private array $variantMemo = [];

    /** @var array<int, true>|null cenniki z plików z ceną specjalną (price_lists.has_supplier_special) — raz na maskę */
    private ?array $flaggedLists = null;

    /** @var array<int, bool> id karty => ślad cennika z ceną specjalną (wiersz historii pliku albo slot pliku) */
    private array $fileTrace = [];

    private ?NbpExchangeRateService $fx = null;

    private function __construct(private readonly bool $hides) {}

    /** Bez użytkownika (null) ukrywa — brak tożsamości nie może odsłonić ceny specjalnej. */
    public static function forUser(?User $user): self
    {
        return new self(! ($user !== null && $user->can(self::PERMISSION)));
    }

    /** Prawda systemowa: CLI, marże rzeczywiste, użytkownicy z uprawnieniem. */
    public static function revealing(): self
    {
        return new self(false);
    }

    /** Widok standardowy niezależnie od użytkownika (np. marża bliźniacza przetargu). */
    public static function hiding(): self
    {
        return new self(true);
    }

    public function hides(): bool
    {
        return $this->hides;
    }

    /**
     * Wczytuje hurtem karty i ich sloty z oceną — dwa zapytania na 1000 kart; maska odsłaniająca nic nie czyta.
     *
     * @param  iterable<int|Product>  $products
     */
    public function preload(iterable $products): void
    {
        if (! $this->hides) {
            return;
        }
        $ids = [];
        foreach ($products as $product) {
            $id = $product instanceof Product ? (int) $product->getKey() : (int) $product;
            if ($id > 0 && ! array_key_exists($id, $this->cards)) {
                $ids[$id] = $id;
            }
        }
        foreach (array_chunk(array_values($ids), self::CHUNK) as $chunk) {
            $this->load($chunk);
        }
    }

    /**
     * Cena specjalna widoczna na karcie — PHP-owy bliźniak SupplierSpecialPrice::whereCardStatus($q, 'special'):
     * slot B2B albo pliku z oceną, którego cena zakupu (do grosza) i waluta (slot bez waluty = waluta karty) są ceną
     * karty, a ocena to „special”. Kilka takich slotów → najwyższa cena standardowa (bezpieczniej ukryć więcej).
     */
    public function card(int $productId): ?MaskedPrice
    {
        // karta niezapisana (bez id) nie ma slotów w bazie
        if (! $this->hides || $productId <= 0) {
            return null;
        }
        if (array_key_exists($productId, $this->cardMemo)) {
            return $this->cardMemo[$productId];
        }
        $this->ensureLoaded($productId);
        $card = $this->cards[$productId] ?? null;
        $best = null;
        if ($card !== null && $card['purchase'] !== null) {
            $price = round($card['purchase'], 2);
            foreach ($this->slots[$productId] ?? [] as $slot) {
                if ($slot['purchase'] === null) {
                    continue;
                }
                $slotCurrency = $slot['currency'] !== null ? self::code($slot['currency']) : $card['currency'];
                if ($slotCurrency !== $card['currency'] || round($slot['purchase'], 2) !== $price) {
                    continue;
                }
                $masked = $this->special($slot, $card['currency']);
                if ($masked !== null && ($best === null || $masked->standardPrice > $best->standardPrice)) {
                    $best = $masked;
                }
            }
        }

        return $this->cardMemo[$productId] = $best;
    }

    /** Slot z oceną „special” (SupplierSpecialPrice::forSlot) — niezależnie od tego, czy jego cena jest ceną karty. */
    public function slot(ProductSourcePrice $slot): ?MaskedPrice
    {
        if (! $this->hides || ! $slot->carriesSupplierSpecial()) {
            return null;
        }
        $raw = $slot->getAttributes();
        $category = $slot->base_price_category !== null ? (string) $slot->base_price_category : null;
        if (array_key_exists('base_price_net', $raw) && array_key_exists('standard_discount_percent', $raw)) {
            $evaluation = SupplierSpecialPrice::forSlot($slot);
        } else {
            // slot wczytany bez kolumn oceny — cennik bazowy i rabat z bazy, żeby wybór kolumn przez wołającego
            // nie odsłonił ceny specjalnej
            $stored = $this->storedSlot($slot);
            $category = $stored['category'] ?? null;
            $evaluation = $stored === null ? null : SupplierSpecialPrice::evaluate(
                $slot->purchase_price !== null ? (float) $slot->purchase_price : null,
                $stored['base'],
                $stored['std'],
            );
        }
        if ($evaluation === null || $evaluation['status'] !== SupplierSpecialPrice::SPECIAL) {
            return null;
        }
        $currency = self::code($slot->currency);
        if ($currency === '' && $slot->product_id !== null) {
            // slot bez waluty ma walutę karty (ProductEffectivePrice::resolve)
            $this->ensureLoaded((int) $slot->product_id);
            $currency = $this->cards[(int) $slot->product_id]['currency'] ?? '';
        }

        return $this->build(
            (string) $slot->source_key,
            self::accountIdOf($slot->b2b_account_id, (string) $slot->source_key),
            $currency,
            (float) $slot->purchase_price,
            $evaluation,
            $category,
        );
    }

    /**
     * Rozmiar (wariant) konta, którego slot na tej karcie ma cenę specjalną; null = cena rozmiaru bez zmian. Rozmiary
     * mają tylko konta B2B — konto 0 (slot pliku) nigdy nie pasuje.
     */
    public function variant(int $productId, ?int $b2bAccountId): ?MaskedPrice
    {
        if (! $this->hides || $b2bAccountId === null || $b2bAccountId <= 0) {
            return null;
        }
        $key = $productId.':'.$b2bAccountId;
        if (array_key_exists($key, $this->variantMemo)) {
            return $this->variantMemo[$key];
        }
        $this->ensureLoaded($productId);
        $cardCurrency = $this->cards[$productId]['currency'] ?? '';
        $found = null;
        foreach ($this->slots[$productId] ?? [] as $slot) {
            if ($slot['account_id'] === $b2bAccountId) {
                $found = $this->special($slot, $cardCurrency);
                if ($found !== null) {
                    break;
                }
            }
        }

        return $this->variantMemo[$key] = $found;
    }

    /**
     * Historia cen konta ukryta (decyzja D1): karta ma slot tego konta z oceną (cena bazowa i rabat standardowy) —
     * także gdy dziś cena jest standardowa, bo dawniejsze wiersze mogły być ceną specjalną. Konto null = dowolny
     * slot konta z oceną na karcie — slot pliku nie, bo wiersz „b2b…” bez przebiegu to cena konta (plik:
     * hidesFileHistory).
     */
    public function hidesHistory(int $productId, ?int $b2bAccountId): bool
    {
        if (! $this->hides || ($b2bAccountId !== null && $b2bAccountId <= 0)) {
            return false;
        }
        $this->ensureLoaded($productId);
        foreach ($this->slots[$productId] ?? [] as $slot) {
            if ($slot['source_key'] === ProductSourcePrice::SOURCE_FILE) {
                continue;
            }
            if ($b2bAccountId === null || $slot['account_id'] === $b2bAccountId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Historia cen z pliku ukryta (import i rabat cennika): karta ma slot pliku z oceną — ta sama reguła co
     * hidesHistory dla konta, także gdy dziś cena jest standardowa — albo ślad cennika z ceną specjalną
     * (price_lists.has_supplier_special): wiersz historii pliku z tego cennika lub slot pliku z jego id. Ślad zostaje,
     * gdy slot przejął inny cennik albo ocena zniknęła — dawne wiersze dalej mogą być ceną 40%.
     */
    public function hidesFileHistory(int $productId): bool
    {
        if (! $this->hides) {
            return false;
        }
        $this->ensureLoaded($productId);
        foreach ($this->slots[$productId] ?? [] as $slot) {
            if ($slot['source_key'] === ProductSourcePrice::SOURCE_FILE) {
                return true;
            }
        }

        return $this->hasFlaggedTrace($productId);
    }

    /** Cennik z pliku z ceną specjalną (price_lists.has_supplier_special) — jedno zapytanie na maskę. */
    public function flaggedPriceList(?int $priceListId): bool
    {
        return $this->hides && $priceListId !== null && isset($this->flaggedLists()[$priceListId]);
    }

    /** Karta ma dowolny slot z oceną (konto albo plik) — zapisane dawniej ceny zakupu mogły być ceną specjalną. */
    public function hidesAnyHistory(int $productId): bool
    {
        return $this->hidesHistory($productId, null) || $this->hidesFileHistory($productId);
    }

    /** Karta w widoku standardowym; bez ceny specjalnej ta sama instancja. */
    public function maskProduct(Product $product): Product
    {
        $masked = $this->card((int) $product->getKey());
        if ($masked === null) {
            return $product;
        }
        $raw = $product->getAttributes();

        return $this->cloneWith($product, $this->onlyPresent($raw, $masked->fields(
            $raw['catalog_price_net'] ?? null,
            $raw['purchase_price'] ?? null,
            $raw['discount_percent'] ?? null,
        )));
    }

    /** Slot w widoku standardowym (z najwyższą ceną rozmiaru przeskalowaną); bez ceny specjalnej ta sama instancja. */
    public function maskSlot(ProductSourcePrice $slot): ProductSourcePrice
    {
        $masked = $this->slot($slot);
        if ($masked === null) {
            return $slot;
        }
        $raw = $slot->getAttributes();
        $values = $masked->fields($raw['catalog_price_net'] ?? null, $raw['purchase_price'] ?? null, $raw['discount_percent'] ?? null);
        $values['size_price_max'] = $masked->scale($raw['size_price_max'] ?? null);
        $values['carton_price_net'] = $masked->scale($raw['carton_price_net'] ?? null);

        return $this->cloneWith($slot, $this->onlyPresent($raw, $values));
    }

    /** Rozmiar w widoku standardowym: cena konta przeskalowana, cena katalogowa rozmiaru bez zmian. */
    public function maskVariant(ProductVariant $variant): ProductVariant
    {
        $masked = $this->variant((int) $variant->product_id, $variant->b2b_account_id !== null ? (int) $variant->b2b_account_id : null);
        if ($masked === null) {
            return $variant;
        }
        $raw = $variant->getAttributes();

        return $this->cloneWith($variant, $this->onlyPresent($raw, [
            'purchase_price' => $masked->scale($raw['purchase_price'] ?? null),
            'carton_price_net' => $masked->scale($raw['carton_price_net'] ?? null),
        ]));
    }

    /**
     * Wiersz karty (tablica z „id”) w widoku standardowym — podmienia tylko klucze, które wiersz ma.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function productRow(array $row): array
    {
        $masked = isset($row['id']) ? $this->card((int) $row['id']) : null;
        if ($masked === null) {
            return $row;
        }
        $fields = $masked->fields($row['catalog_price_net'] ?? null, $row['purchase_price'] ?? null, $row['discount_percent'] ?? null);
        foreach ($fields as $key => $value) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $value;
            }
        }
        $currency = self::code($row['currency'] ?? null);
        $currency = $currency !== '' ? $currency : $masked->currency;
        // ten sam wzór co NbpExchangeRateService::appendPricePln
        if (array_key_exists('price_pln', $row)) {
            $row['price_pln'] = $this->fx()->toPln((float) $fields['catalog_price_net'], $currency);
        }
        if (array_key_exists('purchase_price_pln', $row)) {
            $row['purchase_price_pln'] = $this->fx()->toPlnOrNull($fields['purchase_price'], $currency);
        }
        if (is_array($row['supplier_special'] ?? null) && ($row['supplier_special']['status'] ?? null) === SupplierSpecialPrice::SPECIAL) {
            $source = array_key_exists('source', $row['supplier_special'])
                ? ['source' => $masked->sourceKey === ProductSourcePrice::SOURCE_FILE ? 'file' : 'b2b']
                : [];
            $row['supplier_special'] = [...$masked->evaluation, ...$source];
        }

        return $row;
    }

    /**
     * Wiersz rozmiaru (tablica z product_id i b2b_account_id) w widoku standardowym.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function variantRow(array $row): array
    {
        $accountId = isset($row['b2b_account_id']) ? (int) $row['b2b_account_id'] : null;
        $masked = isset($row['product_id']) ? $this->variant((int) $row['product_id'], $accountId) : null;
        if ($masked === null) {
            return $row;
        }
        $scaled = $masked->scale($row['purchase_price'] ?? null);
        if (array_key_exists('purchase_price', $row)) {
            $row['purchase_price'] = $scaled;
        }
        if (array_key_exists('purchase_price_pln', $row)) {
            $currency = self::code($row['currency'] ?? null);
            $row['purchase_price_pln'] = $this->fx()->toPlnOrNull($scaled, $currency !== '' ? $currency : $masked->currency);
        }

        return $row;
    }

    public function purchasePln(Product $product): ?float
    {
        return $this->fx()->purchasePln($this->maskProduct($product));
    }

    /**
     * @param  array{id: int, source_key: string, account_id: int, purchase: float|null, currency: string|null, base: float|null, std: float|null, category: string|null}  $slot
     */
    private function special(array $slot, string $cardCurrency): ?MaskedPrice
    {
        $evaluation = SupplierSpecialPrice::evaluate($slot['purchase'], $slot['base'], $slot['std']);
        if ($evaluation === null || $evaluation['status'] !== SupplierSpecialPrice::SPECIAL) {
            return null;
        }
        $currency = $slot['currency'] !== null ? self::code($slot['currency']) : '';

        return $this->build(
            $slot['source_key'],
            $slot['account_id'],
            $currency !== '' ? $currency : $cardCurrency,
            (float) $slot['purchase'],
            $evaluation,
            $slot['category'],
        );
    }

    /**
     * @param  array{status: string, standard_price: float, actual_discount_percent: float, saving_net: float, base_price: float, standard_discount_percent: float}  $evaluation  ocena prawdziwej ceny (special)
     */
    private function build(string $sourceKey, int $accountId, string $currency, float $realPurchase, array $evaluation, ?string $category): MaskedPrice
    {
        $standard = (float) $evaluation['standard_price'];
        // ocena ceny standardowej: status „standard”, oszczędność 0, rabat faktyczny = standardowy
        $standardEvaluation = SupplierSpecialPrice::evaluate($standard, $evaluation['base_price'], $evaluation['standard_discount_percent']) ?? $evaluation;

        return new MaskedPrice(
            $sourceKey,
            $accountId,
            $currency,
            $realPurchase,
            $standard,
            $standard / $realPurchase,
            [...$standardEvaluation, 'category' => $category],
        );
    }

    /**
     * Slot z oceną zapisany w bazie dla tej samej karty i źródła (wczytany hurtem z kartą).
     *
     * @return array{id: int, source_key: string, account_id: int, purchase: float|null, currency: string|null, base: float|null, std: float|null, category: string|null}|null
     */
    private function storedSlot(ProductSourcePrice $slot): ?array
    {
        if ($slot->product_id === null) {
            return null;
        }
        $productId = (int) $slot->product_id;
        $this->ensureLoaded($productId);
        foreach ($this->slots[$productId] ?? [] as $stored) {
            if ($stored['source_key'] === (string) $slot->source_key) {
                return $stored;
            }
        }

        return null;
    }

    /**
     * Ślad cennika z ceną specjalną doczytywany hurtem: za pierwszym razem dla wszystkich kart już wczytanych przez
     * maskę (preload), jedno zapytanie na 1000 kart; bez flagowanych cenników — bez zapytania.
     */
    private function hasFlaggedTrace(int $productId): bool
    {
        if (! array_key_exists($productId, $this->fileTrace)) {
            $pending = [$productId => $productId];
            foreach (array_keys($this->cards) as $id) {
                if (! array_key_exists($id, $this->fileTrace)) {
                    $pending[$id] = $id;
                }
            }
            $this->loadFileTraces(array_values($pending));
        }

        return $this->fileTrace[$productId];
    }

    /**
     * @param  list<int>  $ids
     */
    private function loadFileTraces(array $ids): void
    {
        foreach ($ids as $id) {
            $this->fileTrace[$id] = false;
        }
        $lists = array_keys($this->flaggedLists());
        if ($lists === []) {
            return;
        }
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $history = DB::table('product_price_history')
                ->select('product_id')
                ->whereIn('product_id', $chunk)
                ->whereIn('source', ProductPriceChangeResolver::FILE_SOURCES)
                ->whereIn('price_list_id', $lists);
            $found = DB::table('product_source_prices')
                ->select('product_id')
                ->whereIn('product_id', $chunk)
                ->where('source_key', ProductSourcePrice::SOURCE_FILE)
                ->whereIn('price_list_id', $lists)
                ->union($history)
                ->pluck('product_id');
            foreach ($found as $id) {
                $this->fileTrace[(int) $id] = true;
            }
        }
    }

    /**
     * @return array<int, true>
     */
    private function flaggedLists(): array
    {
        if ($this->flaggedLists === null) {
            $this->flaggedLists = [];
            foreach (PriceList::query()->where('has_supplier_special', true)->pluck('id') as $id) {
                $this->flaggedLists[(int) $id] = true;
            }
        }

        return $this->flaggedLists;
    }

    private function ensureLoaded(int $productId): void
    {
        if (! array_key_exists($productId, $this->cards)) {
            $this->load([$productId]);
        }
    }

    /**
     * @param  list<int>  $ids
     */
    private function load(array $ids): void
    {
        foreach ($ids as $id) {
            $this->cards[$id] = null;
            $this->slots[$id] = [];
        }
        $cards = Product::query()->whereIn('id', $ids)->toBase()->get(['id', 'purchase_price', 'currency']);
        foreach ($cards as $card) {
            $this->cards[(int) $card->id] = [
                'purchase' => self::float($card->purchase_price),
                'currency' => self::code($card->currency),
            ];
        }
        // te same sloty co ProductController::evaluableSlotsByProduct — konta i plik z ceną bazową i rabatem
        $slots = ProductSourcePrice::query()
            ->whereIn('product_id', $ids)
            ->tap(static fn ($q) => SupplierSpecialPrice::whereEvaluableSource($q))
            ->whereNotNull('base_price_net')
            ->whereNotNull('standard_discount_percent')
            ->orderBy('id')
            ->toBase()
            ->get(['id', 'product_id', 'source_key', 'b2b_account_id', 'purchase_price', 'currency', 'base_price_net', 'base_price_category', 'standard_discount_percent']);
        foreach ($slots as $slot) {
            $this->slots[(int) $slot->product_id][] = [
                'id' => (int) $slot->id,
                'source_key' => (string) $slot->source_key,
                'account_id' => self::accountIdOf($slot->b2b_account_id, (string) $slot->source_key),
                'purchase' => self::float($slot->purchase_price),
                'currency' => $slot->currency !== null ? (string) $slot->currency : null,
                'base' => self::float($slot->base_price_net),
                'std' => self::float($slot->standard_discount_percent),
                'category' => $slot->base_price_category !== null ? (string) $slot->base_price_category : null,
            ];
        }
    }

    /**
     * Klon z podmienionymi cenami. Tablice atrybutów kopiują się przy klonowaniu, relacje (obiekty) klonujemy
     * o poziom niżej, żeby zmiana na klonie nie dotknęła oryginału; oryginał zostaje nietknięty (bez „dirty”).
     *
     * @template T of Model
     *
     * @param  T  $model
     * @param  array<string, mixed>  $values
     * @return T
     */
    private function cloneWith(Model $model, array $values): Model
    {
        $clone = clone $model;
        $relations = [];
        foreach ($model->getRelations() as $name => $related) {
            $relations[$name] = match (true) {
                $related instanceof EloquentCollection => new ($related::class)(array_map(
                    static fn (mixed $item): mixed => $item instanceof Model ? clone $item : $item,
                    $related->all(),
                )),
                $related instanceof Model => clone $related,
                default => $related,
            };
        }
        $clone->setRelations($relations);
        $clone->setRawAttributes([...$clone->getAttributes(), ...$values]);
        $clone->priceMasked = true;

        return $clone;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function onlyPresent(array $raw, array $values): array
    {
        return array_filter($values, static fn (string $key): bool => array_key_exists($key, $raw), ARRAY_FILTER_USE_KEY);
    }

    private function fx(): NbpExchangeRateService
    {
        return $this->fx ??= app(NbpExchangeRateService::class);
    }

    /** Konto slotu; slot pliku = 0 (nie jest kontem — variant() i hidesHistory() go nie dopasują). */
    private static function accountIdOf(mixed $accountId, string $sourceKey): int
    {
        if ($sourceKey === ProductSourcePrice::SOURCE_FILE) {
            return 0;
        }

        return $accountId !== null ? (int) $accountId : (int) substr($sourceKey, 4);
    }

    private static function code(mixed $currency): string
    {
        return strtoupper(trim((string) $currency));
    }

    private static function float(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
