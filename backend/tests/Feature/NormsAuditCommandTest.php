<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\NormsAuditCommand;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Support\ManufacturerNormFacts;
use App\Support\RequirementCheck\En388Code;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Miara norm (plan norm 23.09.2026, etap 0): podział wzmianek EN 388 na kod / zapis słowny / brak poziomów, dwa
 * wydania normy odróżnione od sprzeczności, kod inny niż u producenta, wspólny EAN dystrybutora — i ani jednego zapisu.
 */
final class NormsAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_classifies_en388_readings_per_card(): void
    {
        // EN 388:2003 i EN 388:2016 to dwie prawdziwe wartości wyrobu, nie sprzeczność
        $editions = $this->card('Rękawice ochronne powlekane A', 'Ansell', [
            'description' => 'Rękawice zgodne z EN 388:2003 4542 oraz EN 388:2016 4X42C.',
        ]);
        // tabelka i lista w tym samym wydaniu różnią się na pozycji przecięcia
        $conflict = $this->card('Rękawice ochronne powlekane B', 'Ansell', [
            'shop_fields_summary' => "Parametry\nnorma: EN 388:2016 2122X",
            'enrichment_payload' => ['norms' => ['EN 388:2016 2132X']],
        ]);
        $bare = $this->card('Rękawice ochronne powlekane C', 'Ansell', [
            'description' => 'Rękawice spełniają EN 388 (ochrona mechaniczna).',
        ]);
        $worded = $this->card('Rękawice ochronne powlekane D', 'Ansell', [
            'description' => 'EN 388: ścieranie 4, rozdzieranie 2, przekłucie 1',
        ]);
        // pełny kod obok słownego odczytu dwóch pozycji
        $partial = $this->card('Rękawice ochronne powlekane F', 'Ansell', [
            'description' => 'Rękawice EN 388 4X42C.',
            'shop_fields_summary' => 'EN 388: ścieranie 4, rozdzieranie 2',
        ]);

        $report = $this->runJson(['--min-cards' => 0, '--samples' => 10]);
        $samples = $report['totals']['samples'];
        $in = static fn (string $metric): array => $samples[$metric] ?? [];

        $this->assertContains($editions->id, $in('en388_inne_wydania'));
        $this->assertNotContains($editions->id, $in('en388_sprzecznosc'));
        $this->assertContains($editions->id, $in('en388_kod'));

        $this->assertContains($conflict->id, $in('en388_sprzecznosc'));
        $this->assertNotContains($conflict->id, $in('en388_inne_wydania'));

        $this->assertContains($bare->id, $in('en388_bez_poziomow'));
        $this->assertNotContains($bare->id, $in('en388_kod'));

        $this->assertContains($worded->id, $in('en388_slownie'));
        $this->assertNotContains($worded->id, $in('en388_kod'));
        $this->assertNotContains($worded->id, $in('en388_bez_poziomow'));

        $this->assertNotContains($editions->id, $in('poziomy_falszywe'));

        $this->assertContains($partial->id, $in('en388_szczatkowe'));
        $this->assertNotContains($partial->id, $in('en388_sprzecznosc'));

        // każda wzmianka trafia dokładnie do jednej z trzech grup
        $totals = $report['totals']['metrics'];
        $this->assertSame(5, $totals['en388_wzmianka']);
        $this->assertSame(
            $totals['en388_wzmianka'],
            $totals['en388_kod'] + $totals['en388_slownie'] + $totals['en388_bez_poziomow'],
        );
        $this->assertSame(1, $totals['en388_bez_poziomow']);
        $this->assertSame(1, $totals['en388_slownie']);
    }

    public function test_levels_that_are_no_code_of_the_card_are_flagged(): void
    {
        // poziomy z porównania wprost, nie z BhpAttributeNormalizer — miara ma łapać wartości starego czytnika
        $readings = ['opis' => En388Code::allIn('Rękawice EN 388:2016 4X42C.'), 'tabelka' => [], 'lista' => [], 'producent' => []];
        $metrics = static fn (?string $poziomy): array => NormsAuditCommand::en388Metrics($readings, true, $poziomy);

        $this->assertContains('poziomy_falszywe', $metrics('2016'));
        $this->assertContains('poziomy_falszywe', $metrics('211'));
        $this->assertContains('poziomy_falszywe', $metrics('4X43C'));
        $this->assertNotContains('poziomy_falszywe', $metrics('4X42C'));
        // ten sam kod rozstrzelony albo z dopiskiem wydania to nie fałsz
        $this->assertNotContains('poziomy_falszywe', $metrics('4 X 4 2 C'));
        $this->assertNotContains('poziomy_falszywe', $metrics('4X42C (2016)'));
        $this->assertContains('poziomy_brak_przy_kodzie', $metrics(null));
        $this->assertNotContains('poziomy_brak_przy_kodzie', $metrics('4X42C'));

        // zapis słowny nie jest kodem: brak poziomów przy samym zapisie słownym to nie „brak przy kodzie”
        $wordedOnly = ['opis' => En388Code::allIn('EN 388: ścieranie 4, rozdzieranie 2'), 'tabelka' => [], 'lista' => [], 'producent' => []];
        $this->assertSame(['en388_wzmianka', 'en388_slownie'], NormsAuditCommand::en388Metrics($wordedOnly, true, null));
    }

    public function test_price_sources_producer_code_and_shared_ean(): void
    {
        $atg = B2bAccount::query()->create(['username' => 'atg', 'password' => 'x', 'sites' => ['atg-glovesolutions.com'], 'connector' => 'atg']);
        $p4s = B2bAccount::query()->create(['username' => 'p4s', 'password' => 'x', 'sites' => ['p4s.pl'], 'connector' => 'p4s']);

        $own = $this->card('Rękawice ochronne MaxiFlex 34-874', 'ATG', [
            'description' => 'Rękawice EN 388 4121A.',
            'manufacturer_norms' => ManufacturerNormFacts::build(
                [['label' => 'EN 388:2016', 'value' => '3121A']],
                'atg',
                'ATG',
                'https://www.atg-glovesolutions.com/maxiflex-34-874',
            ),
        ]);
        $distributor = $this->card('Rękawice MaxiFlex Ultimate 34-874 rozm. 9', 'ATG', [
            'description' => 'Rękawice robocze.',
        ]);
        $file = $this->card('Rękawice ochronne skórzane', 'Canis', [
            'description' => 'Rękawice EN 388 3122X.',
        ]);
        $this->link($atg, $own);
        $this->link($p4s, $distributor);
        PriceList::query()->create([
            'original_filename' => 'canis.xlsx',
            'manufacturer' => 'Canis',
            'version' => '2026',
            'rows_total' => 1,
            'products_created' => 1,
            'product_ids' => [$file->id],
        ]);
        foreach ([[$own, 'b2b:'.$atg->id], [$distributor, 'b2b:'.$p4s->id]] as [$card, $sourceKey]) {
            ProductIdentifier::query()->create([
                'product_id' => $card->id,
                'source_key' => $sourceKey,
                'position_key' => (string) $card->sku,
                'type' => ProductIdentifier::TYPE_EAN,
                'value' => '5901234567890',
                'normalized' => '5901234567890',
            ]);
        }

        $report = $this->runJson(['--min-cards' => 0, '--samples' => 5]);

        $this->assertSame(['b2b:atg', 'b2b:p4s', 'plik:Canis'], array_keys($report['sources']));
        $groups = [];
        foreach ($report['groups'] as $group) {
            $groups[$group['source'].' | '.$group['manufacturer']] = $group;
        }
        $this->assertSame(['b2b:atg | ATG', 'b2b:p4s | ATG', 'plik:Canis | Canis'], array_keys($groups));

        $ownMetrics = $groups['b2b:atg | ATG']['metrics'];
        $this->assertSame(1, $ownMetrics['normy_producenta']);
        $this->assertSame(1, $ownMetrics['normy_producenta:atg']);
        $this->assertSame(1, $ownMetrics['kod_inny_niz_producent']);
        $this->assertSame(1, $ownMetrics['en388_sprzecznosc']);
        // poziomy biorą kod producenta — ten jest odczytem karty, więc nie są fałszywe
        $this->assertSame(0, $ownMetrics['poziomy_falszywe']);
        $this->assertSame(0, $ownMetrics['karta_dystrybutora']);

        $distributorGroup = $groups['b2b:p4s | ATG'];
        $this->assertSame(1, $distributorGroup['metrics']['karta_dystrybutora']);
        $this->assertSame(1, $distributorGroup['metrics']['ean_wspolny_z_producentem']);
        $this->assertSame([$distributor->id], $distributorGroup['samples']['ean_wspolny_z_producentem']);

        $this->assertSame(0, $groups['plik:Canis | Canis']['metrics']['kod_inny_niz_producent']);

        // --source=b2b:p4s zawęża do kart z tym kontem, --manufacturer do producenta
        $this->assertSame(1, $this->runJson(['--source' => 'b2b:p4s'])['checked_cards']);
        $this->assertSame(2, $this->runJson(['--manufacturer' => 'ATG'])['checked_cards']);
        $this->assertSame(1, $this->runJson(['--source' => 'plik:Canis'])['checked_cards']);
    }

    public function test_writes_nothing_but_the_csv_file(): void
    {
        $account = B2bAccount::query()->create(['username' => 'p4s', 'password' => 'x', 'sites' => ['p4s.pl'], 'connector' => 'p4s']);
        $card = $this->card('Rękawice ochronne powlekane', 'Ansell', [
            'description' => 'Rękawice EN 388:2016 4X42C, EN 388 (ochrona mechaniczna).',
        ]);
        $this->card('Rękawice ochronne nitrylowe', 'Ansell', [
            'shop_fields_summary' => 'EN 388:2016 2122X',
            'enrichment_payload' => ['norms' => ['EN 388:2016 2132X'], 'attributes' => ['poziomy_en388' => '211']],
        ]);
        $this->link($account, $card);

        $tables = ['products', 'b2b_accounts', 'b2b_product_links', 'price_lists', 'product_identifiers'];
        $snapshot = static fn (): array => array_map(
            static fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all(),
            array_combine($tables, $tables),
        );
        $before = $snapshot();

        $csv = storage_path('app/norms-audit-test.csv');
        @unlink($csv);
        $this->travel(5)->minutes();
        $this->artisan('norms:audit', ['--csv' => $csv, '--samples' => 3])
            ->expectsOutputToContain('Źródła cen')
            ->expectsOutputToContain('Razem (sprawdzone karty: 2)')
            ->expectsOutputToContain('Przykładowe karty')
            ->assertSuccessful();
        // drugi przebieg w tym samym procesie liczy od zera
        $this->assertSame(2, $this->runJson([])['totals']['metrics']['kart']);

        $this->assertSame($before, $snapshot());
        $rows = array_map(static fn (string $line): array => str_getcsv($line, ';'), file($csv, FILE_IGNORE_NEW_LINES) ?: []);
        @unlink($csv);
        $this->assertSame(['zrodlo_cen', 'producent', 'kart', 'soi'], array_slice($rows[0], 0, 4));
        $this->assertCount(3, $rows);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function runJson(array $options): array
    {
        $this->assertSame(0, Artisan::call('norms:audit', ['--json' => true, ...$options]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($report);

        return $report;
    }

    private function link(B2bAccount $account, Product $product): void
    {
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id,
            'remote_id' => 'r-'.$product->id,
            'product_id' => $product->id,
            'remote_sku' => $product->sku,
            'remote_name' => $product->name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function card(string $name, string $manufacturer, array $fields): Product
    {
        return Product::query()->create([
            'sku' => $name,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'category' => 'Rękawice ochronne',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            ...$fields,
        ]);
    }
}
