<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PrefetchProductSourcesJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductDocument;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductImage;
use App\Models\ProductSourceDocument;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\B2b\AnroB2bClient;
use App\Services\Campaigns\SmtpHostGuard;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\SourceDocumentStore;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Przegląd opisów (etap 1, 08.10.2026): GET /product-reviews, GET /products/{id}/description-versions,
 * POST /products/{id}/review (approve / reject / url), POST …/description-versions/{id}/restore.
 */
final class ProductReviewApiTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTION = 'Rękawice robocze powlekane nitrylem, mankiet ściągacz, norma EN 388 4121X.';

    private const PROPOSAL = 'Rękawice robocze z innego sklepu, powlekane lateksem, rozmiary 7–11.';

    private DescriptionVersionStore $store;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->store = app(DescriptionVersionStore::class);
        // bez prawdziwego DNS: intranet.firma.pl wskazuje sieć wewnętrzną, reszta — adres publiczny
        $this->app->instance(SmtpHostGuard::class, new SmtpHostGuard(static fn (string $host): array => match ($host) {
            'intranet.firma.pl' => ['10.0.0.5'],
            'metadata.firma.pl' => ['93.184.216.34', '169.254.169.254'],
            default => ['93.184.216.34'],
        }));
        $this->user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($this->user);
    }

    public function test_permissions_salesperson_has_list_and_actions_director_only_history(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1', ['review_reason' => Product::REVIEW_IDENTITY_SOFT, 'review_since' => now()]);
        $published = $this->published($card, 'soft', 2);

        $this->getJson('/api/product-reviews')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson("/api/products/{$card->id}/review", ['action' => 'approve', 'version_id' => $published->id])->assertOk();

        Sanctum::actingAs(User::factory()->withRole('dyrektor')->create());
        $this->getJson('/api/product-reviews')->assertForbidden();
        $this->postJson("/api/products/{$card->id}/review", ['action' => 'approve'])->assertForbidden();
        $this->postJson("/api/products/{$card->id}/description-versions/{$published->id}/restore")->assertForbidden();
        // historia wersji jest częścią karty produktu (products.view)
        $this->getJson("/api/products/{$card->id}/description-versions")->assertOk()->assertJsonPath('current_version_id', $published->id);
    }

    public function test_list_returns_file_price_list_cards_with_reason_counts_and_versions_without_description(): void
    {
        $coba = $this->list('Coba');
        $mapa = $this->list('MAPA');
        $soft = $this->card($coba, 'C-1', ['review_reason' => Product::REVIEW_IDENTITY_SOFT, 'review_since' => now()->subDay(),
            'enrichment_payload' => ['primary_source_url' => 'https://coba.com/c-1', 'primary_source_kind' => 'manufacturer',
                'identity' => ['verdict' => 'soft', 'reason' => 'marka z nazwą'], 'evidence_summary' => ['explicit' => 2, 'inferred' => 1]]]);
        $softVersion = $this->published($soft, 'soft', 2);
        $worse = $this->card($coba, 'C-2', ['review_reason' => Product::REVIEW_WORSE_VERSION, 'review_since' => now()]);
        $worsePublished = $this->published($worse, 'hard', 4);
        $proposal = $this->proposal($worse, 'soft', 1);
        $this->card($mapa, 'M-1', ['review_reason' => Product::REVIEW_IDENTITY_NONE, 'review_since' => now()]);
        // bez powodu i bez slotu pliku — poza listą
        $this->card($coba, 'C-3');
        Product::query()->create(['sku' => 'X-1', 'name' => 'Bez cennika', 'manufacturer' => 'Coba', 'description' => self::DESCRIPTION,
            'catalog_price_net' => 1, 'purchase_price' => 1, 'stock' => 0, 'review_reason' => Product::REVIEW_IDENTITY_NONE]);

        $response = $this->getJson('/api/product-reviews?price_list_id='.$coba->id)->assertOk();

        $response->assertJsonPath('meta', ['total' => 2, 'page' => 1, 'per_page' => 50]);
        $this->assertSame([
            'identity_soft' => 1, 'identity_none' => 0, 'worse_version' => 1, 'rejected_source' => 0,
        ], $response->json('counts.by_reason'));
        $this->assertSame([
            ['id' => $coba->id, 'manufacturer' => 'Coba', 'count' => 2],
            ['id' => $mapa->id, 'manufacturer' => 'MAPA', 'count' => 1],
        ], $response->json('counts.by_price_list'));
        // najnowszy powód pierwszy
        $this->assertSame([$worse->id, $soft->id], array_column($response->json('data'), 'product_id'));

        $first = $response->json('data.0');
        $this->assertArrayNotHasKey('description', $first);
        $this->assertSame([
            'version_id' => $worsePublished->id, 'identity_verdict' => 'hard', 'evidence_count' => 4, 'primary_source_url' => 'https://coba.com/c-2',
            'description_sha1' => $worsePublished->description_sha1,
        ], $first['published']);
        $this->assertSame(40, strlen((string) $worsePublished->description_sha1));
        $this->assertSame('https://sklep.pl/p', $first['proposal']['primary_source_url']);
        $this->assertSame($proposal->id, $first['proposal']['version_id']);
        $this->assertSame('soft', $first['proposal']['identity_verdict']);
        // skrót tekstu propozycji — podstawa „Zastosuj do N kart modelu” (ten sam tekst na kartach grupy)
        $this->assertSame($proposal->description_sha1, $first['proposal']['description_sha1']);
        $this->assertNotSame($first['published']['description_sha1'], $first['proposal']['description_sha1']);

        $second = $response->json('data.1');
        $this->assertSame($coba->id, $second['price_list_id']);
        $this->assertSame('https://coba.com/c-1', $second['primary_source_url']);
        $this->assertSame('manufacturer', $second['primary_source_kind']);
        $this->assertSame(['verdict' => 'soft', 'reason' => 'marka z nazwą'], $second['identity']);
        $this->assertSame(['explicit' => 2, 'inferred' => 1], $second['evidence_summary']);
        $this->assertSame($softVersion->id, $second['published']['version_id']);
        $this->assertNull($second['proposal']);

        $byReason = $this->getJson('/api/product-reviews?reason=identity_none')->assertOk();
        $byReason->assertJsonPath('meta.total', 1);
        $this->assertSame([['id' => $mapa->id, 'manufacturer' => 'MAPA', 'count' => 1]], $byReason->json('counts.by_price_list'));

        $this->getJson('/api/product-reviews?reason=cokolwiek')->assertUnprocessable();
        $this->getJson('/api/product-reviews?per_page=201')->assertUnprocessable();
    }

    /**
     * Etap 2: wiersz niesie model karty — klucz w locie z sku, nazwy i producenta, liczba kart modelu w wybranym zbiorze
     * (przed stronicowaniem) i lider, od którego karta dostała opis wspólny; marka bez grupowania → null.
     */
    public function test_list_rows_carry_model_key_count_in_review_and_leader(): void
    {
        $coba = $this->list('Coba');
        $leader = $this->card($coba, 'AF060001', ['name' => 'Orthomat Standard Szary 0.6m x 0.9m', 'review_reason' => Product::REVIEW_IDENTITY_SOFT, 'review_since' => now()->subDays(2)]);
        $member = $this->card($coba, 'AF060002', ['name' => 'Orthomat Standard Czarny 0.9m x 1.5m', 'review_reason' => Product::REVIEW_IDENTITY_SOFT, 'review_since' => now()->subDay(),
            'enrichment_payload' => ['model_group' => ['key' => 'coba|AF|orthomat standard', 'leader_product_id' => $leader->id, 'shared' => true]]]);
        $other = $this->card($coba, 'CCLIP25', ['name' => 'Akcesoria Krata GRP - Uchwyt typu C - 25mm', 'review_reason' => Product::REVIEW_WORSE_VERSION, 'review_since' => now()]);
        $mapa = $this->card($this->list('MAPA'), 'M-1', ['review_reason' => Product::REVIEW_IDENTITY_NONE, 'review_since' => now()]);

        $rows = collect($this->getJson('/api/product-reviews?per_page=2')->assertOk()->json('data'))->keyBy('product_id');

        // strona ma 2 wiersze, a licznik modelu liczy cały zbiór
        $this->assertSame([$mapa->id, $other->id], $rows->keys()->all());
        $this->assertNull($rows[$mapa->id]['model']);
        $this->assertSame(1, $rows[$other->id]['model']['in_review']);
        // rdzeń bez wymiaru (reguły rdzenia: ProductModelKeyTest)
        $this->assertStringStartsWith('Akcesoria Krata GRP', $rows[$other->id]['model']['stem']);
        $this->assertStringNotContainsString('25mm', $rows[$other->id]['model']['stem']);

        $rows = collect($this->getJson('/api/product-reviews?reason='.Product::REVIEW_IDENTITY_SOFT)->assertOk()->json('data'))->keyBy('product_id');
        $this->assertSame($rows[$leader->id]['model']['key'], $rows[$member->id]['model']['key']);
        $this->assertSame('Orthomat Standard', $rows[$leader->id]['model']['stem']);
        $this->assertSame(2, $rows[$leader->id]['model']['in_review']);
        $this->assertNull($rows[$leader->id]['model']['shared_from']);
        $this->assertSame($leader->id, $rows[$member->id]['model']['shared_from']);
    }

    public function test_list_keeps_cards_of_one_model_adjacent_and_counts_whole_set(): void
    {
        $coba = $this->list('Coba');
        // karty jednego modelu rozdzielone w czasie kartą innego modelu i kartą bez modelu
        $older = $this->card($coba, 'AF060001', ['name' => 'Orthomat Standard Szary 0.6m x 0.9m', 'review_reason' => Product::REVIEW_IDENTITY_SOFT, 'review_since' => now()->subDays(3)]);
        $other = $this->card($coba, 'CCLIP25', ['name' => 'Akcesoria Krata GRP - Uchwyt typu C - 25mm', 'review_reason' => Product::REVIEW_WORSE_VERSION, 'review_since' => now()->subDays(2)]);
        $between = $this->card($this->list('MAPA'), 'M-1', ['review_reason' => Product::REVIEW_IDENTITY_NONE, 'review_since' => now()->subDays(2)->addHour()]);
        $newer = $this->card($coba, 'AF060002', ['name' => 'Orthomat Standard Czarny 0.9m x 1.5m', 'review_reason' => Product::REVIEW_IDENTITY_SOFT, 'review_since' => now()->subDay()]);
        $latest = $this->card($this->list('MAPA'), 'M-2', ['review_reason' => Product::REVIEW_IDENTITY_NONE, 'review_since' => now()]);

        $ids = fn (string $query): array => array_column($this->getJson('/api/product-reviews'.$query)->assertOk()->json('data'), 'product_id');
        // grupa modelu stoi tam, gdzie jej najnowszy powód, w grupie najnowszy pierwszy; karty bez modelu po swoim review_since
        $this->assertSame([$latest->id, $newer->id, $older->id, $between->id, $other->id], $ids(''));
        // stronicowanie tnie listę w tym porządku, a licznik modelu liczy cały zbiór
        $page = $this->getJson('/api/product-reviews?per_page=2')->assertOk()->json();
        $this->assertSame([$latest->id, $newer->id], array_column($page['data'], 'product_id'));
        $this->assertSame(2, $page['data'][1]['model']['in_review']);
        $this->assertSame(5, $page['meta']['total']);
        $this->assertSame([$older->id, $between->id], $ids('?per_page=2&page=2'));
    }

    public function test_approve_worse_version_publishes_proposal_and_second_click_changes_nothing(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1', ['review_reason' => Product::REVIEW_WORSE_VERSION, 'review_since' => now()]);
        $this->published($card, 'hard', 4);
        $proposal = $this->proposal($card, 'soft', 1);

        $first = $this->postJson("/api/products/{$card->id}/review", ['action' => 'approve', 'version_id' => $proposal->id, 'note' => 'sprawdzone'])
            ->assertOk();

        $card->refresh();
        $this->assertSame(self::PROPOSAL, $card->description);
        $this->assertNull($card->review_reason);
        // propozycja trafia na kartę bez swoich plików — odpowiedź o tym mówi
        $this->assertSame(['review_reason' => null, 'current_version_id' => $first->json('current_version_id'), 'batch_id' => null, 'shop_source_url' => null, 'shop_source_url_cleared' => false, 'files_from_previous' => true], $first->json());
        $this->assertSame(ProductDescriptionVersion::ORIGIN_REVIEW_APPROVE, ProductDescriptionVersion::query()->find($first->json('current_version_id'))->origin);
        $proposal->refresh();
        $this->assertSame(ProductDescriptionVersion::DECISION_APPROVED, $proposal->decision);
        $this->assertSame($this->user->id, $proposal->decided_by);
        $this->assertStringContainsString('uwaga: sprawdzone', (string) $proposal->reason);

        $count = ProductDescriptionVersion::query()->count();
        $this->postJson("/api/products/{$card->id}/review", ['action' => 'approve', 'version_id' => $proposal->id])
            ->assertOk()
            ->assertJsonPath('current_version_id', $first->json('current_version_id'));
        $this->assertSame($count, ProductDescriptionVersion::query()->count());
    }

    public function test_approve_proposal_does_not_overwrite_b2b_description_written_meanwhile(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1', ['review_reason' => Product::REVIEW_WORSE_VERSION]);
        $this->published($card, 'hard', 4);
        $proposal = $this->proposal($card, 'soft', 1);
        $b2bText = 'Opis ze sklepu dostawcy B2B, dzianina nylonowa 13, powlekana.';
        $card->update(['description' => $b2bText]);
        $this->b2bLink($card);

        $this->postJson("/api/products/{$card->id}/review", ['action' => 'approve', 'version_id' => $proposal->id])->assertStatus(409);
        $this->assertSame($b2bText, $card->fresh()->description);
        $this->assertNull($proposal->fresh()->decision);
    }

    public function test_approve_identity_reason_keeps_description_and_records_decision(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1', ['review_reason' => Product::REVIEW_IDENTITY_SOFT, 'review_since' => now()]);
        $published = $this->published($card, 'soft', 2);

        $this->postJson("/api/products/{$card->id}/review", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('review_reason', null)
            ->assertJsonPath('current_version_id', $published->id);

        $this->assertSame(self::DESCRIPTION, $card->fresh()->description);
        $this->assertNull($card->fresh()->review_reason);
        $this->assertSame(ProductDescriptionVersion::DECISION_APPROVED, $published->fresh()->decision);
    }

    public function test_approving_current_description_while_proposal_waits_is_conflict(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1', ['review_reason' => Product::REVIEW_WORSE_VERSION]);
        $published = $this->published($card, 'hard', 4);
        $this->proposal($card, 'soft', 1);

        $this->postJson("/api/products/{$card->id}/review", ['action' => 'approve', 'version_id' => $published->id])->assertStatus(409);
        $this->postJson("/api/products/{$card->id}/review", ['action' => 'reject', 'version_id' => $published->id])->assertStatus(409);
        $this->assertSame(self::DESCRIPTION, $card->fresh()->description);
    }

    public function test_reject_proposal_keeps_description_does_not_block_its_url_and_is_idempotent(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1', ['review_reason' => Product::REVIEW_WORSE_VERSION]);
        $published = $this->published($card, 'hard', 4);
        // propozycja z tego samego adresu co obecny dobry opis
        $proposal = $this->proposal($card, 'soft', 1, 'https://coba.com/a-1');

        $this->postJson("/api/products/{$card->id}/review", ['action' => 'reject', 'version_id' => $proposal->id])
            ->assertOk()
            ->assertJsonPath('review_reason', null)
            ->assertJsonPath('batch_id', null)
            ->assertJsonPath('current_version_id', $published->id);
        $this->postJson("/api/products/{$card->id}/review", ['action' => 'reject', 'version_id' => $proposal->id])
            ->assertOk()
            ->assertJsonPath('current_version_id', $published->id);

        $this->assertSame(ProductDescriptionVersion::STATUS_REJECTED, $proposal->fresh()->status);
        $this->assertSame(self::DESCRIPTION, $card->fresh()->description);
        $this->assertSame([], $this->store->rejectedUrls($card));
        $this->assertSame('publish', $this->store->decide($card->fresh(), ['identity' => 'hard', 'evidence_count' => 4, 'primary_source_url' => 'https://coba.com/a-1'])['action']);
        Queue::assertNotPushed(PrefetchProductSourcesJob::class);
    }

    public function test_reject_proposal_sets_review_reason_from_current_description_verdict(): void
    {
        $soft = $this->card($this->list('Coba'), 'A-1', ['review_reason' => Product::REVIEW_WORSE_VERSION, 'review_since' => now()->subDay()]);
        $this->published($soft, 'soft', 3);
        $softProposal = $this->proposal($soft, 'none', 1);
        $none = $this->card($this->list('Coba'), 'A-2', ['review_reason' => Product::REVIEW_REJECTED_SOURCE]);
        $this->published($none, 'none', 3);
        $noneProposal = $this->proposal($none, 'hard', 9);
        // opis zatwierdzony już przez człowieka nie wraca na listę
        $approved = $this->card($this->list('Coba'), 'A-3', ['review_reason' => Product::REVIEW_WORSE_VERSION]);
        $this->published($approved, 'soft', 3)->forceFill(['decision' => ProductDescriptionVersion::DECISION_APPROVED])->save();
        $approvedProposal = $this->proposal($approved, 'none', 1);

        $this->postJson("/api/products/{$soft->id}/review", ['action' => 'reject', 'version_id' => $softProposal->id])
            ->assertOk()->assertJsonPath('review_reason', Product::REVIEW_IDENTITY_SOFT);
        $this->postJson("/api/products/{$none->id}/review", ['action' => 'reject', 'version_id' => $noneProposal->id])
            ->assertOk()->assertJsonPath('review_reason', Product::REVIEW_IDENTITY_NONE);
        $this->postJson("/api/products/{$approved->id}/review", ['action' => 'reject', 'version_id' => $approvedProposal->id])
            ->assertOk()->assertJsonPath('review_reason', null);

        $this->assertNotNull($soft->fresh()->review_since);
        $this->assertTrue($soft->fresh()->review_since->greaterThan(now()->subHour()));
    }

    public function test_reject_requires_version_id(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1', ['review_reason' => Product::REVIEW_IDENTITY_SOFT]);
        $this->published($card, 'soft', 2);

        $this->postJson("/api/products/{$card->id}/review", ['action' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('version_id');
        $this->assertSame(self::DESCRIPTION, $card->fresh()->description);
    }

    public function test_reject_published_description_restores_previous_published(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1');
        $old = $this->published($card, 'hard', 4);
        $newText = 'Rękawice z cudzej strony sklepu, powlekane poliuretanem, rozmiar 9.';
        $card->update(['description' => $newText, 'review_reason' => Product::REVIEW_IDENTITY_NONE, 'review_since' => now()]);
        $bad = $this->store->record($card, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => $newText, 'primary_source_url' => 'https://obcy.pl/x', 'identity_verdict' => 'none',
        ]);

        $response = $this->postJson("/api/products/{$card->id}/review", ['action' => 'reject', 'version_id' => $bad->id, 'note' => 'cudzy wyrób'])
            ->assertOk();

        $card->refresh();
        $this->assertSame(self::DESCRIPTION, $card->description);
        $this->assertNull($card->review_reason);
        // poprzedni opis wrócił, a karta idzie do ponownego pobrania z pominięciem odrzuconej strony
        $batch = ProductEnrichmentBatch::query()->findOrFail($response->json('batch_id'));
        $this->assertTrue((bool) $batch->force);
        $this->assertSame(Product::ENRICHMENT_QUEUED, $card->enrichment_status);
        Queue::assertPushed(PrefetchProductSourcesJob::class, 1);
        $current = ProductDescriptionVersion::query()->find($response->json('current_version_id'));
        $this->assertSame(ProductDescriptionVersion::ORIGIN_RESTORE, $current->origin);
        $this->assertSame($old->description_sha1, $current->description_sha1);
        $this->assertSame(ProductDescriptionVersion::STATUS_REJECTED, $bad->fresh()->status);
        $this->assertSame($newText, $bad->fresh()->description);
        $this->assertSame(['https://obcy.pl/x'], $this->store->rejectedUrls($card));

        // drugie kliknięcie — wersja już odrzucona, opis bez zmian
        $this->postJson("/api/products/{$card->id}/review", ['action' => 'reject', 'version_id' => $bad->id])->assertOk();
        $this->assertSame(self::DESCRIPTION, $card->fresh()->description);
    }

    public function test_reject_only_published_description_resets_card_and_keeps_text_in_version(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1', ['review_reason' => Product::REVIEW_IDENTITY_NONE, 'shop_source_url' => null,
            'norms' => 'EN 388', 'enrichment_payload' => ['norms' => ['EN 388']]]);
        $only = $this->published($card, 'none', 0);

        $b2bImage = ProductImage::query()->create(['product_id' => $card->id, 'b2b_account_id' => $this->b2bAccount()->id, 'path' => 'products/b2b.jpg',
            'source_url' => 'https://b2b.pl/b.jpg', 'checksum' => 'b2b', 'sort_order' => 0]);
        $webImage = ProductImage::query()->create(['product_id' => $card->id, 'path' => 'products/web.jpg', 'source_url' => 'https://obcy.pl/w.jpg', 'checksum' => 'web', 'sort_order' => 1]);

        $response = $this->postJson("/api/products/{$card->id}/review", ['action' => 'reject', 'version_id' => $only->id])
            ->assertOk()
            ->assertJsonPath('current_version_id', null)
            ->assertJsonPath('review_reason', null);

        $card->refresh();
        $this->assertNull($card->description);
        $this->assertNull($card->norms);
        $this->assertNull($card->review_reason);
        // zerowana karta od razu idzie do ponownego pobrania (z force), z odrzuconym adresem zablokowanym
        $this->assertSame(Product::ENRICHMENT_QUEUED, $card->enrichment_status);
        $this->assertNotNull($response->json('batch_id'));
        Queue::assertPushed(PrefetchProductSourcesJob::class, 1);
        $this->assertSame(['https://coba.com/a-1'], $this->store->rejectedUrls($card));
        // zdjęcie z internetu znika, zdjęcie z panelu B2B zostaje
        $this->assertNull($webImage->fresh());
        $this->assertNotNull($b2bImage->fresh());
        $this->assertSame(self::DESCRIPTION, $only->fresh()->description);
        $this->assertSame(ProductDescriptionVersion::STATUS_REJECTED, $only->fresh()->status);
    }

    public function test_reject_published_removes_files_added_by_its_run_restores_manufacturer_norms_and_requeues(): void
    {
        Storage::fake('public');
        $normsBefore = ['rows' => [['norm' => 'EN 388:2016', 'source' => 'strona-producenta']]];
        $card = $this->card($this->list('Coba'), 'A-1', ['manufacturer_norms' => $normsBefore]);
        $old = $this->published($card, 'hard', 4);
        $kept = ProductImage::query()->create(['product_id' => $card->id, 'path' => 'products/'.$card->id.'/old.jpg', 'source_url' => 'https://coba.com/old.jpg', 'checksum' => 'old', 'sort_order' => 0]);
        $b2b = ProductDocument::query()->create(['product_id' => $card->id, 'b2b_account_id' => $this->b2bAccount()->id, 'path' => 'products/'.$card->id.'/docs/b2b.pdf',
            'source_url' => 'https://b2b.pl/a-1-declaration-of-conformity.pdf', 'title' => 'Declaration of conformity', 'kind' => ProductDocument::KIND_CERTIFICATE]);
        $before = $this->store->snapshot($card);

        // przebieg z cudzej strony: nowe zdjęcie i deklaracja, normy producenta nadpisane, opis zapisany
        $foreignImage = ProductImage::query()->create(['product_id' => $card->id, 'path' => 'products/'.$card->id.'/obcy.jpg', 'source_url' => 'https://obcy.pl/x.jpg', 'checksum' => 'obcy', 'sort_order' => 1]);
        $foreignDoc = ProductDocument::query()->create(['product_id' => $card->id, 'path' => 'products/'.$card->id.'/docs/obcy.pdf',
            'source_url' => 'https://obcy.pl/x-declaration-of-conformity.pdf', 'title' => 'Declaration of conformity', 'kind' => ProductDocument::KIND_CERTIFICATE]);
        Storage::disk('public')->put($foreignImage->path, 'jpg');
        Storage::disk('public')->put($foreignDoc->path, 'pdf');
        $foreignNorms = ['rows' => [['norm' => 'EN 407', 'source' => 'strona-producenta']]];
        $card->update(['manufacturer_norms' => $foreignNorms]);
        $meta = $this->store->versionMeta($card, $before, ['manufacturer_norms' => $foreignNorms]);
        $foreignText = 'Rękawice z cudzej strony sklepu, powlekane poliuretanem, rozmiar 9.';
        $card->update(['description' => $foreignText, 'review_reason' => Product::REVIEW_IDENTITY_NONE, 'review_since' => now()]);
        $bad = $this->store->record($card, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => $foreignText, 'primary_source_url' => 'https://obcy.pl/x', 'identity_verdict' => 'none',
            'enrichment_payload' => ['certificates' => ['Deklaracja zgodności UE'], 'document_urls' => ['https://obcy.pl/x-declaration-of-conformity.pdf']],
            '_version' => $meta,
        ]);

        $response = $this->postJson("/api/products/{$card->id}/review", ['action' => 'reject', 'version_id' => $bad->id])->assertOk();

        $card->refresh();
        $this->assertNull($foreignImage->fresh());
        $this->assertNull($foreignDoc->fresh());
        Storage::disk('public')->assertMissing($foreignImage->path);
        Storage::disk('public')->assertMissing($foreignDoc->path);
        $this->assertNotNull($kept->fresh());
        $this->assertNotNull($b2b->fresh());
        $this->assertSame($normsBefore, $card->manufacturer_norms);
        $this->assertSame(self::DESCRIPTION, $card->description);
        $this->assertSame($old->description_sha1, $this->store->current($card)?->description_sha1);
        // lista plików na karcie bez usuniętej deklaracji z cudzej strony
        $this->assertSame([], $card->enrichment_payload['document_urls'] ?? []);
        $this->assertNotNull($response->json('batch_id'));
        Queue::assertPushed(PrefetchProductSourcesJob::class, 1);
        $this->assertSame('propose', $this->store->decide($card, ['identity' => 'hard', 'evidence_count' => 9, 'primary_source_url' => 'https://obcy.pl/x'])['action']);
    }

    public function test_reject_keeps_manufacturer_norms_the_run_did_not_write(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1');
        $this->published($card, 'hard', 4);
        // opis ze sklepu: przebieg nie zapisał norm producenta, więc wersja nie niesie ich wartości
        $meta = $this->store->versionMeta($card, $this->store->snapshot($card));
        $this->assertArrayNotHasKey('manufacturer_norms_before', $meta);
        $shopText = 'Rękawice ze strony sklepu, powlekane nitrylem, rozmiary 8–10.';
        $card->update(['description' => $shopText]);
        $shop = $this->store->record($card, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => $shopText, 'primary_source_url' => 'https://sklep.pl/a-1', 'identity_verdict' => 'none', '_version' => $meta,
        ]);
        // później normy producenta zapisał ktoś inny (np. norms:from-manufacturer-pages)
        $later = ['source' => ['connector' => 'strona-producenta', 'url' => 'https://coba.com/a-1'], 'rows' => [['label' => 'EN 388:2016', 'value' => '4121X']]];
        $card->update(['manufacturer_norms' => $later]);

        $this->postJson("/api/products/{$card->id}/review", ['action' => 'reject', 'version_id' => $shop->id])->assertOk();

        $card->refresh();
        $this->assertSame(self::DESCRIPTION, $card->description);
        $this->assertSame($later, $card->manufacturer_norms);
    }

    public function test_reject_restores_manufacturer_norms_only_while_card_still_has_the_run_value(): void
    {
        $normsBefore = ['source' => ['connector' => 'strona-producenta', 'url' => 'https://coba.com/stara'], 'rows' => [['label' => 'EN 388:2016', 'value' => '3121X']]];
        $written = ['source' => ['connector' => 'strona-producenta', 'url' => 'https://obcy.pl/x'], 'rows' => [['label' => 'EN 407', 'value' => 'X2XXXX']]];
        $foreignText = 'Rękawice z cudzej strony producenta, powlekane poliuretanem, rozmiar 9.';
        $runVersion = function (Product $card) use ($written, $foreignText): ProductDescriptionVersion {
            $this->published($card, 'hard', 4);
            $before = $this->store->snapshot($card);
            $card->update(['description' => $foreignText, 'manufacturer_norms' => $written]);

            return $this->store->record($card, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
                'description' => $foreignText, 'primary_source_url' => 'https://obcy.pl/x', 'identity_verdict' => 'none',
                '_version' => $this->store->versionMeta($card, $before, ['manufacturer_norms' => $written]),
            ]);
        };

        // nikt nie zmienił norm po przebiegu (te same fakty, klucze w innej kolejności) — wracają sprzed przebiegu
        $unchanged = $this->card($this->list('Coba'), 'A-1', ['manufacturer_norms' => $normsBefore]);
        $unchangedVersion = $runVersion($unchanged);
        $unchanged->update(['manufacturer_norms' => ['rows' => $written['rows'], 'source' => $written['source']]]);
        $this->postJson("/api/products/{$unchanged->id}/review", ['action' => 'reject', 'version_id' => $unchangedVersion->id])->assertOk();
        $this->assertSame($normsBefore, $unchanged->fresh()->manufacturer_norms);

        // po przebiegu normy zapisał ktoś inny — zostają
        $changed = $this->card($this->list('MAPA'), 'M-1', ['manufacturer_norms' => $normsBefore]);
        $changedVersion = $runVersion($changed);
        $newer = ['source' => ['connector' => 'strona-producenta', 'url' => 'https://mapa.pl/m-1'], 'rows' => [['label' => 'EN 374', 'value' => 'JKL']]];
        $changed->update(['manufacturer_norms' => $newer]);
        $this->postJson("/api/products/{$changed->id}/review", ['action' => 'reject', 'version_id' => $changedVersion->id])->assertOk();
        $this->assertSame($newer, $changed->fresh()->manufacturer_norms);
    }

    public function test_reject_description_from_manually_given_page_clears_that_page_from_card(): void
    {
        // adres ręczny pod wariantem hosta z „www.” — ta sama strona, którą blokuje odrzucenie
        $card = $this->card($this->list('Coba'), 'A-1', ['shop_source_url' => 'https://www.obcy.pl/x/']);
        $this->published($card, 'hard', 4);
        $manualText = 'Rękawice ze strony wskazanej ręcznie, cudzy wyrób, powlekane lateksem.';
        $card->update(['description' => $manualText]);
        $manual = $this->store->record($card, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => $manualText, 'primary_source_url' => 'https://obcy.pl/x', 'identity_verdict' => 'hard',
        ]);

        $response = $this->postJson("/api/products/{$card->id}/review", ['action' => 'reject', 'version_id' => $manual->id])
            ->assertOk()
            ->assertJsonPath('shop_source_url', null)
            ->assertJsonPath('shop_source_url_cleared', true);

        $card->refresh();
        // poprzedni opis wrócił, a ponowne pobranie nie dostaje już odrzuconej strony jako adresu ręcznego
        $this->assertSame(self::DESCRIPTION, $card->description);
        $this->assertNull($card->shop_source_url);
        $this->assertNull($card->trustedShopUrl());
        $this->assertNotNull($response->json('batch_id'));
        $this->assertSame(['https://obcy.pl/x'], $this->store->rejectedUrls($card));
        $this->assertSame('propose', $this->store->decide($card, ['identity' => 'hard', 'evidence_count' => 9, 'primary_source_url' => 'https://obcy.pl/x'])['action']);

        // adres ręczny prowadzący na inną stronę zostaje
        $other = $this->card($this->list('MAPA'), 'M-1', ['shop_source_url' => 'https://mapa.pl/m-1']);
        $this->published($other, 'hard', 4);
        $foreignText = 'Rękawice z cudzej strony, inne niż wskazane ręcznie, powlekane.';
        $other->update(['description' => $foreignText]);
        $foreign = $this->store->record($other, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => $foreignText, 'primary_source_url' => 'https://obcy.pl/y', 'identity_verdict' => 'none',
        ]);
        $this->postJson("/api/products/{$other->id}/review", ['action' => 'reject', 'version_id' => $foreign->id])
            ->assertOk()
            ->assertJsonPath('shop_source_url', 'https://mapa.pl/m-1')
            ->assertJsonPath('shop_source_url_cleared', false);
        $this->assertSame('https://mapa.pl/m-1', $other->fresh()->shop_source_url);
    }

    public function test_approve_without_version_while_proposal_appeared_is_conflict_and_publishes_nothing(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1', ['review_reason' => Product::REVIEW_IDENTITY_SOFT, 'review_since' => now()]);
        $published = $this->published($card, 'soft', 2);
        // handlowiec wczytał listę bez propozycji, a zanim kliknął „Zatwierdź”, przebieg zostawił propozycję
        $proposal = $this->proposal($card, 'soft', 1);

        $this->postJson("/api/products/{$card->id}/review", ['action' => 'approve'])
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'odśwież listę'));

        $this->assertSame(self::DESCRIPTION, $card->fresh()->description);
        $this->assertSame(Product::REVIEW_IDENTITY_SOFT, $card->fresh()->review_reason);
        $this->assertNull($proposal->fresh()->decision);
        $this->assertNull($published->fresh()->decision);
        $this->assertSame((int) $published->id, (int) $this->store->current($card->fresh())?->id);
    }

    public function test_reject_keeps_manually_uploaded_files_even_when_listed_in_version_meta(): void
    {
        Storage::fake('public');
        $card = $this->card($this->list('Coba'), 'A-1');
        $this->published($card, 'hard', 4);
        $before = $this->store->snapshot($card);
        $webImage = ProductImage::query()->create(['product_id' => $card->id, 'path' => 'products/'.$card->id.'/obcy.jpg', 'source_url' => 'https://obcy.pl/x.jpg', 'checksum' => 'obcy', 'sort_order' => 0]);
        // w trakcie przebiegu handlowiec wgrał zdjęcie i plik ręcznie (bez adresu źródła) — trafiły na listę wersji
        $uploadedImage = ProductImage::query()->create(['product_id' => $card->id, 'path' => 'products/'.$card->id.'/reczne.jpg', 'source_url' => null, 'checksum' => 'reczne', 'sort_order' => 1]);
        $uploadedDoc = ProductDocument::query()->create(['product_id' => $card->id, 'path' => 'products/'.$card->id.'/docs/reczny.pdf', 'source_url' => null,
            'title' => 'Karta techniczna', 'kind' => ProductDocument::KIND_DATASHEET]);
        Storage::disk('public')->put($uploadedImage->path, 'jpg');
        Storage::disk('public')->put($uploadedDoc->path, 'pdf');
        $meta = $this->store->versionMeta($card, $before);
        $this->assertContains((int) $uploadedImage->id, $meta['web_file_ids']['images']);
        $this->assertContains((int) $uploadedDoc->id, $meta['web_file_ids']['documents']);
        $foreignText = 'Rękawice z cudzej strony sklepu, powlekane poliuretanem, rozmiar 9.';
        $card->update(['description' => $foreignText]);
        $bad = $this->store->record($card, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => $foreignText, 'primary_source_url' => 'https://obcy.pl/x', 'identity_verdict' => 'none', '_version' => $meta,
        ]);

        $this->postJson("/api/products/{$card->id}/review", ['action' => 'reject', 'version_id' => $bad->id])->assertOk();

        $this->assertNull($webImage->fresh());
        $this->assertNotNull($uploadedImage->fresh());
        $this->assertNotNull($uploadedDoc->fresh());
        Storage::disk('public')->assertExists($uploadedImage->path);
        Storage::disk('public')->assertExists($uploadedDoc->path);
    }

    public function test_rejected_proposal_does_not_hide_older_description_when_current_is_rejected(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1');
        $first = $this->published($card, 'hard', 4);
        $secondText = 'Rękawice robocze z drugiego przebiegu, inny sklep, powlekane.';
        $card->update(['description' => $secondText]);
        $second = $this->store->record($card, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => $secondText, 'primary_source_url' => 'https://obcy.pl/y', 'identity_verdict' => 'hard', 'evidence_count' => 4,
        ]);
        // propozycja z adresu pierwszego opisu odrzucona — adres nie jest przez to zablokowany
        $proposal = $this->proposal($card, 'soft', 1, 'https://coba.com/a-1');
        $this->postJson("/api/products/{$card->id}/review", ['action' => 'reject', 'version_id' => $proposal->id])->assertOk();

        $this->postJson("/api/products/{$card->id}/review", ['action' => 'reject', 'version_id' => $second->id])->assertOk();

        $this->assertSame(self::DESCRIPTION, $card->fresh()->description);
        $this->assertSame($first->description_sha1, $this->store->current($card->fresh())?->description_sha1);
    }

    public function test_approving_or_restoring_version_from_blocked_url_unblocks_it(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1', ['review_reason' => Product::REVIEW_REJECTED_SOURCE]);
        $this->published($card, 'hard', 4);
        $blocked = $this->proposal($card, 'none', 1, 'https://obcy.pl/x');
        $blocked->forceFill(['status' => ProductDescriptionVersion::STATUS_REJECTED, 'decision' => ProductDescriptionVersion::DECISION_REJECTED])->save();
        $this->store->blockSourceUrl($blocked);
        $this->assertSame(['https://obcy.pl/x'], $this->store->rejectedUrls($card));
        $proposal = $this->proposal($card, 'soft', 2, 'https://obcy.pl/x/');

        $this->postJson("/api/products/{$card->id}/review", ['action' => 'approve', 'version_id' => $proposal->id])->assertOk();

        $this->assertSame([], $this->store->rejectedUrls($card));
        $this->assertSame(ProductDescriptionVersion::STATUS_REJECTED, $blocked->fresh()->status);
        $history = $this->getJson("/api/products/{$card->id}/description-versions")->assertOk();
        $row = collect($history->json('data'))->firstWhere('id', $blocked->id);
        $this->assertFalse($row['url_blocked']);

        // przywrócenie wersji z zablokowanego adresu też zdejmuje blokadę
        $this->store->blockSourceUrl($blocked->fresh());
        $this->assertSame(['https://obcy.pl/x'], $this->store->rejectedUrls($card));
        $older = ProductDescriptionVersion::query()->where('product_id', $card->id)->where('status', 'superseded')->orderByDesc('id')->firstOrFail();
        $older->forceFill(['primary_source_url' => 'https://obcy.pl/x'])->save();
        $this->postJson("/api/products/{$card->id}/description-versions/{$older->id}/restore")->assertOk()->assertJsonPath('files_from_previous', true);
        $this->assertSame([], $this->store->rejectedUrls($card));
    }

    public function test_approve_on_card_without_current_version_and_without_proposal_clears_reason(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1', ['review_reason' => Product::REVIEW_IDENTITY_SOFT, 'review_since' => now()]);
        $published = $this->published($card, 'soft', 2);
        // opis zmieniony innym torem — wersja nie jest już bieżąca, powód został
        $card->update(['description' => 'Opis zmieniony ręcznie w karcie produktu, rękawice nitrylowe.']);

        $this->postJson("/api/products/{$card->id}/review", ['action' => 'approve', 'version_id' => $published->id])
            ->assertOk()
            ->assertJsonPath('review_reason', null)
            ->assertJsonPath('current_version_id', null);
        $this->assertNull($card->fresh()->review_reason);
        $this->assertSame('Opis zmieniony ręcznie w karcie produktu, rękawice nitrylowe.', $card->fresh()->description);
    }

    public function test_approve_of_already_approved_version_that_left_the_card_clears_reason(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1');
        $published = $this->published($card, 'soft', 2);
        $published->forceFill(['decision' => ProductDescriptionVersion::DECISION_APPROVED])->save();
        // opis zmieniony innym torem, a powód przeglądu wrócił — lista wysyła wersję opublikowaną, którą widać
        $card->update(['description' => 'Opis zmieniony ręcznie w karcie produktu, rękawice nitrylowe.',
            'review_reason' => Product::REVIEW_IDENTITY_SOFT, 'review_since' => now()]);

        $this->postJson("/api/products/{$card->id}/review", ['action' => 'approve', 'version_id' => $published->id])
            ->assertOk()
            ->assertJsonPath('review_reason', null);
        $this->assertNull($card->fresh()->review_reason);
    }

    public function test_approved_proposal_lists_only_files_present_on_card(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1', ['review_reason' => Product::REVIEW_WORSE_VERSION]);
        $this->published($card, 'hard', 4);
        ProductDocument::query()->create(['product_id' => $card->id, 'path' => 'products/'.$card->id.'/docs/doc.pdf',
            'source_url' => 'https://coba.com/a-1-declaration-of-conformity.pdf', 'title' => 'Declaration of conformity', 'kind' => ProductDocument::KIND_CERTIFICATE]);
        $proposal = $this->store->record($card, ProductDescriptionVersion::STATUS_PROPOSED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::PROPOSAL,
            'enrichment_payload' => ['certificates' => ['Certyfikat badania typu UE', 'Certyfikat SATRA 0321'], 'document_urls' => ['https://sklep.pl/nie-ma.pdf']],
            'identity_verdict' => 'soft',
            'evidence_count' => 1,
        ]);

        $this->postJson("/api/products/{$card->id}/review", ['action' => 'approve', 'version_id' => $proposal->id])
            ->assertOk()
            ->assertJsonPath('files_from_previous', true);

        $payload = $card->fresh()->enrichment_payload;
        $this->assertSame(['Certyfikat SATRA 0321', 'Deklaracja zgodności UE'], $payload['certificates']);
        $this->assertSame(['https://coba.com/a-1-declaration-of-conformity.pdf'], $payload['document_urls']);
    }

    public function test_url_saves_trusted_link_records_decision_and_queues_forced_enrichment_once(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1', ['review_reason' => Product::REVIEW_IDENTITY_SOFT, 'review_since' => now()]);
        $published = $this->published($card, 'soft', 2);

        $first = $this->postJson("/api/products/{$card->id}/review", ['action' => 'url', 'url' => 'https://www.coba.com/produkt/a-1'])
            ->assertOk()
            ->assertJsonPath('review_reason', null)
            ->assertJsonPath('shop_source_url', 'https://www.coba.com/produkt/a-1');

        $batch = ProductEnrichmentBatch::query()->findOrFail($first->json('batch_id'));
        $this->assertTrue((bool) $batch->force);
        $this->assertSame(Product::ENRICHMENT_QUEUED, $card->fresh()->enrichment_status);
        $this->assertSame(ProductDescriptionVersion::DECISION_URL_GIVEN, $published->fresh()->decision);
        Queue::assertPushed(PrefetchProductSourcesJob::class, 1);

        // drugie kliknięcie z tym samym adresem — ta sama partia, bez nowego zadania
        $this->postJson("/api/products/{$card->id}/review", ['action' => 'url', 'url' => 'https://www.coba.com/produkt/a-1/'])
            ->assertOk()
            ->assertJsonPath('batch_id', $batch->id);
        Queue::assertPushed(PrefetchProductSourcesJob::class, 1);
    }

    public function test_url_rejects_b2b_connector_and_non_public_addresses(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1', ['review_reason' => Product::REVIEW_IDENTITY_SOFT]);
        $this->published($card, 'soft', 2);

        $this->postJson("/api/products/{$card->id}/review", ['action' => 'url', 'url' => 'https://'.AnroB2bClient::HOST.'/produkt/1'])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'B2B'));

        foreach ([
            'http://localhost/produkt',
            'http://127.0.0.1/produkt',
            'http://10.0.0.5/produkt',
            'http://169.254.169.254/latest/meta-data',
            'https://intranet.firma.pl/produkt',
            'https://metadata.firma.pl/produkt',
            'http://user:pass@coba.com/produkt',
            'https://coba.com:8080/produkt',
            'ftp://coba.com/produkt',
            'javascript:alert(1)',
        ] as $url) {
            $this->postJson("/api/products/{$card->id}/review", ['action' => 'url', 'url' => $url])->assertUnprocessable();
        }

        $this->assertNull($card->fresh()->shop_source_url);
        $this->assertSame(Product::REVIEW_IDENTITY_SOFT, $card->fresh()->review_reason);
        Queue::assertNotPushed(PrefetchProductSourcesJob::class);
        $this->assertSame(0, ProductEnrichmentBatch::query()->count());
    }

    public function test_url_on_card_with_b2b_description_is_conflict(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1', ['review_reason' => Product::REVIEW_IDENTITY_SOFT]);
        $this->b2bLink($card);

        $this->postJson("/api/products/{$card->id}/review", ['action' => 'url', 'url' => 'https://coba.com/a-1'])->assertStatus(409);
        $this->assertNull($card->fresh()->shop_source_url);
        Queue::assertNotPushed(PrefetchProductSourcesJob::class);
    }

    public function test_restore_publishes_older_version_and_refuses_shadow_b2b_and_foreign_versions(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1');
        $old = $this->published($card, 'hard', 4);
        $newText = 'Rękawice robocze powlekane, wersja opisu z nowszego przebiegu.';
        $card->update(['description' => $newText]);
        $this->store->record($card, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, ['description' => $newText]);
        $shadow = $this->store->record($card, ProductDescriptionVersion::STATUS_SHADOW, ProductDescriptionVersion::ORIGIN_STORED_SOURCES, ['description' => 'Opis z przebiegu w cieniu, rękawice nitrylowe.']);

        $this->postJson("/api/products/{$card->id}/description-versions/{$shadow->id}/restore")->assertStatus(409);

        $restored = $this->postJson("/api/products/{$card->id}/description-versions/{$old->id}/restore", ['note' => 'lepszy'])
            ->assertOk()
            ->assertJsonPath('description', self::DESCRIPTION);
        $this->assertSame(self::DESCRIPTION, $card->fresh()->description);
        $this->assertSame(ProductDescriptionVersion::DECISION_RESTORED, $old->fresh()->decision);
        $count = ProductDescriptionVersion::query()->count();
        // drugie kliknięcie: ten sam opis już na karcie
        $this->postJson("/api/products/{$card->id}/description-versions/{$old->id}/restore")
            ->assertOk()
            ->assertJsonPath('current_version_id', $restored->json('current_version_id'));
        $this->assertSame($count, ProductDescriptionVersion::query()->count());

        $other = $this->card($this->list('MAPA'), 'M-1');
        $this->postJson("/api/products/{$other->id}/description-versions/{$old->id}/restore")->assertNotFound();

        $this->b2bLink($card);
        $this->postJson("/api/products/{$card->id}/description-versions/{$old->id}/restore")->assertStatus(409);
    }

    public function test_history_lists_versions_newest_first_with_decider(): void
    {
        $card = $this->card($this->list('Coba'), 'A-1', ['review_reason' => Product::REVIEW_WORSE_VERSION]);
        $published = $this->published($card, 'hard', 4);
        $proposal = $this->proposal($card, 'soft', 1);
        $this->postJson("/api/products/{$card->id}/review", ['action' => 'reject', 'version_id' => $proposal->id])->assertOk();

        $response = $this->getJson("/api/products/{$card->id}/description-versions")->assertOk();

        $response->assertJsonPath('current_version_id', $published->id);
        $this->assertSame([$proposal->id, $published->id], array_column($response->json('data'), 'id'));
        $this->assertSame(['id' => $this->user->id, 'name' => $this->user->name], $response->json('data.0.decided_by'));
        $this->assertSame('rejected', $response->json('data.0.decision'));
        $this->assertSame(self::PROPOSAL, $response->json('data.0.description'));
        $this->assertSame(['https://sklep.pl/p'], $response->json('data.0.source_urls'));
        // odrzucona propozycja nie blokuje adresu
        $this->assertFalse($response->json('data.0.url_blocked'));
        $this->assertSame([], $response->json('data.0.evidence'));
    }

    public function test_source_document_text_is_plain_text_of_own_card_only(): void
    {
        config(['enrichment.store_sources' => true]);
        Storage::fake('sources');
        $card = $this->card($this->list('Coba'), 'A-1');
        $other = $this->card($this->list('MAPA'), 'M-1');
        app(SourceDocumentStore::class)->record($card, [['url' => 'https://coba.com/a-1', 'text' => 'Zaczep do kabli A-1, stal ocynkowana.']], null);
        $document = ProductSourceDocument::query()->sole();

        $response = $this->get("/api/products/{$card->id}/source-documents/{$document->id}/text")->assertOk();
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $this->assertSame('Zaczep do kabli A-1, stal ocynkowana.', $response->getContent());

        $this->get("/api/products/{$other->id}/source-documents/{$document->id}/text")->assertNotFound();
        Storage::disk('sources')->delete($document->sha256.'.txt.gz');
        $this->get("/api/products/{$card->id}/source-documents/{$document->id}/text")->assertNotFound();
    }

    private function list(string $manufacturer): PriceList
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
        ])->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function card(PriceList $list, string $sku, array $attributes = []): Product
    {
        $card = Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawice testowe '.$sku,
            'manufacturer' => (string) $list->manufacturer,
            'description' => self::DESCRIPTION,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 0,
            ...$attributes,
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id,
            'source_key' => ProductSourcePrice::SOURCE_FILE,
            'price_list_id' => $list->id,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'currency' => 'PLN',
            'checked_at' => now(),
        ]);

        return $card;
    }

    private function published(Product $card, ?string $verdict, ?int $evidence): ProductDescriptionVersion
    {
        return $this->store->record($card, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => (string) $card->description,
            'enrichment_payload' => is_array($card->enrichment_payload) ? $card->enrichment_payload : null,
            'primary_source_url' => 'https://coba.com/'.mb_strtolower((string) $card->sku),
            'identity_verdict' => $verdict,
            'evidence_count' => $evidence,
        ]);
    }

    private function proposal(Product $card, string $verdict, int $evidence, string $url = 'https://sklep.pl/p'): ProductDescriptionVersion
    {
        return $this->store->record($card, ProductDescriptionVersion::STATUS_PROPOSED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::PROPOSAL,
            'enrichment_payload' => ['source_urls' => [$url], 'primary_source_url' => $url],
            'identity_verdict' => $verdict,
            'evidence_count' => $evidence,
            'review_reason' => Product::REVIEW_WORSE_VERSION,
        ]);
    }

    private function b2bAccount(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(['username' => 'jan'], [
            'password' => 'sekret',
            'sites' => [AnroB2bClient::HOST],
            'connector' => 'anro',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    private function b2bLink(Product $card): void
    {
        $account = $this->b2bAccount();
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id,
            'remote_id' => (string) $card->sku,
            'product_id' => $card->id,
            'remote_sku' => (string) $card->sku,
            'remote_name' => 'Rękawice',
            'description_hash' => sha1((string) $card->fresh()->description),
        ]);
    }
}
