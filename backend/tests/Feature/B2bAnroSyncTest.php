<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductPriceHistory;
use App\Models\ProductShopCard;
use App\Models\User;
use App\Services\B2b\AnroB2bClient;
use App\Services\B2b\AnroB2bConnector;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRemoteShopField;
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

    /** Ile razy w przebiegu poszło zapytanie o parametry techniczne (opis i karta wyrobu pytają o to samo). */
    private int $technicalDataRequests = 0;

    private bool $expireTokenOnce = false;

    /** Lista podaje kod towaru tylko w TOWARKOD (KOD pusty). */
    private bool $codeOnlyInTowarkod = false;

    /** Symuluje „Zatrzymaj” kliknięte w panelu, gdy przebieg pobiera cenę pierwszego produktu. */
    private bool $cancelOnFirstPrice = false;

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
        // Parametry techniczne są tylko w tabelce karty wyrobu u dostawcy — opis ich nie powtarza.
        $this->assertStringNotContainsString('Parametry:', (string) $product->description);
        $this->assertStringNotContainsString('Format: 200 x 200', (string) $product->description);
        $this->assertStringNotContainsString('b2b.anro.net.pl', (string) $product->description);
        $this->assertContains(
            ['section' => 'Informacje techniczne', 'rows' => [
                ['name' => 'Format', 'value' => '200 x 200'],
                ['name' => 'Materiał', 'value' => 'Płyta PVC'],
            ]],
            (array) ProductShopCard::query()->where('product_id', $product->id)->sole()->fields,
        );

        $image = $product->images()->firstOrFail();
        Storage::disk('public')->assertExists($image->path);
        $this->assertSame(AnroB2bClient::attachmentSourceUrl(34080), $image->source_url);
        $this->assertStringNotContainsString('token', (string) $image->source_url);

        $link = B2bProductLink::query()->where('b2b_account_id', $this->account->id)->where('remote_id', '13507')->firstOrFail();
        $this->assertSame($product->id, $link->product_id);
        $this->assertSame(sha1((string) $product->description), $link->description_hash);

        // Jeden stały wpis konta w Cennikach; historia ceny karty wskazuje przebieg i ten wpis.
        $list = PriceList::query()->sole();
        $this->assertSame($result['price_list_id'], $list->id);
        $this->assertSame('b2b.anro.net.pl (API)', $list->original_filename);
        $this->assertSame([$product->id], $list->product_ids);
        $this->assertNotNull($result['sync_run_id']);
        $this->assertTrue(ProductPriceHistory::query()
            ->where('product_id', $product->id)
            ->where('price_list_id', $list->id)
            ->where('b2b_sync_run_id', $result['sync_run_id'])
            ->where('source', 'b2b:anro')
            ->exists());

        $reasons = implode(' | ', $result['errors']);
        $this->assertStringContainsString('BEZ-CENY: brak ceny', $reasons);
        $this->assertStringContainsString('OBCY-1: kod należy do karty producenta 3M', $reasons);
        $this->assertSame('Karta 3M', Product::query()->where('sku', 'OBCY-1')->value('name'));

        $account = $this->account->fresh();
        $this->assertSame('ok', $account->last_sync_status);
        $this->assertSame($list->id, $account->last_price_list_id);
        $this->assertStringContainsString('nowe: 1', (string) $account->last_sync_message);
        $this->assertNotNull($account->last_sync_finished_at);
    }

    public function test_karta_wyrobu_u_dostawcy_ma_dane_handlowe_parametry_i_klasyfikacje(): void
    {
        $this->fakeAnro();
        $connector = AnroB2bConnector::forAccount($this->account, 0);
        $remote = iterator_to_array($connector->products(), false);

        $rows = self::rows($connector->shopFields($remote[0]));

        $this->assertSame([
            ['Informacje handlowe', 'Nazwa towaru', 'Alarm pożarowy na wysięgniku W-02'],
            ['Informacje handlowe', 'Dział towarowy', 'Sito \\ TH - Towary handlowe \\ 17/WGK - Wysięgniki'],
            ['Informacje handlowe', 'Kod towaru', self::SKU],
            // Kod producenta jest w źródle pusty — wiersza nie ma wcale, zamiast pustej wartości.
            ['Informacje handlowe', 'Jednostka sprzedaży', 'SZT'],
            ['Informacje techniczne', 'Format', '200 x 200'],
            ['Informacje techniczne', 'Materiał', 'Płyta PVC'],
            ['Klasyfikacja produktowa', 'Dział asortymentowy', 'Sito'],
            ['Klasyfikacja produktowa', 'Grupa asortymentowa', 'TH - Towary handlowe'],
            ['Klasyfikacja produktowa', 'Podgrupa', '17/WGK - Wysięgniki'],
        ], $rows);

        // Kod towaru to kod producenta (wyroby Anro są jego własne); pusty „Kod producenta” i ID Zami — nie.
        $this->assertSame(
            [[ProductIdentifier::TYPE_MANUFACTURER_CODE, self::SKU, null, null, 'KOD']],
            self::identifiers($remote[0]),
        );

        // Cen w tabelce nie ma — karta wyrobu ma własne sloty cen ze źródeł.
        $this->assertSame([], array_values(array_filter(
            $rows,
            static fn (array $row): bool => str_contains(mb_strtolower($row[1]), 'cena'),
        )));
    }

    public function test_karta_wyrobu_pokazuje_kod_producenta_gdy_jest_i_pomija_pusta_klasyfikacje(): void
    {
        $this->fakeAnro();
        $connector = AnroB2bConnector::forAccount($this->account, 0);
        $remote = iterator_to_array($connector->products(), false);

        // OBCY-1: kod producenta uzupełniony, dział towarowy pusty.
        $rows = self::rows($connector->shopFields($remote[2]));

        $this->assertContains(['Informacje handlowe', 'Kod producenta', 'UV-9999'], $rows);
        $this->assertSame([], array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row[0] === 'Klasyfikacja produktowa' || $row[1] === 'Dział towarowy',
        )));
        $this->assertSame(
            [
                [ProductIdentifier::TYPE_MANUFACTURER_CODE, 'OBCY-1', null, null, 'KOD'],
                [ProductIdentifier::TYPE_MANUFACTURER_CODE, 'UV-9999', null, null, 'TOWARKODD'],
            ],
            self::identifiers($remote[2]),
        );
    }

    public function test_kod_towaru_z_towarkod_gdy_kod_pusty(): void
    {
        $this->codeOnlyInTowarkod = true;
        $this->fakeAnro();
        $connector = AnroB2bConnector::forAccount($this->account, 0);
        $remote = iterator_to_array($connector->products(), false);

        // Tabelka karty i identyfikator biorą ten sam kod — z pola, które naprawdę go podało.
        $this->assertContains(['Informacje handlowe', 'Kod towaru', self::SKU], self::rows($connector->shopFields($remote[0])));
        $this->assertSame(
            [[ProductIdentifier::TYPE_MANUFACTURER_CODE, self::SKU, null, null, 'TOWARKOD']],
            self::identifiers($remote[0]),
        );
        // Pozycja bez żadnego kodu podaje pustą listę, nie „nie wiem”.
        $this->assertSame([], $remote[1]->identifiers);
    }

    public function test_parametry_techniczne_pobiera_tylko_karta_wyrobu_a_nie_opis(): void
    {
        $this->fakeAnro();
        $connector = AnroB2bConnector::forAccount($this->account, 0);
        $remote = iterator_to_array($connector->products(), false);

        $description = $connector->description($remote[0]);

        // Opis to sama proza z pola OPIS — po parametry nie sięga wcale.
        $this->assertSame(0, $this->technicalDataRequests, 'Opis nie ma już pytać o parametry techniczne.');
        $this->assertStringNotContainsString('Parametry:', $description);
        $this->assertStringNotContainsString('Format: 200 x 200', $description);

        $fields = $connector->shopFields($remote[0]);

        $this->assertSame(1, $this->technicalDataRequests, 'Parametry techniczne mają być pobrane raz na produkt.');
        $this->assertContains(['Informacje techniczne', 'Format', '200 x 200'], self::rows($fields));
        $this->assertContains(['Informacje techniczne', 'Materiał', 'Płyta PVC'], self::rows($fields));

        // To samo w pełnym przebiegu synchronizacji: jeden produkt z parametrami = jedno zapytanie.
        $this->technicalDataRequests = 0;
        $this->sync();
        $this->assertSame(1, $this->technicalDataRequests);
    }

    public function test_second_sync_without_changes_adds_no_price_history_and_keeps_one_price_list(): void
    {
        $this->fakeAnro();

        $this->sync();
        $product = Product::query()->where('sku', self::SKU)->firstOrFail();
        // kod towaru zapisany jako kod producenta pod pozycją karty (ID Zami); pominięte pozycje nie mają identyfikatorów
        $this->assertSame(
            [['13507', ProductIdentifier::TYPE_MANUFACTURER_CODE, self::SKU, null, 'KOD', 'Anro']],
            ProductIdentifier::query()->orderBy('id')->get()
                ->map(static fn (ProductIdentifier $i): array => [$i->position_key, $i->type, $i->value, $i->variant_label, $i->source_field, $i->manufacturer])
                ->all(),
        );
        $this->assertSame($product->id, ProductIdentifier::query()->sole()->product_id);
        $identifiers = ProductIdentifier::query()->orderBy('id')->get(['id', 'product_id', 'position_key', 'type', 'value'])->toArray();

        $second = $this->sync();

        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(0, $second['created'] + $second['updated']);
        $this->assertSame(1, PriceList::query()->count());
        $this->assertSame(1, ProductPriceHistory::query()->count());
        $this->assertSame(1, $product->images()->count());
        // drugi przebieg identyfikatorów nie dubluje ani nie oznacza jako zniknięte
        $this->assertSame($identifiers, ProductIdentifier::query()->orderBy('id')->get(['id', 'product_id', 'position_key', 'type', 'value'])->toArray());
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());
        $this->assertSame(2, B2bSyncRun::query()->count());
        foreach (B2bSyncRun::query()->get() as $run) {
            $this->assertSame([], preg_grep('/identyfikator /', array_column((array) $run->log, 'text')));
        }
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
        // nazwa istniejącej karty zostaje dla każdego łącznika (decyzja użytkownika 15.09.2026)
        $this->assertSame('Stara nazwa', $existing->name);
        $this->assertSame('Opis wpisany ręcznie, dłuższy niż dwadzieścia cztery znaki.', $existing->description);
        $this->assertSame('https://inny.example.test/karta', $existing->shop_source_url);
        $this->assertSame('36.72', $existing->purchase_price);
        $this->assertSame(1, $existing->images()->count());
        $priceChange = B2bSyncRun::query()->findOrFail($result['sync_run_id'])->price_changes[0];
        $this->assertSame(self::SKU, $priceChange['sku']);
        $this->assertSame($existing->id, $priceChange['product_id']);
        $this->assertNull(B2bProductLink::query()->where('product_id', $existing->id)->value('description_hash'));
    }

    /**
     * Przegląd 22.09.2026 (N1): karta z kategorią (ścieżka drzewa dobrana automatem) zachowuje category, a kategoria
     * ze sklepu B2B idzie obok jako dowód rodzaju wyrobu.
     */
    public function test_sync_stores_b2b_category_as_evidence_even_when_card_has_category(): void
    {
        $this->fakeAnro();
        $existing = Product::query()->create([
            'sku' => self::SKU,
            'name' => 'Stara nazwa',
            'manufacturer' => 'Anro',
            'category' => 'Sklep - kategorie / Znaki / Wysięgniki',
            'category_source' => Product::CATEGORY_SOURCE_PRESTA_REWRITE,
            'catalog_price_net' => 40.80,
            'purchase_price' => 30.00,
            'discount_percent' => 0,
            'currency' => 'PLN',
        ]);

        $this->sync();

        $existing->refresh();
        $this->assertSame('Sklep - kategorie / Znaki / Wysięgniki', $existing->category);
        $this->assertSame(Product::CATEGORY_SOURCE_PRESTA_REWRITE, $existing->category_source);
        $this->assertSame('17/WGK - Wysięgniki', $existing->category_evidence);
        $this->assertSame('17/WGK - Wysięgniki', $existing->categoryAsEvidence());
    }

    public function test_dry_run_writes_nothing_and_keeps_account_status(): void
    {
        $this->fakeAnro();

        $result = $this->sync(dryRun: true);

        $this->assertSame(1, $result['created']);
        $this->assertNull($result['sync_run_id']);
        $this->assertSame(0, B2bSyncRun::query()->count());
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

    public function test_schedule_waits_for_night_hour_frequency_and_never_while_running(): void
    {
        $account = $this->makeAccount('konto', ['sync_frequency' => 'weekly']);

        $this->assertFalse($account->isSyncDue(CarbonImmutable::parse('2026-09-15 01:30', B2bAccount::SYNC_TIMEZONE)));
        $this->assertTrue($account->isSyncDue(CarbonImmutable::parse('2026-09-15 02:05', B2bAccount::SYNC_TIMEZONE)));

        $account->last_sync_started_at = CarbonImmutable::parse('2026-09-15 02:05', B2bAccount::SYNC_TIMEZONE);
        $this->assertFalse($account->isSyncDue(CarbonImmutable::parse('2026-09-20 02:05', B2bAccount::SYNC_TIMEZONE)));
        $this->assertTrue($account->isSyncDue(CarbonImmutable::parse('2026-09-22 02:05', B2bAccount::SYNC_TIMEZONE)));

        // Dawniej „running” starszy niż 6 h uznawany był za przerwany — pobranie SignProject trwa kilka godzin, więc
        // ruszałby drugi przebieg. Teraz przerwanie wykrywa tylko b2b:sync-due po braku postępu (B2bSyncOverlapTest).
        $account->last_sync_status = 'running';
        $this->assertFalse($account->isSyncDue(CarbonImmutable::parse('2026-09-22 09:00', B2bAccount::SYNC_TIMEZONE)));
        $this->assertFalse($account->isSyncDue(CarbonImmutable::parse('2026-09-15 04:00', B2bAccount::SYNC_TIMEZONE)));

        $account->last_sync_status = 'failed';
        $this->assertTrue($account->isSyncDue(CarbonImmutable::parse('2026-09-22 09:00', B2bAccount::SYNC_TIMEZONE)));
    }

    public function test_manual_daytime_run_does_not_shift_night_schedule(): void
    {
        $at = static fn (string $time): CarbonImmutable => CarbonImmutable::parse($time, B2bAccount::SYNC_TIMEZONE);

        $daily = $this->makeAccount('codziennie', [
            'sync_frequency' => 'daily',
            'last_sync_status' => 'ok',
            'last_sync_started_at' => $at('2026-09-15 18:00'),
        ]);
        $this->assertFalse($daily->isSyncDue($at('2026-09-15 20:00')));
        $this->assertFalse($daily->isSyncDue($at('2026-09-16 01:59')));
        $this->assertTrue($daily->isSyncDue($at('2026-09-16 02:00')));

        $daily->last_sync_started_at = $at('2026-09-16 02:05');
        $this->assertFalse($daily->isSyncDue($at('2026-09-16 23:00')));
        $this->assertTrue($daily->isSyncDue($at('2026-09-17 02:00')));

        $weekly = $this->makeAccount('co-tydzien', [
            'sync_frequency' => 'weekly',
            'last_sync_status' => 'ok',
            'last_sync_started_at' => $at('2026-09-15 18:00'),
        ]);
        $this->assertFalse($weekly->isSyncDue($at('2026-09-21 02:30')));
        $this->assertFalse($weekly->isSyncDue($at('2026-09-22 01:59')));
        $this->assertTrue($weekly->isSyncDue($at('2026-09-22 02:00')));

        $weekly->last_sync_started_at = $at('2026-09-15 02:05');
        $this->assertFalse($weekly->isSyncDue($at('2026-09-21 23:00')));
        $this->assertTrue($weekly->isSyncDue($at('2026-09-22 02:00')));
    }

    public function test_sync_records_run_with_counters_total_log_and_price_changes(): void
    {
        $this->fakeAnro();

        $first = $this->sync();
        $run = B2bSyncRun::query()->findOrFail($first['sync_run_id']);

        $this->assertSame($this->account->id, $run->b2b_account_id);
        $this->assertSame('ok', $run->status);
        $this->assertSame('cli', $run->trigger);
        $this->assertSame(3, $run->total);
        $this->assertSame(3, $run->processed);
        $this->assertSame(1, $run->created);
        $this->assertSame(2, $run->skipped);
        $this->assertSame(1, $run->images);
        $this->assertNull($run->current_sku);
        $this->assertNotNull($run->finished_at);
        $this->assertStringContainsString('nowe: 1', (string) $run->message);

        $texts = array_column($run->log, 'text');
        $this->assertSame('Logowanie…', $texts[0]);
        $this->assertContains('Produktów w B2B: 3', $texts);
        $this->assertContains('[1/3] '.self::SKU.' — nowy', $texts);
        $this->assertContains('[2/3] BEZ-CENY — pominięty: brak ceny w B2B', $texts);
        $this->assertSame('warn', $run->log[array_search('[2/3] BEZ-CENY — pominięty: brak ceny w B2B', $texts, true)]['level']);
        $this->assertStringStartsWith('W B2B: 3 · sprawdzone: 3', (string) end($texts));

        $second = B2bSyncRun::query()->findOrFail($this->sync()['sync_run_id']);
        $this->assertSame(1, $second->unchanged);
        $this->assertSame([], array_values(array_filter(
            array_column($second->log, 'text'),
            static fn (string $text): bool => str_contains($text, '— bez zmian'),
        )));

        $this->netPrice = '39.90';
        $third = B2bSyncRun::query()->findOrFail($this->sync()['sync_run_id']);
        $product = Product::query()->where('sku', self::SKU)->firstOrFail();
        $this->assertSame(1, $third->prices_changed);
        $this->assertCount(1, $third->price_changes);
        $this->assertSame($product->id, $third->price_changes[0]['product_id']);
        $this->assertEquals(36.72, $third->price_changes[0]['purchase_old']);
        $this->assertEquals(39.9, $third->price_changes[0]['purchase_new']);
        $this->assertNotEmpty($third->price_changes[0]['at']);
        $this->assertTrue(ProductPriceHistory::query()
            ->where('product_id', $product->id)
            ->where('b2b_sync_run_id', $third->id)
            ->where('source', 'b2b:anro')
            ->exists());
    }

    public function test_cancel_request_stops_sync_after_current_product(): void
    {
        $this->fakeAnro();
        $this->cancelOnFirstPrice = true;

        $result = $this->sync();

        $this->assertTrue($result['cancelled']);
        $this->assertSame(1, $result['seen']);
        $this->assertTrue(Product::query()->where('sku', self::SKU)->exists());
        Http::assertNotSent(static fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/products/2/price'));

        $run = B2bSyncRun::query()->findOrFail($result['sync_run_id']);
        $this->assertSame('cancelled', $run->status);
        $this->assertNotNull($run->cancel_requested_at);
        $this->assertSame(1, $run->processed);
        $this->assertSame(3, $run->total);
        $this->assertSame('Zatrzymano ręcznie po 1 z 3', $run->message);
        $this->assertContains('Zatrzymano ręcznie po 1 z 3', array_column($run->log, 'text'));

        $account = $this->account->fresh();
        $this->assertSame('cancelled', $account->last_sync_status);
        $this->assertSame('Zatrzymano ręcznie po 1 z 3', $account->last_sync_message);
        $this->assertNotNull($account->last_sync_finished_at);
    }

    public function test_failed_login_marks_run_failed_and_triggers_are_recorded(): void
    {
        Http::fake(['b2b.anro.net.pl/api-zami/api/token' => Http::response(['error_description' => 'Nieprawidłowy login lub hasło'], 400)]);
        $now = CarbonImmutable::parse('2026-09-15 10:00', B2bAccount::SYNC_TIMEZONE);
        $this->travelTo($now);

        $daily = $this->makeAccount('dzienne', ['sync_frequency' => 'daily', 'last_sync_started_at' => $now->subHours(25)]);
        $requested = $this->makeAccount('na-zadanie', ['sync_requested_at' => $now->subMinutes(3)]);

        $this->artisan('b2b:sync-due')->assertSuccessful();
        $this->artisan('b2b:sync', ['account' => $this->account->id])->assertFailed();

        $this->assertSame('schedule', $daily->syncRuns()->sole()->trigger);
        $this->assertSame('manual', $requested->syncRuns()->sole()->trigger);
        $cli = $this->account->syncRuns()->sole();
        $this->assertSame('cli', $cli->trigger);
        $this->assertSame('failed', $cli->status);
        $this->assertNotNull($cli->finished_at);
        $this->assertStringContainsString('Nieprawidłowy login lub hasło', (string) $cli->message);
        $log = $cli->log;
        $this->assertSame(['Logowanie…', 'error'], [$log[0]['text'], end($log)['level']]);
    }

    public function test_sync_due_marks_runs_without_progress_as_failed(): void
    {
        Http::fake();
        $now = CarbonImmutable::parse('2026-09-15 10:00', B2bAccount::SYNC_TIMEZONE);
        $this->travelTo($now);

        $killed = $this->makeAccount('zabity', ['last_sync_status' => 'running', 'last_sync_started_at' => $now->subHours(2)]);
        $staleRun = $killed->syncRuns()->create(['status' => 'running', 'trigger' => 'manual', 'started_at' => $now->subHours(2), 'processed' => 700]);
        // now() w strefie aplikacji (UTC) — surowy update zapisuje czas bez przeliczenia strefy
        B2bSyncRun::query()->whereKey($staleRun->id)->update(['updated_at' => now()->subMinutes(31)]);

        $alive = $this->makeAccount('zywy', ['last_sync_status' => 'running', 'last_sync_started_at' => $now->subHours(2)]);
        $aliveRun = $alive->syncRuns()->create(['status' => 'running', 'trigger' => 'cli', 'started_at' => $now->subHours(2)]);
        B2bSyncRun::query()->whereKey($aliveRun->id)->update(['updated_at' => now()->subMinutes(5)]);

        $this->artisan('b2b:sync-due')->assertSuccessful();

        $staleRun->refresh();
        $message = 'Przerwane — brak postępu ponad 30 min (np. restart serwera)';
        $this->assertSame('failed', $staleRun->status);
        $this->assertSame($message, $staleRun->message);
        $this->assertNotNull($staleRun->finished_at);
        $this->assertSame(700, $staleRun->processed);
        $this->assertSame('failed', $killed->fresh()->last_sync_status);
        $this->assertSame($message, $killed->fresh()->last_sync_message);

        $this->assertSame('running', $aliveRun->fresh()->status);
        $this->assertSame('running', $alive->fresh()->last_sync_status);
        Http::assertNothingSent();
    }

    /**
     * @return list<array{0: string, 1: string, 2: string|null, 3: string|null, 4: string|null}>
     */
    private static function identifiers(B2bRemoteProduct $product): array
    {
        return array_map(
            static fn (B2bRemoteIdentifier $i): array => [$i->type, $i->value, $i->remoteId, $i->label, $i->field],
            $product->identifiers ?? [],
        );
    }

    /**
     * Wiersze karty wyrobu jako proste trójki — czytelniej porównać niż obiekty.
     *
     * @param  list<B2bRemoteShopField>  $fields
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function rows(array $fields): array
    {
        return array_map(
            static fn (B2bRemoteShopField $field): array => [$field->section, $field->name, $field->value],
            $fields,
        );
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
            if ($this->cancelOnFirstPrice && str_ends_with($path, '/api/zit/products/13507/price')) {
                B2bSyncRun::query()->where('status', 'running')->update(['cancel_requested_at' => now()]);
            }
            if (str_contains($path, '/technical-data')) {
                $this->technicalDataRequests++;
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
                'DZIAL_OPIS_PELNY' => 'Sito \\ TH - Towary handlowe \\ 17/WGK - Wysięgniki',
                // Kod producenta u Anro bywa pusty — tak jak tutaj.
                'TOWARKODD' => '',
                'JEDNOSTKA' => 'SZT',
                'CENA_NETTO' => 0,
            ],
            ['ID' => 2, 'KOD' => 'BEZ-CENY', 'NAME' => 'Produkt bez ceny', 'OPIS' => '', 'DZIAL_OPIS' => ''],
            ['ID' => 3, 'KOD' => 'OBCY-1', 'NAME' => 'Kod zajęty', 'OPIS' => '', 'DZIAL_OPIS' => '', 'TOWARKODD' => 'UV-9999', 'JEDNOSTKA' => 'PAR'],
        ];
        if ($this->codeOnlyInTowarkod) {
            // Kod towaru tylko w TOWARKOD; druga pozycja bez żadnego kodu.
            $items[0] = ['KOD' => '', 'TOWARKOD' => self::SKU] + $items[0];
            $items[1]['KOD'] = '';
        }

        // Dwie strony po 2 pozycje — sprawdza stronicowanie od 0 niezależnie od onPage.
        return ['count' => count($items), 'list' => array_slice($items, $page * 2, 2)];
    }
}
