<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CardMatchCandidate;
use App\Models\Product;
use App\Services\Catalog\CardMatchFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * products:match-candidates po kroku 5 (plan „pozycja → karta”): kolumny „Rodzaj” i „Sygnał”, nowe kolumny CSV
 * dopisane na końcu (dotychczasowe bez przesunięcia) i linia pomiaru. CardMatchFinder podmieniony atrapą — reguły
 * planu sprawdza CardMatchPlanTest, tu tylko wydruk wierszy w kształcie z finder'a.
 */
final class MatchCandidatesCommandTest extends TestCase
{
    use RefreshDatabase;

    /** dotychczasowe kolumny CSV — kolejność nie może się zmienić */
    private const OLD_COLUMNS = [
        'status', 'klucz', 'wartosc', 'zrodlo_klucza', 'marka', 'trafione', 'pozycje',
        'dystrybutor_id', 'dystrybutor_sku', 'dystrybutor_nazwa', 'dystrybutor_producent', 'dystrybutor_cena', 'dystrybutor_waluta',
        'producent_id', 'producent_sku', 'producent_nazwa', 'producent_producent', 'producent_cena', 'producent_waluta',
        'karty_konfliktu', 'powod',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->app->instance(CardMatchFinder::class, new class
        {
            /** @return array<string, mixed> */
            public function refresh(): array
            {
                return [
                    'pending' => 2, 'conflict' => 1, 'removed' => 0, 'refreshed_at' => '2026-09-24T06:10:00+00:00',
                    'by_kind' => [
                        'merge' => ['pending' => 1, 'conflict' => 0],
                        'size_merge' => ['pending' => 1, 'conflict' => 0],
                        'split' => ['pending' => 0, 'conflict' => 1],
                    ],
                    'signals' => ['size' => 1, 'color' => 0, 'unknown' => 1],
                ];
            }
        });
    }

