<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\PrestaCategoryMap;
use App\Models\Product;
use App\Models\User;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Presta\PrestaCategoryMapService;
use App\Services\Presta\PrestaCategorySyncService;
use App\Services\PriceListImportService;
use App\Support\BhpAttributeNormalizer;
use App\Support\CatalogCascadeRecall;
use App\Support\PpeAssortment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Audyt ręcznych cenników 22.09.2026 (C4, decyzja użytkownika): kategoria sklepu nadana automatycznie nie jest
 * dowodem rodzaju wyrobu. Znacznik category_source idzie razem z kategorią; rodzina i dopasowanie pomijają
 * kategorię automatu, eksport do Presty dalej czyta products.category.
 */
final class CategoryProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private const GLOVE_LEAF = 'Sklep - kategorie / Rękawice ochronne / Rękawice do olejów i cieczy';

    private const COATED_LEAF = 'Sklep - kategorie / Rękawice ochronne / Rękawice powlekane';

    public function test_family_ignores_category_assigned_by_the_automat(): void
    {
        $assortment = new PpeAssortment;
        $auto = new Product([
            'sku' => '4410-043-000-00',
            'name' => 'ONE TOUCH PRO 4410',
            'category' => self::GLOVE_LEAF,
            'category_source' => Product::CATEGORY_SOURCE_PRESTA_REWRITE,
        ]);
        $mapped = new Product([...$auto->getAttributes(), 'category_source' => Product::CATEGORY_SOURCE_PRESTA_MAP]);
        $fromPriceList = new Product([...$auto->getAttributes(), 'category_source' => Product::CATEGORY_SOURCE_IMPORT]);
        // karta sprzed znacznika: pochodzenie nieznane — kategoria liczy się jak dotąd
        $legacy = new Product([...$auto->getAttributes(), 'category_source' => null]);

        $this->assertNull($assortment->productFamily($auto));
        // mapę kategorii układa człowiek — ścieżka z mapy jest dowodem (decyzja 22.09.2026)
        $this->assertSame(PpeAssortment::FAMILY_GLOVES, $assortment->productFamily($mapped));
        $this->assertSame(PpeAssortment::FAMILY_GLOVES, $assortment->productFamily($fromPriceList));
        $this->assertSame(PpeAssortment::FAMILY_GLOVES, $assortment->productFamily($legacy));
    }

    public function test_cascade_family_scope_does_not_match_automatic_category(): void
    {
        $this->product('AUTO-1', 'ONE TOUCH PRO', self::GLOVE_LEAF, Product::CATEGORY_SOURCE_PRESTA_REWRITE);
        $this->product('IMP-1', 'VITAL 115', 'Rękawice ochronne', Product::CATEGORY_SOURCE_IMPORT);
        $this->product('OLD-1', 'KX 20', 'Rękawice robocze', null);

        $builder = Product::query();
        $method = new ReflectionMethod(CatalogCascadeRecall::class, 'applyFamilyScope');
        $method->setAccessible(true);
        $method->invoke(app(CatalogCascadeRecall::class), $builder, ['family' => null, 'family_nouns' => ['rękawic']]);

        $this->assertSame(['IMP-1', 'OLD-1'], $builder->orderBy('sku')->pluck('sku')->all());
    }

    public function test_rewrite_marks_its_paths_and_leaves_manual_choice_alone(): void
    {
        $this->gloveTree();
        $this->product('AUTO-1', 'Rękawice nitrylowe X', '223', null);
        $this->product('MAN-1', 'Rękawice nitrylowe Y', 'Moja grupa', Product::CATEGORY_SOURCE_MANUAL);

        $this->artisan('presta:rewrite-categories --apply')->assertSuccessful();

        $auto = Product::query()->where('sku', 'AUTO-1')->firstOrFail();
        $this->assertStringContainsString('Rękawice', (string) $auto->category);
        $this->assertSame(Product::CATEGORY_SOURCE_PRESTA_REWRITE, $auto->category_source);
        $this->assertDatabaseHas('products', [
            'sku' => 'MAN-1',
            'category' => 'Moja grupa',
            'category_source' => Product::CATEGORY_SOURCE_MANUAL,
        ]);
    }

    public function test_import_records_where_the_category_came_from(): void
    {
        $this->gloveTree();
        $method = new ReflectionMethod(PriceListImportService::class, 'categoriesFromTree');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $out */
        $out = $method->invoke(app(PriceListImportService::class), [
            ['sku' => 'A', 'name' => 'Rękawice nitrylowe X', 'category' => 'Rękawice'],
            ['sku' => 'B', 'name' => 'QWERTY 123', 'category' => 'Grupa dostawcy'],
            ['sku' => 'C', 'name' => 'QWERTY 456'],
        ]);

        $this->assertSame(Product::CATEGORY_SOURCE_PRESTA_REWRITE, $out[0]['category_source']);
        $this->assertSame('Grupa dostawcy', $out[1]['category']);
        $this->assertSame(Product::CATEGORY_SOURCE_IMPORT, $out[1]['category_source']);
        // pozycja bez kategorii nie zeruje pochodzenia na istniejącej karcie
        $this->assertArrayNotHasKey('category_source', $out[2]);
    }

    public function test_panel_choice_is_marked_manual(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->product('P-1', 'Ręcznik', 'Felpa', Product::CATEGORY_SOURCE_IMPORT);

        $this->patchJson('/api/products/'.$product->id.'/category', ['category' => self::GLOVE_LEAF])->assertOk();
        $this->assertSame(Product::CATEGORY_SOURCE_MANUAL, $product->fresh()?->category_source);

        $this->patchJson('/api/products/'.$product->id.'/category', ['category' => null])->assertOk();
        $this->assertNull($product->fresh()?->category_source);
    }

    public function test_provenance_command_previews_then_marks_only_what_it_can_prove(): void
    {
        $this->gloveTree();
        $manual = $this->product('MAN-1', 'VITAL 115', self::GLOVE_LEAF, null);
        $this->product('AUTO-1', 'VITAL 117', self::GLOVE_LEAF, null);
        // etykieta rodziny, którą wskazuje nazwa — zapis automatu
        $this->product('LBL-1', 'Rękawice nitrylowe KX 20', 'Rękawice', null);
        // etykieta rodziny, której nazwa nie wskazuje — przyszła z kolumny kategorii cennika
        $this->product('LBL-2', 'KX 25', 'Rękawice', null);
        $this->product('RAW-1', 'KX 30', 'Grupa z cennika', null);
        ActivityLog::query()->create([
            'action' => 'products.update',
            'subject_type' => Product::class,
            'subject_id' => $manual->id,
            'meta' => [
                'method' => 'PATCH',
                'path' => 'products/'.$manual->id.'/category',
                'payload' => ['category' => self::GLOVE_LEAF],
            ],
        ]);

        $this->artisan('products:category-provenance')
            ->expectsOutputToContain('ręczne (dziennik) 1, nadane automatem 2, nieznane — bez zmian 2')
            ->expectsOutputToContain('odzyskają kategorię-dowód (category_evidence) po ponownej synchronizacji B2B')
            ->expectsOutputToContain('Podgląd — nic nie zapisano')
            ->assertSuccessful();
        $this->assertSame(0, Product::query()->whereNotNull('category_source')->count());

        $this->artisan('products:category-provenance --apply')->assertSuccessful();

        $this->assertSame(Product::CATEGORY_SOURCE_MANUAL, $manual->fresh()?->category_source);
        $this->assertDatabaseHas('products', ['sku' => 'AUTO-1', 'category_source' => Product::CATEGORY_SOURCE_PRESTA_REWRITE]);
        $this->assertDatabaseHas('products', ['sku' => 'LBL-1', 'category_source' => Product::CATEGORY_SOURCE_PRESTA_REWRITE]);
        $this->assertDatabaseHas('products', ['sku' => 'LBL-2', 'category_source' => null]);
        $this->assertDatabaseHas('products', ['sku' => 'RAW-1', 'category_source' => null]);
    }

    /**
     * Decyzja 22.09.2026: mapa „kategoria lokalna → drzewo Presty” to ręczne tłumaczenie kategorii cennika.
     * Ścieżka, na którą mapa wskazuje, nie jest oznaczana jako automat; ta sama ścieżka bez wpisu w mapie — jest.
     */
    public function test_provenance_command_does_not_mark_map_target_paths_as_automatic(): void
    {
        $this->gloveTree();
        PrestaCategoryMap::query()->create(['local_category' => 'RĘKAWICE POWLEKANE', 'presta_id' => 30]);
        // wpis „ścieżka → ta sama ścieżka” dopisuje przepisanie — nie jest ręcznym tłumaczeniem
        PrestaCategoryMap::query()->create(['local_category' => 'Sklep - kategorie / Rękawice ochronne', 'presta_id' => 20]);
        $this->product('MAP-1', 'ROXY 3420', self::GLOVE_LEAF, null);
        $this->product('AUTO-1', 'Rękawice KX', 'Sklep - kategorie / Rękawice ochronne', null);

        $this->artisan('products:category-provenance --apply')
            ->expectsOutputToContain('nadane automatem 1, nieznane — bez zmian 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('products', ['sku' => 'MAP-1', 'category_source' => null]);
        $this->assertDatabaseHas('products', ['sku' => 'AUTO-1', 'category_source' => Product::CATEGORY_SOURCE_PRESTA_REWRITE]);
    }

    /**
     * Przegląd 22.09.2026 (N1): import od razu zamienia kategorię cennika na ścieżkę drzewa dobraną z nazwy
     * (presta_rewrite), więc bez osobnej kolumny dowód „POWLEKANE” znikał. category zostaje ścieżką sklepu (eksport),
     * a kategoria z cennika idzie do category_evidence.
     */
    public function test_import_keeps_price_list_category_as_evidence_next_to_tree_path(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->coatedTree();
        $user = User::factory()->withRole('admin')->create();

        $this->importRows($user, [[
            'sku' => 'RPX9000', 'name' => 'Rękawice powlekane X', 'category' => 'POWLEKANE',
            'catalog_price_net' => 10.0, 'purchase_price' => 8.0, 'currency' => 'PLN',
        ]]);

        $card = Product::query()->where('sku', 'RPX9000')->firstOrFail();
        $this->assertSame(self::COATED_LEAF, $card->category);
        $this->assertSame(Product::CATEGORY_SOURCE_PRESTA_REWRITE, $card->category_source);
        $this->assertSame('POWLEKANE', $card->category_evidence);
        $this->assertSame('POWLEKANE', $card->categoryAsEvidence());

        // opis z przeczeniem spawania: typ z kategorii-dowodu, nie ze słów „spawalnicze” czy „przecięcie”
        $card->update(['description' => 'Rękawice dziane z powłoką nitrylową na dłoni, chronią przed ścieraniem. '
            .'Nie stosować do prac spawalniczych ani przy ryzyku przecięcia.']);
        $attrs = app(BhpAttributeNormalizer::class)->forProduct($card->fresh());
        $this->assertSame('coated', $attrs['typ_wyrobu']);
        $this->assertNotContains($attrs['typ_wyrobu'], ['welding', 'cut', 'nitrile']);
        $this->assertNotSame('welding', $attrs['przeznaczenie']);

        // eksport do Presty dalej czyta ścieżkę z products.category
        app(PrestaCategoryMapService::class)->autoFillMaps();
        $this->assertSame(40, app(PrestaCategoryMapService::class)->resolveId($card->category));
    }

    public function test_import_evidence_from_form_default_but_not_from_garbage_or_invented_label(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->withRole('admin')->create();
        // dowód z B2B na istniejącej karcie — cennik bez prawdziwej kategorii go nie kasuje
        $this->product('KEEP-1', 'Rękawice nitrylowe KX 20', 'Rękawice', Product::CATEGORY_SOURCE_B2B)
            ->update(['category_evidence' => 'Rękawice nitrylowe']);

        $this->importRows($user, [
            ['sku' => 'KEEP-1', 'name' => 'Rękawice nitrylowe KX 20', 'category' => 'A', 'catalog_price_net' => 10.0, 'purchase_price' => 8.0],
            ['sku' => 'NEW-1', 'name' => 'Rękawice nitrylowe KX 21', 'catalog_price_net' => 10.0, 'purchase_price' => 8.0],
        ]);
        // „A” to śmieć z komórki: kategoria to etykieta wymyślona z nazwy — nie dowód
        $this->assertSame('Rękawice nitrylowe', Product::query()->where('sku', 'KEEP-1')->value('category_evidence'));
        $this->assertNull(Product::query()->where('sku', 'NEW-1')->value('category_evidence'));

        $this->importRows($user, [
            ['sku' => 'DEF-1', 'name' => 'KX 30', 'catalog_price_net' => 10.0, 'purchase_price' => 8.0],
        ], 'Rękawice robocze');
        $this->assertSame('Rękawice robocze', Product::query()->where('sku', 'DEF-1')->value('category_evidence'));
    }

    public function test_tree_rewrites_leave_category_evidence_alone(): void
    {
        $this->coatedTree();
        $rewritten = $this->product('RW-1', 'Rękawice powlekane Y', 'POWLEKANE', Product::CATEGORY_SOURCE_IMPORT);
        $rewritten->update(['category_evidence' => 'POWLEKANE']);

        $this->artisan('presta:rewrite-categories --apply')->assertSuccessful();

        $rewritten->refresh();
        $this->assertSame(self::COATED_LEAF, $rewritten->category);
        $this->assertSame(Product::CATEGORY_SOURCE_PRESTA_REWRITE, $rewritten->category_source);
        $this->assertSame('POWLEKANE', $rewritten->category_evidence);
        $this->assertSame('POWLEKANE', $rewritten->categoryAsEvidence());

        // uzupełnianie opisu przepisuje category na ścieżkę z opisu, dowodu nie rusza
        $refined = $this->product('RF-1', 'QWERTY 77', 'Grupa dostawcy', Product::CATEGORY_SOURCE_IMPORT);
        $refined->update(['category_evidence' => 'Grupa dostawcy']);
        $method = new ReflectionMethod(ProductEnrichmentService::class, 'refineCategoryFromDescription');
        $method->setAccessible(true);
        $method->invoke(app(ProductEnrichmentService::class), $refined, 'Rękawice powlekane nitrylem, dziane.');

        $refined->refresh();
        $this->assertSame(self::COATED_LEAF, $refined->category);
        $this->assertSame('Grupa dostawcy', $refined->category_evidence);
    }

    public function test_manual_choice_wins_over_category_evidence(): void
    {
        $card = new Product([
            'name' => 'KX 20',
            'category' => 'Moja grupa',
            'category_source' => Product::CATEGORY_SOURCE_MANUAL,
            'category_evidence' => 'POWLEKANE',
        ]);
        $this->assertSame('Moja grupa', $card->categoryAsEvidence());

        $auto = new Product([...$card->getAttributes(), 'category' => self::COATED_LEAF, 'category_source' => Product::CATEGORY_SOURCE_PRESTA_REWRITE]);
        $this->assertSame('POWLEKANE', $auto->categoryAsEvidence());
        $bare = new Product([...$auto->getAttributes(), 'category_evidence' => null]);
        $this->assertSame('', $bare->categoryAsEvidence());
    }

    /** N4: ścieżka „…/Obuwie ESD” dobrana automatem nie jest dowodem antystatyki. */
    public function test_automatic_esd_tree_path_does_not_meet_antistatic_requirement(): void
    {
        $assortment = new PpeAssortment;
        $auto = new Product([
            'sku' => 'PB-310',
            'name' => 'Półbuty ochronne PB 310 S3',
            'description' => 'Półbuty skórzane z podnoskiem kompozytowym i wkładką antyprzebiciową.',
            'category' => 'Sklep - kategorie / Obuwie ochronne / Obuwie ESD',
            'category_source' => Product::CATEGORY_SOURCE_PRESTA_REWRITE,
        ]);
        $this->assertFalse($assortment->productMeetsAntistaticRequirement('Półbuty ochronne S3 ESD', $auto));

        // ta sama kategoria z cennika (dowód) — spełnia
        $imported = new Product([...$auto->getAttributes(), 'category_source' => Product::CATEGORY_SOURCE_IMPORT]);
        $this->assertTrue($assortment->productMeetsAntistaticRequirement('Półbuty ochronne S3 ESD', $imported));
    }

    private function coatedTree(): void
    {
        app(PrestaCategorySyncService::class)->storeCategories([
            ['presta_id' => 10, 'parent_presta_id' => 2, 'name' => 'Sklep - kategorie', 'level_depth' => 1, 'active' => true],
            ['presta_id' => 20, 'parent_presta_id' => 10, 'name' => 'Rękawice ochronne', 'level_depth' => 2, 'active' => true],
            ['presta_id' => 40, 'parent_presta_id' => 20, 'name' => 'Rękawice powlekane', 'level_depth' => 3, 'active' => true],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function importRows(User $user, array $rows, ?string $defaultCategory = null): void
    {
        Queue::fake();
        $path = tempnam(sys_get_temp_dir(), 'catev').'.pdf';
        file_put_contents($path, "%PDF-1.4\n");
        $file = new UploadedFile($path, 'cennik.pdf', 'application/pdf', null, true);
        try {
            app(PriceListImportService::class)->importFromProducts($file, 'X', 'v'.uniqid(), $user, $rows, $defaultCategory);
        } finally {
            @unlink($path);
        }
    }

    private function gloveTree(): void
    {
        app(PrestaCategorySyncService::class)->storeCategories([
            ['presta_id' => 10, 'parent_presta_id' => 2, 'name' => 'Sklep - kategorie', 'level_depth' => 1, 'active' => true],
            ['presta_id' => 20, 'parent_presta_id' => 10, 'name' => 'Rękawice ochronne', 'level_depth' => 2, 'active' => true],
            ['presta_id' => 30, 'parent_presta_id' => 20, 'name' => 'Rękawice do olejów i cieczy', 'level_depth' => 3, 'active' => true],
        ]);
    }

    private function product(string $sku, string $name, ?string $category, ?string $source): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'X',
            'category' => $category,
            'category_source' => $source,
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);
    }
}
