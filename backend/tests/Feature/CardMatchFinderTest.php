<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CardMatchCandidate;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Services\Catalog\CardMatchFinder;
use App\Support\ProductIdentifierCode;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Propozycje łączenia kart (plan łączenia kart, etap B) na przypadkach z produkcji 23.09.2026: karta P4S „ZPPV99C”
 * (producent „ANRO”, kod producenta „IF/016/F/PS”) obok karty Anro, której SKU w B2B Anro to ten kod; EAN karty
 * Raw-Pol/Procera równy EAN-owi z cennika producenta; konflikty, których scalenie nie przenosi.
 */
final class CardMatchFinderTest extends TestCase
{
    use RefreshDatabase;

    private B2bAccount $anro;

    private B2bAccount $p4s;

    private B2bAccount $rawpol;

    private B2bAccount $procera;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->anro = $this->account('anro');
        $this->p4s = $this->account('p4s');
        $this->rawpol = $this->account('rawpol');
        $this->procera = $this->account('procera');
    }

    public function test_p4s_manufacturer_code_equal_to_anro_remote_sku_gives_pending_pair(): void
    {
        $anroCard = $this->anroCard('IF/016/F/PS');
        $p4sCard = $this->p4sCard('ZPPV99C', 'ANRO', 'IF/016/F/PS');

        $result = app(CardMatchFinder::class)->evaluate($p4sCard);

        $this->assertSame([
            'status' => 'pending',
            'target_product_id' => $anroCard->id,
            'matched_by' => 'manufacturer_code',
            'matched_value' => 'IF016FPS',
            'matched_source_key' => 'b2b:'.$this->anro->id,
            'brand' => 'anro',
            'hits' => 1,
            'positions' => 1,
            'reason' => null,
            'conflict_product_ids' => null,
        ], $result);

        $summary = app(CardMatchFinder::class)->refresh();
        $this->assertSame(1, $summary['pending']);
        $this->assertSame(0, $summary['conflict']);
        $this->assertSame(0, $summary['removed']);
        $row = CardMatchCandidate::query()->sole();
        $this->assertSame($p4sCard->id, $row->source_product_id);
        $this->assertSame($anroCard->id, $row->target_product_id);
        $this->assertSame('IF016FPS', $row->matched_value);
        $this->assertSame(['sku' => 'ZPPV99C', 'name' => 'Rękawice ANRO ZPPV99C', 'manufacturer' => 'ANRO'], $row->source_snapshot);
        $this->assertNotNull($row->last_seen_at);

        // drugi przebieg — ta sama para, bez powtórzeń
        $this->assertSame(1, app(CardMatchFinder::class)->refresh()['pending']);
        $this->assertSame(1, CardMatchCandidate::query()->count());
        // karta Anro (chroniona) nie jest duplikatem niczego
        $this->assertNull(app(CardMatchFinder::class)->evaluate($anroCard));
    }

    public function test_owner_identifier_codes_match_too_and_every_hit_position_counts(): void
    {
        // kod producenta na karcie producenta jako identyfikator konta Anro (source_code), dwie pozycje P4S
        $anroCard = $this->card('IF/020', 'Anro');
        $this->link($anroCard, $this->anro, 'A-20', 'A-20');
        $this->identifier($anroCard, 'b2b:'.$this->anro->id, 'A-20', 'source_code', 'IF/020/S');
        $this->identifier($anroCard, 'b2b:'.$this->anro->id, 'A-21', 'source_code', 'IF/020/M');
        $p4sCard = $this->card('P4S-20', 'ANRO');
        $this->link($p4sCard, $this->p4s, 'P-20S', 'P4S-20S');
        $this->link($p4sCard, $this->p4s, 'P-20M', 'P4S-20M');
        $this->link($p4sCard, $this->p4s, 'P-20L', 'P4S-20L');
        $this->identifier($p4sCard, 'b2b:'.$this->p4s->id, 'P-20S', 'manufacturer_code', 'IF/020/S');
        $this->identifier($p4sCard, 'b2b:'.$this->p4s->id, 'P-20M', 'manufacturer_code', 'IF/020/M');
        $this->identifier($p4sCard, 'b2b:'.$this->p4s->id, 'P-20L', 'manufacturer_code', 'IF/020/L');

        $result = app(CardMatchFinder::class)->evaluate($p4sCard);

        $this->assertSame('pending', $result['status']);
        $this->assertSame($anroCard->id, $result['target_product_id']);
        $this->assertSame(2, $result['hits']);
        $this->assertSame(3, $result['positions']);
        $this->assertSame('IF020M', $result['matched_value']);
    }

    public function test_manually_merged_pair_and_distributor_codes_on_producer_card_give_nothing(): void
    {
        // ręcznie połączone 23.09: powiązanie i identyfikator P4S już na karcie Anro
        $anroCard = $this->anroCard('IF/016/F/PS');
        $this->link($anroCard, $this->p4s, 'P-1', 'ZPPV99C', merged: true);
        $this->identifier($anroCard, 'b2b:'.$this->p4s->id, 'P-1', 'manufacturer_code', 'IF/016/F/PS');

        $summary = app(CardMatchFinder::class)->refresh();

        $this->assertSame(['pending' => 0, 'conflict' => 0, 'removed' => 0], array_slice($summary, 0, 3));
        $this->assertSame(0, CardMatchCandidate::query()->count());
    }

    public function test_ean_of_rawpol_and_procera_cards_equal_to_producer_file_ean_gives_pending_pairs(): void
    {
        $list = PriceList::query()->create(['manufacturer' => 'Ansell', 'version' => '2026-09']);
        $ansellCard = $this->card('11-840-8', 'Ansell');
        $this->fileSlot($ansellCard, $list);
        $this->identifier($ansellCard, 'file:'.$list->id, '11-840-8', 'ean', '5012345678900');
        $rawpolCard = $this->card('RP-11840', 'ANSELL');
        $this->link($rawpolCard, $this->rawpol, 'RP-1', 'RP-11840');
        $this->identifier($rawpolCard, 'b2b:'.$this->rawpol->id, 'RP-1', 'ean', '5012345678900');
        $proceraCard = $this->card('PR-11840', 'Ansell Healthcare');
        $this->link($proceraCard, $this->procera, 'PR-1', 'PR-11840');
        $this->identifier($proceraCard, 'b2b:'.$this->procera->id, 'PR-1', 'ean', '05012345678900');

        $finder = app(CardMatchFinder::class);
        $rawpol = $finder->evaluate($rawpolCard);
        $this->assertSame('pending', $rawpol['status']);
        $this->assertSame($ansellCard->id, $rawpol['target_product_id']);
        $this->assertSame('ean', $rawpol['matched_by']);
        $this->assertSame('5012345678900', $rawpol['matched_value']);
        $this->assertSame('file:'.$list->id, $rawpol['matched_source_key']);
        $this->assertSame('ansell', $rawpol['brand']);

        $summary = $finder->refresh();
        $this->assertSame(2, $summary['pending']);
        $this->assertEqualsCanonicalizing(
            [$rawpolCard->id, $proceraCard->id],
            CardMatchCandidate::query()->where('target_product_id', $ansellCard->id)->pluck('source_product_id')->all(),
        );
        // refresh i evaluate dają ten sam wynik
        $row = CardMatchCandidate::query()->where('source_product_id', $rawpolCard->id)->sole();
        foreach ($rawpol as $field => $value) {
            $this->assertSame($value, $row->{$field}, $field);
        }
    }

    public function test_internal_isbn_carton_and_invalid_eans_are_not_keys(): void
    {
        $list = PriceList::query()->create(['manufacturer' => 'Ansell', 'version' => '2026-09']);
        $ansellCard = $this->card('11-840-8', 'Ansell');
        $this->fileSlot($ansellCard, $list);
        $rawpolCard = $this->card('RP-11840', 'Ansell');
        $this->link($rawpolCard, $this->rawpol, 'RP-1', 'RP-11840');
        // obieg wewnętrzny (20–29), ISBN (978), GTIN-14 kartonu, pack_ean i zła suma kontrolna
        foreach (['2012345678903', '9788301000004', '15012345678907'] as $ean) {
            $this->identifier($ansellCard, 'file:'.$list->id, $ean, 'ean', $ean);
            $this->identifier($rawpolCard, 'b2b:'.$this->rawpol->id, 'RP-1', 'ean', $ean);
        }
        $this->identifier($ansellCard, 'file:'.$list->id, 'K', 'pack_ean', '5012345678900');
        $this->identifier($rawpolCard, 'b2b:'.$this->rawpol->id, 'RP-1', 'pack_ean', '5012345678900');
        $this->identifier($ansellCard, 'file:'.$list->id, 'Z', 'ean', '5012345678901');
        $this->identifier($rawpolCard, 'b2b:'.$this->rawpol->id, 'RP-1', 'ean', '5012345678901');

        $this->assertNull(app(CardMatchFinder::class)->evaluate($rawpolCard));
        $this->assertSame(0, app(CardMatchFinder::class)->refresh()['pending']);
    }

    public function test_code_needs_same_canonical_brand_and_five_characters(): void
    {
        $anroCard = $this->anroCard('AB/12');
        $anroLong = $this->anroCard('IF/030/L');
        // „AB12” — za krótki; kod Anro na karcie P4S innej marki — to nie ten wyrób
        $short = $this->p4sCard('P4S-1', 'ANRO', 'AB-12');
        $otherBrand = $this->p4sCard('P4S-2', 'Uvex', 'IF/030/L');

        $finder = app(CardMatchFinder::class);
        $this->assertNull($finder->evaluate($short));
        $this->assertNull($finder->evaluate($otherBrand));
        $this->assertSame(0, $finder->refresh()['pending']);
        $this->assertNotNull($anroCard->id);
        $this->assertNotNull($anroLong->id);
    }

    public function test_distributor_card_is_never_a_target(): void
    {
        // P4S i Raw-Pol: ten sam kod Anro na dwóch kartach dystrybutorów, karty Anro brak
        $p4sCard = $this->p4sCard('ZPPV99C', 'ANRO', 'IF/016/F/PS');
        $rawpolCard = $this->card('RP-016', 'Anro');
        $this->link($rawpolCard, $this->rawpol, 'RP-016', 'IF/016/F/PS');
        $this->identifier($rawpolCard, 'b2b:'.$this->rawpol->id, 'RP-016', 'source_code', 'IF/016/F/PS');
        $this->identifier($rawpolCard, 'b2b:'.$this->rawpol->id, 'RP-016', 'manufacturer_code', 'IF/016/F/PS');

        $finder = app(CardMatchFinder::class);
        $this->assertNull($finder->evaluate($p4sCard));
        $this->assertNull($finder->evaluate($rawpolCard));
        $this->assertSame(0, $finder->refresh()['pending']);
        $this->assertSame(0, CardMatchCandidate::query()->count());
    }

    public function test_ean_pointing_at_two_producer_cards_is_conflict_without_target(): void
    {
        $list = PriceList::query()->create(['manufacturer' => 'Ansell', 'version' => '2026-09']);
        $first = $this->card('11-840-8', 'Ansell');
        $this->fileSlot($first, $list);
        $this->identifier($first, 'file:'.$list->id, '11-840-8', 'ean', '5012345678900');
        $second = $this->card('11-840-9', 'Ansell');
        $this->fileSlot($second, $list);
        $this->identifier($second, 'file:'.$list->id, '11-840-9', 'ean', '5012345678900');
        $rawpolCard = $this->card('RP-11840', 'Ansell');
        $this->link($rawpolCard, $this->rawpol, 'RP-1', 'RP-11840');
        $this->identifier($rawpolCard, 'b2b:'.$this->rawpol->id, 'RP-1', 'ean', '5012345678900');

        $result = app(CardMatchFinder::class)->evaluate($rawpolCard);

        $this->assertSame('conflict', $result['status']);
        $this->assertNull($result['target_product_id']);
        $this->assertSame([$first->id, $second->id], $result['conflict_product_ids']);
        $this->assertStringContainsString('kilka kart producenta', (string) $result['reason']);

        $summary = app(CardMatchFinder::class)->refresh();
        $this->assertSame(['pending' => 0, 'conflict' => 1], array_slice($summary, 0, 2));
        $this->assertNull(CardMatchCandidate::query()->sole()->target_product_id);
        // drugi przebieg nie dubluje konfliktu bez karty-celu (UNIQUE nie łapie NULL)
        app(CardMatchFinder::class)->refresh();
        $this->assertSame(1, CardMatchCandidate::query()->count());
    }

    public function test_conflicts_keep_target_and_explain_why(): void
    {
        $finder = app(CardMatchFinder::class);

        // inna marka przy EAN
        $list = PriceList::query()->create(['manufacturer' => 'Ansell', 'version' => '2026-09']);
        $ansellCard = $this->card('11-840-8', 'Ansell');
        $this->fileSlot($ansellCard, $list);
        $this->identifier($ansellCard, 'file:'.$list->id, '11-840-8', 'ean', '5012345678900');
        $foreign = $this->card('RP-X', 'Mapa');
        $this->link($foreign, $this->rawpol, 'RP-X', 'RP-X');
        $this->identifier($foreign, 'b2b:'.$this->rawpol->id, 'RP-X', 'ean', '5012345678900');
        $result = $finder->evaluate($foreign);
        $this->assertSame('conflict', $result['status']);
        $this->assertSame($ansellCard->id, $result['target_product_id']);
        $this->assertStringContainsString('innej marki', (string) $result['reason']);

        // obie karty mają cenę z pliku
        $other = PriceList::query()->create(['manufacturer' => 'Hurtownia', 'version' => '1']);
        $withFile = $this->card('RP-Y', 'Ansell');
        $this->link($withFile, $this->procera, 'PR-Y', 'PR-Y');
        $this->fileSlot($withFile, $other);
        $this->identifier($withFile, 'b2b:'.$this->procera->id, 'PR-Y', 'ean', '5012345678900');
        $this->assertStringContainsString('obie karty mają cenę z pliku', (string) $finder->evaluate($withFile)['reason']);

        // karta producenta ma już pozycję tego konta pod innym kodem (sztuka / karton)
        $anroCard = $this->anroCard('IF/040/X');
        $this->link($anroCard, $this->p4s, 'P-KARTON', 'ZPPV40K', merged: true);
        $p4sCard = $this->p4sCard('ZPPV40', 'ANRO', 'IF/040/X');
        $result = $finder->evaluate($p4sCard);
        $this->assertSame('conflict', $result['status']);
        $this->assertSame($anroCard->id, $result['target_product_id']);
        $this->assertStringContainsString('ma już pozycję tego konta', (string) $result['reason']);
        $this->assertStringContainsString('P-KARTON', (string) $result['reason']);

        // karta producenta z wersjami; duplikat z cenami specjalnymi, akcesoriami i wycofaną wersją
        $anroVariants = $this->anroCard('IF/050/X');
        $this->variant($anroVariants, 'V-1', removed: false);
        $p4sBlocked = $this->p4sCard('ZPPV50', 'ANRO', 'IF/050/X');
        DB::table('product_special_prices')->insert([
            'product_id' => $p4sBlocked->id, 'client_name' => 'Szpital', 'price' => 5, 'currency' => 'PLN',
            'contract_ref' => '', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_accessories')->insert([
            'product_id' => $p4sBlocked->id, 'source' => 'presta', 'link_key' => 'k1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->variant($p4sBlocked, 'V-2', removed: true);
        $reason = (string) $finder->evaluate($p4sBlocked)['reason'];
        $this->assertStringContainsString('karta producenta ma wersje', $reason);
        $this->assertStringContainsString('ceny specjalne', $reason);
        $this->assertStringContainsString('akcesoria', $reason);
        $this->assertStringContainsString('wycofane wersje', $reason);

        // karta dystrybutora z aktywną wersją nie jest duplikatem
        $p4sWithVariant = $this->p4sCard('ZPPV60', 'ANRO', 'IF/060/X');
        $this->anroCard('IF/060/X');
        $this->variant($p4sWithVariant, 'V-3', removed: false);
        $this->assertNull($finder->evaluate($p4sWithVariant));

        $summary = $finder->refresh();
        $this->assertSame(0, $summary['pending']);
        $this->assertSame(4, $summary['conflict']);
        $this->assertSame(4, CardMatchCandidate::query()->where('status', 'conflict')->whereNotNull('reason')->count());
    }

    public function test_rejected_pair_does_not_return_and_stale_proposals_are_removed(): void
    {
        $anroCard = $this->anroCard('IF/016/F/PS');
        $p4sCard = $this->p4sCard('ZPPV99C', 'ANRO', 'IF/016/F/PS');
        $anroOther = $this->anroCard('IF/017/F/PS');
        $p4sOther = $this->p4sCard('ZPPV98C', 'ANRO', 'IF/017/F/PS');
        $finder = app(CardMatchFinder::class);
        $this->assertSame(2, $finder->refresh()['pending']);

        CardMatchCandidate::query()->where('source_product_id', $p4sCard->id)->update(['status' => 'rejected', 'decided_at' => now()]);
        // kod drugiej karty P4S zniknął ze źródła — propozycja nieaktualna
        ProductIdentifier::query()->where('product_id', $p4sOther->id)->update(['removed_at' => now()]);

        $summary = $finder->refresh();

        $this->assertSame(['pending' => 0, 'conflict' => 0, 'removed' => 1], array_slice($summary, 0, 3));
        $rejected = CardMatchCandidate::query()->sole();
        $this->assertSame('rejected', $rejected->status);
        $this->assertSame($anroCard->id, $rejected->target_product_id);
        $this->assertNotNull($anroOther->id);
    }

    public function test_rejected_pair_whose_target_was_deleted_does_not_hide_new_conflict(): void
    {
        $list = PriceList::query()->create(['manufacturer' => 'Ansell', 'version' => '2026-09']);
        foreach (['11-840-8', '11-840-9'] as $sku) {
            $card = $this->card($sku, 'Ansell');
            $this->fileSlot($card, $list);
            $this->identifier($card, 'file:'.$list->id, $sku, 'ean', '5012345678900');
        }
        $rawpolCard = $this->card('RP-11840', 'Ansell');
        $this->link($rawpolCard, $this->rawpol, 'RP-1', 'RP-11840');
        $this->identifier($rawpolCard, 'b2b:'.$this->rawpol->id, 'RP-1', 'ean', '5012345678900');
        // odrzucona para z kartą-celem, którą potem usunięto (klucz obcy wyzerował target_product_id)
        CardMatchCandidate::query()->create([
            'source_product_id' => $rawpolCard->id, 'target_product_id' => null, 'status' => 'rejected', 'decided_at' => now(),
        ]);

        $this->assertSame(1, app(CardMatchFinder::class)->refresh()['conflict']);
        $this->assertSame(['conflict', 'rejected'], CardMatchCandidate::query()->orderBy('status')->pluck('status')->all());
    }

    public function test_refresh_query_count_does_not_grow_with_unrelated_catalog(): void
    {
        $this->anroCard('IF/016/F/PS');
        $this->p4sCard('ZPPV99C', 'ANRO', 'IF/016/F/PS');
        $finder = app(CardMatchFinder::class);
        $finder->refresh();
        $before = $this->countQueries(fn () => $finder->refresh());

        // 40 kart dystrybutora i 40 kart producenta z kodami, których nikt inny nie ma
        for ($i = 0; $i < 40; $i++) {
            $this->anroCard('IF/9'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'/Q');
            $this->p4sCard('P4S-U'.$i, 'ANRO', 'XX/8'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'/Q');
        }
        $after = $this->countQueries(fn () => $finder->refresh());

        $this->assertSame($before, $after);
    }

    public function test_refresh_does_not_load_producer_cards_whose_code_matches_only_their_own_link(): void
    {
        // produkcja 25.09.2026: łącznik producenta zapisuje kod producenta równy SKU własnego powiązania — przebieg
        // ładował 10,8 tys. takich kart i przekraczał 128 MB, choć żadna z nich nie ma drugiej karty z tym kodem
        $anroCard = $this->anroCard('IF/016/F/PS');
        $p4sCard = $this->p4sCard('ZPPV99C', 'ANRO', 'IF/016/F/PS');
        $alone = [];
        for ($i = 0; $i < 5; $i++) {
            $code = 'IF/7'.$i.'0/Q';
            $card = $this->anroCard($code);
            $this->identifier($card, 'b2b:'.$this->anro->id, 'A-'.$code, 'manufacturer_code', $code);
            $alone[] = $card->id;
        }
        $loaded = [];
        DB::listen(static function (QueryExecuted $query) use (&$loaded): void {
            if (str_contains($query->sql, 'from "products" where "id" in')) {
                array_push($loaded, ...$query->bindings);
            }
        });

        $summary = app(CardMatchFinder::class)->refresh();

        $this->assertSame(1, $summary['pending']);
        $this->assertSame($anroCard->id, CardMatchCandidate::query()->where('source_product_id', $p4sCard->id)->value('target_product_id'));
        // para jest w przebiegu, karty trafiające kodem same w siebie — nie
        $this->assertContains($anroCard->id, $loaded);
        $this->assertContains($p4sCard->id, $loaded);
        $this->assertSame([], array_values(array_intersect($alone, $loaded)));
    }

    public function test_own_link_of_producer_card_counts_when_code_is_on_several_cards(): void
    {
        // kod producenta jest też na karcie Anro (identyfikator P4S po ręcznym połączeniu), więc nie jest jedyny —
        // własne powiązanie Anro zostaje kluczem nowej karty P4S z tym kodem (karton obok połączonej sztuki)
        $anroCard = $this->anroCard('IF/016/F/PS');
        $this->link($anroCard, $this->p4s, 'P-1', 'ZPPV99C', merged: true);
        $this->identifier($anroCard, 'b2b:'.$this->p4s->id, 'P-1', 'manufacturer_code', 'IF/016/F/PS');
        $carton = $this->p4sCard('ZPPV99CK', 'ANRO', 'IF/016/F/PS');

        $result = app(CardMatchFinder::class)->evaluate($carton);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame($anroCard->id, $result['target_product_id']);
        $this->assertSame('b2b:'.$this->anro->id, $result['matched_source_key']);
        $this->assertStringContainsString('ma już pozycję tego konta', (string) $result['reason']);

        $summary = app(CardMatchFinder::class)->refresh();
        $this->assertSame(['pending' => 0, 'conflict' => 1], array_slice($summary, 0, 2));
        $this->assertSame($anroCard->id, CardMatchCandidate::query()->where('source_product_id', $carton->id)->value('target_product_id'));
    }

    public function test_command_refreshes_prints_and_writes_csv_without_merging(): void
    {
        $anroCard = $this->anroCard('IF/016/F/PS');
        $p4sCard = $this->p4sCard('ZPPV99C', 'ANRO', 'IF/016/F/PS');
        $csv = storage_path('framework/testing/match-candidates.csv');
        @mkdir(dirname($csv), 0775, true);

        $this->artisan('products:match-candidates', ['--csv' => $csv])
            ->expectsOutputToContain('Do decyzji: 1')
            ->assertSuccessful();

        $lines = file($csv, FILE_IGNORE_NEW_LINES) ?: [];
        @unlink($csv);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('pending;manufacturer_code;IF016FPS', $lines[1]);
        $this->assertNotNull(Product::query()->find($p4sCard->id));
        $this->assertNotNull(Product::query()->find($anroCard->id));
    }

    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(static function () use (&$count): void {
            $count++;
        });
        $callback();

        return $count;
    }

    /** Karta Anro z konta Anro — SKU w B2B Anro to kod producenta. */
    private function anroCard(string $code): Product
    {
        $card = $this->card($code, 'Anro');
        $this->link($card, $this->anro, 'A-'.$code, $code);

        return $card;
    }

    /** Karta P4S z kodem producenta w identyfikatorach konta P4S. */
    private function p4sCard(string $sku, string $manufacturer, string $code): Product
    {
        $card = $this->card($sku, $manufacturer);
        $this->link($card, $this->p4s, 'P-'.$sku, $sku);
        $this->identifier($card, 'b2b:'.$this->p4s->id, 'P-'.$sku, 'manufacturer_code', $code);

        return $card;
    }

    private function card(string $sku, string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => 'Rękawice '.$manufacturer.' '.$sku, 'manufacturer' => $manufacturer,
            'catalog_price_net' => 10, 'purchase_price' => 8, 'currency' => 'PLN', 'stock' => 0,
        ]);
    }

    private function link(Product $card, B2bAccount $account, string $remoteId, ?string $remoteSku, bool $merged = false): void
    {
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id,
            'remote_id' => $remoteId,
            'remote_sku' => $remoteSku,
            'product_id' => $card->id,
            'merged_at' => $merged ? now() : null,
        ]);
    }

    private function fileSlot(Product $card, PriceList $list): void
    {
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $list->id,
            'catalog_price_net' => 10, 'purchase_price' => 8,
        ]);
    }

    private function identifier(Product $card, string $source, string $position, string $type, string $value): void
    {
        ProductIdentifier::query()->create([
            'product_id' => $card->id,
            'source_key' => $source,
            'position_key' => $position,
            'type' => $type,
            'value' => $value,
            'normalized' => ProductIdentifierCode::normalize($type, $value),
            'manufacturer' => $card->manufacturer,
            'last_seen_at' => now(),
        ]);
    }

    private function variant(Product $card, string $remoteId, bool $removed): void
    {
        ProductVariant::query()->create([
            'product_id' => $card->id, 'source' => 'test', 'remote_id' => $remoteId, 'label' => 'Wersja '.$remoteId,
            'removed_at' => $removed ? now() : null,
        ]);
    }

    private function account(string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $connector, 'password' => 'x', 'connector' => $connector, 'sites' => ['b2b.'.$connector.'.example.test'],
        ]);
    }
}