    public function test_prints_kind_and_signal_and_appends_plan_columns_to_csv(): void
    {
        $anroTarget = $this->card('IF/016/F/PS', 'Półmaska ANRO IF/016/F/PS', 'ANRO');
        $p4sAnro = $this->card('ZPPV99C', 'Półmaska P4S IF/016/F/PS', 'ANRO');
        CardMatchCandidate::query()->create([
            'source_product_id' => $p4sAnro->id, 'target_product_id' => $anroTarget->id, 'status' => 'pending',
            'kind' => 'merge', 'targets_key' => (string) $anroTarget->id,
            'matched_by' => 'manufacturer_code', 'matched_value' => 'IF016FPS', 'matched_source_key' => 'b2b:2',
            'brand' => 'anro', 'hits' => 1, 'positions' => 1,
        ]);

        $s = $this->card('70001468S', 'Półmaska wielokrotnego użytku 3M™, rozmiar mały, 6100', '3M');
        $m = $this->card('70001468M', 'Półmaska wielokrotnego użytku 3M™, rozmiar średni, 6200', '3M');
        $l = $this->card('70001468L', 'Półmaska wielokrotnego użytku 3M™, rozmiar duży, 6300', '3M');
        $p4s3m = $this->card('6X00', 'Półmaska 3M 6000', '3M');
        CardMatchCandidate::query()->create([
            'source_product_id' => $p4s3m->id, 'target_product_id' => null, 'status' => 'pending',
            'kind' => 'size_merge', 'targets_key' => $s->id.','.$m->id.','.$l->id, 'plan_hash' => str_repeat('a', 40),
            'conflict_product_ids' => [$s->id, $m->id, $l->id],
            'matched_by' => 'manufacturer_code', 'matched_value' => '70001468S', 'matched_source_key' => 'b2b:7',
            'brand' => '3m', 'hits' => 3, 'positions' => 3,
            'plan' => [
                'version' => 1, 'signal' => 'size', 'source_label' => 'B2B P4S', 'same_owner' => true, 'equal_prices' => true,
                'price_differences' => [], 'blockers' => [],
                'positions' => [
                    $this->position('6X00/S', 'p-s', $s->id),
                    $this->position('6X00/M', 'p-m', $m->id),
                    $this->position('6X00/L', 'p-l', $l->id),
                ],
                'suggested' => ['keep_product_id' => $s->id, 'common_name' => 'Półmaska wielokrotnego użytku 3M™', 'sizes' => []],
            ],
        ]);

        $red = $this->card('KL-B-CZ', 'KLODKA B CZERWONA RK', 'ABUS');
        $green = $this->card('KL-B-ZI', 'KLODKA B ZIELONA RK', 'ABUS');
        $distributorLock = $this->card('KLB', 'Kłódka B', 'ABUS');
        CardMatchCandidate::query()->create([
            'source_product_id' => $distributorLock->id, 'target_product_id' => null, 'status' => 'conflict',
            'kind' => 'split', 'targets_key' => $red->id.','.$green->id, 'plan_hash' => str_repeat('b', 40),
            'conflict_product_ids' => [$red->id, $green->id],
            'matched_by' => 'ean', 'matched_value' => '5901234567890', 'matched_source_key' => 'file:12',
            'brand' => 'abus', 'hits' => 1, 'positions' => 3,
            'reason' => 'pozycja KLB-Z wskazuje kilka kart producenta (#'.$red->id.', #'.$green->id.')',
            'plan' => [
                'version' => 1, 'signal' => 'unknown', 'source_label' => 'cennik ABUS', 'same_owner' => true, 'equal_prices' => true,
                'price_differences' => [], 'blockers' => [], 'suggested' => null,
                'positions' => [
                    $this->position('KLB-C', 'k-c', $red->id),
                    ['target_ids' => [$red->id, $green->id]] + $this->position('KLB-Z', 'k-z', null),
                    $this->position(null, 'k-y', null),
                ],
            ],
        ]);

        $csv = storage_path('framework/testing/match-candidates-kind.csv');
        @mkdir(dirname($csv), 0775, true);
        $this->artisan('products:match-candidates', ['--csv' => $csv])
            ->expectsOutputToContain('Rodzaj')
            ->expectsOutputToContain('rozdzielanie')
            ->expectsOutputToContain('Do decyzji: 2 · niepewne: 1')
            ->expectsOutputToContain('Pomiar: rozmiary 1 · kolory 0 · niepewne 1')
            ->assertSuccessful();

        $lines = file($csv, FILE_IGNORE_NEW_LINES) ?: [];
        @unlink($csv);
        $this->assertCount(4, $lines);
        $rows = array_map(static fn (string $line): array => str_getcsv($line, ';', '"', '\\'), $lines);
        // nowe kolumny dopisane na końcu, dotychczasowe na swoich miejscach
        $this->assertSame([...self::OLD_COLUMNS, 'rodzaj', 'sygnal', 'plan_pozycje', 'podpowiedz_nazwy'], $rows[0]);
        $byColumn = array_map(static fn (array $row): array => array_combine($rows[0], $row), array_slice($rows, 1));

        // do decyzji najpierw (marka: 3m przed anro), niepewne na końcu
        [$sizes, $merge, $split] = $byColumn;
        $this->assertSame(['pending', 'size_merge', 'size', '6X00/S→#'.$s->id.'|6X00/M→#'.$m->id.'|6X00/L→#'.$l->id, 'Półmaska wielokrotnego użytku 3M™'],
            [$sizes['status'], $sizes['rodzaj'], $sizes['sygnal'], $sizes['plan_pozycje'], $sizes['podpowiedz_nazwy']]);
        $this->assertSame($s->id.' '.$m->id.' '.$l->id, $sizes['karty_konfliktu']);
        $this->assertSame('', $sizes['producent_id']);

        $this->assertSame(['pending', 'merge', '', '', ''], [$merge['status'], $merge['rodzaj'], $merge['sygnal'], $merge['plan_pozycje'], $merge['podpowiedz_nazwy']]);
        $this->assertSame('manufacturer_code', $merge['klucz']);
        $this->assertSame((string) $anroTarget->id, $merge['producent_id']);

        $this->assertSame(['conflict', 'split', 'unknown', 'KLB-C→#'.$red->id.'|KLB-Z→#'.$red->id.'/#'.$green->id.'|k-y→—', ''],
            [$split['status'], $split['rodzaj'], $split['sygnal'], $split['plan_pozycje'], $split['podpowiedz_nazwy']]);
        $this->assertStringContainsString('wskazuje kilka kart producenta', $split['powod']);

        // niczego nie połączono: 2 karty pary Anro, 3 karty 3M i karta P4S, 2 kłódki ABUS i karta dystrybutora
        $this->assertSame(9, Product::query()->count());
        $this->assertSame(0, CardMatchCandidate::query()->where('status', 'merged')->count());
    }

    private function card(string $sku, string $name, string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => $manufacturer,
            'catalog_price_net' => 10, 'purchase_price' => 8, 'currency' => 'PLN',
        ]);
    }

    /** @return array<string, mixed> */
    private function position(?string $remoteSku, string $positionKey, ?int $targetId): array
    {
        return [
            'source_key' => 'b2b:7', 'source_label' => 'B2B P4S', 'position_key' => $positionKey, 'remote_sku' => $remoteSku,
            'label' => null, 'size_label' => null, 'target_product_id' => $targetId, 'target_ids' => null,
            'matched_by' => 'manufacturer_code', 'matched_value' => $positionKey, 'signal' => 'unknown', 'signal_why' => '',
        ];
    }
}
