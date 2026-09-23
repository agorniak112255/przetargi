<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Services\B2b\B2bConnectorRegistry;
use App\Support\BhpAttributeNormalizer;
use App\Support\ManufacturerNormFacts;
use App\Support\RequirementCheck\En388Code;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Miara jakości norm na kartach (plan SUPON_AI_Plan_Norm_2026-09-23.md, etap 0) — wyłącznie odczyt.
 *
 * Liczy per źródło cen × producent: karty ŚOI, karty z normami, pary norm producenta, pokrycie EN 388 (kod /
 * zapis słowny / sama wzmianka bez poziomów), fałszywe `poziomy_en388` (np. „2016”, „211” ze starego czytnika),
 * różne odczyty EN 388 na jednej karcie (inne wydanie normy / prawdziwa sprzeczność / odczyt szczątkowy), kod inny
 * niż u producenta i karty dystrybutorów z EAN-em wspólnym z kartą, która ma normy producenta.
 *
 * Nic nie zapisuje — ani w bazie, ani w pamięci podręcznej, ani w plikach; jedyny wyjątek to plik z --csv.
 * `--json` drukuje jeden dokument do porównania przebiegów przed i po zmianie czytników.
 */
final class NormsAuditCommand extends Command
{
    protected $signature = 'norms:audit
        {--manufacturer= : Tylko karty tego producenta (dokładnie jak w products.manufacturer)}
        {--source= : Tylko to źródło cen, np. „b2b:p4s” (karta z tym kontem, także obok innych), „plik:Canis”, „bez_cennika”}
        {--samples=0 : Ile numerów kart zapamiętać przy każdej mierze (0 = żadnych)}
        {--min-cards=1 : Ukryj grupy z mniejszą liczbą kart ŚOI}
        {--top=30 : Ile grup pokazać w tabeli problemów}
        {--csv= : Zapisz jeden wiersz na grupę do tego pliku CSV}
        {--json : Zamiast tabel wydrukuj jeden dokument JSON}';

    protected $description = 'Miara norm na kartach (EN 388, normy producenta, wspólne EAN) per źródło cen i producent — tylko odczyt';

    /** Miary w kolejności kolumn; `normy_producenta:<łącznik>` dochodzą w CSV i JSON za nimi. */
    public const METRICS = [
        'kart',
        'soi',
        'z_normami',
        'normy_producenta',
        'en388_wzmianka',
        'en388_kod',
        'en388_slownie',
        'en388_bez_poziomow',
        'poziomy_falszywe',
        'poziomy_brak_przy_kodzie',
        'en388_inne_wydania',
        'en388_sprzecznosc',
        'en388_szczatkowe',
        'kod_inny_niz_producent',
        'karta_dystrybutora',
        'ean_wspolny_z_producentem',
    ];

    /** Krótkie nagłówki tabel w konsoli. */
    private const HEADERS = [
        'kart' => 'kart',
        'soi' => 'ŚOI',
        'z_normami' => 'normy',
        'normy_producenta' => 'n.prod',
        'en388_wzmianka' => '388',
        'en388_kod' => 'kod',
        'en388_slownie' => 'słown.',
        'en388_bez_poziomow' => 'bez poz.',
        'poziomy_falszywe' => 'fałsz.',
        'poziomy_brak_przy_kodzie' => 'brak poz.',
        'en388_inne_wydania' => 'wyd.',
        'en388_sprzecznosc' => 'sprz.',
        'en388_szczatkowe' => 'szczątk.',
        'kod_inny_niz_producent' => '≠prod.',
        'karta_dystrybutora' => 'dystr.',
        'ean_wspolny_z_producentem' => 'EAN',
    ];

    /** Miary sumowane w kolejności tabeli problemów. */
    private const PROBLEM_METRICS = ['en388_bez_poziomow', 'en388_sprzecznosc', 'poziomy_falszywe'];

    private const MENTIONS_EN388 = '/EN\s?(ISO\s?)?388(?!\d)/iu';

    private int $sampleLimit = 0;

    /** @var array<string, array{source: string, manufacturer: string, m: array<string, int>, s: array<string, list<int>>}> */
    private array $groups = [];

    /** @var array<string, array{m: array<string, int>, s: array<string, list<int>>}> */
    private array $sources = [];

