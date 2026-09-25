<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Vector\QdrantClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\FakeQdrant;
use Tests\TestCase;

/**
 * Przegląd punktów kolekcji (scroll) i kasowanie wielu punktów jednym żądaniem — potrzebne do sprzątania wektorów
 * kart, których nie ma w bazie (products:prune-orphan-vectors), i do kasowania wektorów usuwanych kart.
 */
final class QdrantPointsClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_scroll_reads_pages_in_id_order_without_vectors_and_never_creates_collection(): void
    {
        FakeQdrant::withPoints([7 => ['sku' => 'C'], 3 => ['sku' => 'A'], 5 => ['sku' => 'B']]);
        $qdrant = app(QdrantClient::class);

        $first = $qdrant->scroll(null, 2, true);
        $this->assertSame([['id' => 3, 'payload' => ['sku' => 'A']], ['id' => 5, 'payload' => ['sku' => 'B']]], $first['points']);
        $this->assertSame(7, $first['next_offset']);

        // bez payloadu Qdrant oddaje payload null — punkt ma wtedy pustą tablicę
        $last = $qdrant->scroll($first['next_offset'], 2);
        $this->assertSame([['id' => 7, 'payload' => []]], $last['points']);
        $this->assertNull($last['next_offset']);

        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/collections/'.$qdrant->collection().'/points/scroll')
            && $request->data() === ['limit' => 2, 'with_payload' => true, 'with_vector' => false]);
        Http::assertSent(static fn (Request $request): bool => ($request->data()['offset'] ?? null) === 7);
        // przegląd tylko czyta — bez ensureCollection, które zakłada brakującą kolekcję
        Http::assertSentCount(2);
    }

    public function test_scroll_skips_points_that_are_not_cards_and_keeps_uuid_offset(): void
    {
        config(['ai.vector_enabled' => true, 'ai.qdrant_url' => 'http://qdrant.test:6333']);
        Http::fake(['qdrant.test:6333/*' => Http::response(['result' => [
            'points' => [
                ['id' => '5c56c793-69f3-4fbf-87e6-c4bf54c28c26', 'payload' => ['sku' => 'obcy']],
                ['id' => 12, 'payload' => ['sku' => 'K-12']],
            ],
            'next_page_offset' => '6f0b8a5e-7d0c-4f4e-9a55-3f8f7c1b2a10',
        ], 'status' => 'ok'])]);

        $page = app(QdrantClient::class)->scroll();

        $this->assertSame([['id' => 12, 'payload' => ['sku' => 'K-12']]], $page['points']);
        $this->assertSame('6f0b8a5e-7d0c-4f4e-9a55-3f8f7c1b2a10', $page['next_offset']);
    }

    public function test_scroll_error_throws(): void
    {
        config(['ai.vector_enabled' => true, 'ai.qdrant_url' => 'http://qdrant.test:6333']);
        Http::fake(['qdrant.test:6333/*' => Http::response(['status' => ['error' => 'Collection `products` doesn\'t exist!']], 404)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Qdrant scroll HTTP 404');

        app(QdrantClient::class)->scroll();
    }

    public function test_delete_many_sends_all_ids_in_one_request_and_nothing_for_empty_list(): void
    {
        FakeQdrant::enable();
        $qdrant = app(QdrantClient::class);

        $qdrant->deleteMany([]);
        Http::assertNothingSent();

        $qdrant->deleteMany([9, 4, 6]);
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/points/delete?wait=true')
            && $request->data() === ['points' => [9, 4, 6]]);
        $this->assertSame([4, 6, 9], FakeQdrant::deletedIds());
    }

    public function test_delete_many_without_collection_is_not_an_error(): void
    {
        FakeQdrant::enable(404);

        app(QdrantClient::class)->deleteMany([1, 2]);

        $this->assertSame([1, 2], FakeQdrant::deletedIds());
    }

    public function test_delete_many_error_throws(): void
    {
        FakeQdrant::enable(500);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Qdrant delete HTTP 500');

        app(QdrantClient::class)->deleteMany([1]);
    }
}
