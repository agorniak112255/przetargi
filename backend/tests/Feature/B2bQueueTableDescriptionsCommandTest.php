<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductShopCard;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Karty, w których opis to sama tabelka ze sklepu B2B (ślad po etapie sprzed rozdzielenia danych od opisu), idą do
 * uzupełniania AI z potwierdzonym nadpisaniem opisu z B2B — zamiast kasowania opisu, które wyjęłoby je z dopasowania.
 * Wybór ma nie ruszyć opisu poprawionego ręcznie, tłumaczenia, karty bez zapisanej tabelki ani karty z prawdziwą prozą.
 */
final class B2bQueueTableDescriptionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const UVEX_TABLE = "Dane techniczne:\nMateriał: poliwęglan\nWaga: 25 g\nKolor: czarny";

    private B2bAccount $uvex;

    private B2bAccount $protekt;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->withRole('admin')->create();
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
            'enrichment_batch_limit' => 50,
        ]);
        $this->uvex = $this->account('uvex', 'jan', 'izam.system-b2b.pl');
        $this->protekt = $this->account('protekt', 'anna', 'protekt.pl');
    }

    public function test_preview_selects_only_table_descriptions_and_changes_nothing(): void
    {
        $table = $this->card('UVEX-TABLE', self::UVEX_TABLE, $this->uvex);
        // opis poprawiony ręcznie (albo przez AI) — hash powiązania już się nie zgadza
        $edited = $this->card('UVEX-EDITED', self::UVEX_TABLE, $this->uvex);
        $edited->update(['description' => 'Okulary ochronne z poliwęglanową soczewką, powłoka przeciwodpryskowa.']);
        // tłumaczenie: nadpisanie zmarnowałoby przetłumaczony tekst
        $translated = $this->card('UVEX-TRANS', self::UVEX_TABLE, $this->uvex);
        B2bProductLink::query()->where('product_id', $translated->id)->update(['source_description_hash' => sha1('Technical data: …')]);
        // tabelka jeszcze nie zapisana osobno — nadpisanie opisu straciłoby dane ze sklepu
        $noShopCard = $this->card('UVEX-NOCARD', self::UVEX_TABLE, $this->uvex, shopCard: false);
        // proza przed tabelką jest prawdziwym opisem — nie ma czego zastępować
        $withProse = $this->card(
            'UVEX-PROSE',
            "Okulary ochronne z poliwęglanową soczewką odporną na zarysowania.\n\nDane techniczne:\nWaga: 25 g",
            $this->uvex,
        );

        $this->artisan('b2b:queue-table-descriptions')
            ->expectsOutputToContain('Do uzupełnienia opisu: 1 kart')
            ->expectsOutputToContain('Podgląd')
            ->assertSuccessful();

        $this->assertSame(0, ProductEnrichmentBatch::query()->count());
        foreach ([$table, $edited, $translated, $noShopCard, $withProse] as $product) {
            $this->assertSame(Product::ENRICHMENT_NONE, $product->fresh()?->enrichment_status);
        }
        $this->assertSame(self::UVEX_TABLE, $table->fresh()?->description);
    }

    public function test_account_option_narrows_selection_to_one_supplier(): void
    {
        $this->card('UVEX-TABLE', self::UVEX_TABLE, $this->uvex);
        $this->card('PROTEKT-TABLE', "Normy: EN 361, EN 358\nSpecyfikacja techniczna:\nDługość: 2 m", $this->protekt);

        $this->artisan('b2b:queue-table-descriptions')
            ->expectsOutputToContain('Do uzupełnienia opisu: 2 kart')
            ->assertSuccessful();

        $this->artisan('b2b:queue-table-descriptions', ['--account' => 'uvex'])
            ->expectsOutputToContain('Do uzupełnienia opisu: 1 kart')
            ->assertSuccessful();

        $this->artisan('b2b:queue-table-descriptions', ['--account' => (string) $this->protekt->id])
            ->expectsOutputToContain('Do uzupełnienia opisu: 1 kart')
            ->assertSuccessful();
    }

    public function test_apply_queues_cards_resets_done_and_leaves_manual_alone(): void
    {
        $fresh = $this->card('UVEX-TABLE', self::UVEX_TABLE, $this->uvex);
        $done = $this->card('UVEX-DONE', self::UVEX_TABLE, $this->uvex, status: Product::ENRICHMENT_DONE);
        $manual = $this->card('UVEX-MANUAL', self::UVEX_TABLE, $this->uvex, status: Product::ENRICHMENT_MANUAL);

        $this->artisan('b2b:queue-table-descriptions', ['--account' => 'uvex', '--apply' => true])
            ->expectsOutputToContain('W tym ze statusem „done”: 1')
            ->expectsOutputToContain('Pominięte karty ze statusem „manual”: 1')
            ->expectsOutputToContain('Zlecono uzupełnianie opisu: 2 kart w 1 partiach')
            ->assertSuccessful();

        $this->assertSame(Product::ENRICHMENT_QUEUED, $fresh->fresh()?->enrichment_status);
        $this->assertSame(Product::ENRICHMENT_QUEUED, $done->fresh()?->enrichment_status);
        $this->assertSame(Product::ENRICHMENT_MANUAL, $manual->fresh()?->enrichment_status);
        $this->assertSame(1, ProductEnrichmentBatch::query()->count());
        $this->assertSame(2, (int) ProductEnrichmentBatch::query()->value('total'));
    }

    private function account(string $connector, string $username, string $site): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $username,
            'contractor_code' => 'K'.$username,
            'password' => 'haslo',
            'sites' => [$site],
            'connector' => $connector,
        ]);
    }

    private function card(
        string $sku,
        string $description,
        B2bAccount $account,
        bool $shopCard = true,
        string $status = Product::ENRICHMENT_NONE,
    ): Product {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => 'Wyrób testowy '.$sku,
            'manufacturer' => 'UVEX',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
            'description' => $description,
            'enrichment_status' => $status,
        ]);
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id,
            'product_id' => $product->id,
            'remote_id' => $sku,
            'remote_sku' => $sku,
            'description_hash' => sha1($description),
            'last_seen_at' => now(),
        ]);
        if ($shopCard) {
            ProductShopCard::query()->create([
                'product_id' => $product->id,
                'b2b_account_id' => $account->id,
                'source_url' => 'https://example.test/'.$sku,
                'fields' => [['section' => 'Dane techniczne', 'rows' => [['name' => 'Waga', 'value' => '25 g']]]],
                'synced_at' => now(),
            ]);
        }

        return $product;
    }
}
