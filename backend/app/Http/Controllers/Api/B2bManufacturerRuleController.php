<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\B2bAccountManufacturerRule;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bManufacturerRules;
use App\Services\Pricing\ProductEffectivePrice;
use App\Support\BrandKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Okno „Producenci” przy koncie B2B (decyzja użytkownika 23.09.2026): lista producentów, których karty konto
 * pobiera, ze znacznikami „cena” i „opis” — czy ten cennik ma je ustalać. Zapisujemy tylko wyłączenia
 * (B2bManufacturerRules). Zmiana znacznika ceny od razu przelicza cenę obowiązującą kart tego producenta;
 * znacznik opisu działa od następnego pobrania (synchronizacja czyta reguły raz na przebieg).
 */
class B2bManufacturerRuleController extends Controller
{
    /** Tyle kart naraz przy przeliczaniu ceny obowiązującej — Raw-Pol ma kilka tysięcy kart. */
    private const RECOMPUTE_CHUNK = 500;

    public function __construct(
        private readonly B2bConnectorRegistry $connectors,
        private readonly ProductEffectivePrice $effectivePrice,
        private readonly B2bManufacturerRules $rules,
    ) {}

    public function index(B2bAccount $b2bAccount): JsonResponse
    {
        return response()->json($this->payload($b2bAccount));
    }

