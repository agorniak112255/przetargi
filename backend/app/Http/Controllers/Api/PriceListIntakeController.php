<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssortmentGroup;
use App\Models\B2bDiscountRule;
use App\Models\ManufacturerSite;
use App\Models\PriceList;
use App\Models\PriceListFile;
use App\Models\Product;
use App\Models\ProductSourcePin;
use App\Services\B2b\B2bAccountPriceList;
use App\Services\Enrichment\ManufacturerDomainResolver;
use App\Services\PriceListCards;
use App\Services\PriceListImportService;
use App\Services\PriceLists\Importers\PriceListFormatChanged;
use App\Services\PriceLists\Importers\PriceListImporterRegistry;
use App\Services\PriceLists\IntakeBusy;
use App\Services\PriceLists\IntakeNotReady;
use App\Services\PriceLists\PriceListFileStore;
use App\Services\PriceLists\PriceListIntakeRunner;
use App\Services\PriceLists\PriceListIntakeView;
use App\Support\EnrichmentSiteList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Cenniki z plików jak B2B (10.10.2026, SUPON_AI_Plan_Cenniki_Importer_2026-10-10.md sekcja 5): formularz cennika
 * przed plikiem, pliki na dysku, importer per cennik (wybiera administrator), podgląd i import przez
 * PriceListIntakeRunner, mapa źródeł kart (product_source_pins).
 */
class PriceListIntakeController extends Controller
{
    /** Ile kart na stronę listy „Karty bez strony”. */
    private const PINS_PER_PAGE = 50;

    public function __construct(
        private readonly PriceListIntakeView $views,
        private readonly PriceListFileStore $files,
        private readonly PriceListImporterRegistry $registry,
        private readonly B2bAccountPriceList $b2bLists,
        private readonly PriceListCards $cards,
        private readonly ManufacturerDomainResolver $manufacturers,
    ) {}

    public function importers(): JsonResponse
    {
        return response()->json(['importers' => $this->registry->options()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'manufacturer' => ['required', 'string', 'min:1', 'max:100'],
            'version' => ['required', 'string', 'min:1', 'max:100'],
            ...$this->settingsRules(),
        ]);
        $manufacturer = trim((string) $data['manufacturer']);
        $key = PriceList::manufacturerKey($manufacturer);
        if ($key === '') {
            throw ValidationException::withMessages(['manufacturer' => 'Podaj nazwę producenta.']);
        }

        $existing = PriceList::query()->where('manufacturer_key', $key)->first();
        if ($existing !== null) {
            return response()->json([
                'message' => $this->isB2bList($existing)
                    ? 'Cennik '.$existing->manufacturer.' już istnieje i zapisuje do niego konto B2B — pliku tego producenta '
                        .'nie dodaje się osobnym cennikiem.'
                    : 'Cennik '.$existing->manufacturer.' już istnieje — otwórz go i dodaj plik albo zmień ustawienia.',
                'price_list_id' => (int) $existing->id,
            ], 409);
        }

        $settings = $this->normalizedSettings($data);
        $this->assertManufacturerHostsAllowed($request, $manufacturer, $settings);

        // grupy asortymentowe są przypięte do nazwy producenta — nowy cennik bez importu, który by ich nie wyzerował
        $groupsBlock = PriceListIntakeRunner::assortmentGroupsBlock($manufacturer);
        if ($groupsBlock !== null) {
            return response()->json(['message' => $groupsBlock], 422);
        }

        $list = DB::transaction(function () use ($manufacturer, $key, $data, $settings): PriceList {
            $list = PriceList::query()->create([
                'manufacturer' => $manufacturer,
                'manufacturer_key' => $key,
                'version' => trim((string) $data['version']),
                // plik i „zaimportował” dopisuje dopiero import (persistImport)
                'original_filename' => null,
                'imported_by' => null,
                'rows_total' => 0,
                'products_created' => 0,
                'products_updated' => 0,
                'prices_changed' => 0,
                'rows_skipped' => 0,
                'product_ids' => [],
                'source_policy' => PriceList::POLICY_MAP_ONLY,
                'suggested_prices' => (bool) ($data['suggested_prices'] ?? false),
                'importer_notes' => $settings['importer_notes'] ?? null,
                'enrichment_sites' => $settings['enrichment_sites'] ?? null,
                'enrichment_sites_mode' => $settings['enrichment_sites_mode'] ?? PriceList::MODE_FIRST,
                'enrichment_sites_updated_at' => ($settings['enrichment_sites'] ?? null) !== null ? now() : null,
            ]);
            $this->applySideSettings($list, $settings);

            return $list;
        });

        return response()->json(['price_list' => $this->views->one($list->fresh())], 201);
    }

