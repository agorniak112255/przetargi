<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductSourcePin;
use App\Models\ProductSourcePrice;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\ModelGroupPlanner;
use App\Services\Enrichment\PartsTable\PartsTablePin;
use App\Services\Enrichment\Sources\MappedSourcePin;
use App\Services\Enrichment\Sources\SourcePins;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePartsTableResolver;
use Tests\TestCase;

/**
 * Kolejność przypięcia karty (SourcePins, 10.10.2026): adres człowieka → tabela części → mapa importera (tylko cennik
 * map_only i wiersz tego cennika); powody blokady karty map_only bez przypięcia; kształt MappedSourcePin i PartsTablePin
 * jako SourcePin; klucz grupy modelu z adresu mapy.
 */
final class SourcePinsTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://www.example.com/produkt/rekawice-z100';

    public function test_map_only_card_with_resolved_row_gets_mapped_pin(): void
    {
        $card = $this->card('Z100');
        $list = $this->priceList($card, PriceList::POLICY_MAP_ONLY);
        $this->pinRow($card, $list, ['spec' => ['Rozmiar: 9', '', 'Rozmiar: 9', 'Kolor: Czarny'], 'image_url' => 'https://www.example.com/z100.jpg']);

        $pin = $this->pins()->pinFor($card);

        $this->assertInstanceOf(MappedSourcePin::class, $pin);
        $this->assertNull($this->pins()->blockedReason($card));
        $this->assertSame(self::PAGE, $pin->url());
        $this->assertSame('Rękawice Z100', $pin->title());
        $this->assertSame(['Rozmiar: 9', 'Kolor: Czarny'], $pin->specLines());
        $this->assertSame('source_map', $pin->payloadKey());
        $this->assertSame('map:'.sha1(DescriptionVersionStore::sourceUrlKey(self::PAGE)), $pin->groupKey());
        $this->assertSame(DescriptionVersionStore::MAPPED_SOURCE_REASON, $pin->publishReason());
        $this->assertTrue($pin->handlesImages());
        $this->assertSame(
            ['verdict' => 'hard', 'reason' => 'mapa importera zeta-2026: exact_code Z100', 'key_type' => 'manufacturer_code', 'key' => 'Z100', 'where' => 'text'],
            $pin->identity(),
        );
        $this->assertStringContainsString('Źródło opisu: wyłącznie strona producenta '.self::PAGE, $pin->promptNote());
    }

    public function test_identity_key_type_follows_match_kind_and_shop_page_note_limits_to_this_product(): void
    {
        $card = $this->card('Z100');
        $list = $this->priceList($card, PriceList::POLICY_MAP_ONLY);
        $row = $this->pinRow($card, $list, ['match_kind' => ProductSourcePin::MATCH_EAN, 'match_key' => '5901234567890', 'source_kind' => ProductSourcePin::KIND_SHOP]);

        $pin = MappedSourcePin::fromRow($row);
        $this->assertSame('ean', $pin->identity()['key_type']);
        $this->assertFalse($pin->handlesImages());
        $this->assertStringContainsString('strona dostawcy/sklepu', $pin->promptNote());
        $this->assertStringContainsString('opisuj tylko ten wyrób', $pin->promptNote());

        $row->update(['match_kind' => ProductSourcePin::MATCH_MODEL, 'match_key' => 'Z100']);
        $this->assertSame('model', MappedSourcePin::fromRow($row->fresh())->identity()['key_type']);
        $row->update(['match_kind' => ProductSourcePin::MATCH_SHORT_CODE]);
        $this->assertSame('manufacturer_code', MappedSourcePin::fromRow($row->fresh())->identity()['key_type']);
    }

    public function test_blocked_reasons_of_map_only_card_without_pin(): void
    {
        $card = $this->card('Z100');
        $list = $this->priceList($card, PriceList::POLICY_MAP_ONLY);
        $this->assertNull($this->pins()->pinFor($card));
        $this->assertSame(SourcePins::REASON_NO_MAP, $this->pins()->blockedReason($card));

        // wiersz innego cennika (karta przeszła do innego cennika po mapowaniu) — jak brak mapy
        $other = PriceList::query()->create(['manufacturer' => 'INNY', 'manufacturer_key' => 'inny', 'version' => '1', 'original_filename' => 'x.xlsx']);
        $row = $this->pinRow($card, $other);
        $this->assertSame(SourcePins::REASON_NO_MAP, $this->pins()->blockedReason($card));

        $row->update(['price_list_id' => $list->id, 'url' => null, 'unresolved_reason' => 'dwie strony z tym kodem']);
        $this->assertSame('dwie strony z tym kodem', $this->pins()->blockedReason($card));
        $row->update(['unresolved_reason' => null]);
        $this->assertSame(SourcePins::REASON_UNRESOLVED, $this->pins()->blockedReason($card));

        // strona z mapy odrzucona przez handlowca — nie wraca jako źródło, karta do przeglądu
        $row->update(['url' => self::PAGE]);
        ProductDescriptionVersion::query()->forceCreate([
            'product_id' => $card->id, 'status' => ProductDescriptionVersion::STATUS_REJECTED,
            'origin' => ProductDescriptionVersion::ORIGIN_ENRICHMENT, 'description' => 'Opis z odrzuconej strony.',
            'primary_source_url' => 'https://example.com/produkt/rekawice-z100/',
            'enrichment_payload' => [DescriptionVersionStore::META_KEY => ['url_blocked' => true]],
        ]);
        $this->assertNull($this->pins()->pinFor($card));
        $this->assertSame(SourcePins::REASON_REJECTED.': '.self::PAGE, $this->pins()->blockedReason($card));
    }

    public function test_link_chosen_by_a_person_and_old_price_lists_are_not_pinned_nor_blocked(): void
    {
        $human = $this->card('Z100', ['shop_source_url' => 'https://www.example.org/z100']);
        $list = $this->priceList($human, PriceList::POLICY_MAP_ONLY);
        $this->pinRow($human, $list);
        $this->assertSame(['pin' => null, 'reason' => null], $this->pins()->resolve($human));

        $legacy = $this->card('Z200', ['manufacturer' => 'INNY']);
        $old = PriceList::query()->create(['manufacturer' => 'INNY', 'manufacturer_key' => 'inny', 'version' => '1', 'original_filename' => 'x.xlsx']);
        ProductSourcePrice::query()->create(['product_id' => $legacy->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $old->id, 'catalog_price_net' => 1, 'purchase_price' => 1, 'currency' => 'PLN']);
        $this->pinRow($legacy, $old);
        $this->assertSame(['pin' => null, 'reason' => null], $this->pins()->resolve($legacy));

        $noSlot = $this->card('Z300');
        $this->assertSame(['pin' => null, 'reason' => null], $this->pins()->resolve($noSlot));
    }

    public function test_parts_table_pin_wins_over_the_map_and_keeps_its_shape(): void
    {
        $coba = $this->card('AF010707', ['manufacturer' => 'Coba', 'name' => 'Orthomat Standard Szary 0.9m x 18.3m']);
        $list = $this->priceList($coba, PriceList::POLICY_MAP_ONLY);
        $this->pinRow($coba, $list);
        FakePartsTableResolver::install([
            'AF010707' => FakePartsTableResolver::pin('AF010707', 'https://www.coba.com/pl/produkt/orthomat-standard', '0,9 m x 18,3 m', 'Szary', 43.9, null, true, 'AF0107'),
        ]);

        $pin = $this->pins()->pinFor($coba);

        $this->assertInstanceOf(PartsTablePin::class, $pin);
        $this->assertNull($this->pins()->blockedReason($coba));
        $this->assertSame('parts_table', $pin->payloadKey());
        $this->assertSame('coba|page:orthomat-standard', $pin->groupKey());
        $this->assertSame(DescriptionVersionStore::PARTS_TABLE_REASON, $pin->publishReason());
        $this->assertTrue($pin->handlesImages());
        $this->assertSame('https://www.coba.com/pl/produkt/orthomat-standard', $pin->url());
        $this->assertSame('tabela części producenta: AF010707 (skrót cennika AF0107)', $pin->logLabel());
    }

    public function test_planner_groups_cards_of_one_mapped_page_only_with_model_profile(): void
    {
        config()->set('manufacturer_profiles.profiles.coba.model', ['group' => 'name_stem', 'min_members' => 2]);
        $a = $this->card('AF010706', ['manufacturer' => 'Coba', 'name' => 'Orthomat Standard Czarny 1.2m x 18.3m']);
        $b = $this->card('XX990001', ['manufacturer' => 'Coba', 'name' => 'Mata Comfort Zone rolka 0.9m x 18.3m']);
        $c = $this->card('AF010799', ['manufacturer' => 'Coba', 'name' => 'Orthomat Standard Niebieski 0.9m x 1.5m']);
        $list = $this->priceList($a, PriceList::POLICY_MAP_ONLY);
        foreach ([$b, $c] as $card) {
            ProductSourcePrice::query()->create(['product_id' => $card->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $list->id, 'catalog_price_net' => 1, 'purchase_price' => 1, 'currency' => 'PLN']);
        }
        $this->pinRow($a, $list);
        $this->pinRow($b, $list);
        $this->pinRow($c, $list, ['url' => 'https://www.example.com/produkt/inna-mata']);

        $groups = app(ModelGroupPlanner::class)->groups([(int) $a->id, (int) $b->id, (int) $c->id]);

        $mapKey = 'map:'.sha1(DescriptionVersionStore::sourceUrlKey(self::PAGE));
        $this->assertSame($mapKey, $groups[0]->key);
        $this->assertSame([(int) $a->id, (int) $b->id], $groups[0]->memberIds);
        $this->assertCount(2, $groups);
        $this->assertSame([(int) $c->id], $groups[1]->memberIds);

        // marka bez profilu grupowania: ta sama strona z mapy nie łączy kart w model
        $x = $this->card('Z100');
        $y = $this->card('Z101');
        $zeta = $this->priceList($x, PriceList::POLICY_MAP_ONLY);
        ProductSourcePrice::query()->create(['product_id' => $y->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $zeta->id, 'catalog_price_net' => 1, 'purchase_price' => 1, 'currency' => 'PLN']);
        $this->pinRow($x, $zeta);
        $this->pinRow($y, $zeta);

        $single = app(ModelGroupPlanner::class)->groups([(int) $x->id, (int) $y->id]);
        $this->assertCount(2, $single);
        $this->assertSame(['', ''], array_map(static fn ($g): string => $g->key, $single));
    }

    private function pins(): SourcePins
    {
        return app(SourcePins::class);
    }

    /** @param  array<string, mixed>  $extra */
    private function card(string $sku, array $extra = []): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => 'Rękawice nitrylowe ZETA '.$sku, 'manufacturer' => 'ZETA SAFETY',
            'catalog_price_net' => 10, 'purchase_price' => 8, 'stock' => 0, 'enrichment_status' => Product::ENRICHMENT_NONE,
            ...$extra,
        ]);
    }

    private function priceList(Product $card, ?string $policy): PriceList
    {
        $list = PriceList::query()->create([
            'manufacturer' => (string) $card->manufacturer, 'manufacturer_key' => PriceList::manufacturerKey((string) $card->manufacturer),
            'version' => '2026', 'original_filename' => 'cennik.xlsx', 'source_policy' => $policy,
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $list->id,
            'catalog_price_net' => 10, 'purchase_price' => 8, 'currency' => 'PLN',
        ]);

        return $list;
    }

    /** @param  array<string, mixed>  $extra */
    private function pinRow(Product $card, PriceList $list, array $extra = []): ProductSourcePin
    {
        return ProductSourcePin::query()->create([
            'product_id' => $card->id, 'price_list_id' => $list->id, 'importer_key' => 'zeta-2026', 'importer_version' => 1,
            'url' => self::PAGE, 'source_kind' => ProductSourcePin::KIND_MANUFACTURER, 'page_title' => 'Rękawice Z100',
            'match_kind' => ProductSourcePin::MATCH_EXACT_CODE, 'match_key' => 'Z100', 'checked_at' => now(),
            ...$extra,
        ]);
    }
}
