<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Models\ProductSourceDocument;
use App\Models\User;
use App\Services\B2b\AnroB2bClient;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\SourceDocumentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Wersje opisu karty (etap 1, 08.10.2026): reguła decide (każda gałąź), wygasanie bazy po zmianie opisu, zastępowanie
 * published, retencja i zapis wersji na kartę przez model.
 */
final class DescriptionVersionStoreTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTION = 'Rękawice robocze powlekane nitrylem, mankiet ściągacz, norma EN 388 4121X.';

    private DescriptionVersionStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->store = app(DescriptionVersionStore::class);
    }

    public function test_manual_url_publishes_even_when_source_was_rejected_and_rank_is_lower(): void
    {
        $card = $this->card();
        $this->published($card, 'hard', 5, 'https://producent.pl/a-1');
        $this->version($card, ProductDescriptionVersion::STATUS_REJECTED, 'https://sklep.pl/a-1', blocked: true);

        $decision = $this->store->decide($card, ['identity' => 'soft', 'evidence_count' => 1, 'primary_source_url' => 'https://sklep.pl/a-1', 'manual_url' => true]);

        $this->assertSame('publish', $decision['action']);
        $this->assertSame(Product::REVIEW_IDENTITY_SOFT, $decision['review_reason']);
    }

    public function test_rejected_source_proposes_also_on_card_without_description(): void
    {
        $card = $this->card(['description' => null]);
        $this->version($card, ProductDescriptionVersion::STATUS_REJECTED, 'https://sklep.pl/a-1/', blocked: true);

        // adres porównywany po Product::normalizeShopUrl (wielkość liter hosta, ukośnik na końcu)
        $decision = $this->store->decide($card, ['identity' => ['verdict' => 'hard'], 'evidence_count' => 9, 'primary_source_url' => 'https://SKLEP.pl/a-1']);

        $this->assertSame(['action' => 'propose', 'review_reason' => Product::REVIEW_REJECTED_SOURCE], array_intersect_key($decision, ['action' => 1, 'review_reason' => 1]));
        $this->assertSame(['https://sklep.pl/a-1/'], $this->store->rejectedUrls($card));
    }

    public function test_card_without_description_or_without_baseline_publishes(): void
    {
        $empty = $this->card(['sku' => 'A-2', 'description' => null]);
        $this->assertSame('publish', $this->store->decide($empty, ['identity' => 'none', 'evidence_count' => 0])['action']);
        $this->assertSame(Product::REVIEW_IDENTITY_NONE, $this->store->decide($empty, ['identity' => 'none'])['review_reason']);

        // opis będący samą nazwą z cennika to nie opis
        $nameOnly = $this->card(['sku' => 'A-3', 'name' => 'Rękawice nitrylowe A-3 długa nazwa', 'description' => 'Rękawice nitrylowe A-3 długa nazwa']);
        $this->published($nameOnly, 'hard', 5, null, (string) $nameOnly->description);
        $this->assertSame('publish', $this->store->decide($nameOnly, ['identity' => 'none', 'evidence_count' => 0])['action']);

        $legacy = $this->card(['sku' => 'A-4']);
        $decision = $this->store->decide($legacy, ['identity' => 'soft', 'evidence_count' => 0]);
        $this->assertSame('publish', $decision['action']);
        $this->assertSame(Product::REVIEW_IDENTITY_SOFT, $decision['review_reason']);
    }

    public function test_known_ranks_lower_proposes_higher_publishes(): void
    {
        $card = $this->card();
        $this->published($card, 'soft', 5);

        $lower = $this->store->decide($card, ['identity' => 'none', 'evidence_count' => 10]);
        $this->assertSame('propose', $lower['action']);
        $this->assertSame(Product::REVIEW_WORSE_VERSION, $lower['review_reason']);

        // wyższa ranga wygrywa mimo mniejszej liczby dowodów
        $higher = $this->store->decide($card, ['identity' => 'hard', 'evidence_count' => 1]);
        $this->assertSame('publish', $higher['action']);
        $this->assertNull($higher['review_reason']);
    }

    public function test_equal_or_unknown_rank_proposes_only_when_evidence_drops_by_two_or_more(): void
    {
        $card = $this->card();
        $this->published($card, 'hard', 4);

        // o jeden dowód mniej to szum pobrania — zapis
        $this->assertSame('publish', $this->store->decide($card, ['identity' => 'hard', 'evidence_count' => 3])['action']);
        $this->assertSame('publish', $this->store->decide($card, ['identity' => 'hard', 'evidence_count' => 4])['action']);
        $dropTwo = $this->store->decide($card, ['identity' => 'hard', 'evidence_count' => 2]);
        $this->assertSame('propose', $dropTwo['action']);
        $this->assertSame(Product::REVIEW_WORSE_VERSION, $dropTwo['review_reason']);
        // nieznana ranga nowego opisu → rozstrzygają dowody, z tym samym progiem
        $this->assertSame('publish', $this->store->decide($card, ['identity' => null, 'evidence_count' => 3])['action']);
        $this->assertSame('propose', $this->store->decide($card, ['identity' => null, 'evidence_count' => 2])['action']);
        $this->assertSame('publish', $this->store->decide($card, ['identity' => null, 'evidence_count' => null])['action']);
    }

    public function test_baseline_without_evidence_count_does_not_block_and_unknown_base_rank_compares_evidence(): void
    {
        $noEvidence = $this->card(['sku' => 'B-1']);
        $this->published($noEvidence, 'hard', null);
        $this->assertSame('publish', $this->store->decide($noEvidence, ['identity' => 'hard', 'evidence_count' => 0])['action']);

        $unknownRank = $this->card(['sku' => 'B-2']);
        $this->published($unknownRank, null, 3);
        $this->assertSame('propose', $this->store->decide($unknownRank, ['identity' => 'hard', 'evidence_count' => 1])['action']);
        $this->assertSame('publish', $this->store->decide($unknownRank, ['identity' => 'hard', 'evidence_count' => 2])['action']);
        $this->assertSame('publish', $this->store->decide($unknownRank, ['identity' => 'none', 'evidence_count' => 3])['action']);
    }

    public function test_baseline_expires_when_description_changes_outside_versions(): void
    {
        $card = $this->card();
        $this->published($card, 'hard', 5);
        $this->assertTrue($this->store->hasProtectedPublished($card));
        $this->assertSame('propose', $this->store->decide($card, ['identity' => 'soft', 'evidence_count' => 5])['action']);

        // zapis innym torem (B2B, edycja) zmienia sha1 opisu — baza wygasa sama
        $card->update(['description' => 'Opis ze sklepu dostawcy B2B, dzianina nylonowa 13, powlekana.']);

        $this->assertNull($this->store->current($card));
        $this->assertFalse($this->store->hasProtectedPublished($card));
        $this->assertSame('publish', $this->store->decide($card, ['identity' => 'soft', 'evidence_count' => 0])['action']);
    }

    public function test_record_supersedes_previous_published_and_reads_payload_fields(): void
    {
        $card = $this->card();
        $first = $this->published($card, 'hard', 5);

        $second = $this->store->record($card, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::DESCRIPTION.' Nowy.',
            'enrichment_payload' => [
                'primary_source_url' => 'https://producent.pl/a-1',
                'identity' => ['verdict' => 'soft', 'reason' => 'marka z nazwą'],
                'evidence_summary' => ['explicit' => 3, 'inferred' => 1],
                'completeness' => 0.75,
            ],
            'review_reason' => Product::REVIEW_IDENTITY_SOFT,
        ]);

        $this->assertSame(ProductDescriptionVersion::STATUS_SUPERSEDED, $first->fresh()->status);
        $this->assertSame(1, ProductDescriptionVersion::query()->where('product_id', $card->id)->where('status', 'published')->count());
        $this->assertSame(sha1(self::DESCRIPTION.' Nowy.'), $second->description_sha1);
        $this->assertSame('https://producent.pl/a-1', $second->primary_source_url);
        $this->assertSame('soft', $second->identity_verdict);
        $this->assertSame('marka z nazwą', $second->identity_reason);
        $this->assertSame(3, $second->evidence_count);
        $this->assertSame('0.750', $second->fresh()->completeness);
        $this->assertSame(Product::REVIEW_IDENTITY_SOFT, $second->review_reason);
    }

    public function test_retention_keeps_published_rejected_decided_proposals_three_open_proposals_and_five_latest_superseded_and_shadow(): void
    {
        $card = $this->card();
        $decided = $this->store->record($card, ProductDescriptionVersion::STATUS_PROPOSED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, ['description' => self::DESCRIPTION.' zatwierdzona']);
        $decided->forceFill(['decision' => ProductDescriptionVersion::DECISION_APPROVED, 'decided_at' => now()])->save();
        for ($i = 0; $i < 8; $i++) {
            $this->store->record($card, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, ['description' => self::DESCRIPTION." {$i}"]);
            $this->store->record($card, ProductDescriptionVersion::STATUS_PROPOSED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, ['description' => self::DESCRIPTION." p{$i}"]);
            $this->store->record($card, ProductDescriptionVersion::STATUS_SHADOW, ProductDescriptionVersion::ORIGIN_STORED_SOURCES, ['description' => self::DESCRIPTION." s{$i}"]);
            $this->version($card, ProductDescriptionVersion::STATUS_REJECTED, "https://sklep.pl/{$i}");
        }

        $count = static fn (string $status): int => ProductDescriptionVersion::query()->where('product_id', $card->id)->where('status', $status)->count();
        $this->assertSame(1, $count('published'));
        // trzy najnowsze propozycje bez decyzji i propozycja z decyzją (historia przeglądu)
        $this->assertSame(4, $count('proposed'));
        $this->assertSame(
            [self::DESCRIPTION.' p7', self::DESCRIPTION.' p6', self::DESCRIPTION.' p5'],
            ProductDescriptionVersion::query()->where('product_id', $card->id)->where('status', 'proposed')->whereNull('decision')->orderByDesc('id')->pluck('description')->all(),
        );
        $this->assertNotNull($decided->fresh());
        $this->assertSame(8, $count('rejected'));
        $this->assertSame(5, $count('superseded'));
        $this->assertSame(5, $count('shadow'));
        // zostają najnowsze
        $this->assertSame(
            [self::DESCRIPTION.' 6', self::DESCRIPTION.' 5', self::DESCRIPTION.' 4', self::DESCRIPTION.' 3', self::DESCRIPTION.' 2'],
            ProductDescriptionVersion::query()->where('product_id', $card->id)->where('status', 'superseded')->orderByDesc('id')->pluck('description')->all(),
        );
    }

    public function test_publish_writes_card_through_model_with_new_published_version(): void
    {
        $user = User::factory()->create();
        $card = $this->card(['packaging' => 'para', 'review_reason' => Product::REVIEW_WORSE_VERSION, 'review_since' => now(), 'enrichment_status' => Product::ENRICHMENT_FAILED, 'enrichment_error' => 'stary błąd']);
        $this->published($card, 'hard', 5);
        $proposal = $this->store->record($card, ProductDescriptionVersion::STATUS_PROPOSED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => 'Półbuty ochronne skórzane S3 SRC, podnosek kompozytowy, wkładka antyprzebiciowa.',
            'enrichment_payload' => ['norms' => ['EN ISO 20345:2022', 'S3'], 'identity' => ['verdict' => 'soft']],
            'enrichment_trace' => [['step' => 'desc']],
            'packaging' => 'karton 10 par',
        ]);
        Queue::fake();
        // reindeks wektorów kolejkuje się tylko przy włączonym wyszukiwaniu wektorowym (ReindexProductEmbeddingJob::dispatch)
        config(['ai.vector_enabled' => true, 'ai.qdrant_url' => 'http://qdrant.test:6333']);

        $saved = $this->store->publish($proposal, $user, ProductDescriptionVersion::ORIGIN_REVIEW_APPROVE);

        $card->refresh();
        $current = $this->store->current($card);
        $this->assertNotNull($current);
        $this->assertSame(ProductDescriptionVersion::ORIGIN_REVIEW_APPROVE, $current->origin);
        $this->assertSame($user->id, $current->created_by);
        $this->assertSame('soft', $current->identity_verdict);
        $this->assertSame(ProductDescriptionVersion::STATUS_PROPOSED, $proposal->fresh()->status);
        $this->assertSame($card->id, $saved->id);
        $this->assertSame('Półbuty ochronne skórzane S3 SRC, podnosek kompozytowy, wkładka antyprzebiciowa.', $card->description);
        $this->assertSame($current->id, $card->enrichment_payload['description_version_id']);
        $this->assertSame('EN ISO 20345:2022, S3', $card->norms);
        $this->assertSame('karton 10 par', $card->packaging);
        $this->assertSame([['step' => 'desc']], $card->enrichment_trace);
        $this->assertSame(Product::ENRICHMENT_DONE, $card->enrichment_status);
        $this->assertNull($card->enrichment_error);
        $this->assertNull($card->review_reason);
        $this->assertNull($card->review_since);
        // przez model: indeks tekstowy przeliczony, reindeks wektora zlecony
        $this->assertStringContainsString('podnosek', mb_strtolower((string) $card->search_blob));
        Queue::assertPushed(ReindexProductEmbeddingJob::class);
    }

    public function test_snapshot_lists_web_files_without_b2b_panel_files(): void
    {
        $card = $this->card(['norms' => 'EN 388', 'manufacturer_norms' => ['rows' => []], 'packaging' => 'para']);
        $web = ProductImage::query()->create(['product_id' => $card->id, 'path' => 'products/a.jpg', 'source_url' => 'https://sklep.pl/a.jpg', 'checksum' => 'a', 'sort_order' => 0]);

        $snapshot = $this->store->snapshot($card);

        $this->assertSame(Product::ENRICHMENT_DONE, $snapshot['status']);
        $this->assertSame('EN 388', $snapshot['norms']);
        $this->assertSame(['rows' => []], $snapshot['manufacturer_norms']);
        $this->assertSame('para', $snapshot['packaging']);
        $this->assertSame(['images' => [$web->id], 'documents' => []], $snapshot['web_files']);
    }

    public function test_only_blocked_rejected_versions_block_url_and_unblock_lifts_it(): void
    {
        $card = $this->card();
        $user = User::factory()->create();
        // odrzucona propozycja — adres bywa źródłem obecnego dobrego opisu, nie blokuje
        $this->version($card, ProductDescriptionVersion::STATUS_REJECTED, 'https://sklep.pl/a-1');
        $this->assertSame([], $this->store->rejectedUrls($card));

        $blocked = $this->version($card, ProductDescriptionVersion::STATUS_REJECTED, 'https://obcy.pl/x/', blocked: true);
        $this->assertSame(['https://obcy.pl/x/'], $this->store->rejectedUrls($card));
        $this->assertTrue($this->store->blocksUrl($blocked));

        $this->assertSame(1, $this->store->unblockSourceUrl($card, 'https://OBCY.pl/x', $user));
        $this->assertSame([], $this->store->rejectedUrls($card));
        $blocked->refresh();
        $this->assertFalse($this->store->blocksUrl($blocked));
        $this->assertSame(ProductDescriptionVersion::STATUS_REJECTED, $blocked->status);
        $this->assertSame($user->id, $this->store->meta($blocked)['url_unblocked_by']);
        // reszta payloadu wersji bez zmian
        $this->assertSame('Opis odrzucony ze sklepu, rękawice robocze innego producenta.', $blocked->description);
    }

    public function test_blocked_url_covers_www_and_mobile_host_variants_of_the_same_page(): void
    {
        $card = $this->card();
        $user = User::factory()->create();
        $this->version($card, ProductDescriptionVersion::STATUS_REJECTED, 'https://www.obcy.pl/x', blocked: true);
        $this->version($card, ProductDescriptionVersion::STATUS_REJECTED, 'https://obcy.pl/x/', blocked: true);
        $candidate = static fn (string $url): array => ['identity' => 'hard', 'evidence_count' => 9, 'primary_source_url' => $url];

        // jedna strona pod trzema wariantami hosta — jeden zablokowany adres
        $this->assertSame(['https://www.obcy.pl/x'], $this->store->rejectedUrls($card));
        foreach (['https://obcy.pl/x', 'https://m.obcy.pl/x', 'http://WWW.obcy.pl/x/'] as $url) {
            $this->assertSame('propose', $this->store->decide($card, $candidate($url))['action'], $url);
        }
        // inna subdomena i inna ścieżka to inne strony
        $this->assertSame('publish', $this->store->decide($card, $candidate('https://sklep.obcy.pl/x'))['action']);
        $this->assertSame('publish', $this->store->decide($card, $candidate('https://obcy.pl/y'))['action']);
        $this->assertSame('obcy.pl/x', DescriptionVersionStore::sourceUrlKey('https://m.obcy.pl/x'));
        $this->assertSame('m.pl/x', DescriptionVersionStore::sourceUrlKey('https://m.pl/x'));

        // zatwierdzenie wersji z wariantu bez „www.” zdejmuje obie blokady tej strony
        $this->assertSame(2, $this->store->unblockSourceUrl($card, 'https://m.obcy.pl/x', $user));
        $this->assertSame([], $this->store->rejectedUrls($card));
    }

    public function test_version_meta_lists_files_added_by_run_and_stays_only_in_version_payload(): void
    {
        $card = $this->card(['manufacturer_norms' => ['rows' => [['norm' => 'EN 388']]]]);
        $old = ProductImage::query()->create(['product_id' => $card->id, 'path' => 'products/old.jpg', 'source_url' => 'https://sklep.pl/old.jpg', 'checksum' => 'old', 'sort_order' => 0]);
        $before = $this->store->snapshot($card);
        $new = ProductImage::query()->create(['product_id' => $card->id, 'path' => 'products/new.jpg', 'source_url' => 'https://sklep.pl/new.jpg', 'checksum' => 'new', 'sort_order' => 1]);
        $b2b = ProductImage::query()->create(['product_id' => $card->id, 'b2b_account_id' => $this->b2bAccount()->id, 'path' => 'products/b2b.jpg', 'source_url' => 'https://b2b.pl/b.jpg', 'checksum' => 'b2b', 'sort_order' => 2]);
        $doc = ProductDocument::query()->create(['product_id' => $card->id, 'path' => 'products/d.pdf', 'source_url' => 'https://sklep.pl/d.pdf', 'title' => 'Karta', 'kind' => ProductDocument::KIND_DATASHEET]);

        // przebieg, który nie zapisał norm producenta, nie niesie ich wartości — odrzucenie ich nie dotknie
        $this->assertSame(['web_file_ids' => ['images' => [$new->id], 'documents' => [$doc->id]]], $this->store->versionMeta($card, $before));
        $written = ['rows' => [['norm' => 'EN 407']]];
        $meta = $this->store->versionMeta($card, $before, ['manufacturer_norms' => $written, 'norms' => 'EN 407']);

        $this->assertSame(['images' => [$new->id], 'documents' => [$doc->id]], $meta['web_file_ids']);
        $this->assertSame(['rows' => [['norm' => 'EN 388']]], $meta['manufacturer_norms_before']);
        $this->assertSame($written, $meta['manufacturer_norms_written']);
        $this->assertNotContains($old->id, $meta['web_file_ids']['images']);
        $this->assertNotContains($b2b->id, $meta['web_file_ids']['images']);

        $payload = ['norms' => ['EN 388'], DescriptionVersionStore::META_KEY => ['url_blocked' => true]];
        $version = $this->store->record($card, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::DESCRIPTION,
            'enrichment_payload' => $payload,
            '_version' => $meta + ['nieznany' => 1],
        ]);

        // klucz z samego payloadu nie przechodzi — dane techniczne tylko z '_version', tylko znane pola
        $this->assertSame($meta, $this->store->meta($version->fresh()));
        $this->assertSame(['EN 388'], $version->fresh()->enrichment_payload['norms']);
        $this->assertArrayHasKey(DescriptionVersionStore::META_KEY, $payload);
    }

    /** Etap 2: adresy zdjęć stron opisu lidera zostają w danych technicznych wersji — bez powtórzeń, najwyżej 40, tylko napisy. */
    public function test_version_meta_keeps_page_image_urls_capped_and_deduplicated(): void
    {
        $card = $this->card();
        $urls = array_map(static fn (int $i): string => "https://coba.com/img/{$i}.jpg", range(1, 45));
        $given = ['', ' https://coba.com/img/1.jpg ', 7, null, ...$urls];

        $version = $this->store->record($card, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::DESCRIPTION,
            'enrichment_payload' => ['norms' => ['EN 388']],
            '_version' => ['page_image_urls' => $given],
        ]);

        $meta = $this->store->meta($version->fresh());
        $this->assertSame(array_slice($urls, 0, DescriptionVersionStore::PAGE_IMAGE_URLS_MAX), $meta['page_image_urls']);
        // tylko w danych technicznych wersji, nie obok reszty payloadu
        $payload = $version->fresh()->enrichment_payload;
        $this->assertSame(['EN 388'], $payload['norms']);
        $this->assertArrayNotHasKey('page_image_urls', $payload);
        // pusta lista nie zostawia klucza
        $empty = $this->store->record($card, ProductDescriptionVersion::STATUS_SHADOW, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::DESCRIPTION, '_version' => ['page_image_urls' => [' ', 3]],
        ]);
        $this->assertSame([], $this->store->meta($empty->fresh()));
    }

    public function test_publish_recomputes_certificates_and_document_urls_from_files_on_card_and_drops_version_meta(): void
    {
        $card = $this->card();
        $this->published($card, 'hard', 5);
        ProductDocument::query()->create(['product_id' => $card->id, 'path' => 'products/doc.pdf', 'source_url' => 'https://coba.com/pdf/a-1-declaration-of-conformity.pdf',
            'title' => 'Declaration of conformity', 'kind' => ProductDocument::KIND_CERTIFICATE]);
        ProductDocument::query()->create(['product_id' => $card->id, 'b2b_account_id' => $this->b2bAccount()->id, 'path' => 'products/b2b.pdf',
            'source_url' => 'https://b2b.pl/type-examination-certificate.pdf', 'title' => 'EU type examination certificate', 'kind' => ProductDocument::KIND_CERTIFICATE]);
        $proposal = $this->store->record($card, ProductDescriptionVersion::STATUS_PROPOSED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => 'Rękawice z propozycji, powlekane nitrylem, certyfikat badania typu z jednostką 0598.',
            // pliki propozycji usunął jej przebieg — wpisy automatu i adresy wskazują pliki, których na karcie nie ma
            'enrichment_payload' => ['certificates' => ['Certyfikat badania typu UE', 'Certyfikat SATRA 0321', 'CE'], 'document_urls' => ['https://sklep.pl/usuniety.pdf']],
            '_version' => ['web_file_ids' => ['images' => [999], 'documents' => []], 'manufacturer_norms_before' => null],
        ]);

        $this->store->publish($proposal, null, ProductDescriptionVersion::ORIGIN_REVIEW_APPROVE);

        $card->refresh();
        $payload = $card->enrichment_payload;
        $this->assertSame(['Certyfikat SATRA 0321', 'Deklaracja zgodności UE'], $payload['certificates']);
        $this->assertSame(['https://coba.com/pdf/a-1-declaration-of-conformity.pdf'], $payload['document_urls']);
        $this->assertArrayNotHasKey(DescriptionVersionStore::META_KEY, $payload);
        $current = $this->store->current($card);
        $this->assertSame([], $this->store->meta($current));
        $this->assertSame($payload['certificates'], $current->enrichment_payload['certificates']);
    }

    public function test_publish_moves_source_documents_of_source_version_to_new_published(): void
    {
        config(['enrichment.store_sources' => true]);
        Storage::fake('sources');
        $card = $this->card();
        $this->published($card, 'hard', 5);
        $proposal = $this->store->record($card, ProductDescriptionVersion::STATUS_PROPOSED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => 'Rękawice z propozycji, powlekane nitrylem, rozmiary 7–11.',
        ]);
        $sources = app(SourceDocumentStore::class);
        $sources->record($card, [['url' => 'https://sklep.pl/a-1', 'text' => 'Rękawice A-1, nitryl, tekst z pierwszego pobrania.']], (int) $proposal->id);

        $this->store->publish($proposal, null, ProductDescriptionVersion::ORIGIN_REVIEW_APPROVE);
        $current = $this->store->current($card->refresh());
        $this->assertSame([(int) $current->id], ProductSourceDocument::query()->where('product_id', $card->id)->pluck('description_version_id')->all());

        // nowy tekst tego samego adresu: źródło opisu na karcie zostaje (retencja trzyma wiersze opublikowanej wersji)
        $sources->record($card, [['url' => 'https://sklep.pl/a-1', 'text' => 'Rękawice A-1, nitryl, tekst z drugiego pobrania.']], null);
        $this->assertSame(2, ProductSourceDocument::query()->where('product_id', $card->id)->count());
        $this->assertTrue(ProductSourceDocument::query()->where('description_version_id', $current->id)->exists());
    }

    public function test_publish_run_records_version_and_writes_card_in_one_transaction(): void
    {
        $card = $this->card(['description' => null]);
        $calls = [];

        $result = $this->store->publishRun($card, ['identity' => 'soft', 'evidence_count' => 2, 'primary_source_url' => 'https://sklep.pl/a-1'], [
            'description' => self::DESCRIPTION,
            'enrichment_payload' => ['norms' => ['EN 388']],
            '_version' => ['web_file_ids' => ['images' => [5], 'documents' => []], 'manufacturer_norms_before' => null],
        ], function (ProductDescriptionVersion $version, array $decision) use ($card, &$calls): void {
            $calls[] = [$version->id, $decision['review_reason']];
            $card->update(['description' => self::DESCRIPTION, 'review_reason' => $decision['review_reason']]);
        });

        $this->assertSame('publish', $result['decision']['action']);
        $this->assertNotNull($result['version']);
        $this->assertSame([[$result['version']->id, Product::REVIEW_IDENTITY_SOFT]], $calls);
        $this->assertSame(Product::REVIEW_IDENTITY_SOFT, $result['version']->review_reason);
        $this->assertSame('karta bez opisu', $result['version']->reason);
        $this->assertSame(['images' => [5], 'documents' => []], $this->store->meta($result['version'])['web_file_ids']);
        $this->assertSame((int) $result['version']->id, (int) $this->store->current($card->refresh())?->id);
    }

    public function test_publish_run_decides_again_under_lock_and_proposal_writes_nothing(): void
    {
        $card = $this->card();
        $this->published($card, 'hard', 5, 'https://producent.pl/a-1');
        $candidate = ['identity' => 'hard', 'evidence_count' => 5, 'primary_source_url' => 'https://obcy.pl/x'];
        $this->assertSame('publish', $this->store->decide($card, $candidate)['action']);
        // między pierwszą decyzją a zapisem handlowiec odrzucił opis z tego adresu
        $this->version($card, ProductDescriptionVersion::STATUS_REJECTED, 'https://obcy.pl/x', blocked: true);
        $versions = ProductDescriptionVersion::query()->count();

        $result = $this->store->publishRun($card, $candidate, ['description' => 'Opis z cudzej strony, inne rękawice.'], function (): void {
            $this->fail('karta nie może być zapisana przy propozycji');
        });

        $this->assertSame('propose', $result['decision']['action']);
        $this->assertSame(Product::REVIEW_REJECTED_SOURCE, $result['decision']['review_reason']);
        $this->assertNull($result['version']);
        $this->assertSame($versions, ProductDescriptionVersion::query()->count());
        $this->assertSame(self::DESCRIPTION, $card->fresh()->description);
    }

    public function test_publish_run_rolls_back_version_when_card_write_fails(): void
    {
        $card = $this->card(['description' => null]);

        try {
            $this->store->publishRun($card, ['identity' => 'hard', 'evidence_count' => 3], ['description' => self::DESCRIPTION], static function (): void {
                throw new RuntimeException('zapis karty nie powiódł się');
            });
            $this->fail('wyjątek zapisu karty miał przejść do wołającego');
        } catch (RuntimeException $e) {
            $this->assertSame('zapis karty nie powiódł się', $e->getMessage());
        }

        $this->assertSame(0, ProductDescriptionVersion::query()->where('product_id', $card->id)->count());
    }

    private function b2bAccount(): B2bAccount
    {
        $user = User::factory()->create();

        return B2bAccount::query()->create([
            'username' => 'jan'.$user->id,
            'password' => 'sekret',
            'sites' => [AnroB2bClient::HOST],
            'connector' => 'anro',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function card(array $attributes = []): Product
    {
        return Product::query()->create([
            'sku' => 'A-1',
            'name' => 'Rękawice testowe',
            'manufacturer' => 'Testowy',
            'description' => self::DESCRIPTION,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 0,
            ...$attributes,
        ]);
    }

    private function published(Product $card, ?string $verdict, ?int $evidence, ?string $url = null, ?string $description = null): ProductDescriptionVersion
    {
        return $this->store->record($card, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => $description ?? (string) $card->description,
            'primary_source_url' => $url,
            'identity_verdict' => $verdict,
            'evidence_count' => $evidence,
        ]);
    }

    private function version(Product $card, string $status, ?string $url, bool $blocked = false): ProductDescriptionVersion
    {
        $version = $this->store->record($card, $status, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => 'Opis odrzucony ze sklepu, rękawice robocze innego producenta.',
            'primary_source_url' => $url,
        ]);
        if ($blocked) {
            $this->store->blockSourceUrl($version);
        }

        return $version->fresh();
    }
}
