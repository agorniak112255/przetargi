<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\FakeQdrant;
use Tests\TestCase;

/**
 * products:prune-orphan-vectors — punkty Qdrant bez karty w bazie (wektory kart skasowanych przy scalaniu sprzed
 * 26d91fa): podgląd niczego nie kasuje, --apply kasuje porcjami tylko punkty, których karty dalej nie ma, a przy
 * ponad 10% punktów bez karty (kolekcja innej bazy) odmawia bez --force.
 */
final class PruneOrphanVectorsCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Punkty bez karty jak na produkcji 25.09.2026 — karty rozmiarów po scaleniu. */
    private const ORPHANS = [
        900001 => ['sku' => '6002706', 'name' => 'Rękawice athletic lite rozm.6', 'manufacturer' => 'UVEX'],
        900002 => ['sku' => '6002707', 'name' => 'Rękawice athletic lite rozm.7', 'manufacturer' => 'UVEX'],
        900003 => ['sku' => '6659/09 FOAM', 'name' => 'Rękawice antyprzecięciowe 6659 foam rozm. 9', 'manufacturer' => 'P&M'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_preview_reports_points_without_card_and_deletes_nothing(): void
    {
        FakeQdrant::withPoints($this->cardPoints(30) + self::ORPHANS);

        $this->artisan('products:prune-orphan-vectors')
            ->expectsOutputToContain('punktów 33, bez karty w bazie: 3.')
            ->expectsOutputToContain('Producenci wg danych punktu: UVEX 2, P&M 1.')
            ->expectsOutputToContain('Rękawice athletic lite rozm.6')
            ->expectsOutputToContain('Podgląd — nic nie skasowano')
            ->doesntExpectOutputToContain('innej bazy')
            ->assertSuccessful();

        $this->assertSame([], FakeQdrant::deletedIds());
    }

    public function test_apply_deletes_points_without_card_when_they_are_a_small_share(): void
    {
        Log::spy();
        FakeQdrant::withPoints($this->cardPoints(30) + self::ORPHANS);

        $this->artisan('products:prune-orphan-vectors', ['--apply' => true])
            ->expectsOutputToContain('Skasowano punktów bez karty: 3 (kolekcja products).')
            ->assertSuccessful();

        $this->assertSame([900001, 900002, 900003], FakeQdrant::deletedIds());
        Log::shouldHaveReceived('info')
            ->with('Orphan product vectors deleted', Mockery::on(static fn (array $context): bool => $context['collection'] === 'products'
                && $context['point_ids'] === [900001, 900002, 900003]))
            ->once();
    }

    public function test_apply_refuses_without_force_when_most_points_have_no_card(): void
    {
        // np. lokalna baza z adresem produkcyjnego Qdrant — „bez karty” byłyby wektory istniejących kart
        FakeQdrant::withPoints($this->cardPoints(1) + self::ORPHANS);

        $this->artisan('products:prune-orphan-vectors', ['--apply' => true])
            ->expectsOutputToContain('Bez karty jest 75,0% punktów, więcej niż 10,0%')
            ->expectsOutputToContain('Nic nie skasowano. Jeśli to na pewno kolekcja tej bazy, dodaj --force.')
            ->assertFailed();

        $this->assertSame([], FakeQdrant::deletedIds());
    }

    public function test_apply_with_force_deletes_in_chunks_across_scroll_pages(): void
    {
        // trzy strony przeglądu (po 1000 punktów): karta na pierwszej i na ostatniej, między nimi punkty bez karty
        // (prawie wszystkie punkty są bez karty, więc --force)
        $points = $this->cardPoints(1);
        $last = $this->card('K-LAST', 600000);
        $points[$last->id] = ['sku' => 'K-LAST', 'name' => 'Karta K-LAST', 'manufacturer' => 'UVEX'];
        $orphans = range(500001, 502100);
        foreach ($orphans as $id) {
            $points[$id] = ['sku' => 'S-'.$id, 'name' => 'Karta scalona '.$id, 'manufacturer' => 'P&M'];
        }
        FakeQdrant::withPoints($points);

        $this->artisan('products:prune-orphan-vectors', ['--apply' => true, '--force' => true, '--chunk' => 1000])
            ->expectsOutputToContain('punktów 2102, bez karty w bazie: 2100.')
            ->expectsOutputToContain('W tabeli pierwsze 20 z 2100.')
            ->expectsOutputToContain('Skasowano punktów bez karty: 2100 (kolekcja products).')
            ->assertSuccessful();

        $this->assertSame($orphans, FakeQdrant::deletedIds());
        // porcje po 1000: 1000 + 1000 + 100
        $sizes = [];
        foreach (Http::recorded(static fn (Request $request): bool => str_contains($request->url(), '/points/delete')) as [$request]) {
            $sizes[] = count($request->data()['points']);
        }
        $this->assertSame([1000, 1000, 100], $sizes);
    }

    public function test_apply_keeps_point_whose_card_appeared_after_the_scan(): void
    {
        config(['ai.vector_enabled' => true, 'ai.qdrant_url' => 'http://qdrant.test:6333']);
        $test = $this;
        // dwie strony przeglądu; w trakcie drugiej wraca karta 700001 z pierwszej (np. z kopii zapasowej)
        Http::fake(['qdrant.test:6333/*' => static function (Request $request) use ($test) {
            if (str_contains($request->url(), '/points/delete')) {
                return Http::response(['result' => ['status' => 'completed'], 'status' => 'ok']);
            }
            if (($request->data()['offset'] ?? null) === null) {
                return Http::response(['result' => [
                    'points' => [['id' => 700001, 'payload' => ['sku' => 'A'], 'vector' => null]],
                    'next_page_offset' => 700002,
                ], 'status' => 'ok']);
            }
            $test->card('A', 700001);

            return Http::response(['result' => [
                'points' => [['id' => 700002, 'payload' => ['sku' => 'B'], 'vector' => null]],
                'next_page_offset' => null,
            ], 'status' => 'ok']);
        }]);

        $this->artisan('products:prune-orphan-vectors', ['--apply' => true, '--force' => true])
            ->expectsOutputToContain('bez karty w bazie: 2.')
            ->expectsOutputToContain('Skasowano punktów bez karty: 1 (kolekcja products). Pominięte, bo karta jest w bazie: 1.')
            ->assertSuccessful();

        $this->assertSame([700002], FakeQdrant::deletedIds());
    }

    public function test_delete_error_stops_with_failure_and_logs_what_was_deleted(): void
    {
        Log::spy();
        config(['ai.vector_enabled' => true, 'ai.qdrant_url' => 'http://qdrant.test:6333']);
        $deletes = 0;
        // pierwsza porcja przechodzi, druga pada
        Http::fake(['qdrant.test:6333/*' => static function (Request $request) use (&$deletes) {
            if (str_contains($request->url(), '/points/delete')) {
                return Http::response(['status' => 'ok'], ++$deletes === 1 ? 200 : 500);
            }

            return Http::response(['result' => [
                'points' => [['id' => 800001, 'payload' => null], ['id' => 800002, 'payload' => null], ['id' => 800003, 'payload' => null]],
                'next_page_offset' => null,
            ], 'status' => 'ok']);
        }]);

        $this->artisan('products:prune-orphan-vectors', ['--apply' => true, '--force' => true, '--chunk' => 1])
            ->expectsOutputToContain('Kasowanie przerwane (skasowano dotąd: 1): Qdrant delete HTTP 500')
            ->assertFailed();

        // trzeciej porcji nie wysłano
        $this->assertSame([800001, 800002], FakeQdrant::deletedIds());
        Log::shouldHaveReceived('info')
            ->with('Orphan product vectors deleted', Mockery::on(static fn (array $context): bool => $context['point_ids'] === [800001]))
            ->once();
    }

    public function test_scroll_error_stops_before_any_delete(): void
    {
        config(['ai.vector_enabled' => true, 'ai.qdrant_url' => 'http://qdrant.test:6333']);
        Http::fake(['qdrant.test:6333/*' => Http::response(['status' => ['error' => 'Service unavailable']], 503)]);

        $this->artisan('products:prune-orphan-vectors', ['--apply' => true])
            ->expectsOutputToContain('Przegląd kolekcji products przerwany: Qdrant scroll HTTP 503')
            ->assertFailed();

        $this->assertSame([], FakeQdrant::deletedIds());
    }

    public function test_scroll_that_does_not_advance_stops_instead_of_looping(): void
    {
        config(['ai.vector_enabled' => true, 'ai.qdrant_url' => 'http://qdrant.test:6333']);
        Http::fake(['qdrant.test:6333/*' => Http::response(['result' => [
            'points' => [['id' => 5, 'payload' => null]],
            'next_page_offset' => 5,
        ], 'status' => 'ok'])]);

        $this->artisan('products:prune-orphan-vectors', ['--apply' => true, '--force' => true])
            ->expectsOutputToContain('Qdrant podał ten sam punkt startowy następnej strony (5).')
            ->assertFailed();

        $this->assertSame([], FakeQdrant::deletedIds());
        Http::assertSentCount(2);
    }

    public function test_without_vector_search_does_nothing(): void
    {
        FakeQdrant::withPoints(self::ORPHANS);
        config(['ai.vector_enabled' => false]);

        $this->artisan('products:prune-orphan-vectors', ['--apply' => true])
            ->expectsOutputToContain('Wyszukiwanie wektorowe wyłączone')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    /**
     * Karty w bazie i ich punkty.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cardPoints(int $count): array
    {
        $points = [];
        for ($i = 1; $i <= $count; $i++) {
            $card = $this->card('K-'.$i);
            $points[$card->id] = ['sku' => 'K-'.$i, 'name' => 'Karta K-'.$i, 'manufacturer' => 'UVEX'];
        }

        return $points;
    }

    public function card(string $sku, ?int $id = null): Product
    {
        $card = new Product;
        $card->forceFill(array_filter([
            'id' => $id,
            'sku' => $sku,
            'name' => 'Karta '.$sku,
            'manufacturer' => 'UVEX',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
        ], static fn ($value): bool => $value !== null))->save();

        return $card;
    }
}