    /**
     * Ustawienia cennika nowym sposobem; zapis na starym cenniku włącza nowy sposób (source_policy = map_only).
     * Producenta zmienia PATCH /price-lists/{id} (przenosi nazwę na karty) — tutaj nie.
     */
    public function update(Request $request, PriceList $priceList): JsonResponse
    {
        if ($this->isB2bList($priceList)) {
            return response()->json([
                'message' => 'Cennik '.$priceList->manufacturer.' zapisuje konto B2B — nie przyjmuje plików nowym sposobem.',
            ], 422);
        }
        $data = $request->validate([
            'manufacturer' => ['sometimes', 'string', 'max:100'],
            'version' => ['sometimes', 'string', 'min:1', 'max:100'],
            ...$this->settingsRules(),
        ]);
        if (array_key_exists('manufacturer', $data) && trim((string) $data['manufacturer']) !== (string) $priceList->manufacturer) {
            throw ValidationException::withMessages([
                'manufacturer' => 'Producenta zmienia się w edycji cennika (Cenniki → Edytuj) — przenosi nazwę także na karty.',
            ]);
        }

        $settings = $this->normalizedSettings($data);
        $this->assertManufacturerHostsAllowed($request, (string) $priceList->manufacturer, $settings);

        // włączenie nowego sposobu na cenniku z grupami rabatowymi zostawiłoby go bez działającego importu (runner
        // odmawia, stary import jest zablokowany) — odmawiamy od razu
        if (! $priceList->usesIntake() && ($block = $this->intakeBlock($priceList)) !== null) {
            return response()->json(['message' => $block], 422);
        }

        DB::transaction(function () use ($priceList, $data, $settings): void {
            if (array_key_exists('version', $data)) {
                $priceList->version = trim((string) $data['version']);
            }
            if (array_key_exists('suggested_prices', $data)) {
                $priceList->suggested_prices = (bool) $data['suggested_prices'];
            }
            if (array_key_exists('importer_notes', $settings)) {
                $priceList->importer_notes = $settings['importer_notes'];
            }
            if (array_key_exists('enrichment_sites', $settings) || array_key_exists('enrichment_sites_mode', $settings)) {
                $oldSha = $priceList->enrichmentHostsSha1();
                if (array_key_exists('enrichment_sites', $settings)) {
                    $priceList->enrichment_sites = $settings['enrichment_sites'];
                }
                if (array_key_exists('enrichment_sites_mode', $settings)) {
                    $priceList->enrichment_sites_mode = $settings['enrichment_sites_mode'];
                }
                // znacznik „opis sprzed zmiany stron” (Cenniki → „Z pliku”) — tylko przy realnej zmianie
                if ($priceList->enrichmentHostsSha1() !== $oldSha) {
                    $priceList->enrichment_sites_updated_at = now();
                }
            }
            $priceList->source_policy = PriceList::POLICY_MAP_ONLY;
            $priceList->save();
            $this->applySideSettings($priceList, $settings);
        });

        return response()->json(['price_list' => $this->views->one($priceList->fresh())]);
    }

