<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductShopCard;
use App\Services\B2b\ShopCardNormFacts;
use App\Support\ManufacturerNormFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Plan norm z 23.09.2026, etap 2: tabelka karty w sklepie B2B producenta (Protekt, Polstar, ARTRA, 3M, UVEX) ma wiersz
 * z normami — zapisujemy go jako normy producenta z pochodzeniem, bez nowych zapytań do sklepu. Wartości wierszy
 * w testach są przepisane z zapisów na produkcji (odczyt 23.09.2026).
 */
final class NormsFromShopCardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_norm_rows_become_label_value_pairs_literally(): void
    {
        $facts = static fn (string $name, string $value, array $names): array => ShopCardNormFacts::facts(
            [['section' => 'Sekcja', 'rows' => [['name' => $name, 'value' => $value], ['name' => 'Kod', 'value' => 'EN 999 w kodzie']]]],
            $names,
        );

        $this->assertSame([['label' => 'EN 355', 'value' => null]], $facts('Norma', 'EN 355', ['Norma']));
        $this->assertSame(
            [['label' => 'EN ISO 20345:2022', 'value' => 'S3L FO SR']],
            $facts('norma', 'EN ISO 20345:2022 S3L FO SR', ['norma']),
        );
        $this->assertSame([
            ['label' => 'EN 166:2001', 'value' => null],
            ['label' => 'EN 14594', 'value' => '3B'],
            ['label' => 'EN 12941', 'value' => 'TH4'],
            ['label' => 'EN 398', 'value' => null],
            ['label' => 'EN 12941:1998', 'value' => 'TH3'],
        ], $facts('Spełnione specyfikacje', 'EN 166:2001, EN 14594 3B, EN 12941 TH4, EN 398, EN 12941:1998 TH3, Oznaczenie CE', ['Spełnione specyfikacje']), 'lista 3M bez dopisku o znaku CE');
        $this->assertSame(
            [['label' => 'EN 166', 'value' => '2:F:4'], ['label' => 'EN14594', 'value' => '3A']],
            $facts('Spełnione specyfikacje', 'EN 166:2:F:4, EN14594 3A', ['Spełnione specyfikacje']),
            '„:2” to nie rok wydania — zostaje w wartości',
        );
        $this->assertSame(
            [['label' => 'EN 207', 'value' => 'full protection'], ['label' => 'EN 60825', 'value' => null]],
            $facts('Protection Class / Norm', 'EN 207 full protection, EN 60825', ['Protection Class / Norm']),
        );
        $this->assertSame([], $facts('Materiał', 'EN 355', ['Norma']), 'wiersz o innej nazwie to nie lista norm');
        $this->assertSame([], $facts('Norma', 'brak', ['Norma']), 'wartość bez oznaczenia normy');
    }

    public function test_command_previews_then_stores_and_restores_manufacturer_norms(): void
    {
        Queue::fake();
        $account = $this->account('protekt', 'https://www.protekt.com.pl/');
        $card = $this->card($account, 'P-1', 'PROTEKT', [['name' => 'Norma', 'value' => 'EN 355'], ['name' => 'Norma', 'value' => 'EN 360']]);

        $this->artisan('norms:from-shop-cards')->assertSuccessful();
        $this->assertNull($card->fresh()->manufacturer_norms, 'podgląd niczego nie zapisuje');

        $backup = storage_path('app/testing/norms-backup-'.uniqid().'.json');
        $this->artisan('norms:from-shop-cards', ['--apply' => true, '--backup' => $backup])->assertSuccessful();
        $column = $card->fresh()->manufacturer_norms;
        $this->assertSame('protekt', $column['source']['connector'] ?? null);
        $this->assertSame('https://example.test/P-1', $column['source']['url'] ?? null);
        $this->assertSame(['EN 355', 'EN 360'], ManufacturerNormFacts::norms($column));

        $this->artisan('norms:from-shop-cards', ['--restore' => $backup])->assertSuccessful();
        $this->assertNull($card->fresh()->manufacturer_norms, 'kopia przywraca stan sprzed zapisu');
        @unlink($backup);
    }

    public function test_foreign_brand_and_other_connector_norms_are_left_alone(): void
    {
        Queue::fake();
        $account = $this->account('protekt', 'https://www.protekt.com.pl/');
        $foreign = $this->card($account, 'F-1', 'Kratos Safety', [['name' => 'Norma', 'value' => 'EN 355']]);
        $atgNorms = ManufacturerNormFacts::build([['label' => 'EN 361', 'value' => null]], 'atg', 'PROTEKT', 'https://b2b.example/karta');
        $other = $this->card($account, 'P-2', 'PROTEKT', [['name' => 'Norma', 'value' => 'EN 355']], $atgNorms);
        $web = ManufacturerNormFacts::build([['label' => 'EN 358', 'value' => null]], ManufacturerNormFacts::WEB_PAGE_CONNECTOR, 'PROTEKT', 'https://protekt.example/karta');
        $fromPage = $this->card($account, 'P-3', 'PROTEKT', [['name' => 'Norma', 'value' => 'EN 355']], $web);

        $this->artisan('norms:from-shop-cards', ['--apply' => true, '--backup' => storage_path('app/testing/norms-'.uniqid().'.json')])
            ->assertSuccessful();

        $this->assertNull($foreign->fresh()->manufacturer_norms, 'karta innej marki nie dostaje norm z witryny Protektu');
        $this->assertSame('atg', $other->fresh()->manufacturer_norms['source']['connector'], 'pary innego łącznika producenta zostają');
        $this->assertSame('protekt', $fromPage->fresh()->manufacturer_norms['source']['connector'], 'tabelka sklepu producenta bije ramkę ze strony WWW');
    }

    private function account(string $connector, string $site): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $connector.'@example.com',
            'contractor_code' => 'K1',
            'password' => 'haslo',
            'sites' => [$site],
            'connector' => $connector,
        ]);
    }

    /**
     * @param  list<array{name: string, value: string}>  $rows
     * @param  array<string, mixed>|null  $norms
     */
    private function card(B2bAccount $account, string $sku, string $manufacturer, array $rows, ?array $norms = null): Product
    {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => 'Szelki bezpieczeństwa '.$sku,
            'manufacturer' => $manufacturer,
            'catalog_price_net' => 100,
            'purchase_price' => 80,
            'stock' => 1,
            'manufacturer_norms' => $norms,
        ]);
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id,
            'product_id' => $product->id,
            'remote_id' => $sku,
            'remote_sku' => $sku,
            'last_seen_at' => now(),
        ]);
        ProductShopCard::query()->create([
            'product_id' => $product->id,
            'b2b_account_id' => $account->id,
            'source_url' => 'https://example.test/'.$sku,
            'fields' => [['section' => 'Normy', 'rows' => $rows]],
            'synced_at' => now(),
        ]);

        return $product;
    }
}
