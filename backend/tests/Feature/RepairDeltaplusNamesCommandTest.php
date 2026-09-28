<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Karty Delta Plus z kodem w h1 dostały przed kod nagłówek kategorii (sektor, zastosowanie) albo krótki opis; decyzja
 * właściciela 28.09.2026 — przedrostek = OPIS z cennika publicznego do pierwszego przecinka. Polecenie naprawia tylko
 * nietknięte nazwy automatu kart konta Delta Plus. Cennik z pliku (--file), bez witryny. Dane SYNTETYCZNE.
 */
final class RepairDeltaplusNamesCommandTest extends TestCase
{
    use RefreshDatabase;

    private B2bAccount $account;

    private string $priceList;

    /** @var list<string> */
    private array $backups = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->account = B2bAccount::query()->create([
            'username' => 'handel@example.com',
            'password' => 'haslo',
            'sites' => ['https://www.deltaplus.eu/'],
            'connector' => 'deltaplus',
        ]);
        $this->priceList = (string) tempnam(sys_get_temp_dir(), 'dp-test-');
        file_put_contents($this->priceList, self::xlsx([
            [null, null, null, 'CENNIK PUBLICZNY 01/01/2026'],
            ['MODEL', 'STATUS', 'KOLOR', 'ILOŚĆ W KARTONIE', 'MIN ZAM.', 'OPIS', 'ROZMIARY', 'CENA PLN NETTO 01/2026'],
            ['OCHRONA RĄK'],
            ['CT402', '', 'BRĄZOWY', 120, 12, 'RĘKAWICE ZE SKÓRY LICOWEJ KOZIEJ, STRONA GRZBIETOWA Z DRELICHU BAWEŁNIANEGO', '7-10', 21.0],
            ['DC103', '', 'SZARY', 120, 12, 'RĘKAWICE DOKER Z DWOINY BYDLĘCEJ', '10', 9.0],
            ['CA515R', '', 'SZARY', 60, 6, 'RĘKAWICE SPAWALNICZE Z DWOINY BYDLĘCEJ, 35 CM', '10', 19.0],
            ['OCHRONA OCZU'],
            ['HEKLA2', '', 'BEZBARWNY', 10, 1, 'NADOKULARY Z POLIWĘGLANU, AR*, UV400', 'Uniwersalny', 30.0],
            ['VV733', '', 'BIAŁY', 10, 1, 'PÓŁBUTY, S1P', '36-47', 80.0],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->priceList);
        foreach ($this->backups as $backup) {
            @unlink($backup);
        }
        parent::tearDown();
    }

    public function test_preview_lists_the_renames_and_writes_nothing(): void
    {
        $cards = $this->cards();

        $this->artisan('products:repair-deltaplus-names', ['--account' => $this->account->id, '--file' => $this->priceList])
            ->expectsOutputToContain('nazwy z kolumny OPIS dla 5 modeli')
            ->expectsOutputToContain('RĘKAWICE ZE SKÓRY LICOWEJ KOZIEJ CT402')
            ->expectsOutputToContain('brak jednoznacznego OPIS modelu DPVE733')
            ->expectsOutputToContain('Do zmiany: 2; automatyczna nazwa bez OPIS w cenniku (zostaje): 1; nie przedrostek automatu')
            ->expectsOutputToContain('Podgląd')
            ->assertSuccessful();

        foreach ($cards as $card) {
            $this->assertSame($card->name, $card->fresh()?->name);
        }
    }

    public function test_apply_renames_category_and_short_description_prefixes_only(): void
    {
        $cards = $this->cards();
        $blobBefore = (string) $cards['category']->search_blob;
        $backup = $this->backupPath('apply');

        $this->artisan('products:repair-deltaplus-names', [
            '--account' => $this->account->id, '--file' => $this->priceList, '--backup' => $backup, '--apply' => true,
        ])
            ->expectsOutputToContain('Zapisano 2 nazw')
            ->assertSuccessful();

        $this->assertSame('RĘKAWICE ZE SKÓRY LICOWEJ KOZIEJ CT402', $cards['category']->fresh()?->name);
        $this->assertSame('RĘKAWICE DOKER Z DWOINY BYDLĘCEJ DC103', $cards['short']->fresh()?->name);
        // zapis przez model: indeks tekstowy przeliczony z nowej nazwy
        $this->assertNotSame($blobBefore, (string) $cards['category']->fresh()?->search_blob);
        // nazwa ręczna, h1 strony, automat bez OPIS i karta innego konta — bez zmian
        $this->assertSame('Rękawice monterskie CA515R', $cards['manual']->fresh()?->name);
        $this->assertSame('APOLLON VV733', $cards['h1']->fresh()?->name);
        $this->assertSame('Kształtowanie krajobrazu DPVE733', $cards['no_opis']->fresh()?->name);
        $this->assertSame('Ochrona oczu HEKLA2', $cards['other_account']->fresh()?->name);
        $this->assertFileExists($backup);

        // drugi przebieg nie ma nic do zrobienia
        $this->artisan('products:repair-deltaplus-names', ['--account' => $this->account->id, '--file' => $this->priceList])
            ->expectsOutputToContain('już z OPIS: 2')
            ->expectsOutputToContain('Nic do zapisania.')
            ->assertSuccessful();
    }

    public function test_restore_brings_back_old_names_except_a_card_edited_after_the_repair(): void
    {
        $cards = $this->cards();
        $backup = $this->backupPath('restore');
        $this->artisan('products:repair-deltaplus-names', [
            '--account' => $this->account->id, '--file' => $this->priceList, '--backup' => $backup, '--apply' => true,
        ])->assertSuccessful();
        // człowiek poprawił nazwę po naprawie
        $cards['short']->fresh()?->update(['name' => 'Rękawice doker DC103']);

        $this->artisan('products:repair-deltaplus-names', ['--restore' => $backup])
            ->expectsOutputToContain('Przywrócono 1 nazw')
            ->expectsOutputToContain('Pominięte (karta usunięta albo nazwa zmieniona po naprawie): #'.$cards['short']->id)
            ->assertSuccessful();

        $this->assertSame('Prace w środowisku zaolejonym i tłustym CT402', $cards['category']->fresh()?->name);
        $this->assertSame('Rękawice doker DC103', $cards['short']->fresh()?->name);
    }

    public function test_account_that_is_not_delta_plus_or_missing_price_list_file_fails_without_changes(): void
    {
        $cards = $this->cards();
        $other = B2bAccount::query()->create(['username' => 'inny', 'password' => 'x', 'sites' => ['artra.pl'], 'connector' => 'artra']);

        $this->artisan('products:repair-deltaplus-names', ['--account' => $other->id, '--file' => $this->priceList, '--apply' => true])
            ->expectsOutputToContain("Konto {$other->id} nie jest kontem Delta Plus")
            ->assertFailed();
        $this->artisan('products:repair-deltaplus-names', ['--file' => $this->priceList, '--apply' => true])
            ->expectsOutputToContain('Podaj konto B2B Delta Plus')
            ->assertFailed();
        $this->artisan('products:repair-deltaplus-names', [
            '--account' => $this->account->id, '--file' => $this->priceList.'-brak', '--apply' => true,
        ])
            ->expectsOutputToContain('Nie ma pliku cennika')
            ->assertFailed();

        $this->assertSame('Prace w środowisku zaolejonym i tłustym CT402', $cards['category']->fresh()?->name);
    }

    /**
     * @return array<string, Product>
     */
    private function cards(): array
    {
        $other = B2bAccount::query()->create(['username' => 'dystrybutor', 'password' => 'x', 'sites' => ['b2b.example.com'], 'connector' => 'artra']);

        return [
            // przedrostek = nagłówek kategorii (ostatni człon ścieżki, na stronie z U+200B); dwie wersje = dwa powiązania
            'category' => $this->card('CT402', 'Prace w środowisku zaolejonym i tłustym CT402',
                "Ochrona rąk > Rękawice > \u{200B}Prace w środowisku zaolejonym i tłustym", 'Rękawice skórzane z grzbietem z drelichu', ['CT402BR09', 'CT402BR10']),
            // przedrostek = krótki opis (hasło reklamowe), którym zaczyna się opis karty
            'short' => $this->card('DC103', 'Pracujemy jak dorośli DC103',
                'Ochrona rąk > Rękawice robocze', "Pracujemy jak dorośli\n\nKomfort\nMiękka dwoina", ['DC103GR10']),
            // nazwa poprawiona przez człowieka — ani kategoria, ani początek opisu
            'manual' => $this->card('CA515R', 'Rękawice monterskie CA515R',
                'Ochrona rąk > Spawalnicze', 'Rękawice spawalnicze z dwoiny', ['CA515R10']),
            // h1 strony z nazwą modelu
            'h1' => $this->card('VV733', 'APOLLON VV733', 'Ochrona stóp > Półbuty', 'Półbuty ochronne S1P', ['VV733BC40']),
            // nazwa automatu, ale modelu nie ma w cenniku
            'no_opis' => $this->card('DPVE733', 'Kształtowanie krajobrazu DPVE733',
                'Ochrona ciała > Kształtowanie krajobrazu', 'Kamizelka', ['DPVE733JA']),
            // nazwa automatu na karcie bez powiązania z kontem Delta Plus
            'other_account' => $this->card('HEKLA2', 'Ochrona oczu HEKLA2', 'Ochrona oczu', 'Nadokulary', ['HEKLA2IN'], $other),
        ];
    }

    /**
     * @param  list<string>  $refs
     */
    private function card(string $sku, string $name, string $category, string $description, array $refs, ?B2bAccount $account = null): Product
    {
        $card = Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => 'Delta Plus', 'category' => $category, 'description' => $description,
            'catalog_price_net' => 20.0, 'discount_percent' => 50, 'purchase_price' => 10.0, 'currency' => 'PLN',
        ]);
        foreach ($refs as $ref) {
            B2bProductLink::query()->create([
                'b2b_account_id' => ($account ?? $this->account)->id, 'remote_id' => $ref, 'product_id' => $card->id,
                'remote_sku' => $ref, 'remote_name' => $name, 'manufacturer' => 'Delta Plus',
            ]);
        }

        return $card;
    }

    private function backupPath(string $name): string
    {
        $path = storage_path('app/repair-backups/test-deltaplus-names-'.$name.'.json');
        @unlink($path);
        $this->backups[] = $path;

        return $path;
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private static function xlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Cennik')->fromArray($rows, null, 'A1', true);
        $path = (string) tempnam(sys_get_temp_dir(), 'deltaplus');
        try {
            (new Xlsx($spreadsheet))->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }
}
