<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\NbpExchangeRateService;
use App\Support\SupplierSpecialPrice;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

/**
 * Widok cen dla użytkownika bez uprawnienia prices.supplier_special.view (decyzja właściciela 30.09.2026): karta
 * z ceną specjalną konta B2B pokazuje cenę standardową (cennik bazowy − rabat standardowy kategorii) i od niej
 * liczą się oferty. Inne ceny zakupu bez zmian.
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
     * slot B2B z oceną, którego cena zakupu (do grosza) i waluta (slot bez waluty = waluta karty) są ceną karty,
     * a ocena to „special”. Kilka takich slotów → najwyższa cena standardowa (bezpieczniej ukryć więcej).
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
        if (! $this->hides || ! $slot->isB2b()) {
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

    /** Rozmiar (wariant) konta, którego slot na tej karcie ma cenę specjalną; null = cena rozmiaru bez zmian. */
    public function variant(int $productId, ?int $b2bAccountId): ?MaskedPrice
    {
        if (! $this->hides || $b2bAccountId === null) {
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
     * slot z oceną na karcie.
     */
    public function hidesHistory(int $productId, ?int $b2bAccountId): bool
    {
        if (! $this->hides) {
            return false;
        }
        $this->ensureLoaded($productId);
        foreach ($this->slots[$productId] ?? [] as $slot) {
            if ($b2bAccountId === null || $slot['account_id'] === $b2bAccountId) {
                return true;
            }
        }

        return false;
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

        return $this->cloneWith($variant, $this->onlyPresent($raw, ['purchase_price' => $masked->scale($raw['purchase_price'] ?? null)]));
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
            $row['supplier_special'] = $masked->evaluation;
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
        // te same sloty co ProductController::evaluableSlotsByProduct — z ceną bazową i rabatem standardowym
        $slots = ProductSourcePrice::query()
            ->whereIn('product_id', $ids)
            ->where('source_key', 'like', 'b2b:%')
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

    private static function accountIdOf(mixed $accountId, string $sourceKey): int
    {
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
