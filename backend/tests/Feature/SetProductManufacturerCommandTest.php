<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cennik dystrybutora bywa podpisany jego nazwą, choć wyrób robi kto inny: „Kombinezon
 * AlphaTec 2000” w cenniku SECURA to wyrób Ansella. Ze złą marką wzbogacanie szuka karty
 * na stronie złego producenta, więc nie znajdzie nic. Polecenie poprawia markę wskazanych
 * kart i niczego nie zgaduje — listę podaje człowiek.
 */
final class SetProductManufacturerCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->card('T5329000', 'Kombinezon AlphaTec 2000 Standard', 'SECURA');
        $this->card('T5328000', 'Kombinezon AlphaTec 1800 Standard', 'SECURA');
        $this->card('S56T0SM0', 'Półmaska SECURA 3000', 'SECURA');
    }

    public function test_preview_changes_nothing(): void
    {
        $this->artisan('products:set-manufacturer', ['--sku' => ['T5329000'], '--to' => 'Ansell'])
            ->expectsOutputToContain('Do zmiany: 1 kart')
            ->assertSuccessful();

        $this->assertSame('SECURA', Product::query()->where('sku', 'T5329000')->value('manufacturer'));
    }

    public function test_apply_sets_the_brand_and_suggests_re_enrichment(): void
    {
        $this->artisan('products:set-manufacturer', [
            '--sku' => ['T5329000', 'T5328000'],
            '--to' => 'Ansell',
            '--apply' => true,
        ])
            ->expectsOutputToContain('Zmieniono markę na Ansell dla 2 kart')
            ->expectsOutputToContain('products:recheck-skus')
            ->assertSuccessful();

        $this->assertSame(
            ['Ansell', 'Ansell'],
            Product::query()->whereIn('sku', ['T5329000', 'T5328000'])->orderBy('sku')->pluck('manufacturer')->all()
        );
    }

    /** Bezpiecznik --from: karta z inną marką niż wskazana nie zostaje ruszona. */
    public function test_from_guard_protects_other_cards(): void
    {
        Product::query()->where('sku', 'T5328000')->update(['manufacturer' => 'Ansell']);

        $this->artisan('products:set-manufacturer', [
            '--sku' => ['T5328000'],
            '--from' => 'SECURA',
            '--to' => 'Ansell',
            '--apply' => true,
        ])
            ->expectsOutputToContain('Żaden z podanych kodów nie ma karty z marką SECURA')
            ->assertFailed();
    }

    /** Marka bez znanych domen producenta to ostrzeżenie, nie cicha zmiana. */
    public function test_unknown_brand_warns(): void
    {
        $this->artisan('products:set-manufacturer', ['--sku' => ['S56T0SM0'], '--to' => 'Marka Bez Strony'])
            ->expectsOutputToContain('nie ma znanych domen producenta')
            ->assertSuccessful();
    }

    public function test_requires_target_brand(): void
    {
        $this->artisan('products:set-manufacturer', ['--sku' => ['T5329000']])
            ->expectsOutputToContain('Podaj nową markę')
            ->assertFailed();
    }

    private function card(string $sku, string $name, string $manufacturer): void
    {
        Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'category' => 'Środki ochrony indywidualnej',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
        ]);
    }
}