    /** Importer cennika — tylko administrator (trasa: admin.access). null odpina. */
    public function setImporter(Request $request, PriceList $priceList): JsonResponse
    {
        if ($this->isB2bList($priceList)) {
            return response()->json([
                'message' => 'Cennik '.$priceList->manufacturer.' zapisuje konto B2B — nie ma importera pliku.',
            ], 422);
        }
        $data = $request->validate([
            'importer_key' => ['present', 'nullable', 'string', 'max:60'],
        ]);
        $key = trim((string) ($data['importer_key'] ?? ''));
        if ($key !== '' && $this->registry->classFor($key) === null) {
            throw ValidationException::withMessages([
                'importer_key' => 'Nie ma importera o kluczu „'.$key.'” w tym wdrożeniu.',
            ]);
        }
        // importer_key blokuje stary import — cennik, którego importer nie przyjmie, zostałby bez żadnej drogi importu
        if ($key !== '' && ($block = $this->intakeBlock($priceList)) !== null) {
            return response()->json(['message' => $block], 422);
        }
        $priceList->importer_key = $key !== '' ? $key : null;
        $priceList->save();

        return response()->json(['price_list' => $this->views->one($priceList->fresh())]);
    }

    public function storeFile(Request $request, PriceList $priceList): JsonResponse
    {
        if (! $priceList->usesIntake()) {
            return response()->json([
                'message' => 'Cennik '.$priceList->manufacturer.' przyjmuje pliki dawnym sposobem (Cenniki → Importuj). '
                    .'Najpierw włącz nowy sposób w ustawieniach cennika.',
            ], 422);
        }
        $request->validate([
            'file' => ['required', 'file', 'max:102400', 'extensions:xlsx,xls,csv,pdf'], // do 100 MB
        ], [
            'file.extensions' => 'Dozwolone formaty: XLSX, XLS, CSV, PDF.',
        ]);
        /** @var UploadedFile $upload */
        $upload = $request->file('file');

        try {
            $stored = $this->files->store($priceList, $upload, $request->user());
        } catch (IntakeBusy $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $file = $stored['file']->load('uploader:id,name');

        if (! $stored['created']) {
            // ten sam plik co najnowszy — nic się nie zmieniło; starszy zaimportowany (niezaimportowany wraca jako
            // najnowszy w PriceListFileStore::store) — cofnięcie do starych cen tylko świadomie, nie przez ponowne wgranie
            $latestId = (int) $priceList->files()->value('id');
            if ((int) $file->id !== $latestId) {
                return response()->json([
                    'message' => 'Ten plik był już dodany '.$file->created_at?->format('d.m.Y H:i')
                        .' i jest starszy niż ostatni — był już zaimportowany. Dodaj nowszy plik cennika albo importuj najnowszy.',
                    'file' => $this->views->file($file),
                ], 409);
            }

            return response()->json([
                'file' => $this->views->file($file),
                'intake' => $this->views->one($priceList->fresh()),
                'duplicate' => true,
            ]);
        }

        return response()->json([
            'file' => $this->views->file($file),
            'intake' => $this->views->one($priceList->fresh()),
            'duplicate' => false,
        ], 201);
    }

    public function files(PriceList $priceList): JsonResponse
    {
        $files = $priceList->files()->with('uploader:id,name')->get();

        return response()->json([
            'files' => $files->map(fn (PriceListFile $file): array => $this->views->file($file))->values()->all(),
        ]);
    }

    public function download(PriceList $priceList, PriceListFile $file): StreamedResponse|JsonResponse
    {
        if (($missing = $this->foreignFile($priceList, $file)) !== null) {
            return $missing;
        }
        $disk = Storage::disk((string) ($file->disk ?: PriceListFileStore::DISK));
        if (! $disk->exists((string) $file->path)) {
            return response()->json(['message' => 'Brak pliku na dysku: '.$file->original_name.'.'], 404);
        }

        return $disk->download((string) $file->path, (string) $file->original_name);
    }

    public function preview(Request $request, PriceList $priceList, PriceListFile $file): JsonResponse
    {
        if (($blocked = $this->runnable($priceList, $file)) !== null) {
            return $blocked;
        }
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);
        @set_time_limit(600);

        try {
            $preview = app(PriceListIntakeRunner::class)->preview($priceList, $file, (int) ($data['limit'] ?? 200));
        } catch (PriceListFormatChanged $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (IntakeNotReady $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            // brak pliku na dysku, zapis w podglądzie (ReadOnlyViolation), błąd importera — treść dla człowieka, wpis
            // w logu dla programisty
            report($e);

            return response()->json(['message' => 'Podgląd nieudany: '.$e->getMessage()], 422);
        }

        return response()->json($preview);
    }

    public function import(Request $request, PriceList $priceList, PriceListFile $file): JsonResponse
    {
        if (($blocked = $this->runnable($priceList, $file)) !== null) {
            return $blocked;
        }
        if ($file->status === PriceListFile::STATUS_SUPERSEDED) {
            return response()->json([
                'message' => 'Plik '.$file->original_name.' został zastąpiony nowszym — importuje się najnowszy plik cennika.',
            ], 422);
        }
        $data = $request->validate([
            'describe' => ['sometimes', 'boolean'],
        ]);
        @set_time_limit(3600);

        try {
            $result = app(PriceListIntakeRunner::class)->import($priceList, $file, $request->user(), (bool) ($data['describe'] ?? false));
        } catch (PriceListFormatChanged $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (IntakeNotReady|IntakeBusy $e) {
            // brak importera albo import tego cennika już trwa (blokada runnera)
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            // runner zapisał błąd przy pliku (status failed) — człowiek dostaje treść, programista wpis w logu
            report($e);

            return response()->json(['message' => 'Import nieudany: '.$e->getMessage()], 422);
        }

        return response()->json($result, 201);
    }

    public function sourcePins(Request $request, PriceList $priceList): JsonResponse
    {
        $data = $request->validate([
            'state' => ['sometimes', Rule::in(['unresolved', 'pinned'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $state = $data['state'] ?? 'unresolved';
        // karta z adresem od człowieka („Wskaż adres”) nie czeka już na stronę — poza listą „Karty bez strony”
        $withHumanUrl = $state === 'unresolved'
            ? ($this->views->humanUrlProductIds([(int) $priceList->id])[(int) $priceList->id] ?? [])
            : [];

        $paginator = ProductSourcePin::query()
            ->where('price_list_id', $priceList->id)
            ->when(
                $state === 'pinned',
                static fn ($q) => $q->whereNotNull('url')->where('url', '<>', ''),
                static fn ($q) => $q->where(static fn ($w) => $w->whereNull('url')->orWhere('url', '')),
            )
            ->when($withHumanUrl !== [], static fn ($q) => $q->whereNotIn('product_id', $withHumanUrl))
            ->with('product')
            ->orderBy('id')
            ->paginate(self::PINS_PER_PAGE);

        return response()->json([
            'data' => collect($paginator->items())->map(static function (ProductSourcePin $pin): array {
                /** @var Product|null $product */
                $product = $pin->product;

                return [
                    'product_id' => (int) $pin->product_id,
                    'sku' => $product?->sku,
                    'name' => $product?->name,
                    'url' => $pin->url,
                    'source_kind' => $pin->source_kind,
                    'match_kind' => $pin->match_kind,
                    'match_key' => $pin->match_key,
                    'unresolved_reason' => $pin->unresolved_reason,
                    'candidates' => $pin->candidates ?? [],
                    // „Wskaż adres” handlowca — wygrywa z mapą
                    'human_url' => $product?->trustedShopUrl(),
                ];
            })->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'state' => $state,
            ],
        ]);
    }

    /** @return array<string, list<mixed>> pola wspólne POST i PATCH */
    private function settingsRules(): array
    {
        return [
            'manufacturer_hosts' => ['sometimes', 'nullable', 'array', 'max:20'],
            'manufacturer_hosts.*' => ['nullable', 'array', 'max:'.EnrichmentSiteList::MAX],
            'manufacturer_hosts.*.*' => ['nullable', 'string', 'max:255'],
            'enrichment_sites' => ['sometimes', 'nullable', 'array', 'max:'.EnrichmentSiteList::MAX],
            'enrichment_sites.*' => ['nullable', 'string', 'max:255'],
            'enrichment_sites_mode' => ['sometimes', 'nullable', Rule::in(PriceList::MODES)],
            'suggested_prices' => ['sometimes', 'boolean'],
            'importer_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'discount_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * Ustawienia po walidacji i normalizacji — klucz obecny tylko, gdy pole przyszło w żądaniu.
     *
     * @param  array<string, mixed>  $data
     * @return array{
     *     manufacturer_hosts?: array<string, list<string>>,
     *     enrichment_sites?: list<string>|null,
     *     enrichment_sites_mode?: string|null,
     *     importer_notes?: string|null,
     *     discount_percent?: float|null
     * }
     */
    /**
     * Powód, dla którego cennik nie może przejść na nowy sposób: runner odmówiłby podglądu i importu (grupy rabatowe,
     * ceny specjalne dostawcy bez kolumny ceny normalnej w importerze), a stary import jest dla cennika nowym sposobem
     * zablokowany — cennik zostałby bez żadnej drogi importu.
     */
    private function intakeBlock(PriceList $priceList): ?string
    {
        $groups = PriceListIntakeRunner::assortmentGroupsBlock((string) $priceList->manufacturer);
        if ($groups !== null) {
            return $groups;
        }
        if (app(PriceListImportService::class)->supplierSpecialRefusalForImporter((string) $priceList->manufacturer) !== null) {
            return 'Cennik ma ceny specjalne dostawcy — importer musi czytać kolumnę ceny normalnej, zgłoś programiście.';
        }

        return null;
    }

    /**
     * Strony producenta (manufacturer_sites) działają w całej aplikacji — to ustawienie administratora „Strony
     * wyszukiwarka” (admin.search_sites.manage). Bez tego uprawnienia formularz cennika przypisuje strony tylko marce
     * producenta tego cennika; inne marki (cennik wielomarkowy) ustawia administrator.
     *
     * @param  array<string, mixed>  $settings
     */
    private function assertManufacturerHostsAllowed(Request $request, string $listManufacturer, array $settings): void
    {
        $brands = array_keys((array) ($settings['manufacturer_hosts'] ?? []));
        if ($brands === [] || $request->user()?->can('admin.search_sites.manage')) {
            return;
        }
        $own = $this->manufacturers->brandKey($listManufacturer);
        // brandKey bierze tekst przed „/” i „(” — nazwa „Ansell (x)” dałaby nowy cennik z marką „ansell” i stronę
        // producenta Ansell w całej aplikacji; taka nazwa nie liczy się jako własna marka
        if ($own !== $this->manufacturers->brandKey(str_replace(['/', '('], ' ', $listManufacturer))) {
            $own = '';
        }
        $foreign = array_values(array_filter($brands, static fn ($brand): bool => (string) $brand !== $own));
        if ($foreign !== []) {
            abort(response()->json([
                'message' => 'Strony producenta innych marek ('.implode(', ', $foreign).') przypisuje administrator w Administracja → Strony wyszukiwarka.',
            ], 403));
        }
    }

    private function normalizedSettings(array $data): array
    {
        $out = [];
        if (array_key_exists('manufacturer_hosts', $data)) {
            $byBrand = [];
            foreach ((array) ($data['manufacturer_hosts'] ?? []) as $brand => $hosts) {
                // lista bez marek ([„host”]) dałaby markę „0”
                $brandKey = is_string($brand) ? $this->manufacturers->brandKey($brand) : '';
                if ($brandKey === '') {
                    throw ValidationException::withMessages([
                        'manufacturer_hosts' => 'Strona producenta: marka „'.$brand.'” nie ma poprawnej nazwy.',
                    ]);
                }
                $normalized = EnrichmentSiteList::normalize((array) ($hosts ?? []), 'manufacturer_hosts', 'Strona producenta');
                if ($normalized !== null) {
                    // nasz sklep i hosty zablokowane nie mogą zostać „stroną producenta” (ta sama reguła co strony opisów)
                    PriceListController::assertEnrichmentHostsAllowed($normalized);
                    $byBrand[$brandKey] = array_values(array_unique([...($byBrand[$brandKey] ?? []), ...$normalized]));
                }
            }
            $out['manufacturer_hosts'] = $byBrand;
        }
        if (array_key_exists('enrichment_sites', $data)) {
            $hosts = EnrichmentSiteList::normalize((array) ($data['enrichment_sites'] ?? []), 'enrichment_sites', 'Źródła opisów');
            if ($hosts !== null) {
                PriceListController::assertEnrichmentHostsAllowed($hosts);
            }
            $out['enrichment_sites'] = $hosts;
        }
        if (array_key_exists('enrichment_sites_mode', $data)) {
            // kolumna NOT NULL z domyślnym „first”
            $out['enrichment_sites_mode'] = $data['enrichment_sites_mode'] !== null ? (string) $data['enrichment_sites_mode'] : PriceList::MODE_FIRST;
        }
        if (array_key_exists('importer_notes', $data)) {
            $notes = trim((string) ($data['importer_notes'] ?? ''));
            $out['importer_notes'] = $notes !== '' ? $notes : null;
        }
        if (array_key_exists('discount_percent', $data)) {
            $out['discount_percent'] = $data['discount_percent'] !== null ? round((float) $data['discount_percent'], 2) : null;
        }

        return $out;
    }

    /**
     * Strony producenta (manufacturer_sites, source manual — jak „Strony wyszukiwarka” → przypisz producenta) i rabat
     * na cały cennik. Strony tylko dopisujemy, istniejących wierszy nie ruszamy: domena wykryta automatem
     * (discovered) albo z configu zostaje swoim źródłem i nazwą, a zdjęcie strony producenta zostaje
     * w Administracja → Strony wyszukiwarka.
     *
     * @param  array<string, mixed>  $settings
     */
    private function applySideSettings(PriceList $list, array $settings): void
    {
        foreach ($settings['manufacturer_hosts'] ?? [] as $brandKey => $hosts) {
            $known = ManufacturerSite::query()
                ->where('brand_key', (string) $brandKey)
                ->whereIn('host', $hosts)
                ->pluck('host')
                ->all();
            // domena wykryta automatem, którą człowiek wybrał w formularzu, staje się przypisana ręcznie (widok nie
            // podaje wykrytych, więc formularz nie odsyła ich sam) — inaczej importer jej nie widzi (assignedDomainsFor);
            // nazwa i wiersze config/manual zostają bez zmian
            $promoted = ManufacturerSite::query()
                ->where('brand_key', (string) $brandKey)
                ->whereIn('host', $hosts)
                ->where('source', 'discovered')
                ->update(['source' => 'manual', 'updated_at' => now()]);
            $new = array_values(array_diff($hosts, $known));
            if ($promoted > 0) {
                Cache::forget('enrich_mfr_domains_v2:'.$brandKey);
            }
            if ($new === []) {
                continue;
            }
            // manufacturer = producent cennika: marka (np. PELTOR) to strona producenta 3M, a widok cennika
            // odnajduje swoje marki po tej nazwie (PriceListIntakeView::manufacturerHosts)
            ManufacturerSite::remember((string) $brandKey, (string) $list->manufacturer, $new, 'manual');
            // ten sam klucz pamięci co CatalogSearchHostService::forgetManufacturerDomains — inaczej domeny
            // producenta wracałyby z pamięci podręcznej sprzed zmiany
            Cache::forget('enrich_mfr_domains_v2:'.$brandKey);
        }
        if (array_key_exists('discount_percent', $settings)) {
            $this->saveGlobalDiscount($list, $settings['discount_percent']);
        }
    }

    /**
     * „Rabat na cały cennik” z formularza = grupa (cały asortyment) producenta. Ceny kart NIE są przeliczane:
     * PriceListDiscountService::apply nie wie, który zakup przyszedł wprost z pliku, i nadpisałby go — rabat działa
     * od następnego importu (importer czyta go z ReadContext, zakup z pliku zostaje). null usuwa grupę.
     *
     * @throws ValidationException
     */
    private function saveGlobalDiscount(PriceList $list, ?float $discount): void
    {
        $manufacturer = (string) $list->manufacturer;
        $global = AssortmentGroup::query()
            ->where('manufacturer', $manufacturer)
            ->where('name', AssortmentGroup::GLOBAL_NAME)
            ->first();
        $current = $global !== null ? (float) $global->discount_percent : null;
        // formularz wysyła rabat przy każdym zapisie — bez zmiany nic nie robimy
        if ($discount === $current || ($discount !== null && $current !== null && abs($discount - $current) < 0.005)) {
            return;
        }
        if (AssortmentGroup::query()->where('manufacturer', $manufacturer)->where('is_global', false)->exists()) {
            throw ValidationException::withMessages([
                'discount_percent' => 'Cennik ma rabaty w grupach — zmień je w Cenniki → Edytuj.',
            ]);
        }

        if ($discount !== null) {
            AssortmentGroup::query()->updateOrCreate(
                ['manufacturer' => $manufacturer, 'name' => AssortmentGroup::GLOBAL_NAME],
                ['discount_percent' => $discount, 'is_global' => true],
            );

            return;
        }
        if ($global === null) {
            return;
        }

        // Klucz obcy zeruje assortment_group_id przy usunięciu grupy — wszędzie. Karty tego cennika zerujemy sami
        // (bez zmiany cen); karty spoza cennika i reguły rabatów B2B wskazujące grupę blokują usunięcie.
        $ownCards = $this->cards->fileSlotIds($list);
        $foreignCards = Product::query()
            ->where('assortment_group_id', $global->id)
            ->when($ownCards !== [], static fn ($q) => $q->whereNotIn('id', $ownCards))
            ->exists();
        $rules = B2bDiscountRule::query()->where('assortment_group_id', $global->id)->exists();
        if ($foreignCards || $rules) {
            throw ValidationException::withMessages([
                'discount_percent' => 'Rabatu nie można usunąć — grupę „'.AssortmentGroup::GLOBAL_NAME.'” producenta '
                    .$manufacturer.' wskazują karty spoza tego cennika albo reguły rabatów B2B. Wpisz 0 %.',
            ]);
        }
        foreach (array_chunk($ownCards, 1000) as $chunk) {
            Product::query()
                ->whereIn('id', $chunk)
                ->where('assortment_group_id', $global->id)
                ->update(['assortment_group_id' => null]);
        }
        $global->delete();
    }

    /** Wpis konta B2B: zapisany na koncie albo „{host} (API)” łącznika (B2bAccountPriceList). */
    private function isB2bList(PriceList $list): bool
    {
        if (str_ends_with(trim((string) $list->original_filename), '(API)')) {
            return true;
        }

        return isset($this->b2bLists->owners([$list])[(int) $list->id]);
    }

    private function foreignFile(PriceList $priceList, PriceListFile $file): ?JsonResponse
    {
        return (int) $file->price_list_id === (int) $priceList->id
            ? null
            : response()->json(['message' => 'Ten plik nie należy do tego cennika.'], 404);
    }

    private function runnable(PriceList $priceList, PriceListFile $file): ?JsonResponse
    {
        if (($missing = $this->foreignFile($priceList, $file)) !== null) {
            return $missing;
        }
        if (! $priceList->usesIntake()) {
            return response()->json([
                'message' => 'Cennik '.$priceList->manufacturer.' przyjmuje pliki dawnym sposobem (Cenniki → Importuj).',
            ], 422);
        }

        return null;
    }
}
