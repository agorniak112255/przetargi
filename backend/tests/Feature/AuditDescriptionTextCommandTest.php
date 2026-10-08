<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditDescriptionTextCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_flags_audit_cases_and_writes_csv_without_changing_products(): void
    {
        $harpon = $this->card('34330018', 'MAPA', 'Rękawice robocze MAPA HARPON 330 przeznaczone są do ochrony rąk w środowisku suchym. '
            .'Produkt należy do kategorii PPE (obuwie, rękawice, odzież), a tekst opisuje właściwości rękawic, co jest zgodne z wymaganiami. '
            .'Rękawice wykonane są z naturalnego lateksu.');
        $tape = $this->card('TP010002', 'Coba', 'COBAtape to samoprzylepna taśma podłogowa z PVC. Grubość powłoki: 15 µm. Pozostałości rozpuszczalnika:');
        $bandage = $this->card('51011011', 'CEDERROTH', 'Bandaż pasuje do stacji pierwszej pomocy Cederroth First Aid Station. o symbolu 51011011 ma wymiary 6 cm szerokości.');
        $hyflex = $this->card('11280180-N', 'Ansell', 'Rękawice HyFlex 11-280 do prac precyzyjnych. Produkt spełnia normy EN ISO 13999-1 (klasa D) oraz ANSI/ISEA 105-2024 (klasa A4).',
            ['norms' => ['EN ISO 13999-1 (klasa D)', 'ANSI/ISEA 105-2024 (klasa A4)'], 'source_urls' => ['https://www.ansell.com/x']]);
        $oslo = $this->card('PRBOSLO108', 'Bolle', 'Męskie okulary ochronne Bolle OSLO z soczewkami z poliwęglanu.', null, 'ATEX HAZARDOUS AREA / ATMOSPHERE GROUP');
        $clean = $this->card('3131X', 'MAPA', 'Rękawice z lateksu do prac ogólnych. Spełniają normę EN 388 3131X.', ['norms' => ['EN 388 3131X']]);
        $before = Product::query()->orderBy('id')->get()->map->getAttributes()->all();
        $out = $this->tempPath();

        $this->artisan('products:audit-description-text', ['--all' => true, '--out' => $out])
            ->expectsOutputToContain('Zapisano: '.$out)
            ->expectsOutputToContain('Kart: 6, z flagą: 5.')
            ->assertSuccessful();

        $this->assertSame($before, Product::query()->orderBy('id')->get()->map->getAttributes()->all());
        $rows = $this->rows($out);
        $this->assertSame(['id', 'sku', 'producent', 'flaga', 'fragment', 'opis_z'], $rows[0]);
        $flags = [];
        foreach (array_slice($rows, 1) as $row) {
            $flags[$row[0]][] = $row[3];
        }
        $this->assertSame(['prompt_echo'], $flags[(string) $harpon->id]);
        $this->assertSame(['unfinished_tail'], $flags[(string) $tape->id]);
        $this->assertSame(['paragraph_lowercase_start'], $flags[(string) $bandage->id]);
        $this->assertSame(['norms_invalid'], $flags[(string) $hyflex->id]);
        $this->assertSame(['norms_invalid'], $flags[(string) $oslo->id]);
        $this->assertArrayNotHasKey((string) $clean->id, $flags);
        $tapeRow = array_values(array_filter($rows, static fn (array $r): bool => $r[0] === (string) $tape->id))[0];
        $this->assertSame('Pozostałości rozpuszczalnika:', $tapeRow[4]);
        $hyflexRow = array_values(array_filter($rows, static fn (array $r): bool => $r[0] === (string) $hyflex->id))[0];
        $this->assertStringContainsString('EN ISO 13999', $hyflexRow[4]);
        $this->assertSame('wzbogacanie', $hyflexRow[5]);
        $this->assertSame('inny', $tapeRow[5]);
    }

    public function test_price_list_and_manufacturer_filters(): void
    {
        $a = $this->card('TP010002', 'Coba', 'COBAtape to taśma podłogowa. Pozostałości rozpuszczalnika:');
        $b = $this->card('TP030002', 'Coba', 'COBAtape czerwona to taśma podłogowa. Pozostałość rozpuszczalnika:');
        $this->card('34852019', 'MAPA', 'Rękawice EXONIT 852 chronią grzbiet dłoni. Ochraniacze rozpraszają energię (średnia siła uderzenia');
        $list = PriceList::query()->create([
            'original_filename' => 'coba.xlsx', 'manufacturer' => 'Coba', 'version' => '2026',
            'rows_total' => 1, 'products_created' => 1, 'product_ids' => [$a->id],
        ]);

        $this->artisan('products:audit-description-text', ['--price-list' => $list->id])
            ->expectsOutputToContain($a->id.' | TP010002 | Coba | unfinished_tail')
            ->doesntExpectOutputToContain('TP030002')
            ->doesntExpectOutputToContain('34852019')
            ->expectsOutputToContain('Kart: 1, z flagą: 1.')
            ->assertSuccessful();

        $this->artisan('products:audit-description-text', ['--manufacturer' => 'coba'])
            ->expectsOutputToContain($b->id.' | TP030002 | Coba | unfinished_tail')
            ->doesntExpectOutputToContain('34852019')
            ->expectsOutputToContain('Kart: 2, z flagą: 2.')
            ->assertSuccessful();
    }

    /** Wspólna lista skrótów (ProductDescriptionText::ABBREVIATIONS): „dł. całkowitej”, „szer. min.” to nie sklejone wiersze. */
    public function test_period_after_shared_abbreviation_before_lowercase_is_not_flagged(): void
    {
        $tape = $this->card('TO-75', 'Coba', 'Taśma ostrzegawcza o dł. całkowitej 100 m i szer. min. 75 mm, kolor biało-czerwony.');
        $glued = $this->card('51011011', 'CEDERROTH', 'Bandaż do stacji First Aid Station. o symbolu 51011011 ma wymiary 6 cm szerokości.');

        $this->artisan('products:audit-description-text', ['--all' => true])
            ->doesntExpectOutputToContain($tape->id.' | TO-75')
            ->expectsOutputToContain($glued->id.' | 51011011 | CEDERROTH | paragraph_lowercase_start')
            ->expectsOutputToContain('Kart: 2, z flagą: 1.')
            ->assertSuccessful();
    }

    public function test_requires_a_scope(): void
    {
        $this->artisan('products:audit-description-text')
            ->expectsOutputToContain('Podaj --price-list=<numer>, --manufacturer=<nazwa> albo --all.')
            ->assertFailed();
    }

    /** @param  array<string, mixed>|null  $payload */
    private function card(string $sku, string $manufacturer, string $description, ?array $payload = null, ?string $norms = null): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $manufacturer.' '.$sku, 'manufacturer' => $manufacturer,
            'description' => $description, 'norms' => $norms, 'enrichment_payload' => $payload,
        ]);
    }

    private function tempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'audit-text-');
        $this->files[] = $path;

        return $path;
    }

    /** @return list<list<string>> */
    private function rows(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_map(static fn (string $l): array => str_getcsv((string) preg_replace('/^\xEF\xBB\xBF/', '', $l), ';'), $lines);
    }
}
