<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BrandDictionaryEntry;
use App\Models\Product;
use App\Models\User;
use App\Support\BrandDictionary;
use App\Support\CatalogManufacturerContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminBrandDictionaryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->product('3M', 'MMM-1');
        $this->product('3M', 'MMM-2');
        $this->product('3M', 'MMM-3');
        $this->product('BHP', 'BHP-1');
        $this->product('Ansell', 'ANS-1');
        $this->product('Ansell', 'ANS-2');
        CatalogManufacturerContext::forgetCache();
    }

    public function test_index_lists_catalog_producers_with_counts_sorted_ascending(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $bhp = BrandDictionaryEntry::query()->create([
            'term' => 'BHP',
            'kind' => BrandDictionaryEntry::KIND_PRODUCER,
            'detect_in_query' => false,
            'note' => 'słowo w nazwach kart',
        ]);
        BrandDictionaryEntry::query()->create([
            'term' => 'Peltor',
            'kind' => BrandDictionaryEntry::KIND_BRAND,
            'manufacturer' => '3M',
        ]);

        $response = $this->getJson('/api/admin/brand-dictionary')->assertOk();

        $response->assertJsonPath('kinds', [
            'producer' => 'Producent',
            'brand' => 'Marka',
            'exclusion' => 'Wykluczenie',
        ]);
        $this->assertSame(
            [
                ['name' => 'BHP', 'key' => 'bhp', 'cards_count' => 1, 'entry_id' => $bhp->id, 'detect_in_query' => false],
                ['name' => 'Ansell', 'key' => 'ansell', 'cards_count' => 2, 'entry_id' => null, 'detect_in_query' => true],
                ['name' => '3M', 'key' => '3m', 'cards_count' => 3, 'entry_id' => null, 'detect_in_query' => true],
            ],
            $response->json('producers'),
        );
        $response->assertJsonCount(2, 'entries')
            ->assertJsonPath('entries.0.term', 'BHP')
            ->assertJsonPath('entries.0.kind', 'producer')
            ->assertJsonPath('entries.0.detect_in_query', false)
            ->assertJsonPath('entries.0.note', 'słowo w nazwach kart')
            ->assertJsonPath('entries.1.term', 'Peltor')
            ->assertJsonPath('entries.1.term_key', 'peltor')
            ->assertJsonPath('entries.1.manufacturer', '3M');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', (string) $response->json('entries.1.updated_at'));
    }

    /**
     * Kolumna „rozpoznawaj w zapytaniu” pokazuje to, co naprawdę dzieje się z zapytaniem. Pierwsza wersja
     * zakładała „brak wpisu = tak”, a domyślnie rozpoznawani są tylko producenci ze zbioru z konfiguracji
     * domen — producent spoza niego stał na ekranie jako rozpoznawany, choć wyszukiwarka go nie widziała.
     */
    public function test_producer_detection_shows_the_real_search_behaviour(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->product('Brandnowy', 'BN-1');
        CatalogManufacturerContext::forgetCache();

        $row = fn (): array => collect($this->getJson('/api/admin/brand-dictionary')->assertOk()->json('producers'))
            ->firstWhere('key', 'brandnowy');

        // spoza konfiguracji i bez wpisu — wyszukiwarka tej nazwy nie rozpoznaje
        $this->assertFalse($row()['detect_in_query']);
        $this->assertTrue(collect($this->getJson('/api/admin/brand-dictionary')->json('producers'))->firstWhere('key', '3m')['detect_in_query']);

        // włączenie wpisem słownika — teraz rozpoznaje, i ekran to widzi
        $this->postJson('/api/admin/brand-dictionary', ['term' => 'Brandnowy', 'kind' => 'producer', 'detect_in_query' => true])
            ->assertCreated();
        $this->assertTrue($row()['detect_in_query']);
    }

    public function test_store_brand_saves_canonical_manufacturer_and_dictionary_sees_it(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        // słownik wczytany przed zapisem — zapis musi go unieważnić bez restartu procesu
        $this->assertNull(app(BrandDictionary::class)->producerFor('peltor'));

        $this->postJson('/api/admin/brand-dictionary', [
            'term' => '  Peltor ',
            'kind' => 'brand',
            'manufacturer' => '3m',
            'note' => null,
        ])
            ->assertCreated()
            ->assertJsonPath('entry.term', 'Peltor')
            ->assertJsonPath('entry.term_key', 'peltor')
            ->assertJsonPath('entry.kind', 'brand')
            ->assertJsonPath('entry.manufacturer', '3M')
            ->assertJsonPath('entry.detect_in_query', true)
            ->assertJsonPath('entry.note', null);

        $this->assertDatabaseHas('brand_dictionary_entries', ['term_key' => 'peltor', 'manufacturer' => '3M']);
        $this->assertSame('3M', app(BrandDictionary::class)->producerFor('peltor'));
    }

    public function test_store_rejects_unknown_manufacturer_duplicate_and_empty_key(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        BrandDictionaryEntry::query()->create([
            'term' => 'Peltor',
            'kind' => BrandDictionaryEntry::KIND_BRAND,
            'manufacturer' => '3M',
        ]);

        $this->postJson('/api/admin/brand-dictionary', [
            'term' => 'Moldex',
            'kind' => 'brand',
            'manufacturer' => 'Nieistniejący',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['manufacturer' => 'Nie ma takiego producenta w katalogu.']);

        $this->postJson('/api/admin/brand-dictionary', ['term' => 'Moldex', 'kind' => 'brand'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['manufacturer']);

        $this->postJson('/api/admin/brand-dictionary', [
            'term' => 'PELTOR',
            'kind' => 'exclusion',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term' => 'To słowo już jest w słowniku.']);

        $this->postJson('/api/admin/brand-dictionary', [
            'term' => '™',
            'kind' => 'exclusion',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term' => 'Słowo musi zawierać literę albo cyfrę.']);

        $this->postJson('/api/admin/brand-dictionary', ['term' => 'kask', 'kind' => 'slowo'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['kind']);

        $this->assertSame(1, BrandDictionaryEntry::query()->count());
    }

    /**
     * Pomyłka „Peltor” jako producent zamiast marki: słowo byłoby rozpoznawane w zapytaniu, a że żadna karta
     * nie ma producenta „Peltor”, wyszukiwarka uznałaby markę za nieobecną w katalogu i wyzerowała producenta
     * oraz model w intencji. Producent musi istnieć w katalogu i zapisuje się w jego pisowni.
     */
    public function test_producer_entry_must_name_a_catalog_producer(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->postJson('/api/admin/brand-dictionary', ['term' => 'Peltor', 'kind' => 'producer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term']);

        $created = $this->postJson('/api/admin/brand-dictionary', [
            'term' => 'bhp',
            'kind' => 'producer',
            'detect_in_query' => false,
        ])->assertCreated();
        $this->assertSame('BHP', $created->json('entry.term'));

        // zmiana rodzaju wpisu na producenta też przechodzi przez tę samą bramkę
        $exclusion = BrandDictionaryEntry::query()->create(['term' => 'kask', 'kind' => BrandDictionaryEntry::KIND_EXCLUSION]);
        $this->patchJson('/api/admin/brand-dictionary/'.$exclusion->id, ['kind' => 'producer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term']);
    }

    public function test_update_and_destroy_entry(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $peltor = BrandDictionaryEntry::query()->create([
            'term' => 'Peltor',
            'kind' => BrandDictionaryEntry::KIND_BRAND,
            'manufacturer' => '3M',
        ]);
        $kask = BrandDictionaryEntry::query()->create([
            'term' => 'kask',
            'kind' => BrandDictionaryEntry::KIND_EXCLUSION,
        ]);

        $this->patchJson("/api/admin/brand-dictionary/{$peltor->id}", [
            'detect_in_query' => false,
            'note' => 'tylko tłumaczenie na producenta',
        ])
            ->assertOk()
            ->assertJsonPath('entry.id', $peltor->id)
            ->assertJsonPath('entry.term', 'Peltor')
            ->assertJsonPath('entry.manufacturer', '3M')
            ->assertJsonPath('entry.detect_in_query', false)
            ->assertJsonPath('entry.note', 'tylko tłumaczenie na producenta');

        // zmiana słowa na klucz innego wpisu — ten sam komunikat co przy dodawaniu
        $this->patchJson("/api/admin/brand-dictionary/{$peltor->id}", ['term' => 'KASK'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term' => 'To słowo już jest w słowniku.']);

        // własne słowo w innej pisowni nie jest duplikatem
        $this->patchJson("/api/admin/brand-dictionary/{$peltor->id}", ['term' => 'PELTOR'])
            ->assertOk()
            ->assertJsonPath('entry.term', 'PELTOR');

        // rodzaj „marka” bez producenta ani w ciele, ani w wierszu
        $this->patchJson("/api/admin/brand-dictionary/{$kask->id}", ['kind' => 'brand'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['manufacturer']);

        $this->patchJson("/api/admin/brand-dictionary/{$kask->id}", ['kind' => 'brand', 'manufacturer' => 'ansell'])
            ->assertOk()
            ->assertJsonPath('entry.kind', 'brand')
            ->assertJsonPath('entry.manufacturer', 'Ansell');

        $this->patchJson("/api/admin/brand-dictionary/{$peltor->id}", ['manufacturer' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['manufacturer']);

        $this->deleteJson("/api/admin/brand-dictionary/{$peltor->id}")->assertNoContent();
        $this->assertDatabaseMissing('brand_dictionary_entries', ['id' => $peltor->id]);
        $this->assertNull(app(BrandDictionary::class)->producerFor('peltor'));

        $this->deleteJson("/api/admin/brand-dictionary/{$peltor->id}")->assertNotFound();
    }

    public function test_user_without_dictionaries_permission_is_forbidden(): void
    {
        // dostęp do panelu administracji nie wystarcza — słownik ma własne uprawnienie
        $user = User::factory()->withRole('handlowiec')->create();
        $user->givePermissionTo('admin.access');
        Sanctum::actingAs($user);
        $entry = BrandDictionaryEntry::query()->create([
            'term' => 'kask',
            'kind' => BrandDictionaryEntry::KIND_EXCLUSION,
        ]);

        $this->getJson('/api/admin/brand-dictionary')->assertForbidden();
        $this->postJson('/api/admin/brand-dictionary', ['term' => 'wersja', 'kind' => 'exclusion'])->assertForbidden();
        $this->patchJson("/api/admin/brand-dictionary/{$entry->id}", ['note' => 'x'])->assertForbidden();
        $this->deleteJson("/api/admin/brand-dictionary/{$entry->id}")->assertForbidden();
        $this->assertDatabaseHas('brand_dictionary_entries', ['id' => $entry->id, 'note' => null]);
    }

    private function product(string $manufacturer, string $sku): void
    {
        Product::query()->create([
            'sku' => $sku,
            'name' => 'Wyrób '.$sku,
            'manufacturer' => $manufacturer,
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);
    }
}