    /** @var array{m: array<string, int>, s: array<string, list<int>>} */
    private array $totals = ['m' => [], 's' => []];

    public function handle(BhpAttributeNormalizer $normalizer, B2bConnectorRegistry $registry): int
    {
        // domyślne 128M w CLI serwera nie wystarcza na mapy powiązań 48 tys. kart
        ini_set('memory_limit', '2048M');
        // jedna instancja polecenia obsługuje kolejne wywołania w tym samym procesie (Artisan::call) — liczymy od zera
        $this->groups = [];
        $this->sources = [];
        $this->totals = ['m' => [], 's' => []];

        $manufacturer = trim((string) $this->option('manufacturer'));
        $sourceFilter = trim((string) $this->option('source'));
        $this->sampleLimit = max(0, (int) $this->option('samples'));
        $minCards = max(0, (int) $this->option('min-cards'));
        $json = (bool) $this->option('json');
        $notes = [];

        $connectorsByProduct = $this->connectorsByProduct($registry);
        $fileSources = $this->fileSources();
        $manufacturerKeys = $this->manufacturerSiteKeys($registry);
        $distributorKeys = array_values(array_diff($registry->keys(), $manufacturerKeys));

        $eanOverlap = [];
        if (Schema::hasTable('product_identifiers')) {
            $eanOverlap = $this->eanOverlap($connectorsByProduct, $distributorKeys);
        } else {
            $notes[] = 'Brak tabeli product_identifiers — pomijam karty dystrybutorów ze wspólnym EAN.';
        }

        $checked = 0;
        Product::query()
            ->when($manufacturer !== '', static fn ($q) => $q->where('manufacturer', $manufacturer))
            ->chunkById(500, function (Collection $products) use (
                $normalizer, $connectorsByProduct, $fileSources, $distributorKeys, $eanOverlap, $sourceFilter, &$checked
            ): void {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $pid = (int) $product->id;
                    $connectors = array_keys($connectorsByProduct[$pid] ?? []);
                    sort($connectors);
                    $source = $connectors !== []
                        ? 'b2b:'.implode('+', $connectors)
                        : ($fileSources[$pid] ?? 'bez_cennika');
                    if ($sourceFilter !== '' && ! self::sourceMatches($sourceFilter, $source, $connectors)) {
                        continue;
                    }
                    $checked++;
                    $maker = trim((string) $product->manufacturer);
                    $hits = $this->cardMetrics($product, $normalizer->forProduct($product));
                    if (array_intersect($connectors, $distributorKeys) !== []) {
                        $hits[] = 'karta_dystrybutora';
                        if (isset($eanOverlap[$pid])) {
                            $hits[] = 'ean_wspolny_z_producentem';
                        }
                    }
                    $this->record($source, $maker !== '' ? $maker : '(brak)', $hits, $pid);
                }
            });

        $visible = array_filter(
            $this->groups,
            static fn (array $group): bool => ($group['m']['soi'] ?? 0) >= $minCards,
        );
        ksort($visible);
        $metricColumns = $this->metricColumns();

        $csv = trim((string) $this->option('csv'));
        if ($csv !== '') {
            $error = $this->writeCsv($csv, $visible, $metricColumns);
            if ($error !== null) {
                $this->error("Nie zapisano pliku CSV ({$error}).");

                return self::FAILURE;
            }
            $notes[] = sprintf('Zapisano %d grup do %s.', count($visible), $csv);
        }

