<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\User;
use App\Services\B2b\AnroB2bClient;
use App\Services\B2b\B2bAccountSyncRunner;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class B2bAnroSyncTest extends TestCase
{
    use RefreshDatabase;

    /** 1×1 PNG */
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82";

    private const SKU = 'N/IF005/W-02/C/PT';

    private int $logins = 0;

    private bool $expireTokenOnce = false;

    private string $opis = '<h2>Znak &amp; alarm pożarowy</h2><p>Nadruk:</p><ul><li>fotoluminescencyjny</li><li>dwustronny</li></ul>';

    private string $netPrice = '36.72';

    private User $user;

    private B2bAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        Storage::fake('public');

        $this->user = User::factory()->withRole('admin')->create();
        $this->account = $this->makeAccount('jan');
        Product::query()->create([
            'sku' => 'OBCY-1',
            'name' => 'Karta 3M',
            'manufacturer' => '3M',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'discount_percent' => 0,
            'currency' => 'PLN',
        ]);
    }

    public function test_first_sync_creates_product_with_account_price_text_description_parameters_image_and_link(): void
    {
        $this->fakeAnro();

        $result = $this->sync();

        $this->assertSame(3, $result['total_remote']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(2, $result['skipped']);

        $product = Product::query()->where('sku', self::SKU)->firstOrFail();
        $this->assertSame('Anro', $product->manufacturer);
        $this->assertSame('Alarm pożarowy na wysięgniku W-02', $product->name);
        $this->assertSame('17/WGK - Wysięgniki', $product->category);
        $this->assertSame('36.72', $product->purchase_price);
        $this->assertSame('40.80', $product->catalog_price_net);
        $this->assertSame('10.00', $product->discount_percent);
        $this->assertSame(AnroB2bClient::PRODUCT_PAGE_URL.'13507', $product->shop_source_url);
        $this->assertStringNotContainsString('<', (string) $product->description);
        $this->assertStringContainsString('Znak & alarm pożarowy', (string) $product->description);
        $this->assertStringContainsString("- fotoluminescencyjny\n- dwustronny", (string) $product->description);
        $this->assertStringContainsString("Parametry:\n- Format: 200 x 200\n- Materiał: Płyta PVC", (string) $product->description);
        $this->assertStringNotContainsString('b2b.anro.net.pl', (string) $product->description);

        $image = $product->images()->firstOrFail();
        Storage::disk('public')->assertExists($image->path);
        $this->assertSame(AnroB2bClient::attachmentSourceUrl(34080), $image->source_url);
        $this->assertStringNotContainsString('token', (string) $image->source_url);

        $link = B2bProductLink::query()->where('b2b_account_id', $this->account->id)->where('remote_id', '13507')->firstOrFail();
        $this->assertSame($product->id, $link->product_id);
        $this->assertSame(sha1((string) $product->description), $link->description_hash);

        $priceList = $result['price_list'];
        $this->assertInstanceOf(PriceList::class, $priceList);
        $this->assertSame('Anro', $priceList->manufacturer);
        $this->assertSame([$product->id], $priceList->fresh()->product_ids);
        $this->assertTrue(ProductPriceHistory::query()
            ->where('product_id', $product->id)
            ->where('price_list_id', $priceList->id)
            ->where('source', 'b2b_api')
            ->exists());

        $reasons = implode(' | ', $result['errors']);
        $this->assertStringContainsString('BEZ-CENY: brak ceny', $reasons);
        $this->assertStringContainsString('OBCY-1: kod należy do karty producenta 3M', $reasons);
        $this->assertSame('Karta 3M', Product::query()->where('sku', 'OBCY-1')->value('name'));

        $account = $this->account->fresh();
        $this->assertSame('ok', $account->last_sync_status);
        $this->assertSame($priceList->id, $account->last_price_list_id);
        $this->assertStringContainsString('nowe: 1', (string) $account->last_sync_message);
        $this->assertNotNull($account->last_sync_finished_at);
    }

    public function test_second_sync_without_changes_adds_no_price_history_and_no_price_list(): void
    {
        $this->fakeAnro();

        $this->sync();
        $second = $this->sync();

        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(0, $second['created'] + $second['updated']);
        $this->assertNull($second['price_list']);
        $this->assertSame(1, PriceList::query()->count());
        $this->assertSame(1, ProductPriceHistory::query()->count());
        $this->assertSame(1, Product::query()->where('sku', self::SKU)->firstOrFail()->images()->count());
    }

    public function test_source_price_and_description_changes_update_card_but_manual_description_is_kept(): void
    {
        $this->fakeAnro();
        $this->sync();

        $this->netPrice = '39.90';
        $this->opis = '<p>Nowy opis od dostawcy dla znaku alarmowego.</p>';
        $second = $this->sync();

        $product = Product::query()->where('sku', self::SKU)->firstOrFail();
        $this->assertSame(1, $second['updated']);
        $this->assertSame(1, $second['prices_changed']);
        $this->assertSame(1, $second['descriptions']);
        $this->assertSame('39.90', $product->purchase_price);
        $this->assertStringContainsString('Nowy opis od dostawcy', (string) $product->description);
        $this->assertSame(2, ProductPriceHistory::query()->where('product_id', $product->id)->count());

        $product->update(['description' => 'Opis poprawiony ręcznie przez handlowca w katalogu.']);
        $this->opis = '<p>Kolejna wersja opisu od dostawcy.</p>';
        $third = $this->sync();

        $this->assertSame(0, $third['descriptions']);
        $this->assertSame('Opis poprawiony ręcznie przez handlowca w katalogu.', $product->fresh()->description);
    }

    public function test_existing_card_found_by_code_keeps_manual_description_link_and_images(): void
    {
        $this->fakeAnro();
        $existing = Product::query()->create([
            'sku' => self::SKU,
            'name' => 'Stara nazwa',
            'manufacturer' => 'Anro',
            'description' => 'Opis wpisany ręcznie, dłuższy niż dwadzieścia cztery znaki.',
            'catalog_price_net' => 40.80,
            'purchase_price' => 30.00,
            'discount_percent' => 0,
            'currency' => 'PLN',
            'shop_source_url' => 'https://inny.example.test/karta',
        ]);
        $existing->images()->create(['path' => 'products/x.png', 'is_primary' => true, 'sort_order' => 0]);

        $result = $this->sync();

        $existing->refresh();
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['images']);
        $this->assertSame('Alarm pożarowy na wysięgniku W-02', $existing->name);
        $this->assertSame('Opis wpisany ręcznie, dłuższy niż dwadzieścia cztery znaki.', $existing->description);
        $this->assertSame('https://inny.example.test/karta', $existing->shop_source_url);
        $this->assertSame('36.72', $existing->purchase_price);
        $this->assertSame(1, $existing->images()->count());
        $this->assertSame(self::SKU, $result['price_list']->fresh()->price_changes[0]['sku']);
        $this->assertNull(B2bProductLink::query()->where('product_id', $existing->id)->value('description_hash'));
    }

    public function test_dry_run_writes_nothing_and_keeps_account_status(): void
    {
        $this->fakeAnro();

        $result = $this->sync(dryRun: true);

        $this->assertSame(1, $result['created']);
        $this->assertNull($result['price_list']);
        $this->assertSame(0, PriceList::query()->count());
        $this->assertSame(0, B2bProductLink::query()->count());
        $this->assertFalse(Product::query()->where('sku', self::SKU)->exists());
        $this->assertNull($this->account->fresh()->last_sync_status);
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), 'att.php'));
    }

    public function test_expired_token_logs_in_again_and_continues(): void
    {
        $this->fakeAnro();
        $this->expireTokenOnce = true;

        $result = $this->sync(limit: 1);

        $this->assertSame(2, $this->logins);
        $this->assertSame(1, $result['created']);
    }

    public function test_sync_command_reports_failed_login_on_account(): void
    {
        Http::fake(['b2b.anro.net.pl/api-zami/api/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Nieprawidłowy login lub hasło'], 400)]);

        $this->artisan('b2b:sync', ['account' => $this->account->id, '--limit' => 1])
            ->expectsOutputToContain('Nieprawidłowy login lub hasło')
            ->assertFailed();

        $account = $this->account->fresh();
        $this->assertSame('failed', $account->last_sync_status);
        $this->assertStringContainsString('Nieprawidłowy login lub hasło', (string) $account->last_sync_message);
        $this->assertSame(0, PriceList::query()->count());
    }

    public function test_sync_due_runs_only_due_and_requested_accounts(): void
    {
        Http::fake(['b2b.anro.net.pl/api-zami/api/token' => Http::response(['error_description' => 'Nieprawidłowy login lub hasło'], 400)]);
        $now = CarbonImmutable::parse('2026-09-15 10:00', B2bAccount::SYNC_TIMEZONE);
        $this->travelTo($now);

        $dailyDue = $this->makeAccount('dzienne', ['sync_frequency' => 'daily', 'last_sync_started_at' => $now->subHours(25)]);
        $weeklyNotDue = $this->makeAccount('tygodniowe', ['sync_frequency' => 'weekly', 'last_sync_started_at' => $now->subDays(2), 'last_sync_status' => 'ok']);
        $requested = $this->makeAccount('na-zadanie', ['sync_frequency' => 'off', 'sync_requested_at' => $now->subMinutes(3)]);
        $running = $this->makeAccount('w-trakcie', ['sync_frequency' => 'daily', 'last_sync_status' => 'running', 'last_sync_started_at' => $now->subHour()]);
        $noConnector = $this->makeAccount('inna-witryna', ['sync_frequency' => 'daily', 'connector' => null, 'sites' => ['b2b.example.test']]);

        $this->artisan('b2b:sync-due')->assertSuccessful();

        $this->assertSame('failed', $dailyDue->fresh()->last_sync_status);
        $this->assertSame('failed', $requested->fresh()->last_sync_status);
        $this->assertNull($requested->fresh()->sync_requested_at);
        $this->assertSame('ok', $weeklyNotDue->fresh()->last_sync_status);
        $this->assertSame('running', $running->fresh()->last_sync_status);
        $this->assertNull($noConnector->fresh()->last_sync_status);
    }

    public function test_schedule_waits_for_night_hour_frequency_and_retries_stale_running(): void
    {
        $account = $this->makeAccount('konto', ['sync_frequency' => 'weekly']);

        $this->assertFalse($account->isSyncDue(CarbonImmutable::parse('2026-09-15 01:30', B2bAccount::SYNC_TIMEZONE)));
        $this->assertTrue($account->isSyncDue(CarbonImmutable::parse('2026-09-15 02:05', B2bAccount::SYNC_TIMEZONE)));

        $account->last_sync_started_at = CarbonImmutable::parse('2026-09-15 02:05', B2bAccount::SYNC_TIMEZONE);
        $this->assertFalse($account->isSyncDue(CarbonImmutable::parse('2026-09-20 02:05', B2bAccount::SYNC_TIMEZONE)));
        $this->assertTrue($account->isSyncDue(CarbonImmutable::parse('2026-09-22 02:05', B2bAccount::SYNC_TIMEZONE)));

        $account->last_sync_status = 'running';
        $this->assertTrue($account->isSyncDue(CarbonImmutable::parse('2026-09-22 09:00', B2bAccount::SYNC_TIMEZONE)));
        $this->assertFalse($account->isSyncDue(CarbonImmutable::parse('2026-09-15 04:00', B2bAccount::SYNC_TIMEZONE)));
    }

    /**
     * @return array<string, mixed>
     */
    private function sync(?int $limit = null, bool $dryRun = false): array
    {
        return app(B2bAccountSyncRunner::class)->run($this->account->fresh(), limit: $limit, dryRun: $dryRun, delayMs: 0);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeAccount(string $username, array $attributes = []): B2bAccount
    {
        $account = B2bAccount::query()->create([
            'username' => $username,
            'password' => 'sekret',
            'sites' => ['b2b.anro.net.pl'],
            'connector' => 'anro',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
        $account->forceFill($attributes)->save();

        return $account->fresh();
    }

    private function fakeAnro(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (str_ends_with($path, '/api/token')) {
                $this->logins++;
                $this->assertSame('password', $request['grant_type']);

                return Http::response(['access_token' => 'tok-'.$this->logins, 'refresh_token' => 'r']);
            }
            if (str_contains($url, 'att.php')) {
                return Http::response(self::PNG, 200, ['Content-Type' => 'image/png']);
            }
            if ($this->expireTokenOnce && $this->logins === 1) {
                $this->expireTokenOnce = false;

                return Http::response(['mgs' => 'Token expired'], 401);
            }

            return match (true) {
                str_ends_with($path, '/api/zit/products') => Http::response($this->productsPage((int) ($request['page'] ?? 0))),
                str_ends_with($path, '/api/zit/products/13507/price') => Http::response(['O_CENA_NETTO' => $this->netPrice, 'O_CENA_BAZOWA' => '40.80', 'O_RABAT' => '10.00']),
                str_ends_with($path, '/api/zit/products/2/price') => Http::response(['O_CENA_NETTO' => null, 'O_CENA_BAZOWA' => null, 'O_RABAT' => '0.00']),
                str_ends_with($path, '/api/zit/products/3/price') => Http::response(['O_CENA_NETTO' => '5.00', 'O_CENA_BAZOWA' => '5.00', 'O_RABAT' => '0.00']),
                str_contains($path, '/technical-data') => Http::response([
                    ['NAZWA' => 'Format:', 'WARTOSC' => '200 x 200'],
                    ['NAZWA' => 'Materiał:', 'WARTOSC' => 'Płyta PVC'],
                ]),
                str_contains($path, '/api/attachments/') => Http::response([
                    ['id' => 34080, 'type' => 1, 'contentType' => 'image/png', 'filename' => 'alarm.png', 'content' => ''],
                ]),
                default => Http::response(['mgs' => 'nieznany adres w teście: '.$path], 404),
            };
        });
    }

    /**
     * @return array{count: int, list: list<array<string, mixed>>}
     */
    private function productsPage(int $page): array
    {
        $items = [
            [
                'ID' => 13507,
                'KOD' => self::SKU,
                'NAME' => 'Alarm pożarowy na wysięgniku W-02',
                'OPIS' => $this->opis,
                'DZIAL_OPIS' => '17/WGK - Wysięgniki',
                'CENA_NETTO' => 0,
            ],
            ['ID' => 2, 'KOD' => 'BEZ-CENY', 'NAME' => 'Produkt bez ceny', 'OPIS' => '', 'DZIAL_OPIS' => ''],
            ['ID' => 3, 'KOD' => 'OBCY-1', 'NAME' => 'Kod zajęty', 'OPIS' => '', 'DZIAL_OPIS' => ''],
        ];

        // Dwie strony po 2 pozycje — sprawdza stronicowanie od 0 niezależnie od onPage.
        return ['count' => count($items), 'list' => array_slice($items, $page * 2, 2)];
    }
}
