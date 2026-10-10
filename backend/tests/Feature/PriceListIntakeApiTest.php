<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AssortmentGroup;
use App\Models\B2bAccount;
use App\Models\ManufacturerSite;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductSourcePin;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\PriceLists\Importers\PriceListImporterRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cenniki z plików jak B2B (10.10.2026): formularz cennika przed plikiem (POST/PATCH intake), importer per cennik
 * (tylko administrator), lista importerów, mapa kart (source-pins) i wiersz w zakładce „Z pliku”.
 */
final class PriceListIntakeApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($this->admin);
    }

    public function test_store_creates_map_only_list_without_cards_with_hosts_and_discount(): void
    {
        $response = $this->postJson('/api/price-lists/intake', [
            'manufacturer' => ' MAPA ',
            'version' => '2025',
            'manufacturer_hosts' => ['mapa' => ['https://www.mapa-pro.pl/produkty', 'mapa-pro.com'], 'PELTOR' => ['peltor.example']],
            'enrichment_sites' => ['https://sklep-a.pl/x', 'sklep-b.pl'],
            'enrichment_sites_mode' => PriceList::MODE_ONLY,
            'suggested_prices' => true,
            'importer_notes' => '  Arkusz „Cennik”, kolumna D = cena katalogowa  ',
            'discount_percent' => 32.5,
        ])->assertCreated();

        $list = PriceList::query()->sole();
        $this->assertSame('MAPA', $list->manufacturer);
        $this->assertSame('mapa', $list->manufacturer_key);
        $this->assertSame('2025', $list->version);
        $this->assertSame(PriceList::POLICY_MAP_ONLY, $list->source_policy);
        $this->assertNull($list->importer_key);
        $this->assertNull($list->original_filename);
        $this->assertSame([], $list->product_ids);
        $this->assertSame(0, (int) $list->rows_total);
        $this->assertTrue($list->suggested_prices);
        $this->assertSame(['sklep-a.pl', 'sklep-b.pl'], $list->enrichment_sites);
        $this->assertNotNull($list->enrichment_sites_updated_at);

        // strony producenta jak „Strony wyszukiwarka” → przypisz producenta (manual), marka pod producentem cennika
        $sites = ManufacturerSite::query()->orderBy('brand_key')->orderBy('host')->get(['brand_key', 'manufacturer', 'host', 'source']);
        $this->assertSame([
            ['brand_key' => 'mapa', 'manufacturer' => 'MAPA', 'host' => 'mapa-pro.com', 'source' => 'manual'],
            ['brand_key' => 'mapa', 'manufacturer' => 'MAPA', 'host' => 'mapa-pro.pl', 'source' => 'manual'],
            ['brand_key' => 'peltor', 'manufacturer' => 'MAPA', 'host' => 'peltor.example', 'source' => 'manual'],
        ], $sites->map(static fn (ManufacturerSite $s): array => $s->only(['brand_key', 'manufacturer', 'host', 'source']))->all());
        // rabat na cały cennik = grupa (cały asortyment) producenta
        $this->assertSame(32.5, (float) AssortmentGroup::query()
            ->where('manufacturer', 'MAPA')->where('name', AssortmentGroup::GLOBAL_NAME)->value('discount_percent'));

        $view = $response->json('price_list');
        $this->assertSame([
            'id', 'manufacturer', 'manufacturer_key', 'version', 'source_policy', 'importer_key', 'importer_label',
            'importer_notes', 'status', 'manufacturer_hosts', 'enrichment_sites', 'enrichment_sites_mode',
            'suggested_prices', 'discount_percent', 'discount_applies_on_next_import', 'latest_file', 'pins', 'mapping',
        ], array_keys($view));
        // przypisywanie stron nie trwa
        $this->assertNull($view['mapping']);
        $this->assertTrue($view['discount_applies_on_next_import']);
        $this->assertSame($list->id, $view['id']);
        $this->assertSame(PriceList::INTAKE_AWAITING_FILE, $view['status']);
        $this->assertSame('Arkusz „Cennik”, kolumna D = cena katalogowa', $view['importer_notes']);
        $this->assertSame(['mapa' => ['mapa-pro.com', 'mapa-pro.pl'], 'peltor' => ['peltor.example']], $view['manufacturer_hosts']);
        $this->assertSame('only', $view['enrichment_sites_mode']);
        $this->assertEquals(32.5, $view['discount_percent']);
        $this->assertNull($view['latest_file']);
        $this->assertSame(['pinned' => 0, 'unresolved' => 0, 'human_url' => 0, 'total' => 0], $view['pins']);
    }

    public function test_without_search_sites_permission_only_own_brand_gets_manufacturer_hosts(): void
    {
        config(['enrichment.blocked_source_hosts' => ['allegro.pl']]);
        // kierownik: import cenników, bez Administracja → Strony wyszukiwarka
        $kierownik = User::factory()->withRole('kierownik')->create();
        $this->assertTrue($kierownik->can('price_lists.import'));
        $this->assertFalse($kierownik->can('admin.search_sites.manage'));
        Sanctum::actingAs($kierownik);

        // strona innej marki działa w całej aplikacji — przypisuje ją administrator
        $this->postJson('/api/price-lists/intake', [
            'manufacturer' => 'MAPA', 'version' => '2025', 'manufacturer_hosts' => ['mapa' => ['mapa-pro.pl'], '3M' => ['3m.pl']],
        ])->assertForbidden();
        $this->assertSame(0, PriceList::query()->count());
        $this->assertSame(0, ManufacturerSite::query()->count());
        // host zablokowany nie zostaje stroną producenta
        $this->postJson('/api/price-lists/intake', [
            'manufacturer' => 'MAPA', 'version' => '2025', 'manufacturer_hosts' => ['mapa' => ['allegro.pl']],
        ])->assertUnprocessable();
        // własna marka cennika — tak
        $this->postJson('/api/price-lists/intake', [
            'manufacturer' => 'MAPA', 'version' => '2025', 'manufacturer_hosts' => ['mapa' => ['mapa-pro.pl']],
        ])->assertCreated();
        $this->assertSame(['mapa-pro.pl'], ManufacturerSite::query()->where('brand_key', 'mapa')->pluck('host')->all());

        // administrator może przypisać także inną markę (cennik wielomarkowy)
        Sanctum::actingAs($this->admin);
        $id = (int) PriceList::query()->value('id');
        $this->patchJson("/api/price-lists/{$id}/intake", ['manufacturer_hosts' => ['PELTOR' => ['peltor.example']]])->assertOk();
        $this->assertTrue(ManufacturerSite::query()->where('host', 'peltor.example')->exists());
    }

    public function test_list_with_supplier_special_prices_cannot_switch_or_get_importer(): void
    {
        // cennik po imporcie z kolumną ceny normalnej (SECURA) — importer bez tej kolumny nie przyjmie pliku, a stary
        // import po przełączeniu byłby zablokowany
        $old = $this->list('SECURA', ['manufacturer_key' => 'secura', 'has_supplier_special' => true]);

        $this->patchJson("/api/price-lists/{$old->id}/intake", ['version' => '2027'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cennik ma ceny specjalne dostawcy — importer musi czytać kolumnę ceny normalnej, zgłoś programiście.');
        $this->assertNull($old->fresh()->source_policy);
        $this->patchJson("/api/price-lists/{$old->id}/importer", ['importer_key' => 'mapa-2025'])->assertStatus(422);
        $this->assertNull($old->fresh()->importer_key);
    }

    public function test_name_with_parenthesis_does_not_count_as_own_brand_without_search_sites_permission(): void
    {
        Sanctum::actingAs(User::factory()->withRole('kierownik')->create());

        // „Ansell (x)” = nowy cennik (inny manufacturer_key), ale marka „ansell” — strona producenta Ansell dla całej aplikacji
        $this->postJson('/api/price-lists/intake', [
            'manufacturer' => 'Ansell (x)', 'version' => '1', 'manufacturer_hosts' => ['Ansell (x)' => ['dowolny-sklep.pl']],
        ])->assertForbidden();
        $this->postJson('/api/price-lists/intake', [
            'manufacturer' => 'Ansell / test', 'version' => '1', 'manufacturer_hosts' => ['ansell' => ['dowolny-sklep.pl']],
        ])->assertForbidden();
        // znaki spoza a–z/0–9 brandKey gubi („Ansellé” → „ansell”)
        $this->postJson('/api/price-lists/intake', [
            'manufacturer' => 'Ansellé', 'version' => '1', 'manufacturer_hosts' => ['ansell' => ['dowolny-sklep.pl']],
        ])->assertForbidden();
        $this->assertSame(0, ManufacturerSite::query()->count());
        $this->assertSame(0, PriceList::query()->count());
        // zwykła nazwa z kropką i myślnikiem — własna marka
        $this->postJson('/api/price-lists/intake', [
            'manufacturer' => 'Fagum-Stomil S.A', 'version' => '1', 'manufacturer_hosts' => ['Fagum-Stomil S.A' => ['fagum.pl']],
        ])->assertCreated();
    }

    public function test_list_with_assortment_groups_cannot_switch_to_new_way(): void
    {
        // grupa rabatowa inna niż „cały asortyment” — runner odmówiłby importu, a stary import byłby już zablokowany
        AssortmentGroup::query()->create(['manufacturer' => 'Tegro', 'name' => 'Rękawice', 'discount_percent' => 20, 'is_global' => false]);
        $old = $this->list('Tegro', ['manufacturer_key' => 'tegro']);

        $this->patchJson("/api/price-lists/{$old->id}/intake", ['version' => '2027'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cennik ma rabaty w grupach asortymentowych — importer ich nie obsługuje, zgłoś programiście.');
        $this->assertNull($old->fresh()->source_policy);
        $this->assertSame('2026', $old->fresh()->version);

        AssortmentGroup::query()->create(['manufacturer' => 'Nowy', 'name' => 'Buty', 'discount_percent' => 10, 'is_global' => false]);
        $this->postJson('/api/price-lists/intake', ['manufacturer' => 'Nowy', 'version' => '2026'])->assertStatus(422);
        $this->assertSame(0, PriceList::query()->where('manufacturer_key', 'nowy')->count());
    }

    public function test_settings_only_add_missing_manufacturer_hosts_and_keep_existing_rows(): void
    {
        // domena wykryta automatem wybrana świadomie w formularzu → przypisana ręcznie (nazwa zostaje); wykryta, której
        // nikt nie wybrał, i domena z configu — bez zmian
        ManufacturerSite::query()->create(['brand_key' => 'mapa', 'manufacturer' => 'Mapa Professional', 'host' => 'mapa-pro.pl', 'source' => 'discovered']);
        ManufacturerSite::query()->create(['brand_key' => 'mapa', 'manufacturer' => 'Mapa Professional', 'host' => 'sklep-mapa.pl', 'source' => 'discovered']);
        ManufacturerSite::query()->create(['brand_key' => 'mapa', 'manufacturer' => 'MAPA PRO', 'host' => 'mapa-pro.de', 'source' => 'config']);
        // ta sama domena przy innej marce to osobny wiersz
        ManufacturerSite::query()->create(['brand_key' => 'inna', 'manufacturer' => 'Inna', 'host' => 'mapa-pro.com', 'source' => 'discovered']);

        $view = $this->postJson('/api/price-lists/intake', [
            'manufacturer' => 'MAPA',
            'version' => '2025',
            'manufacturer_hosts' => ['mapa' => ['mapa-pro.pl', 'mapa-pro.de', 'mapa-pro.com']],
        ])->assertCreated()->json('price_list');

        $rows = ManufacturerSite::query()->orderBy('brand_key')->orderBy('host')->get()
            ->map(static fn (ManufacturerSite $s): array => $s->only(['brand_key', 'manufacturer', 'host', 'source']))->all();
        $this->assertSame([
            ['brand_key' => 'inna', 'manufacturer' => 'Inna', 'host' => 'mapa-pro.com', 'source' => 'discovered'],
            ['brand_key' => 'mapa', 'manufacturer' => 'MAPA', 'host' => 'mapa-pro.com', 'source' => 'manual'],
            ['brand_key' => 'mapa', 'manufacturer' => 'MAPA PRO', 'host' => 'mapa-pro.de', 'source' => 'config'],
            ['brand_key' => 'mapa', 'manufacturer' => 'Mapa Professional', 'host' => 'mapa-pro.pl', 'source' => 'manual'],
            ['brand_key' => 'mapa', 'manufacturer' => 'Mapa Professional', 'host' => 'sklep-mapa.pl', 'source' => 'discovered'],
        ], $rows);
        // formularz pokazuje strony ręczne i z configu, bez wykrytych automatem
        $this->assertEqualsCanonicalizing(['mapa-pro.com', 'mapa-pro.de', 'mapa-pro.pl'], $view['manufacturer_hosts']['mapa'] ?? []);

        // ponowny zapis tych samych ustawień niczego nie zmienia
        $this->patchJson("/api/price-lists/{$view['id']}/intake", ['manufacturer_hosts' => ['mapa' => ['mapa-pro.pl']]])->assertOk();
        $this->assertSame('manual', ManufacturerSite::query()->where('brand_key', 'mapa')->where('host', 'mapa-pro.pl')->value('source'));
        $this->assertSame('discovered', ManufacturerSite::query()->where('brand_key', 'mapa')->where('host', 'sklep-mapa.pl')->value('source'));
        $this->assertSame(5, ManufacturerSite::query()->count());
    }

    public function test_store_conflicts_with_existing_list_of_the_same_manufacturer(): void
    {
        $existing = $this->list('Mapa', ['manufacturer_key' => 'mapa']);

        $this->postJson('/api/price-lists/intake', ['manufacturer' => 'MAPA.', 'version' => '2025'])
            ->assertStatus(409)
            ->assertJsonPath('price_list_id', $existing->id)
            ->assertJsonPath('message', 'Cennik Mapa już istnieje — otwórz go i dodaj plik albo zmień ustawienia.');

        $this->assertSame(1, PriceList::query()->count());
    }

    public function test_store_conflict_with_b2b_list_says_the_account_writes_to_it(): void
    {
        $api = $this->list('b2b.anro.net.pl (API)', ['manufacturer_key' => 'anro', 'original_filename' => 'b2b.anro.net.pl (API)']);
        $this->postJson('/api/price-lists/intake', ['manufacturer' => 'ANRO', 'version' => '1'])
            ->assertStatus(409)
            ->assertJsonPath('price_list_id', $api->id)
            ->assertJsonFragment(['message' => 'Cennik b2b.anro.net.pl (API) już istnieje i zapisuje do niego konto B2B — pliku tego producenta nie dodaje się osobnym cennikiem.']);

        // wpis zapisany na koncie (b2b_accounts.last_price_list_id), nazwa przejęta przez człowieka
        $owned = $this->list('Delta Plus', ['manufacturer_key' => 'delta plus']);
        B2bAccount::query()->create([
            'username' => 'jan', 'password' => 'sekret', 'sites' => ['b2b.deltaplus.example'], 'connector' => 'deltaplus',
            'last_price_list_id' => $owned->id, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
        ]);
        $this->postJson('/api/price-lists/intake', ['manufacturer' => 'Delta Plus', 'version' => '1'])
            ->assertStatus(409)
            ->assertJsonPath('price_list_id', $owned->id);
        $this->assertStringContainsString('konto B2B', (string) $this->postJson('/api/price-lists/intake', ['manufacturer' => 'Delta Plus', 'version' => '1'])->json('message'));
    }

    public function test_store_validates_required_fields_and_hosts(): void
    {
        $this->postJson('/api/price-lists/intake', ['manufacturer' => 'MAPA'])->assertStatus(422)->assertJsonValidationErrors('version');
        $this->postJson('/api/price-lists/intake', ['version' => '1'])->assertStatus(422)->assertJsonValidationErrors('manufacturer');
        $this->postJson('/api/price-lists/intake', ['manufacturer' => 'MAPA', 'version' => '1', 'manufacturer_hosts' => ['mapa' => ['nie adres']]])
            ->assertStatus(422)->assertJsonValidationErrors('manufacturer_hosts');
        // hosty bez marki (lista zamiast {marka: [hosty]})
        $this->postJson('/api/price-lists/intake', ['manufacturer' => 'MAPA', 'version' => '1', 'manufacturer_hosts' => [['mapa-pro.pl']]])
            ->assertStatus(422)->assertJsonValidationErrors('manufacturer_hosts');
        $this->postJson('/api/price-lists/intake', ['manufacturer' => 'MAPA', 'version' => '1', 'discount_percent' => 120])
            ->assertStatus(422)->assertJsonValidationErrors('discount_percent');
        config(['enrichment.blocked_source_hosts' => ['allegro.pl']]);
        $this->postJson('/api/price-lists/intake', ['manufacturer' => 'MAPA', 'version' => '1', 'enrichment_sites' => ['allegro.pl']])
            ->assertStatus(422)->assertJsonValidationErrors('enrichment_sites');

        $this->assertSame(0, PriceList::query()->count());
    }

    public function test_write_endpoints_require_import_permission_and_importer_requires_admin(): void
    {
        $list = $this->intakeList('MAPA');

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->postJson('/api/price-lists/intake', ['manufacturer' => 'Nowy', 'version' => '1'])->assertForbidden();
        $this->patchJson("/api/price-lists/{$list->id}/intake", ['version' => '2'])->assertForbidden();
        $this->patchJson("/api/price-lists/{$list->id}/importer", ['importer_key' => null])->assertForbidden();
        // odczyt — price_lists.view
        $this->getJson('/api/price-lists/importers')->assertOk();
        $this->getJson("/api/price-lists/{$list->id}/source-pins")->assertOk();

        // kierownik importuje cenniki, ale importera nie wiąże (administrator)
        Sanctum::actingAs(User::factory()->withRole('kierownik')->create());
        $this->patchJson("/api/price-lists/{$list->id}/intake", ['version' => '2'])->assertOk();
        $this->patchJson("/api/price-lists/{$list->id}/importer", ['importer_key' => null])->assertForbidden();
    }

    public function test_update_turns_legacy_list_into_intake_and_keeps_manufacturer(): void
    {
        $legacy = $this->list('ARTRA', ['manufacturer_key' => 'artra']);
        $this->assertSame(PriceList::INTAKE_LEGACY, $legacy->intakeStatus());

        $this->patchJson("/api/price-lists/{$legacy->id}/intake", ['manufacturer' => 'ARTRA SAFETY', 'version' => '2'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('manufacturer');

        $this->patchJson("/api/price-lists/{$legacy->id}/intake", [
            'manufacturer' => 'ARTRA',
            'version' => '2026-10',
            'importer_notes' => 'Plik z zakładką „PL”',
        ])
            ->assertOk()
            ->assertJsonPath('price_list.status', PriceList::INTAKE_AWAITING_FILE)
            ->assertJsonPath('price_list.version', '2026-10');

        $legacy->refresh();
        $this->assertSame(PriceList::POLICY_MAP_ONLY, $legacy->source_policy);
        $this->assertSame('ARTRA', $legacy->manufacturer);
        $this->assertSame('Plik z zakładką „PL”', $legacy->importer_notes);
        // pola nieprzysłane zostają
        $this->assertNull($legacy->enrichment_sites);
    }

    public function test_update_refuses_b2b_list(): void
    {
        $api = $this->list('b2b.anro.net.pl (API)', ['manufacturer_key' => 'anro', 'original_filename' => 'b2b.anro.net.pl (API)']);

        $this->patchJson("/api/price-lists/{$api->id}/intake", ['version' => '2'])->assertStatus(422);
        $this->patchJson("/api/price-lists/{$api->id}/importer", ['importer_key' => null])->assertStatus(422);
        $this->assertNull($api->fresh()->source_policy);
        $this->assertSame(0, ManufacturerSite::query()->count());
    }

    public function test_discount_saves_only_global_group_without_touching_card_prices(): void
    {
        $list = $this->intakeList('MAPA');
        $card = Product::query()->create(['sku' => 'M-1', 'name' => 'Rękawica', 'manufacturer' => 'MAPA', 'catalog_price_net' => 100, 'purchase_price' => 55, 'stock' => 0]);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $list->id,
            'catalog_price_net' => 100, 'purchase_price' => 55, 'discount_percent' => null, 'currency' => 'PLN', 'checked_at' => now(),
        ]);

        // nowy i zmieniony rabat: tylko grupa (cały asortyment) — zakup z pliku zostaje do następnego importu
        $this->patchJson("/api/price-lists/{$list->id}/intake", ['discount_percent' => 30])->assertOk()
            ->assertJsonPath('price_list.discount_percent', 30)
            ->assertJsonPath('price_list.discount_applies_on_next_import', true);
        $this->patchJson("/api/price-lists/{$list->id}/intake", ['discount_percent' => 40])->assertOk()->assertJsonPath('price_list.discount_percent', 40);
        $slot = ProductSourcePrice::query()->sole();
        $this->assertEquals(55, $slot->purchase_price);
        $this->assertNull($slot->discount_percent);
        $this->assertEquals(55, $card->fresh()->purchase_price);
        $global = AssortmentGroup::query()->sole();
        $this->assertSame(AssortmentGroup::GLOBAL_NAME, $global->name);
        $this->assertTrue($global->is_global);
        $this->assertEquals(40, $global->discount_percent);

        // null usuwa grupę; karta tego cennika wskazująca grupę traci wskazanie, cena bez zmian
        $card->forceFill(['assortment_group_id' => $global->id])->save();
        $this->patchJson("/api/price-lists/{$list->id}/intake", ['discount_percent' => null])->assertOk()->assertJsonPath('price_list.discount_percent', null);
        $this->assertSame(0, AssortmentGroup::query()->count());
        $this->assertNull($card->fresh()->assortment_group_id);
        $this->assertEquals(55, $card->fresh()->purchase_price);
        // null bez grupy — nic do zrobienia
        $this->patchJson("/api/price-lists/{$list->id}/intake", ['discount_percent' => null])->assertOk();
    }

    public function test_discount_is_refused_when_global_group_is_used_outside_the_list_or_list_has_groups(): void
    {
        $list = $this->intakeList('MAPA');
        $global = AssortmentGroup::query()->create(['manufacturer' => 'MAPA', 'name' => AssortmentGroup::GLOBAL_NAME, 'discount_percent' => 30, 'is_global' => true]);
        // karta spoza cennika (bez slotu pliku tego cennika) wskazuje grupę — usunięcie zabrałoby jej grupę
        $foreign = Product::query()->create(['sku' => 'X-1', 'name' => 'Obca', 'manufacturer' => 'MAPA', 'catalog_price_net' => 1, 'purchase_price' => 1, 'stock' => 0, 'assortment_group_id' => $global->id]);

        $this->patchJson("/api/price-lists/{$list->id}/intake", ['discount_percent' => null])
            ->assertStatus(422)->assertJsonValidationErrors('discount_percent');
        $this->assertSame($global->id, (int) $foreign->fresh()->assortment_group_id);
        $this->assertSame(1, AssortmentGroup::query()->count());

        // cennik z grupami asortymentowymi: rabat zmienia się w Cenniki → Edytuj
        AssortmentGroup::query()->create(['manufacturer' => 'MAPA', 'name' => 'Rękawice', 'discount_percent' => 20, 'is_global' => false]);
        $this->patchJson("/api/price-lists/{$list->id}/intake", ['discount_percent' => 35])
            ->assertStatus(422)
            ->assertJsonPath('errors.discount_percent.0', 'Cennik ma rabaty w grupach — zmień je w Cenniki → Edytuj.');
        $this->assertEquals(30, $global->fresh()->discount_percent);
        // ten sam rabat co zapisany (formularz wysyła go przy każdym zapisie) przechodzi
        $this->patchJson("/api/price-lists/{$list->id}/intake", ['discount_percent' => 30, 'version' => '2026'])->assertOk();
    }

    public function test_importer_binding_validates_key_and_unbinds(): void
    {
        $list = $this->intakeList('MAPA');

        $this->patchJson("/api/price-lists/{$list->id}/importer", ['importer_key' => 'nie-ma-takiego'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('importer_key');
        $this->assertNull($list->fresh()->importer_key);
        $this->patchJson("/api/price-lists/{$list->id}/importer", [])->assertStatus(422);

        $registered = app(PriceListImporterRegistry::class)->options();
        if ($registered !== []) {
            $key = $registered[0]['key'];
            $this->patchJson("/api/price-lists/{$list->id}/importer", ['importer_key' => $key])
                ->assertOk()
                ->assertJsonPath('price_list.importer_key', $key)
                ->assertJsonPath('price_list.importer_label', $registered[0]['label']);
        }

        // klucz zapisany wcześniej, którego nie ma we wdrożeniu (np. cofnięte wdrożenie) — stan „importer brak”
        $list->forceFill(['importer_key' => 'usuniety-importer'])->save();
        $list->files()->create([
            'sha256' => str_repeat('a', 64), 'disk' => 'local', 'path' => 'price-list-files/'.str_repeat('a', 64).'.xlsx',
            'original_name' => 'mapa.xlsx', 'size' => 10, 'status' => 'new',
        ]);
        $this->getJson('/api/price-lists/files')->assertOk()->assertJsonPath('lists.0.intake.status', PriceList::INTAKE_IMPORTER_MISSING);

        $this->patchJson("/api/price-lists/{$list->id}/importer", ['importer_key' => null])
            ->assertOk()
            ->assertJsonPath('price_list.importer_key', null)
            ->assertJsonPath('price_list.status', PriceList::INTAKE_AWAITING_IMPORTER);
    }

    public function test_importers_lists_registry_options(): void
    {
        $this->getJson('/api/price-lists/importers')
            ->assertOk()
            ->assertExactJson(['importers' => app(PriceListImporterRegistry::class)->options()]);
    }

    public function test_files_tab_shows_new_list_without_cards(): void
    {
        $created = $this->postJson('/api/price-lists/intake', ['manufacturer' => 'MAPA', 'version' => '2025'])->assertCreated();
        // stary cennik bez kart z pliku nadal poza zakładką
        $this->list('Tylko B2B');

        $response = $this->getJson('/api/price-lists/files')->assertOk()->assertJsonCount(1, 'lists');
        $row = $response->json('lists.0');
        $this->assertSame($created->json('price_list.id'), $row['id']);
        $this->assertSame(0, $row['cards']);
        $this->assertSame(PriceList::INTAKE_AWAITING_FILE, $row['intake']['status']);
        $this->assertSame(PriceList::POLICY_MAP_ONLY, $row['intake']['source_policy']);
    }

    public function test_source_pins_are_paginated_by_state_with_human_url(): void
    {
        $list = $this->intakeList('MAPA');
        $other = $this->intakeList('Inny');
        $pinned = $this->pinnedCard($list, 'P-1', 'https://mapa-pro.pl/p-1');
        // adres od człowieka („Wskaż adres”) — karta nie czeka już na stronę
        $this->pinnedCard($list, 'U-1', null, ['shop_source_url' => 'https://sklep.example/u-1']);
        // adres ze sklepu łącznika B2B to nie decyzja człowieka (trustedShopUrl) — karta zostaje na liście
        $b2b = $this->pinnedCard($list, 'U-2', null, ['shop_source_url' => 'https://b2b.anro.net.pl/produkt/u-2']);
        $this->pinnedCard($other, 'X-1', null);
        for ($i = 3; $i <= 52; $i++) {
            $this->pinnedCard($list, 'U-'.$i, null);
        }

        $page = $this->getJson("/api/price-lists/{$list->id}/source-pins?state=unresolved")->assertOk();
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 50, 'total' => 51, 'state' => 'unresolved'], $page->json('meta'));
        $first = $page->json('data.0');
        $this->assertSame([
            'product_id', 'sku', 'name', 'url', 'source_kind', 'match_kind', 'match_key', 'unresolved_reason', 'candidates', 'human_url',
        ], array_keys($first));
        $this->assertSame($b2b->id, $first['product_id']);
        $this->assertSame('U-2', $first['sku']);
        $this->assertNull($first['url']);
        $this->assertSame('brak kodu na stronie producenta', $first['unresolved_reason']);
        $this->assertSame([['url' => 'https://mapa-pro.pl/inna', 'reason' => 'inny kod']], $first['candidates']);
        $this->assertNull($first['human_url']);
        $this->assertNotContains('U-1', array_column($page->json('data'), 'sku'));
        $this->getJson("/api/price-lists/{$list->id}/source-pins?state=unresolved&page=2")->assertOk()->assertJsonCount(1, 'data');

        $this->getJson("/api/price-lists/{$list->id}/source-pins?state=pinned")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_id', $pinned->id)
            ->assertJsonPath('data.0.url', 'https://mapa-pro.pl/p-1')
            ->assertJsonPath('data.0.match_kind', ProductSourcePin::MATCH_EXACT_CODE)
            ->assertJsonPath('data.0.human_url', null);
        $this->getJson("/api/price-lists/{$list->id}/source-pins?state=wszystkie")->assertStatus(422);

        // liczniki mapy w widoku cennika
        $this->getJson('/api/price-lists/files')->assertOk()
            ->assertJsonPath('lists.1.intake.pins', ['pinned' => 1, 'unresolved' => 51, 'human_url' => 1, 'total' => 53])
            ->assertJsonPath('lists.0.intake.pins', ['pinned' => 0, 'unresolved' => 1, 'human_url' => 0, 'total' => 1]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function list(string $manufacturer, array $attributes = []): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => $manufacturer,
            'version' => '2026',
            'original_filename' => 'plik.xlsx',
            'rows_total' => 1,
            'products_created' => 1,
            'products_updated' => 0,
            'rows_skipped' => 0,
            'product_ids' => [],
            ...$attributes,
        ])->fresh();
    }

    private function intakeList(string $manufacturer): PriceList
    {
        return $this->list($manufacturer, [
            'manufacturer_key' => PriceList::manufacturerKey($manufacturer),
            'source_policy' => PriceList::POLICY_MAP_ONLY,
            'original_filename' => null,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function pinnedCard(PriceList $list, string $sku, ?string $url, array $attributes = []): Product
    {
        $card = Product::query()->create([
            'sku' => $sku, 'name' => 'Rękawica '.$sku, 'manufacturer' => (string) $list->manufacturer,
            'catalog_price_net' => 10, 'purchase_price' => 8, 'stock' => 0, ...$attributes,
        ]);
        ProductSourcePin::query()->create([
            'product_id' => $card->id,
            'price_list_id' => $list->id,
            'importer_key' => 'test',
            'importer_version' => 1,
            'url' => $url,
            'source_kind' => $url !== null ? ProductSourcePin::KIND_MANUFACTURER : null,
            'match_kind' => $url !== null ? ProductSourcePin::MATCH_EXACT_CODE : null,
            'match_key' => $url !== null ? $sku : null,
            'unresolved_reason' => $url === null ? 'brak kodu na stronie producenta' : null,
            'candidates' => $url === null ? [['url' => 'https://mapa-pro.pl/inna', 'reason' => 'inny kod']] : null,
        ]);

        return $card;
    }
}
