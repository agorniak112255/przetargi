<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ApplyModelDescriptionJob;
use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentBatchItem;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ManufacturerDomainResolver;
use App\Services\Enrichment\ProductDocumentDownloader;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\ProductImageCandidateVerifier;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\ProductSearchIdentity;
use App\Services\Enrichment\SourceDocumentStore;
use App\Support\BhpAttributeNormalizer;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\FakePartsTableResolver;
use Tests\TestCase;

/**
 * Przypięcie kart Coby do wiersza tabeli części coba.com (decyzje właściciela 09–10.10.2026): karta przypięta bierze opis
 * wyłącznie ze strony z tabeli (bez wyszukiwarki), werdykt i specs z wiersza, publikacja mimo twardej bazy z innej
 * strony, zdjęcie z tabeli po zapisie (PartsTableImages); członek modelu z tą samą stroną dostaje swój wiersz i swoje
 * zdjęcie, z inną stroną — błąd; strona 404 i pusty opis kończą się błędem bez kasowania; karta nierozwiązana idzie
 * starą ścieżką.
 */
final class EnrichmentPartsTablePinTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://www.coba.com/pl/produkt/orthomat-standard';

    private const OTHER_PAGE = 'https://www.coba.com/pl/produkt/orthomat-premium';

    private const IMAGE_0706 = 'https://www.coba.com/wp-content/uploads/AF010706-Orthomat-Standard.jpg';

    private const IMAGE_0707 = 'https://www.coba.com/wp-content/uploads/AF010707-Orthomat-Standard.jpg';

    private const PAGE_IMAGE = 'https://www.coba.com/wp-content/uploads/orthomat-standard-gallery.jpg';

    private const DESCRIPTION = 'Mata antyzmęczeniowa Coba Orthomat Standard z pianki PVC o zamkniętych komórkach, z fakturą bąbelkową '
        .'i skośnymi krawędziami. Przeznaczona do stanowisk pracy stojącej w suchych pomieszczeniach, zmniejsza zmęczenie nóg '
        .'i pleców oraz poprawia krążenie krwi. Lekka, łatwa do przycięcia i utrzymania w czystości.';

    private const OLD_DESCRIPTION = 'Mata Orthomat AF0107 z poprzedniego pobrania ze strony innego modelu coba.com z kodem karty w tabeli '
        .'części — opis bazowy z twardym werdyktem i dużą liczbą dowodów, który przy zwykłej regule by nie ustąpił.';

    private const MODEL_KEY = 'coba|page:orthomat-standard';

    /** treść użytkownika ostatniego polecenia opisu (bez filtra stron) */
    private ?string $lastUserPrompt = null;

    /** odpowiedź modelu opisu (nadpisywana w testach pustego opisu) */
    private ?string $modelDescription = self::DESCRIPTION;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake(SourceDocumentStore::DISK);
        config()->set('enrichment.store_sources', true);
        config()->set('manufacturer_profiles.profiles.coba.model', ['group' => 'name_stem', 'min_members' => 2]);
        config(['ai.enrichment_batch_limit' => 50]);
        FakePartsTableResolver::install([
            // skrót cennika rozwiązany po kolorze i rozmiarze
            'AF0107' => FakePartsTableResolver::pin('AF010706', self::PAGE, '1,2 m x 18,3 m', 'Czarny/Żółty', 58.55, self::IMAGE_0706, true, 'AF0107'),
            'AF010707' => FakePartsTableResolver::pin('AF010707', self::PAGE, '0,9 m x 18,3 m', 'Szary', 43.9, self::IMAGE_0707),
            'FF010001' => FakePartsTableResolver::pin('FF010001', self::OTHER_PAGE, '0,6 m x 0,9 m', 'Czarny', 2.1, null, pageTitle: 'Orthomat Premium'),
            'AF0999' => 'brak rodziny na stronach coba.com',
            // wiersz bez wagi i bez koloru (pusta komórka tabeli)
            'AF010708' => FakePartsTableResolver::pin('AF010708', self::PAGE, '0,6 m x 0,9 m', null, null, self::IMAGE_0707),
        ]);
    }

    /** Przegląd 10.10: członek z pustą komórką tabeli nie dostaje wagi ani koloru z wiersza lidera. */
    public function test_member_with_empty_table_cells_does_not_inherit_leader_row_values(): void
    {
        $leader = $this->coba('AF0107', 'Orthomat Standard Czarny/Żółty 1.2m x 18.3m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $member = $this->coba('AF010708', 'Orthomat Standard 0.6m x 0.9m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $batch = $this->batch($leader, [$member]);
        $service = $this->service($this->search());
        $service->enrichProduct($leader, false, (int) $batch->id);
        $version = $service->lastRunVersion();
        $this->assertNotNull($version);
        $this->assertContains('Waga: 58,55 kg', $leader->fresh()->enrichment_payload['specs'] ?? []);
        $service->recordBatchProduct($batch, $member, ProductEnrichmentBatchItem::STATUS_RUNNING);

        $this->assertSame(ApplyModelDescriptionJob::RESULT_PUBLISHED, $service->applyModelDescription($member->fresh(), $version, (int) $batch->id));

        $specs = $member->fresh()->enrichment_payload['specs'] ?? [];
        $this->assertContains('Numer części: AF010708', $specs);
        $this->assertContains('Rozmiar: 0,6 m x 0,9 m', $specs);
        foreach ($specs as $line) {
            $this->assertStringStartsNotWith('Waga:', $line, 'waga innego wariantu');
            $this->assertStringStartsNotWith('Kolor:', $line, 'kolor innego wariantu');
        }
        $this->assertContains('Faktura: bąbelkowa', $specs);
    }

    /** Przegląd 10.10: strona z tabeli przekierowana (wycofany wyrób → kategoria) nie daje opisu z tekstu listy. */
    public function test_pinned_page_redirected_elsewhere_fails_without_deleting_anything(): void
    {
        $leader = $this->pinnedCardWithOldDescription();
        $icd = $this->webImage($leader, 'https://www.icd.pl/img/orthomat-af0107.jpg');
        $search = $this->search();
        $service = $this->service($search, redirectTo: 'https://www.coba.com/pl/kategoria/maty-antyzmeczeniowe');

        try {
            $service->enrichProduct($leader, true);
            $this->fail('przekierowana strona z tabeli części — błąd przebiegu');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Strona z tabeli części nie odpowiedziała', $e->getMessage());
        }

        $this->assertUntouchedAfterFailure($leader, [(int) $icd->id]);
        $this->assertNull($this->lastUserPrompt, 'model opisu nie jest wołany');
    }

    public function test_pinned_leader_uses_only_parts_table_page_publishes_over_hard_base_and_gets_table_image(): void
    {
        $leader = $this->coba('AF0107', 'Orthomat Standard Czarny/Żółty 1.2m x 18.3m (9.5mm)', [
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'description' => self::OLD_DESCRIPTION,
        ]);
        // twarda baza z innej strony coba.com z większą liczbą dowodów — przy zwykłej regule nowy opis byłby propozycją
        app(DescriptionVersionStore::class)->record($leader, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::OLD_DESCRIPTION,
            'primary_source_url' => self::OTHER_PAGE,
            'identity_verdict' => 'hard',
            'evidence_count' => 30,
        ]);
        $account = B2bAccount::query()->create(['username' => 'p4s', 'password' => 'sekret', 'sites' => ['b2b.p4s.pl']]);
        $b2b = $this->webImage($leader, 'https://b2b.p4s.pl/images/AF0107.jpg', ['b2b_account_id' => $account->id]);
        $icd = $this->webImage($leader, 'https://www.icd.pl/img/orthomat-af0107.jpg');
        $banner = $this->webImage($leader, 'https://www.coba.com/wp-content/uploads/StandUpforHealth-banner.jpg');
        $member = $this->coba('AF010707', 'Orthomat Standard Szary 0.9m x 18.3m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $batch = $this->batch($leader, [$member], [$leader->id => Product::ENRICHMENT_DONE], force: true);
        $search = $this->search();
        $service = $this->service($search);

        $service->enrichProduct($leader, true, (int) $batch->id);

        $leader->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $leader->enrichment_status, (string) $leader->enrichment_error);
        $this->assertSame(self::DESCRIPTION, $leader->description);
        $version = $service->lastRunVersion();
        $this->assertNotNull($version);
        $this->assertSame(ProductDescriptionVersion::STATUS_PUBLISHED, $version->status);
        $this->assertSame(DescriptionVersionStore::PARTS_TABLE_REASON, $version->reason);
        $this->assertNull($leader->review_reason);
        // werdykt z wiersza tabeli, źródło = sama strona z tabeli
        $payload = $leader->enrichment_payload;
        $this->assertSame('hard', $payload['identity']['verdict'] ?? null);
        $this->assertSame('manufacturer_code', $payload['identity']['key_type'] ?? null);
        $this->assertSame('AF010706', $payload['identity']['key'] ?? null);
        $this->assertStringContainsString('skrót cennika AF0107', (string) ($payload['identity']['reason'] ?? ''));
        $this->assertSame(self::PAGE, $payload['identity']['source_url'] ?? null);
        $this->assertSame([self::PAGE], $payload['source_urls'] ?? null);
        $this->assertSame(self::PAGE, $payload['primary_source_url'] ?? null);
        $this->assertSame(['page_url' => self::PAGE, 'page_key' => 'orthomat-standard', 'part' => 'AF010706'], array_intersect_key($payload['parts_table'] ?? [], ['page_url' => 1, 'page_key' => 1, 'part' => 1]));
        $this->assertTrue($payload['parts_table']['via_short_code'] ?? false);
        // specs: wiersz tabeli zastępuje linie modelu z tą samą etykietą, linia neutralna zostaje
        $specs = $payload['specs'] ?? [];
        $this->assertContains('Numer części: AF010706', $specs);
        $this->assertContains('Rozmiar: 1,2 m x 18,3 m', $specs);
        $this->assertContains('Kolor: Czarny/Żółty', $specs);
        $this->assertContains('Waga: 58,55 kg', $specs);
        $this->assertContains('Faktura: bąbelkowa', $specs);
        $this->assertNotContains('Kolor: Szary', $specs, 'kolor innego wariantu z odpowiedzi modelu odpada');
        $this->assertCount(1, array_filter($specs, static fn (string $line): bool => str_starts_with($line, 'Kolor:')));
        // polecenie: źródło wyłącznie strona z tabeli, wiersz tabeli, zasady „tylko źródła”, bez wyników wyszukiwania
        $prompt = (string) $this->lastUserPrompt;
        $this->assertStringContainsString('Źródło opisu: wyłącznie strona producenta '.self::PAGE, $prompt);
        $this->assertStringContainsString('Numer części: AF010706', $prompt);
        $this->assertStringContainsString('ZASADY TEGO OPISU', $prompt);
        $this->assertStringNotContainsString('Wyniki wyszukiwania', $prompt);
        // bez wyszukiwarki: tylko strona z tabeli pobrana z sieci (plus zdjęcie z tabeli)
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), 'icd.pl') || str_contains($r->url(), self::OTHER_PAGE));

        // zdjęcia: z tabeli główne, B2B zostaje jako dodatkowe, zdjęcie z internetu spoza producenta i baner znikają
        $images = $leader->images()->orderByDesc('is_primary')->orderBy('sort_order')->get();
        $primary = $images->firstWhere('is_primary', true);
        $this->assertNotNull($primary);
        $this->assertSame(self::IMAGE_0706, $primary->source_url);
        $this->assertEqualsCanonicalizing([self::IMAGE_0706, 'https://b2b.p4s.pl/images/AF0107.jpg'], $images->pluck('source_url')->all());
        $this->assertFalse((bool) ProductImage::query()->whereKey($b2b->id)->value('is_primary'));
        $this->assertNull(ProductImage::query()->find($icd->id));
        $this->assertNull(ProductImage::query()->find($banner->id));
        Http::assertNotSent(static fn (Request $r): bool => $r->url() === self::PAGE_IMAGE);

        // członek modelu: ta sama strona, swój wiersz w specs, swój werdykt i swoje zdjęcie
        $service->recordBatchProduct($batch, $member, ProductEnrichmentBatchItem::STATUS_RUNNING);
        $this->assertSame(ApplyModelDescriptionJob::RESULT_PUBLISHED, $service->applyModelDescription($member->fresh(), $version, (int) $batch->id));
        $member->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $member->enrichment_status, (string) $member->enrichment_error);
        $this->assertSame(self::DESCRIPTION, $member->description);
        $memberPayload = $member->enrichment_payload;
        $this->assertSame('AF010707', $memberPayload['identity']['key'] ?? null);
        $this->assertSame('hard', $memberPayload['identity']['verdict'] ?? null);
        $this->assertContains('Numer części: AF010707', $memberPayload['specs'] ?? []);
        $this->assertContains('Kolor: Szary', $memberPayload['specs'] ?? []);
        $this->assertNotContains('Numer części: AF010706', $memberPayload['specs'] ?? []);
        $this->assertNotContains('Kolor: Czarny/Żółty', $memberPayload['specs'] ?? []);
        $this->assertContains('Faktura: bąbelkowa', $memberPayload['specs'] ?? []);
        $this->assertSame('AF010707', $memberPayload['parts_table']['part'] ?? null);
        $this->assertSame([self::IMAGE_0707], $member->images()->pluck('source_url')->all());
        $this->assertTrue((bool) $member->images()->sole()->is_primary);
        $memberVersion = ProductDescriptionVersion::query()->where('product_id', $member->id)->sole();
        $this->assertSame(DescriptionVersionStore::PARTS_TABLE_REASON, $memberVersion->reason);

        $search->shouldNotHaveReceived('searchBothPhases');
        $search->shouldNotHaveReceived('searchOnHosts');
        $search->shouldNotHaveReceived('catalogHitsOnHosts');
    }

    /**
     * Skrót cennika nie stoi na stronie, a nazwa z cennika nie przypomina tytułu strony — bramki pobierania i puli
     * odrzuciłyby stronę; przypięta strona przechodzi jak adres wskazany ręcznie (na karcie adres się nie zmienia).
     */
    public function test_pinned_short_code_card_with_name_unlike_the_page_still_gets_the_page(): void
    {
        $card = $this->coba('AF0107', 'Mata przeciwzmęczeniowa Comfort Zone rolka 1.2m x 18.3m', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $service = $this->service($this->search());

        $service->enrichProduct($card, false);

        $card->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $card->enrichment_status, (string) $card->enrichment_error);
        $this->assertSame(self::DESCRIPTION, $card->description);
        $this->assertSame([self::PAGE], $card->enrichment_payload['source_urls'] ?? null);
        $this->assertNull($card->shop_source_url, 'adres strony z tabeli nie trafia na kartę jako adres ręczny');
    }

    public function test_member_pinned_to_another_page_than_the_leader_fails_and_keeps_its_card(): void
    {
        $leader = $this->coba('AF0107', 'Orthomat Standard Czarny/Żółty 1.2m x 18.3m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $other = $this->coba('FF010001', 'Orthomat Premium Czarny 0.6m x 0.9m (12.5mm)', [
            'enrichment_status' => Product::ENRICHMENT_QUEUED,
            'description' => self::OLD_DESCRIPTION,
        ]);
        $batch = $this->batch($leader, [$other]);
        $service = $this->service($this->search());
        $service->enrichProduct($leader, false, (int) $batch->id);
        $version = $service->lastRunVersion();
        $this->assertNotNull($version);
        $service->recordBatchProduct($batch, $other, ProductEnrichmentBatchItem::STATUS_RUNNING);

        try {
            $service->applyModelDescription($other->fresh(), $version, (int) $batch->id);
            $this->fail('członek z inną stroną z tabeli części nie dostaje opisu lidera');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('inna niż lidera', $e->getMessage());
        }

        $other->refresh();
        $this->assertSame(self::OLD_DESCRIPTION, $other->description);
        $this->assertSame(0, ProductDescriptionVersion::query()->where('product_id', $other->id)->count());
    }

    public function test_pinned_page_404_fails_without_deleting_anything(): void
    {
        $leader = $this->pinnedCardWithOldDescription();
        $icd = $this->webImage($leader, 'https://www.icd.pl/img/orthomat-af0107.jpg');
        $search = $this->search();
        $service = $this->service($search, pageStatus: 404);

        try {
            $service->enrichProduct($leader, true);
            $this->fail('strona z tabeli części 404 — błąd przebiegu');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Strona z tabeli części nie odpowiedziała', $e->getMessage());
        }

        $this->assertUntouchedAfterFailure($leader, [(int) $icd->id]);
        $this->assertNull($this->lastUserPrompt, 'model opisu nie jest wołany');
        $search->shouldNotHaveReceived('searchBothPhases');
    }

    public function test_empty_description_from_pinned_page_fails_without_deleting_anything(): void
    {
        $leader = $this->pinnedCardWithOldDescription();
        $icd = $this->webImage($leader, 'https://www.icd.pl/img/orthomat-af0107.jpg');
        $this->modelDescription = '';
        $search = $this->search();
        $service = $this->service($search);

        try {
            $service->enrichProduct($leader, true);
            $this->fail('pusty opis ze strony z tabeli części — błąd przebiegu');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Model nie napisał opisu ze strony z tabeli części', $e->getMessage());
        }

        $this->assertUntouchedAfterFailure($leader, [(int) $icd->id]);
        $search->shouldNotHaveReceived('searchBothPhases');
        $search->shouldNotHaveReceived('moreCatalogHits');
    }

    public function test_unresolved_card_goes_the_old_way_through_the_search_engine(): void
    {
        $card = $this->coba('AF0999', 'Orthomat Standard Niebieski 0.9m x 1.5m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $search = $this->search(oldPath: true);
        $service = $this->service($search);

        $service->enrichProduct($card, false);

        $search->shouldHaveReceived('searchBothPhases')->atLeast()->once();
        $card->refresh();
        $this->assertArrayNotHasKey('parts_table', (array) $card->enrichment_payload);
        $this->assertStringNotContainsString('Źródło opisu: wyłącznie strona producenta', (string) $this->lastUserPrompt);
        $this->assertStringContainsString('Wyniki wyszukiwania', (string) $this->lastUserPrompt);
        $version = $service->lastRunVersion();
        $this->assertNotNull($version);
        $this->assertNotSame(DescriptionVersionStore::PARTS_TABLE_REASON, $version->reason);
    }

    private function pinnedCardWithOldDescription(): Product
    {
        $leader = $this->coba('AF0107', 'Orthomat Standard Czarny/Żółty 1.2m x 18.3m (9.5mm)', [
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'description' => self::OLD_DESCRIPTION,
            'norms' => 'EN 13552',
        ]);
        app(DescriptionVersionStore::class)->record($leader, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::OLD_DESCRIPTION,
            'primary_source_url' => self::OTHER_PAGE,
            'identity_verdict' => 'hard',
            'evidence_count' => 3,
        ]);

        return $leader;
    }

    /** @param  list<int>  $imageIds */
    private function assertUntouchedAfterFailure(Product $card, array $imageIds): void
    {
        $card->refresh();
        $this->assertSame(Product::ENRICHMENT_FAILED, $card->enrichment_status, (string) $card->enrichment_error);
        $this->assertSame(self::OLD_DESCRIPTION, $card->description);
        $this->assertSame('EN 13552', $card->norms);
        $this->assertNull($card->review_reason);
        $this->assertSame($imageIds, $card->images()->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertSame(1, ProductDescriptionVersion::query()->where('product_id', $card->id)->count());
        $this->assertSame(ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::query()->where('product_id', $card->id)->value('status'));
    }

    /**
     * Wyszukiwarka-szpieg: przy karcie przypiętej nic nie jest wołane poza czyszczeniem pamięci (force). $oldPath —
     * stara ścieżka: wynik = strona z tabeli (opis z kodem karty w treści).
     */
    private function search(bool $oldPath = false): MockInterface
    {
        $search = Mockery::spy(HybridWebSearchService::class);
        $search->shouldReceive('searchBothPhases')->andReturn([
            'results' => $oldPath ? [['url' => self::PAGE, 'title' => 'Orthomat Standard | COBA', 'snippet' => 'AF0999']] : [],
            'errors' => [],
        ]);
        $search->shouldReceive('dropListingResults')->andReturnUsing(static fn (array $results): array => $results);
        $search->shouldReceive('moreCatalogHits')->andReturn([]);
        $search->shouldReceive('searchMappedRetailers')->andReturn([]);
        $search->shouldReceive('searchWebWithoutLocalIndex')->andReturn(['results' => [], 'images' => [], 'errors' => []]);
        $search->shouldReceive('shopCardsForImage')->andReturn([]);
        $search->shouldReceive('catalogHitsOnHosts')->andReturn([]);
        $search->shouldReceive('searchOnHosts')->andReturn([]);
        $search->shouldReceive('lastHostSearchErrors')->andReturn([]);

        return $search;
    }

    private function service(MockInterface $search, int $pageStatus = 200, ?string $redirectTo = null): ProductEnrichmentService
    {
        $facts = 'Orthomat Standard to mata antyzmęczeniowa Coba z pianki PVC o zamkniętych komórkach, z fakturą bąbelkową i skośnymi '
            .'krawędziami, do stanowisk pracy stojącej w suchych pomieszczeniach. Zmniejsza zmęczenie nóg i pleców, poprawia krążenie. '
            .'Kod AF0999 — wariant niebieski 0.9m x 1.5m.';
        $html = '<html><head><title>Orthomat Standard | COBA Europe</title>'
            .'<meta property="og:image" content="'.self::PAGE_IMAGE.'"></head><body><h1>Orthomat Standard</h1>'
            .'<img src="'.self::PAGE_IMAGE.'" alt="Orthomat Standard">'
            .'<div class="product-description"><p>'.$facts.'</p>'
            .'<p>Producent: Coba Europe. Mata o grubości 9,5 mm, lekka i łatwa do przycięcia, utrzymuje czystość na stanowisku.</p></div>'
            .'<table id="parts-table"><tr><th>Numer części</th><th>Rozmiar</th><th>Kolor</th><th>Waga</th></tr>'
            .'<tr data-part="AF010706"><td>AF010706</td><td>1,2 m x 18,3 m</td><td>Czarny/Żółty</td><td>58,55 kg</td></tr>'
            .'<tr data-part="AF010707"><td>AF010707</td><td>0,9 m x 18,3 m</td><td>Szary</td><td>43,9 kg</td></tr></table>'
            .'</body></html>';

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $handler = function (array $messages) use ($facts): array {
            $system = (string) ($messages[0]['content'] ?? '');
            $user = (string) ($messages[1]['content'] ?? '');
            if (str_contains($system, 'filtrem treści')) {
                return ['pages' => [['url' => self::PAGE, 'text' => $facts]]];
            }
            $this->lastUserPrompt = $user;

            return [
                'features' => ['Pianka PVC o zamkniętych komórkach', 'Skośne krawędzie'],
                // kolor innego wariantu i neutralna linia — wiersz tabeli zastępuje „Kolor”
                'specs' => ['Kolor: Szary', 'Faktura: bąbelkowa'],
                'norms' => [], 'certificates' => [], 'materials' => ['PVC'], 'use_cases' => ['stanowiska pracy stojącej'],
                'image_urls' => [self::PAGE_IMAGE], 'source_urls' => [self::PAGE, self::OTHER_PAGE],
                'description' => (string) $this->modelDescription, 'confidence' => 0.9,
            ];
        };
        $llm->shouldReceive('chatJsonEnrichment')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJsonWithImages')->zeroOrMoreTimes()->andReturn(['candidates' => []]);

        Http::fake(function (Request $request) use ($html, $pageStatus, $redirectTo) {
            $url = $request->url();
            if ($redirectTo !== null && str_starts_with($url, self::PAGE)) {
                return Http::response('', 301, ['Location' => $redirectTo]);
            }
            if ($redirectTo !== null && $url === $redirectTo) {
                return Http::response(str_replace('<h1>Orthomat Standard</h1>', '<h1>Maty antyzmęczeniowe</h1>', $html), 200, ['Content-Type' => 'text/html']);
            }
            if (str_starts_with($url, 'https://www.coba.com/wp-content/uploads/') && str_ends_with(mb_strtolower($url), '.jpg')) {
                return Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']);
            }
            if (str_contains($url, 'r.jina.ai')) {
                return Http::response("Title: x\n\nMarkdown Content:\nundefined", 200, ['Content-Type' => 'text/plain']);
            }
            if (str_starts_with($url, self::PAGE)) {
                return $pageStatus === 200
                    ? Http::response($html, 200, ['Content-Type' => 'text/html'])
                    : Http::response('', $pageStatus);
            }

            return Http::response('', 404);
        });

        return new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );
    }

    /**
     * @param  list<Product>  $members
     * @param  array<int, string>  $previousStatus
     */
    private function batch(Product $leader, array $members, array $previousStatus = [], bool $force = false): ProductEnrichmentBatch
    {
        $user = User::factory()->create();
        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRICE_LIST, 'scope_id' => 14, 'total' => 1 + count($members), 'done' => 0, 'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_RUNNING, 'created_by' => $user->id, 'force' => $force,
        ]);
        foreach ([$leader, ...$members] as $product) {
            ProductEnrichmentBatchItem::query()->create([
                'batch_id' => $batch->id, 'product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name,
                'status' => ProductEnrichmentBatchItem::STATUS_QUEUED,
                'previous_status' => $previousStatus[$product->id] ?? Product::ENRICHMENT_NONE,
                'model_key' => self::MODEL_KEY, 'model_leader_id' => $leader->id,
            ]);
        }

        return $batch;
    }

    /** @param  array<string, mixed>  $extra */
    private function coba(string $sku, string $name, array $extra = []): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => 'Coba', 'catalog_price_net' => 10, 'purchase_price' => 8, 'stock' => 0,
            'enrichment_status' => Product::ENRICHMENT_NONE,
            ...$extra,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function webImage(Product $product, ?string $sourceUrl, array $extra = []): ProductImage
    {
        $path = 'products/'.$product->id.'/'.uniqid('img', true).'.jpg';
        Storage::disk('public')->put($path, $this->jpeg());
        $sort = (int) (ProductImage::query()->where('product_id', $product->id)->max('sort_order') ?? -1) + 1;

        return ProductImage::query()->create([
            'product_id' => $product->id, 'path' => $path, 'source_url' => $sourceUrl, 'is_primary' => $sort === 0,
            'sort_order' => $sort, 'checksum' => hash('sha256', $path),
            ...$extra,
        ]);
    }

    private function jpeg(): string
    {
        $im = imagecreatetruecolor(320, 480);
        imagefill($im, 0, 0, imagecolorallocate($im, 40, 40, 40));
        ob_start();
        imagejpeg($im, null, 85);
        imagedestroy($im);

        return (string) ob_get_clean();
    }
}
