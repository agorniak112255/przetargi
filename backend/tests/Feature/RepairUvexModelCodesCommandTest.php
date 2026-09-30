<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PrestaProductMatch;
use App\Models\Product;
use App\Models\ProductIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * products:repair-uvex-model-codes — decyzja użytkownika 30.09.2026: kod karty UVEX z rozmiarami bez rozmiaru. Dane
 * syntetyczne w kształcie z produkcji (karty scalone 28–29.09.2026 z kodem pierwszego rozmiaru: 6931/2/35, NB60SZ/9,
 * HECKEL6275/3/36, 1723808, HA2023(L), 89880.09; 6998/8/36 — rozmiar, którego sklep już nie podaje; 9579/7 + 9579/8
 * na jednej karcie).
 */
final class RepairUvexModelCodesCommandTest extends TestCase
{
    use RefreshDatabase;

    private B2bAccount $uvex;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->uvex = B2bAccount::query()->create(['username' => 'jan', 'contractor_code' => 'K1', 'sites' => ['https://izam.system-b2b.pl/'], 'connector' => 'uvex']);
    }

    public function test_preview_changes_nothing_apply_drops_the_size_and_restore_skips_cards_edited_after_the_repair(): void
    {
        $shoes = $this->card('6931/2/35', 'Półbut uvex 2 trend 6931/2', ['6931/2/35', '6931/2/36', '6931/2/37']);
        $gloves = $this->card('NB60SZ/9', 'Rękawice Rubiflex S długie NB60SZ', ['NB60SZ/9', 'NB60SZ/10', 'NB60SZ/11']);
        $heckel = $this->card('HECKEL6275/3/36', 'HECKEL6275/3 OFFROAD S3 SNOW', ['HECKEL6275/3/36', 'HECKEL6275/3/37']);
        // kod karty = rozmiar, którego sklep już nie podaje
        $motion = $this->card('6998/8/36', 'Półbuty Motion Style 6998/8', ['6998/8/38', '6998/8/39']);
        // rozmiar w nazwie pozycji, w kodzie dwie ostatnie cyfry; HexArmor — rozmiar w nawiasie; kombinezon z kropką
        $polo = $this->card('1723808', 'Koszulka polo uvex fire & arc 17238', ['1723808', '1723809'], [
            '1723808' => 'Koszulka polo uvex fire & arc 17238 rozm. XS', '1723809' => 'Koszulka polo uvex fire & arc 17238 rozm. S',
        ]);
        $hex = $this->card('HA2023(L)', 'Rękawice HexArmor Rig Lizard Arctic 2023', ['HA2023(L)', 'HA2023(M)'], [
            'HA2023(L)' => 'Rękawice HexArmor Rig Lizard Arctic 2023 rozmiar 9 (L)', 'HA2023(M)' => 'Rękawice HexArmor Rig Lizard Arctic 2023 rozmiar 8 (M)',
        ]);
        $suit = $this->card('89880.09', 'Kombinezon ochronny uvex typu 3B classic', ['89880.09', '89880.10'], [
            '89880.09' => 'Kombinezon ochronny uvex typu 3B classic rozm. S', '89880.10' => 'Kombinezon ochronny uvex typu 3B classic rozm. M',
        ]);
        // te same końcówki bez rozmiaru w nazwie (kolory okularów) — to nie karta rozmiarów
        $glasses = $this->card('2600.010', 'Okulary uvex K JUNIOR', ['2600.010', '2600.011']);
        // pozycja pojedyncza
        $cleaner = $this->card('9970.005', 'Pojemnik mini 9970.005', ['9970.005']);

        $this->artisan('products:repair-uvex-model-codes', ['--account' => $this->uvex->id])
            ->expectsOutputToContain('do zmiany kodu: 7')
            ->expectsOutputToContain('Podgląd — uruchom z --apply')
            ->assertSuccessful();
        $this->assertSame('6931/2/35', $shoes->fresh()?->sku);

        $backup = storage_path('framework/testing/uvex-model-codes.json');
        @unlink($backup);
        $this->artisan('products:repair-uvex-model-codes', ['--account' => $this->uvex->id, '--apply' => true, '--backup' => $backup])
            ->expectsOutputToContain('Poprawiono 7 kart.')
            ->assertSuccessful();

        $this->assertSame(
            ['6931/2', 'NB60SZ', 'HECKEL6275/3', '6998/8', '17238', 'HA2023', '89880', '2600.010', '9970.005'],
            array_map(static fn (Product $p): ?string => $p->fresh()?->sku, [$shoes, $gloves, $heckel, $motion, $polo, $hex, $suit, $glasses, $cleaner]),
        );
        // nazwa, kody pozycji u dostawcy i identyfikatory zostają dosłownie
        $this->assertSame('Rękawice Rubiflex S długie NB60SZ', $gloves->fresh()?->name);
        $this->assertSame(['NB60SZ/10', 'NB60SZ/11', 'NB60SZ/9'], B2bProductLink::query()->where('product_id', $gloves->id)->orderBy('remote_sku')->pluck('remote_sku')->all());
        $this->assertSame(['NB60SZ/10', 'NB60SZ/11', 'NB60SZ/9'], ProductIdentifier::query()->where('product_id', $gloves->id)->orderBy('value')->pluck('value')->all());

        // drugi przebieg: nic do zmiany
        $this->artisan('products:repair-uvex-model-codes', ['--account' => $this->uvex->id])
            ->expectsOutputToContain('Nic do zapisania.')
            ->assertSuccessful();

        // przywracanie omija kartę, której kod zmieniono po naprawie
        $heckel->fresh()?->update(['sku' => 'HECKEL-RECZNIE']);
        $this->artisan('products:repair-uvex-model-codes', ['--restore' => $backup])
            ->expectsOutputToContain('Przywrócono 6 kart')
            ->expectsOutputToContain('#'.$heckel->id.' (kod karty zmienił się od naprawy)')
            ->assertSuccessful();
        $this->assertSame(
            ['6931/2/35', 'NB60SZ/9', 'HECKEL-RECZNIE', '6998/8/36', '1723808', 'HA2023(L)', '89880.09'],
            array_map(static fn (Product $p): ?string => $p->fresh()?->sku, [$shoes, $gloves, $heckel, $motion, $polo, $hex, $suit]),
        );
        @unlink($backup);
    }

    public function test_taken_code_two_models_foreign_links_presta_and_manual_codes_are_left_alone(): void
    {
        $other = B2bAccount::query()->create(['username' => 'inne', 'sites' => ['https://inne.example/'], 'connector' => 'p4s']);
        // kod modelu ma już inna karta (np. dystrybutora)
        $foreign = Product::query()->create(['sku' => '8430/2', 'name' => 'Półbuty 8430/2 u dystrybutora', 'manufacturer' => 'UVEX']);
        $taken = $this->card('8430/2/39', 'Półbuty ochronne uvex 1 business 8430/2', ['8430/2/39', '8430/2/40']);
        // dwa modele na jednej karcie
        $mixed = $this->card('9579/7/41', 'Wkładki 9579', ['9579/7/41', '9579/7/42', '9579/8/35']);
        // dawny podział według ceny: dwie karty jednego modelu
        $split38 = $this->card('6935/2/38', 'Trzewik uvex 2 trend 6935/2', ['6935/2/38', '6935/2/41']);
        $split39 = $this->card('6935/2/39', 'Trzewik uvex 2 trend 6935/2', ['6935/2/39', '6935/2/40']);
        // karta z powiązaniem innego konta
        $shared = $this->card('6500/2/35', 'Sandały UVEX 2 6500/2', ['6500/2/35', '6500/2/36']);
        B2bProductLink::query()->create(['b2b_account_id' => $other->id, 'remote_id' => 'x1', 'product_id' => $shared->id, 'remote_sku' => '65002', 'remote_name' => 'Sandały uvex']);
        // karta dopasowana do PrestaShop
        $presta = $this->card('6501/2/35', 'Półbuty UVEX 2 6501/2', ['6501/2/35', '6501/2/36']);
        PrestaProductMatch::query()->create(['product_id' => $presta->id, 'presta_id' => 77, 'method' => 'sku', 'score' => 100, 'status' => PrestaProductMatch::STATUS_APPLIED]);
        // kod nadany ręcznie
        $manual = $this->card('TREND-6937', 'Półbut uvex 2 trend 6937/2', ['6937/2/36', '6937/2/37']);

        $backup = storage_path('framework/testing/uvex-model-codes-conflicts.json');
        @unlink($backup);
        $this->artisan('products:repair-uvex-model-codes', ['--account' => $this->uvex->id, '--apply' => true, '--backup' => $backup])
            ->expectsOutputToContain('kod 8430/2 zajęty przez #'.$foreign->id)
            ->expectsOutputToContain('kody pozycji bez wspólnego kodu modelu (9579/7/41, 9579/7/42, 9579/8/35) — kod zostaje')
            ->expectsOutputToContain('ten sam kod 6935/2 na kartach #'.$split38->id.', #'.$split39->id.' — najpierw Scal rozmiary')
            ->expectsOutputToContain('pominięta: powiązania innych kont B2B (#'.$other->id.')')
            ->expectsOutputToContain('pominięta: karta dopasowana do PrestaShop')
            ->expectsOutputToContain('kod karty nie jest kodem rozmiaru 6937/2 — kod zostaje')
            ->expectsOutputToContain('Nic do zapisania.')
            ->assertSuccessful();

        $this->assertSame(
            ['8430/2/39', '9579/7/41', '6935/2/38', '6935/2/39', '6500/2/35', '6501/2/35', 'TREND-6937', '8430/2'],
            array_map(static fn (Product $p): ?string => $p->fresh()?->sku, [$taken, $mixed, $split38, $split39, $shared, $presta, $manual, $foreign]),
        );
        $this->assertFileDoesNotExist($backup);
    }

    public function test_account_must_exist_and_be_a_uvex_account(): void
    {
        $other = B2bAccount::query()->create(['username' => 'inne', 'sites' => ['https://inne.example/'], 'connector' => 'tegro']);

        $this->artisan('products:repair-uvex-model-codes')->assertFailed();
        $this->artisan('products:repair-uvex-model-codes', ['--account' => 999])->expectsOutputToContain('Nie ma konta B2B numer 999.')->assertFailed();
        $this->artisan('products:repair-uvex-model-codes', ['--account' => $other->id])->expectsOutputToContain('nie jest kontem UVEX')->assertFailed();
    }

    /**
     * Karta konta UVEX z pozycjami (kod pozycji = remote_id = remote_sku, jak w łączniku; nazwa pozycji — z $names
     * albo nazwa karty).
     *
     * @param  list<string>  $codes
     * @param  array<string, string>  $names
     */
    private function card(string $sku, string $name, array $codes, array $names = []): Product
    {
        $product = Product::query()->create(['sku' => $sku, 'name' => $name, 'manufacturer' => 'UVEX']);
        foreach ($codes as $code) {
            B2bProductLink::query()->create([
                'b2b_account_id' => $this->uvex->id, 'remote_id' => $code, 'product_id' => $product->id,
                'remote_sku' => $code, 'remote_name' => $names[$code] ?? $name, 'manufacturer' => 'UVEX',
            ]);
            ProductIdentifier::query()->create([
                'product_id' => $product->id, 'source_key' => 'b2b:'.$this->uvex->id, 'b2b_account_id' => $this->uvex->id,
                'position_key' => $code, 'type' => ProductIdentifier::TYPE_MANUFACTURER_CODE, 'value' => $code,
                'source_field' => 'Kod', 'variant_label' => null,
            ]);
        }

        return $product;
    }
}