    public function update(Request $request, B2bAccount $b2bAccount): JsonResponse
    {
        $data = $request->validate([
            'rules' => ['present', 'array', 'max:2000'],
            'rules.*.manufacturer' => ['required', 'string', 'max:100'],
            'rules.*.take_price' => ['required', 'boolean'],
            'rules.*.take_description' => ['required', 'boolean'],
        ]);

        $usesPriceRules = $this->connectors->usesPriceRules($this->connectors->keyForAccount($b2bAccount));
        $wanted = [];
        foreach (array_values($data['rules']) as $index => $row) {
            $manufacturer = trim((string) $row['manufacturer']);
            $key = B2bManufacturerRules::key($manufacturer);
            if ($key === '') {
                return response()->json([
                    'message' => 'Nazwa producenta „'.$manufacturer.'” nie zawiera liter ani cyfr.',
                    'errors' => ['rules.'.$index.'.manufacturer' => ['Podaj nazwę producenta.']],
                ], 422);
            }
            $wanted[$key] = [
                'manufacturer' => $manufacturer,
                // bez ceny w slocie konta (łącznik treści, łącznik z wersjami) wyłączenie ceny nic by nie znaczyło,
                // a zostałoby w bazie jako ukryty stan — zapisujemy tylko znacznik opisu
                'take_price' => $usesPriceRules ? (bool) $row['take_price'] : true,
                'take_description' => (bool) $row['take_description'],
            ];
        }

        $existing = B2bAccountManufacturerRule::query()
            ->where('b2b_account_id', $b2bAccount->id)
            ->whereIn('manufacturer_key', array_map('strval', array_keys($wanted)))
            ->get()
            ->keyBy('manufacturer_key');

        $priceChanged = [];
        DB::transaction(function () use ($b2bAccount, $wanted, $existing, &$priceChanged): void {
            foreach ($wanted as $key => $flags) {
                $key = (string) $key;
                $before = $existing->get($key);
                // brak reguły = oba znaczniki włączone
                if (($before === null ? true : (bool) $before->take_price) !== $flags['take_price']) {
                    $priceChanged[] = $key;
                }
                if ($flags['take_price'] && $flags['take_description']) {
                    $before?->delete();

                    continue;
                }
                B2bAccountManufacturerRule::query()->updateOrCreate(
                    ['b2b_account_id' => $b2bAccount->id, 'manufacturer_key' => $key],
                    $flags,
                );
            }
        });

        // Po zapisie reguł i poza transakcją: refresh() ma własną transakcję z blokadą karty — kilka tysięcy kart
        // w jednej zewnętrznej transakcji trzymałoby blokady przez cały przebieg.
        [$recomputed, $frozen] = $this->recompute($b2bAccount, $priceChanged);

        return response()->json([
            ...$this->payload($b2bAccount->fresh()),
            'recomputed' => $recomputed,
            'frozen' => $frozen,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(B2bAccount $account): array
    {
        $accountKey = $this->connectors->keyForAccount($account);
        $ownSource = $this->ownSourceResolver($account);

        $list = [];
        foreach ($this->cardManufacturers($account) as $key => $entry) {
            $list[$key] = [
                'manufacturer' => $entry['manufacturer'],
                // klucz z samych cyfr PHP trzyma w tablicy jako liczbę — w odpowiedzi zawsze tekst
                'key' => (string) $key,
                'cards' => $entry['cards'],
                'take_price' => true,
                'take_description' => true,
                'has_rule' => false,
            ];
        }
        foreach (B2bAccountManufacturerRule::query()->where('b2b_account_id', $account->id)->get() as $rule) {
            $key = (string) $rule->manufacturer_key;
            $list[$key] ??= [
                'manufacturer' => (string) $rule->manufacturer,
                'key' => $key,
                'cards' => 0,
            ];
            $list[$key]['take_price'] = (bool) $rule->take_price;
            $list[$key]['take_description'] = (bool) $rule->take_description;
            $list[$key]['has_rule'] = true;
        }

        $rows = [];
        foreach ($list as $row) {
            $rows[] = [...$row, 'own_source' => $ownSource($row['manufacturer'])];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['cards'], mb_strtolower($a['manufacturer'])]
            <=> [$a['cards'], mb_strtolower($b['manufacturer'])]);

        return [
            'uses_price_rules' => $this->connectors->usesPriceRules($accountKey),
            'sync_running' => $account->last_sync_status === 'running' || $account->sync_requested_at !== null,
            'manufacturers' => $rows,
        ];
    }

    /**
     * Producenci kart powiązanych z kontem: brzmienie konta (b2b_product_links.manufacturer), a przy zapisach
     * sprzed 23.09.2026 — producent z karty. Brzmienia o tym samym kluczu reguł to jeden producent; nazwa =
     * najczęstsze brzmienie. Karta z kilkoma powiązaniami (scalone rozmiary) liczy się raz.
     *
     * @return array<string, array{manufacturer: string, cards: int}> klucz producenta => nazwa i liczba kart
     */
    private function cardManufacturers(B2bAccount $account): array
    {
        $pairs = DB::table('b2b_product_links as l')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.b2b_account_id', $account->id)
            ->selectRaw('DISTINCT l.product_id as product_id, COALESCE(l.manufacturer, p.manufacturer) as manufacturer')
            ->get();

        $cards = [];
        $spellings = [];
        foreach ($pairs as $pair) {
            $name = trim((string) $pair->manufacturer);
            $key = $name !== '' ? B2bManufacturerRules::key($name) : '';
            if ($key === '') {
                continue;
            }
            $cards[$key][(int) $pair->product_id] = true;
            $spellings[$key][$name] = ($spellings[$key][$name] ?? 0) + 1;
        }

        $out = [];
        foreach ($cards as $key => $ids) {
            $names = $spellings[$key];
            // najczęstsze brzmienie, przy remisie alfabetycznie — ta sama nazwa przy każdym otwarciu okna
            // (klucze tablicy PHP z samych cyfr są liczbami — porównanie po tekście)
            uksort($names, static fn (int|string $a, int|string $b): int => [$names[$b], (string) $a] <=> [$names[$a], (string) $b]);
            $out[(string) $key] = ['manufacturer' => (string) array_key_first($names), 'cards' => count($ids)];
        }

        return $out;
    }

    /**
     * Uwaga przy producencie: czy jego cenę ustala cennik producenta (to konto, inne konto B2B producenta albo plik
     * cennika producenta) — wtedy cena tego konta i tak nie obowiązuje (ProductEffectivePrice::explain).
     * Konto producenta z wyłączoną ceną tej marki nie jest jej cennikiem (jak ownB2bAccountIds).
     *
     * @return callable(string): ?string
     */
    private function ownSourceResolver(B2bAccount $account): callable
    {
        $thisBrands = $this->connectors->brandsForKey($this->connectors->keyForAccount($account));

        $others = [];
        foreach (B2bAccount::query()->whereKeyNot($account->id)->orderBy('id')->get() as $other) {
            $key = $this->connectors->keyForAccount($other);
            $brands = $this->connectors->brandsForKey($key);
            if ($brands !== []) {
                $others[] = ['id' => (int) $other->id, 'label' => (string) $this->connectors->label($key), 'brands' => $brands];
            }
        }
        $disabled = $this->rules->priceDisabled(array_column($others, 'id'));

        // tylko cenniki, z których karty mają cenę (slot „file”) — wpis cennika konta B2B plikiem nie jest
        $files = PriceList::query()
            ->whereIn('id', ProductSourcePrice::query()
                ->select('price_list_id')
                ->where('source_key', ProductSourcePrice::SOURCE_FILE)
                ->whereNotNull('price_list_id')
                ->distinct())
            // cennik sugerowany (bez cen zakupu) nie ma pierwszeństwa przed tym kontem — to nie „cennik producenta”
            ->where('suggested_prices', false)
            ->orderByDesc('id')
            ->get(['id', 'manufacturer', 'version']);

        $matches = static function (array $brands, string $manufacturer): bool {
            foreach ($brands as $brand) {
                if (BrandKey::same($brand, $manufacturer)) {
                    return true;
                }
            }

            return false;
        };

        return static function (string $manufacturer) use ($thisBrands, $others, $disabled, $files, $matches): ?string {
            if ($matches($thisBrands, $manufacturer)) {
                return 'to cennik producenta';
            }
            $key = B2bManufacturerRules::key($manufacturer);
            foreach ($others as $other) {
                if (! isset($disabled[$other['id']][$key]) && $matches($other['brands'], $manufacturer)) {
                    return 'cennik producenta: konto '.$other['label'].' (#'.$other['id'].')';
                }
            }
            foreach ($files as $file) {
                if (BrandKey::same((string) $file->manufacturer, $manufacturer)) {
                    return 'cennik producenta z pliku: '.trim(trim((string) $file->manufacturer).' '.trim((string) $file->version));
                }
            }

            return null;
        };
    }

    /**
     * Cena obowiązująca kart, na które wpływa zmiana znacznika ceny: karta ma slot tego konta i należy do
     * producenta o zmienionym kluczu (brzmienie konta, inaczej producent karty — jak w explain()).
     *
     * @param  list<string>  $keys  klucze producentów ze zmienionym znacznikiem ceny
     * @return array{0: int, 1: int} [karty ze zmienioną ceną, karty z ceną tylko z wyłączonych źródeł]
     */
    private function recompute(B2bAccount $account, array $keys): array
    {
        if ($keys === []) {
            return [0, 0];
        }
        $wanted = array_flip($keys);
        $sourceKey = ProductSourcePrice::b2bKey((int) $account->id);

        $pairs = DB::table('product_source_prices as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('b2b_product_links as l', function ($join) use ($account): void {
                $join->on('l.product_id', '=', 's.product_id')->where('l.b2b_account_id', '=', $account->id);
            })
            ->where('s.source_key', $sourceKey)
            ->selectRaw('DISTINCT s.product_id as product_id, COALESCE(l.manufacturer, p.manufacturer) as manufacturer')
            ->get();

        $productIds = [];
        foreach ($pairs as $pair) {
            if (isset($wanted[B2bManufacturerRules::key((string) $pair->manufacturer)])) {
                $productIds[(int) $pair->product_id] = true;
            }
        }

        $recomputed = 0;
        $frozen = 0;
        foreach (array_chunk(array_keys($productIds), self::RECOMPUTE_CHUNK) as $chunk) {
            foreach (Product::query()->whereIn('id', $chunk)->get() as $product) {
                if ($this->effectivePrice->refresh($product) !== []) {
                    $recomputed++;
                }
                $explain = $this->effectivePrice->explain($product);
                // zamrożona = żaden slot nie ustala ceny, a slot tego konta cenę ma (wyłączony znacznikiem);
                // karta z wersjami (powody puste) i slot bez ceny to nie ten przypadek
                if ($explain['winner'] === null
                    && isset($explain['reasons'][$sourceKey])
                    && $this->slotHasPrice((int) $product->id, $sourceKey)) {
                    $frozen++;
                }
            }
        }

        return [$recomputed, $frozen];
    }

    private function slotHasPrice(int $productId, string $sourceKey): bool
    {
        return ProductSourcePrice::query()
            ->where('product_id', $productId)
            ->where('source_key', $sourceKey)
            ->where(static fn ($q) => $q->where('purchase_price', '>', 0)->orWhere('catalog_price_net', '>', 0))
            ->exists();
    }
}
