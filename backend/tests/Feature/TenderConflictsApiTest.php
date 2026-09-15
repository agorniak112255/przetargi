<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Support\RequirementCheck\RequirementCheck;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * Skróty sprzeczności dla listy pozycji przetargu (oznaczenie „! Sprzeczności N”). Same reguły —
 * żadne żądanie do modelu nie może wyjść, a liczba zapytań nie rośnie z liczbą pozycji.
 */
final class TenderConflictsApiTest extends TestCase
{
    use RefreshDatabase;

    private const CARD_SKU = '11202000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_returns_summary_only_for_items_with_catalog_product(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->seededCard();
        $tender = $this->makeTender(User::factory()->create());
        $requirement = Opisowy15Fixture::requirement(1);

        $withProduct = $this->item($tender, 1, $requirement, ['main_product_id' => $product->id]);
        $custom = $this->item($tender, 2, $requirement, [
            'main_product_id' => $product->id,
            'custom_name' => 'Rękawice spoza katalogu',
            'match_source' => 'manual',
        ]);
        $withoutProduct = $this->item($tender, 3, $requirement);
        $tooShort = $this->item($tender, 4, '  ab  ', ['main_product_id' => $product->id]);

        $items = $this->getJson("/api/tenders/{$tender->id}/conflicts")
            ->assertOk()
            ->json('items');

        $this->assertSame([(string) $withProduct->id], array_map('strval', array_keys($items)));
        $this->assertArrayNotHasKey((string) $custom->id, $items, 'oferta custom nie jest kartą z katalogu');
        $this->assertArrayNotHasKey((string) $withoutProduct->id, $items);
        $this->assertArrayNotHasKey((string) $tooShort->id, $items, 'wymaganie krótsze niż 3 znaki po trim');

        // Reguły zmieniają się równolegle — oczekiwania bierzemy z bezpośredniego porównania.
        $expected = app(RequirementCheck::class)->compare($requirement, Product::query()->findOrFail($product->id))['conflicts'];
        $cardKeys = array_column($expected['card_fields'], 'key');
        $entry = $items[(string) $withProduct->id];

        $this->assertSame(['product_id', 'count', 'requirement', 'card_fields'], array_keys($entry));
        $this->assertSame((int) $product->id, $entry['product_id']);
        $this->assertSame($expected['requirement'], $entry['requirement']);
        $this->assertSame($cardKeys, $entry['card_fields']);
        $this->assertSame($expected['count'], $entry['count']);
        $this->assertSame(count(array_unique([...$entry['requirement'], ...$entry['card_fields']])), $entry['count']);
    }

    public function test_item_without_conflicts_is_still_listed_with_zero(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->seededCard();
        $tender = $this->makeTender(User::factory()->create());
        // wymaganie bez parametrów, które reguły umieją sprawdzić
        $item = $this->item($tender, 1, 'Artykuł do oferty', ['main_product_id' => $product->id]);

        $expected = app(RequirementCheck::class)->compare('Artykuł do oferty', Product::query()->findOrFail($product->id))['conflicts'];

        $this->getJson("/api/tenders/{$tender->id}/conflicts")
            ->assertOk()
            ->assertJsonPath("items.{$item->id}.count", $expected['count'])
            ->assertJsonPath("items.{$item->id}.product_id", (int) $product->id);
    }

    public function test_empty_map_is_json_object(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $tender = $this->makeTender(User::factory()->create());
        $this->item($tender, 1, Opisowy15Fixture::requirement(1));

        $response = $this->getJson("/api/tenders/{$tender->id}/conflicts")->assertOk();

        $this->assertSame('{"items":{}}', $response->getContent());
    }

    public function test_query_count_does_not_grow_with_items(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->seededCard();
        $owner = User::factory()->create();
        $requirement = Opisowy15Fixture::requirement(1);

        $one = $this->makeTender($owner, 'PRZ/SPRZ/1');
        $this->item($one, 1, $requirement, ['main_product_id' => $product->id]);

        $three = $this->makeTender($owner, 'PRZ/SPRZ/3');
        foreach ([1, 2, 3] as $lineNo) {
            $this->item($three, $lineNo, $requirement, ['main_product_id' => $product->id]);
        }

        // rozgrzewka: cache uprawnień nie może zafałszować porównania
        $this->getJson("/api/tenders/{$one->id}/conflicts")->assertOk();

        $queriesForOne = $this->countQueries(fn () => $this->getJson("/api/tenders/{$one->id}/conflicts")->assertOk()->assertJsonCount(1, 'items'));
        $queriesForThree = $this->countQueries(fn () => $this->getJson("/api/tenders/{$three->id}/conflicts")->assertOk()->assertJsonCount(3, 'items'));

        $this->assertSame($queriesForOne, $queriesForThree, 'liczba zapytań nie może zależeć od liczby pozycji');
    }

    public function test_user_without_tender_access_is_rejected(): void
    {
        $owner = User::factory()->withRole('handlowiec')->create();
        $stranger = User::factory()->withRole('handlowiec')->create();
        $product = $this->seededCard();
        $tender = $this->makeTender($owner);
        $this->item($tender, 1, Opisowy15Fixture::requirement(1), ['main_product_id' => $product->id]);

        Sanctum::actingAs($stranger);
        $this->getJson("/api/tenders/{$tender->id}/conflicts")->assertForbidden();

        Sanctum::actingAs($owner);
        $this->getJson("/api/tenders/{$tender->id}/conflicts")->assertOk()->assertJsonCount(1, 'items');
    }

    private function countQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** Karta zapisana w bazie — relacja mainProduct czyta ją z tabeli. */
    private function seededCard(): Product
    {
        return Product::query()->findOrFail(Opisowy15Fixture::seed([self::CARD_SKU])[self::CARD_SKU]);
    }

    private function makeTender(User $owner, string $number = 'PRZ/SPRZ/0'): Tender
    {
        return Tender::query()->create([
            'number' => $number,
            'title' => 'Sprzeczności',
            'client_id' => Client::query()->create(['name' => 'Klient'])->id,
            'owner_id' => $owner->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(Tender $tender, int $lineNo, string $requirement, array $attributes = []): TenderItem
    {
        return TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => $lineNo,
            'requirement' => $requirement,
            'quantity' => 10,
            'status' => 'brak',
            ...$attributes,
        ]);
    }
}