        if ($json) {
            $this->line((string) json_encode($this->jsonDocument($visible, $metricColumns, $checked, $notes, [
                'manufacturer' => $manufacturer !== '' ? $manufacturer : null,
                'source' => $sourceFilter !== '' ? $sourceFilter : null,
                'min_cards' => $minCards,
                'samples' => $this->sampleLimit,
            ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($notes as $note) {
            $this->warn($note);
        }
        $this->printTables($visible, $checked, max(1, (int) $this->option('top')));

        return self::SUCCESS;
    }

    /**
     * Miary jednej karty. Źródła EN 388: `opis` (description), `tabelka` (shop_fields_summary), `lista`
     * (enrichment_payload.norms + products.norms) i `producent` („EN 388 ” + manufacturer_norms.en388).
     *
     * @param  array<string, mixed>  $attrs  wynik BhpAttributeNormalizer::forProduct
     * @return list<string>
     */
    private function cardMetrics(Product $product, array $attrs): array
    {
        $hits = ['kart'];
        $kategoria = $attrs['kategoria_bhp'] ?? null;
        if (is_string($kategoria) && $kategoria !== '' && $kategoria !== 'inne') {
            $hits[] = 'soi';
        }
        $normy = array_values(array_filter(
            (array) ($attrs['normy_en'] ?? []),
            static fn (mixed $n): bool => is_string($n) && trim($n) !== '',
        ));
        if ($normy !== []) {
            $hits[] = 'z_normami';
        }
        $column = $product->manufacturer_norms;
        if (is_array($column)) {
            $hits[] = 'normy_producenta';
            $connector = $column['source']['connector'] ?? null;
            $hits[] = 'normy_producenta:'.(is_string($connector) && $connector !== '' ? $connector : '?');
        }

        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $list = array_filter(
            [...(is_array($payload['norms'] ?? null) ? $payload['norms'] : []), (string) $product->norms],
            static fn (mixed $n): bool => is_string($n) && trim($n) !== '',
        );
        $producerCode = ManufacturerNormFacts::context($column)['en388'] ?? null;
        // średnik rozdziela pozycje listy — En388Code kończy na nim fragment normy
        $readings = [
            'opis' => En388Code::allIn((string) $product->description),
            'tabelka' => En388Code::allIn((string) $product->shop_fields_summary),
            'lista' => En388Code::allIn(implode('; ', $list)),
            'producent' => $producerCode !== null ? En388Code::allIn('EN 388 '.$producerCode) : [],
        ];
        $mentions = preg_match(
            self::MENTIONS_EN388,
            implode(' ', $normy).' '.$product->description.' '.$product->shop_fields_summary,
        ) === 1;
        $poziomy = $attrs['poziomy_en388'] ?? null;

        return [...$hits, ...self::en388Metrics($readings, $mentions, is_string($poziomy) ? $poziomy : null)];
    }

    /**
     * Miary EN 388 z odczytów karty.
     *
     * - `en388_wzmianka`: tekst wymienia EN 388; wtedy dokładnie jedno z `en388_kod` (jest odczyt kodem w którymkolwiek
     *   źródle), `en388_slownie` (tylko zapis słowny) albo `en388_bez_poziomow` (żadnego odczytu).
     * - `poziomy_falszywe`: `poziomy_en388` nie jest żadnym z kodów odczytanych ze źródeł karty.
     * - `poziomy_brak_przy_kodzie`: w źródłach jest kod, a `poziomy_en388` puste.
     * - `en388_sprzecznosc` / `en388_inne_wydania`: dwa kody różnią się na którejś pozycji podanej w obu; sprzeczność,
     *   gdy choć jedna taka para ma porównywalne wydania, inaczej to dwa podane, różne wydania normy.
     * - `en388_szczatkowe`: zapis słowny z mniej niż czterema pierwszymi pozycjami obok pełnego kodu.
     * - `kod_inny_niz_producent`: kod z opisu, tabelki albo listy przeczy kodowi producenta w tym samym wydaniu.
     *
     * @param  array<string, list<En388Code>>  $readings  źródło => odczyty
     * @return list<string>
     */
    public static function en388Metrics(array $readings, bool $mentions, ?string $poziomy): array
    {
        $coded = [];
        $worded = [];
        foreach ($readings as $source => $codes) {
            foreach ($codes as $code) {
                if ($code->worded) {
                    $worded[] = $code;
                } else {
                    $coded[] = [$source, $code];
                }
            }
        }

        $hits = [];
        if ($mentions) {
            $hits[] = 'en388_wzmianka';
            $hits[] = $coded !== [] ? 'en388_kod' : ($worded !== [] ? 'en388_slownie' : 'en388_bez_poziomow');
        }

        $compacts = array_values(array_unique(array_map(static fn (array $c): string => (string) $c[1]->compact(), $coded)));
        $poziomy = trim((string) $poziomy);
        if ($poziomy !== '') {
            if (! in_array(self::poziomyCompact($poziomy), $compacts, true)) {
                $hits[] = 'poziomy_falszywe';
            }
        } elseif ($coded !== []) {
            $hits[] = 'poziomy_brak_przy_kodzie';
        }

        $differs = false;
        $conflict = false;
        $count = count($coded);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                [, $a] = $coded[$i];
                [, $b] = $coded[$j];
                if (! self::positionsDiffer($a, $b)) {
                    continue;
                }
                $differs = true;
                if (En388Code::comparableEditions($a->edition, $b->edition)) {
                    $conflict = true;
                }
            }
        }
        if ($conflict) {
            $hits[] = 'en388_sprzecznosc';
        } elseif ($differs) {
            $hits[] = 'en388_inne_wydania';
        }

        $fullCode = array_filter($coded, static fn (array $c): bool => self::firstFourGiven($c[1]) === 4) !== [];
        $partialWords = array_filter($worded, static fn (En388Code $c): bool => self::firstFourGiven($c) < 4) !== [];
        if ($fullCode && $partialWords) {
            $hits[] = 'en388_szczatkowe';
        }

        $producer = array_filter($coded, static fn (array $c): bool => $c[0] === 'producent');
        foreach ($coded as [$source, $code]) {
            if ($source === 'producent') {
                continue;
            }
            foreach ($producer as [, $own]) {
                if (En388Code::comparableEditions($code->edition, $own->edition) && self::positionsDiffer($code, $own)) {
                    $hits[] = 'kod_inny_niz_producent';
                    break 2;
                }
            }
        }

        return $hits;
    }

    /**
     * `poziomy_en388` w zapisie porównywalnym z compact(): kod odczytany jak z tekstu („4 X 4 2 C (2016)” → „4X42C”),
     * a gdy En388Code go nie widzi („2016”, „211”) — sama wartość bez spacji i kropek.
     */
    private static function poziomyCompact(string $poziomy): string
    {
        foreach (En388Code::allIn('EN 388 '.$poziomy) as $code) {
            $compact = $code->compact();
            if ($compact !== null) {
                return $compact;
            }
        }

        return mb_strtoupper(preg_replace('/[\h.]/u', '', $poziomy) ?? $poziomy);
    }

    /** Czy któraś pozycja jest podana w obu odczytach i ma inną wartość. */
    private static function positionsDiffer(En388Code $a, En388Code $b): bool
    {
        foreach (array_keys(En388Code::POSITIONS) as $key) {
            $left = $a->levels[$key] ?? null;
            $right = $b->levels[$key] ?? null;
            if ($left !== null && $right !== null && $left !== $right) {
                return true;
            }
        }

        return false;
    }

    private static function firstFourGiven(En388Code $code): int
    {
        $given = 0;
        foreach (['abrasion', 'coupe', 'tear', 'puncture'] as $key) {
            if (($code->levels[$key] ?? null) !== null) {
                $given++;
            }
        }

        return $given;
    }

    /** @param  list<string>  $connectors */
    private static function sourceMatches(string $filter, string $source, array $connectors): bool
    {
        if ($filter === $source) {
            return true;
        }

        return str_starts_with($filter, 'b2b:') && in_array(substr($filter, 4), $connectors, true);
    }

    /**
     * Klucze łączników kart: product_id => [klucz => true]. Konto bez zapisanego łącznika rozstrzyga się
     * z witryn (B2bConnectorRegistry::keyForAccount), nieznane jako „?”.
     *
     * @return array<int, array<string, true>>
     */
    private function connectorsByProduct(B2bConnectorRegistry $registry): array
    {
        $keys = [];
        foreach (B2bAccount::query()->get(['id', 'connector', 'sites']) as $account) {
            $keys[(int) $account->id] = $registry->keyForAccount($account) ?? '?';
        }

        $out = [];
        $links = B2bProductLink::query()->toBase()
            ->whereNotNull('product_id')
            ->select(['product_id', 'b2b_account_id'])
            ->cursor();
        foreach ($links as $link) {
            $out[(int) $link->product_id][$keys[(int) $link->b2b_account_id] ?? '?'] = true;
        }

        return $out;
    }

    /**
     * Ostatni cennik z pliku z listą kart: product_id => „plik:<producent cennika>”.
     *
     * @return array<int, string>
     */
    private function fileSources(): array
    {
        $out = [];
        foreach (PriceList::query()->orderBy('id')->get(['id', 'manufacturer', 'product_ids']) as $list) {
            $label = 'plik:'.trim((string) $list->manufacturer);
            foreach ((array) ($list->product_ids ?? []) as $pid) {
                $out[(int) $pid] = $label;
            }
        }

        return $out;
    }

    /**
     * Klucze łączników witryn producenta (B2bManufacturerSite) — bez tworzenia łączników (te sięgają po hasła).
     *
     * @return list<string>
     */
    private function manufacturerSiteKeys(B2bConnectorRegistry $registry): array
    {
        return array_values(array_filter($registry->keys(), $registry->isManufacturerSite(...)));
    }

    /**
     * Karty dystrybutorów (konto łącznika spoza witryn producenta), których EAN ma też inna karta z normami
     * producenta: product_id => true. Identyfikatory usunięte u źródła (removed_at) pomijamy.
     *
     * @param  array<int, array<string, true>>  $connectorsByProduct
     * @param  list<string>  $distributorKeys
     * @return array<int, true>
     */
    private function eanOverlap(array $connectorsByProduct, array $distributorKeys): array
    {
        $withNorms = array_fill_keys(
            Product::query()->toBase()->whereNotNull('manufacturer_norms')->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            true,
        );
        $distributorCards = [];
        foreach ($connectorsByProduct as $pid => $connectors) {
            if (array_intersect(array_keys($connectors), $distributorKeys) !== []) {
                $distributorCards[$pid] = true;
            }
        }
        if ($withNorms === [] || $distributorCards === []) {
            return [];
        }

        $owners = [];
        $cardEans = [];
        $rows = ProductIdentifier::query()->toBase()
            ->where('type', ProductIdentifier::TYPE_EAN)
            ->whereNull('removed_at')
            ->select(['product_id', 'value', 'normalized'])
            ->cursor();
        foreach ($rows as $row) {
            $pid = (int) $row->product_id;
            $ean = trim((string) ($row->normalized ?? $row->value));
            if ($ean === '') {
                continue;
            }
            if (isset($withNorms[$pid])) {
                $owners[$ean][$pid] = true;
            }
            if (isset($distributorCards[$pid])) {
                $cardEans[$pid][$ean] = true;
            }
        }

        $out = [];
        foreach ($cardEans as $pid => $eans) {
            foreach (array_keys($eans) as $ean) {
                $others = $owners[$ean] ?? [];
                unset($others[$pid]);
                if ($others !== []) {
                    $out[$pid] = true;
                    break;
                }
            }
        }

        return $out;
    }

    /** @param  list<string>  $hits */
    private function record(string $source, string $manufacturer, array $hits, int $pid): void
    {
        $key = $source.' | '.$manufacturer;
        $this->groups[$key] ??= ['source' => $source, 'manufacturer' => $manufacturer, 'm' => [], 's' => []];
        $this->sources[$source] ??= ['m' => [], 's' => []];
        foreach (array_unique($hits) as $metric) {
            $this->bump($this->groups[$key], $metric, $pid);
            $this->bump($this->sources[$source], $metric, $pid);
            $this->bump($this->totals, $metric, $pid);
        }
    }

    /** @param  array{m: array<string, int>, s: array<string, list<int>>}  $row */
    private function bump(array &$row, string $metric, int $pid): void
    {
        $row['m'][$metric] = ($row['m'][$metric] ?? 0) + 1;
        if ($this->sampleLimit > 0 && $metric !== 'kart' && count($row['s'][$metric] ?? []) < $this->sampleLimit) {
            $row['s'][$metric][] = $pid;
        }
    }

    /**
     * Stałe miary i `normy_producenta:<łącznik>` spotkane w tym przebiegu.
     *
     * @return list<string>
     */
    private function metricColumns(): array
    {
        $dynamic = array_values(array_filter(
            array_keys($this->totals['m']),
            static fn (string $metric): bool => str_starts_with($metric, 'normy_producenta:'),
        ));
        sort($dynamic);

        return [...self::METRICS, ...$dynamic];
    }

    /**
     * @param  array<string, array{source: string, manufacturer: string, m: array<string, int>, s: array<string, list<int>>}>  $groups
     * @param  list<string>  $columns
     */
    private function writeCsv(string $path, array $groups, array $columns): ?string
    {
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            return 'nie można otworzyć '.$path;
        }
        fputcsv($handle, ['zrodlo_cen', 'producent', ...$columns], ';');
        foreach ($groups as $group) {
            fputcsv($handle, [
                $group['source'],
                $group['manufacturer'],
                ...array_map(static fn (string $metric): int => $group['m'][$metric] ?? 0, $columns),
            ], ';');
        }
        fclose($handle);

        return null;
    }

    /**
     * @param  array<string, array{source: string, manufacturer: string, m: array<string, int>, s: array<string, list<int>>}>  $groups
     * @param  list<string>  $columns
     * @param  list<string>  $notes
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function jsonDocument(array $groups, array $columns, int $checked, array $notes, array $filters): array
    {
        $metrics = static function (array $row) use ($columns): array {
            $out = [];
            foreach ($columns as $metric) {
                $out[$metric] = $row['m'][$metric] ?? 0;
            }

            return $out;
        };
        $withSamples = function (array $row) use ($metrics): array {
            $out = ['metrics' => $metrics($row)];
            if ($this->sampleLimit > 0) {
                $out['samples'] = $row['s'];
            }

            return $out;
        };

        $sources = $this->sources;
        ksort($sources);

        return [
            'generated_at' => now()->toIso8601String(),
            'filters' => $filters,
            'checked_cards' => $checked,
            'notes' => $notes,
            'totals' => $withSamples($this->totals),
            'sources' => array_map($withSamples, $sources),
            'groups' => array_values(array_map(
                static fn (array $group): array => ['source' => $group['source'], 'manufacturer' => $group['manufacturer'], ...$withSamples($group)],
                $groups,
            )),
        ];
    }

    /**
     * @param  array<string, array{source: string, manufacturer: string, m: array<string, int>, s: array<string, list<int>>}>  $groups
     */
    private function printTables(array $groups, int $checked, int $top): void
    {
        $headers = array_map(static fn (string $metric): string => self::HEADERS[$metric], self::METRICS);
        $cells = static fn (array $row): array => array_map(static fn (string $metric): int => $row['m'][$metric] ?? 0, self::METRICS);

        $this->info('Źródła cen (wszystkie karty źródła):');
        $sources = $this->sources;
        uasort($sources, static fn (array $a, array $b): int => ($b['m']['kart'] ?? 0) <=> ($a['m']['kart'] ?? 0));
        $this->table(
            ['źródło', ...$headers],
            array_map(static fn (string $source, array $row): array => [$source, ...$cells($row)], array_keys($sources), $sources),
        );

        $score = static fn (array $group): int => array_sum(array_map(
            static fn (string $metric): int => $group['m'][$metric] ?? 0,
            self::PROBLEM_METRICS,
        ));
        $problems = array_filter($groups, static fn (array $group): bool => $score($group) > 0);
        uasort($problems, static fn (array $a, array $b): int => $score($b) <=> $score($a) ?: strcmp($a['source'].$a['manufacturer'], $b['source'].$b['manufacturer']));
        $this->newLine();
        $this->info(sprintf(
            'Grupy z największą sumą „bez poz.” + „sprz.” + „fałsz.” (pierwsze %d z %d; grupy od --min-cards kart ŚOI):',
            min($top, count($problems)),
            count($problems),
        ));
        $this->table(
            ['źródło', 'producent', 'suma', ...$headers],
            array_map(
                static fn (array $group): array => [$group['source'], $group['manufacturer'], $score($group), ...$cells($group)],
                array_slice($problems, 0, $top),
            ),
        );

        $this->newLine();
        $this->info("Razem (sprawdzone karty: {$checked}):");
        $this->table(
            ['miara', 'kart'],
            array_map(fn (string $metric): array => [$metric, $this->totals['m'][$metric] ?? 0], $this->metricColumns()),
        );

        if ($this->sampleLimit > 0) {
            $this->newLine();
            $this->info('Przykładowe karty (numery):');
            foreach ($this->metricColumns() as $metric) {
                $ids = $this->totals['s'][$metric] ?? [];
                if ($ids !== []) {
                    $this->line("  {$metric}: ".implode(', ', $ids));
                }
            }
        }
    }
}
