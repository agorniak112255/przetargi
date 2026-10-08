<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ManufacturerPageMissingException;
use App\Exceptions\ProductSourcesNotFoundException;
use App\Jobs\ApplyModelDescriptionJob;
use App\Jobs\PrefetchProductSourcesJob;
use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentBatchItem;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use App\Models\ProductSourceDocument;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ManufacturerDomainResolver;
use App\Services\Enrichment\ModelImagePicker;
use App\Services\Enrichment\ProductDocumentDownloader;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\ProductImageCandidateVerifier;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\ProductSearchIdentity;
use App\Services\Enrichment\SourceDocumentStore;
use App\Support\BhpAttributeNormalizer;
use App\Support\ColourWords;
use App\Support\ManufacturerNormFacts;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/**
 * Etap 2 opisów z cenników (08.10.2026), opis wspólny dla modelu: przebieg lidera z notą modelu i sitem na rdzeniu
 * nazwy, członkowie bez modelu językowego (werdykt per karta na zapisanych stronach lidera, fakty z cennika, zdjęcie
 * w kolorze karty albo kopia lidera, decyzja per karta), pominięcie członka z tym opisem, sztafeta bez wersji, pamięć
 * SKU wyłączona dla marki z grupowaniem, page_image_urls tylko w wersji, zabity lider, plan partii po modelach.
 */
final class EnrichmentModelSharedFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://www.coba.com/product/orthomat-standard';

    private const IMAGE_BLACK = 'https://www.coba.com/wp-content/uploads/orthomat-standard-black-1.jpg';

    private const IMAGE_GREY = 'https://www.coba.com/wp-content/uploads/orthomat-standard-grey-1.jpg';

    private const MODEL_KEY = 'coba|AF|orthomat standard';

    private const DESCRIPTION = 'Mata antyzmęczeniowa Coba Orthomat Standard z pianki PVC o zamkniętych komórkach, z fakturą bąbelkową '
        .'i skośnymi krawędziami. Przeznaczona do stanowisk pracy stojącej w suchych pomieszczeniach, zmniejsza zmęczenie nóg '
        .'i pleców oraz poprawia krążenie krwi. Lekka, łatwa do przycięcia i utrzymania w czystości.';

    private const OLD_DESCRIPTION = 'Mata Orthomat Standard AF060002 z poprzedniego pobrania ze strony producenta z kodem karty '
        .'w tabeli części — opis bazowy z twardym werdyktem tożsamości, który nie powinien ustąpić słabszemu.';

    /** treść użytkownika ostatniego polecenia opisu (bez filtra stron) */
    private ?string $lastUserPrompt = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake(SourceDocumentStore::DISK);
        config()->set('enrichment.store_sources', true);
        config()->set('manufacturer_profiles.profiles.coba.model', ['group' => 'name_stem', 'min_members' => 2]);
        config(['ai.enrichment_batch_limit' => 50]);
    }

    public function test_leader_publishes_and_members_get_the_model_description_per_card(): void
    {
        $leader = $this->coba('AF010001', 'Orthomat Standard Czarny 0.6m x 0.9m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        // kod w tabeli części strony lidera (postać bazowa „AF060003” dla cięcia na metry) — werdykt twardy
        $hard = $this->coba('AF060003C', 'Orthomat Standard Szary 0.9m x mb. (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED, 'ean' => '5060123456789']);
        // bez kodu na stronie, pełna nazwa w tekście — werdykt miękki; bez koloru w nazwie — kopia zdjęcia lidera
        $soft = $this->coba('AF060004', 'Orthomat Standard 0.9m x 1.5m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        // twarda baza i słabszy werdykt na stronie lidera — propozycja, karta bez zmian
        $worse = $this->coba('AF060002', 'Orthomat Standard Szary 0.9m x 18.3m (9.5mm)', [
            'enrichment_status' => Product::ENRICHMENT_QUEUED,
            'description' => self::OLD_DESCRIPTION,
            'norms' => 'EN 420',
            'enrichment_error' => 'Opis OK, nie udało się pobrać zdjęcia.',
        ]);
        app(DescriptionVersionStore::class)->record($worse, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::OLD_DESCRIPTION,
            'primary_source_url' => 'https://www.coba.com/product/orthomat-standard-old',
            'identity_verdict' => 'hard',
            'evidence_count' => 1,
        ]);
        $batch = $this->batch($leader, [$hard, $soft, $worse], [$worse->id => Product::ENRICHMENT_DONE]);
        $service = $this->service();

        $service->enrichProduct($leader, false, (int) $batch->id);

        $leader->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $leader->enrichment_status, (string) $leader->enrichment_error);
        $this->assertSame(self::DESCRIPTION, $leader->description);
        $this->assertSame('hard', $leader->enrichment_payload['identity']['verdict'] ?? null, json_encode($leader->enrichment_payload['identity'] ?? null));
        $this->assertSame([self::IMAGE_BLACK], $leader->images()->pluck('source_url')->all(), 'zdjęcie w kolorze karty lidera (czarny), nie szare');
        $this->assertStringContainsString('EN 13552', (string) $leader->norms);
        $this->assertSame([['label' => 'EN 13552', 'value' => 'klasa 2']], $leader->manufacturer_norms['rows'] ?? null, 'ramka norm ze strony producenta');
        $leaderVersion = $service->lastRunVersion();
        $this->assertNotNull($leaderVersion);
        $this->assertSame(ProductDescriptionVersion::STATUS_PUBLISHED, $leaderVersion->status);
        $this->assertSame((int) $leaderVersion->id, $leader->enrichment_payload['description_version_id'] ?? null);
        // (e) nota modelu w poleceniu lidera — klucz, rdzeń, lista wariantów
        $this->assertStringContainsString('Opis wspólny dla 4 wariantów tego modelu „Orthomat Standard”', (string) $this->lastUserPrompt);
        $this->assertStringContainsString('- AF060003C — Orthomat Standard Szary 0.9m x mb. (9.5mm)', (string) $this->lastUserPrompt);
        $this->assertStringContainsString('warianty/rozmiary/kolory ze źródła podaj w specs', (string) $this->lastUserPrompt);
        // pochodzenie modelu u lidera
        $this->assertSame([
            'key' => self::MODEL_KEY, 'stem' => 'Orthomat Standard', 'family' => 'AF', 'leader_product_id' => (int) $leader->id,
            'leader_version_id' => null, 'members' => 4, 'shared' => false,
        ], $leader->enrichment_payload['model_group'] ?? null);
        // (f) adresy zdjęć stron tylko w danych technicznych wersji, nie na karcie
        $meta = app(DescriptionVersionStore::class)->meta($leaderVersion);
        $this->assertSame([self::IMAGE_BLACK, self::IMAGE_GREY], $meta['page_image_urls'] ?? null, 'zaufane (og:image) pierwsze');
        $this->assertArrayNotHasKey(DescriptionVersionStore::META_KEY, $leader->enrichment_payload);
        // (d) marka z grupowaniem nie pisze pamięci SKU
        $this->assertSame(0, ProductEnrichmentCache::query()->count());
        $leaderDocs = ProductSourceDocument::query()->where('product_id', $leader->id)->get();
        $this->assertCount(1, $leaderDocs);

        $results = [];
        foreach ([$hard, $soft, $worse] as $member) {
            $service->recordBatchProduct($batch, $member, ProductEnrichmentBatchItem::STATUS_RUNNING);
            $results[$member->sku] = $service->applyModelDescription($member->fresh(), $leaderVersion, (int) $batch->id);
        }
        $this->assertSame([
            'AF060003C' => ApplyModelDescriptionJob::RESULT_PUBLISHED,
            'AF060004' => ApplyModelDescriptionJob::RESULT_PUBLISHED,
            'AF060002' => ApplyModelDescriptionJob::RESULT_PROPOSED,
        ], $results);

        // twardy członek: identyczny opis, specs z pliku, dowody z cennika, zdjęcie w kolorze karty (szary)
        $hard->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $hard->enrichment_status, (string) $hard->enrichment_error);
        $this->assertSame(self::DESCRIPTION, $hard->description);
        $this->assertNull($hard->review_reason);
        $payload = $hard->enrichment_payload;
        $this->assertSame('hard', $payload['identity']['verdict'] ?? null, json_encode($payload['identity'] ?? null));
        $this->assertSame(self::PAGE, $payload['identity']['source_url'] ?? null);
        $this->assertContains('Grubość: 9,5 mm', $payload['specs'] ?? []);
        $this->assertContains('Sprzedaż: na metry bieżące', $payload['specs'] ?? []);
        $this->assertContains('Kolor: Szary', $payload['specs'] ?? []);
        $this->assertContains('EAN: 5060123456789', $payload['specs'] ?? []);
        // specs lidera: linia z etykietą, którą członek ma z cennika, to wartość innego wariantu — odpada; neutralna zostaje
        $this->assertSame(['Grubość: 9,5 mm', 'Kolor: Czarny', 'Wymiary: 0,6 × 0,9 m', 'Faktura: bąbelkowa'], $leader->enrichment_payload['specs'] ?? null);
        $this->assertContains('Faktura: bąbelkowa', $payload['specs'] ?? []);
        $this->assertNotContains('Kolor: Czarny', $payload['specs'] ?? []);
        $this->assertNotContains('Wymiary: 0,6 × 0,9 m', $payload['specs'] ?? []);
        $this->assertCount(1, array_filter($payload['specs'] ?? [], static fn (string $line): bool => str_starts_with($line, 'Grubość:')));
        $this->assertCount(1, array_filter($payload['specs'] ?? [], static fn (string $line): bool => str_starts_with($line, 'Kolor:')));
        // kod wyrobu z SKU członka, nie lidera (atrybut tożsamości nie przechodzi)
        $this->assertSame('AF060003C', $payload['attributes']['kod_producenta'] ?? null);
        $this->assertSame('AF010001', $leader->enrichment_payload['attributes']['kod_producenta'] ?? null);
        // dowody z cennika w evidence (source price_list), ale liczone osobno — explicit tylko ze stron
        $priceListEvidence = array_values(array_filter($payload['evidence'] ?? [], static fn (array $e): bool => ($e['source'] ?? null) === 'price_list'));
        $this->assertNotEmpty($priceListEvidence);
        $this->assertSame('explicit', $priceListEvidence[0]['status']);
        $this->assertSame(count($priceListEvidence), $payload['evidence_summary']['price_list'] ?? null);
        $pageExplicit = count(array_filter($payload['evidence'] ?? [], static fn (array $e): bool => ($e['status'] ?? null) === 'explicit' && ($e['source'] ?? null) !== 'price_list'));
        $this->assertSame($pageExplicit, $payload['evidence_summary']['explicit'] ?? null);
        $this->assertSame([
            'key' => self::MODEL_KEY, 'stem' => 'Orthomat Standard', 'family' => 'AF', 'leader_product_id' => (int) $leader->id,
            'leader_version_id' => (int) $leaderVersion->id, 'members' => 4, 'shared' => true,
        ], $payload['model_group'] ?? null);
        $this->assertSame([self::IMAGE_GREY], $hard->images()->pluck('source_url')->all());
        $this->assertSame($leader->norms, $hard->norms, 'kolumna norm jak u lidera');
        // normy producenta jak u lidera (pary i łącznik), ale klucz tożsamości członka: twardy werdykt kodem w tekście
        $this->assertSame($leader->manufacturer_norms['rows'], $hard->manufacturer_norms['rows'] ?? null, 'pary norm producenta jak u lidera');
        $this->assertSame('strona-producenta', $hard->manufacturer_norms['source']['connector'] ?? null);
        $this->assertSame((int) $leader->id, $hard->manufacturer_norms['source']['copied_from_product_id'] ?? null);
        $this->assertSame(['by' => 'sku', 'value' => 'AF060003', 'where' => 'text'], $hard->manufacturer_norms['source']['identity'] ?? null);
        $hardVersion = ProductDescriptionVersion::query()->where('product_id', $hard->id)->sole();
        $this->assertSame($payload['evidence_summary']['explicit'], (int) $hardVersion->evidence_count, 'liczba dowodów wersji bez faktów z cennika');
        $this->assertSame(ProductDescriptionVersion::ORIGIN_MODEL_SHARED, $hardVersion->origin);
        $this->assertSame(ProductDescriptionVersion::STATUS_PUBLISHED, $hardVersion->status);
        $this->assertSame(sha1(self::DESCRIPTION), $hardVersion->description_sha1);
        $this->assertSame('hard', $hardVersion->identity_verdict);
        $this->assertSame((int) $batch->id, (int) $hardVersion->batch_id);
        $hardDocs = ProductSourceDocument::query()->where('product_id', $hard->id)->get();
        $this->assertCount(1, $hardDocs);
        $this->assertSame((string) $leaderDocs[0]->sha256, (string) $hardDocs[0]->sha256, 'ten sam tekst źródła co u lidera');
        $this->assertSame('hard', $hardDocs[0]->identity_verdict);
        $this->assertSame((int) $hardVersion->id, (int) $hardDocs[0]->description_version_id);

        // miękki członek: zapis z powodem przeglądu, kopia zdjęcia lidera bez sieci
        $soft->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $soft->enrichment_status, (string) $soft->enrichment_error);
        $this->assertSame(self::DESCRIPTION, $soft->description);
        $this->assertSame(Product::REVIEW_IDENTITY_SOFT, $soft->review_reason);
        $this->assertSame('soft', $soft->enrichment_payload['identity']['verdict'] ?? null, json_encode($soft->enrichment_payload['identity'] ?? null));
        // miękki werdykt: pary norm producenta jak u lidera, bez klucza tożsamości (kolumna nie jest „sprawdzona”), z pochodzeniem
        $this->assertSame($leader->manufacturer_norms['rows'], $soft->manufacturer_norms['rows'] ?? null);
        $this->assertArrayNotHasKey('identity', $soft->manufacturer_norms['source'] ?? ['identity' => null]);
        $this->assertSame((int) $leader->id, $soft->manufacturer_norms['source']['copied_from_product_id'] ?? null);
        $softImage = $soft->images()->sole();
        $leaderImage = $leader->images()->sole();
        $this->assertSame(self::IMAGE_BLACK, $softImage->source_url);
        $this->assertSame($leaderImage->checksum, $softImage->checksum);
        $this->assertNotSame($leaderImage->path, $softImage->path);
        $this->assertTrue(Storage::disk('public')->exists($softImage->path));

        // członek z twardą bazą: propozycja, karta bez zmian (opis, normy, zdjęcia, status sprzed kolejki)
        $worse->refresh();
        $this->assertSame(self::OLD_DESCRIPTION, $worse->description);
        $this->assertSame('EN 420', $worse->norms);
        $this->assertSame(0, $worse->images()->count());
        $this->assertSame(Product::ENRICHMENT_DONE, $worse->enrichment_status);
        $this->assertSame('Opis OK, nie udało się pobrać zdjęcia. Nowy opis czeka w „Do przeglądu” (gorszy od obecnego).', $worse->enrichment_error);
        $this->assertSame(Product::REVIEW_WORSE_VERSION, $worse->review_reason);
        $this->assertSame('Nowy opis czeka w „Do przeglądu” (gorszy od obecnego).', $service->lastProposalNote());
        $proposal = ProductDescriptionVersion::query()->where('product_id', $worse->id)->where('status', ProductDescriptionVersion::STATUS_PROPOSED)->sole();
        $this->assertSame(ProductDescriptionVersion::ORIGIN_MODEL_SHARED, $proposal->origin);
        $this->assertSame(self::DESCRIPTION, $proposal->description);
        $this->assertSame(Product::REVIEW_WORSE_VERSION, $proposal->review_reason);
        $this->assertSame((int) $proposal->id, $service->lastRunVersion()?->id);

        // (b) ten opis lidera już na karcie — pominięcie bez nowej wersji
        $this->assertSame(ApplyModelDescriptionJob::RESULT_SKIPPED, $service->applyModelDescription($hard->fresh(), $leaderVersion, (int) $batch->id));
        $this->assertSame(1, ProductDescriptionVersion::query()->where('product_id', $hard->id)->count());
        $this->assertSame(Product::ENRICHMENT_DONE, $hard->fresh()->enrichment_status);
    }

    public function test_member_uses_only_pages_of_the_leader_version_and_gets_no_files_from_a_proposal_leader(): void
    {
        $leader = $this->coba('AF010001', 'Orthomat Standard Czarny 0.6m x 0.9m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        // bez kodu na stronie modelu — werdykt z tej strony miękki; cudza strona z poprzedniego przebiegu lidera ma kod
        // członka w tabeli (dałaby twardy werdykt) i nie należy do wersji lidera
        $member = $this->coba('AF060009', 'Orthomat Standard 0.9m x 3m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $foreign = 'https://www.coba.com/product/orthomat-premium';
        app(SourceDocumentStore::class)->record($leader, [[
            'url' => $foreign,
            'text' => "Orthomat Premium | COBA Europe\nKod: AF060009\nMata Orthomat Premium 0.9m x 3m — inny model.",
            'identity' => ['verdict' => 'hard', 'reason' => 'kod w tabeli', 'key_type' => 'sku', 'key' => 'AF010001'],
        ]], null);
        $batch = $this->batch($leader, [$member]);
        $service = $this->service();
        $service->enrichProduct($leader, false, (int) $batch->id);
        $leaderVersion = $service->lastRunVersion();
        $this->assertNotNull($leaderVersion);
        $this->assertCount(2, ProductSourceDocument::query()->where('product_id', $leader->id)->get(), 'cudza strona dalej zapisana na karcie lidera');

        $service->recordBatchProduct($batch, $member, ProductEnrichmentBatchItem::STATUS_RUNNING);
        $this->assertSame(ApplyModelDescriptionJob::RESULT_PUBLISHED, $service->applyModelDescription($member->fresh(), $leaderVersion, (int) $batch->id));

        $member->refresh();
        // strona wersji lidera nie ma ani kodu, ani nazwy tego wariantu → „none”; cudza strona z historii dałaby „hard”
        $this->assertSame('none', $member->enrichment_payload['identity']['verdict'] ?? null, 'werdykt ze strony wersji lidera, nie z cudzej strony z historii: '.json_encode($member->enrichment_payload['identity'] ?? null));
        $this->assertSame(Product::REVIEW_IDENTITY_NONE, $member->review_reason);
        $memberDocs = ProductSourceDocument::query()->where('product_id', $member->id)->get();
        $this->assertSame([self::PAGE], $memberDocs->pluck('url')->all(), 'na karcie członka tylko strona wersji lidera');
        $this->assertSame([self::IMAGE_BLACK], $member->images()->pluck('source_url')->all(), 'bez koloru w nazwie — kopia zdjęcia lidera');
        $this->assertNotNull($member->manufacturer_norms);

        // lider skończył propozycją: członek dostaje tekst do własnej decyzji, ale bez plików i norm z karty lidera
        $other = $this->coba('AF060010', 'Orthomat Standard 1.2m x 3m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        ProductEnrichmentBatchItem::query()->create([
            'batch_id' => $batch->id, 'product_id' => $other->id, 'sku' => $other->sku, 'name' => $other->name,
            'status' => ProductEnrichmentBatchItem::STATUS_RUNNING, 'previous_status' => Product::ENRICHMENT_NONE,
            'model_key' => self::MODEL_KEY, 'model_leader_id' => $leader->id,
        ]);
        $proposalText = self::DESCRIPTION.' Wersja z propozycji lidera.';
        $leaderProposal = app(DescriptionVersionStore::class)->record($leader, ProductDescriptionVersion::STATUS_PROPOSED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => $proposalText,
            'enrichment_payload' => [...$leader->fresh()->enrichment_payload, 'description' => $proposalText],
            'primary_source_url' => self::PAGE,
            'review_reason' => Product::REVIEW_WORSE_VERSION,
        ]);

        $this->assertSame(ApplyModelDescriptionJob::RESULT_PUBLISHED, $service->applyModelDescription($other->fresh(), $leaderProposal, (int) $batch->id));

        $other->refresh();
        $this->assertSame($proposalText, $other->description);
        $this->assertSame(0, $other->images()->count(), 'zdjęcie lidera sprzed przebiegu nie idzie na członka');
        $this->assertSame(0, $other->documents()->count());
        $this->assertNull($other->manufacturer_norms, 'normy producenta z karty lidera sprzed przebiegu nie idą na członka');
        $this->assertStringContainsString('lider skończył propozycją', (string) $other->enrichment_error);
        $this->assertSame((int) $leaderProposal->id, $other->enrichment_payload['model_group']['leader_version_id'] ?? null);
    }

    public function test_member_without_stored_leader_pages_gets_none_verdict_and_hard_base_keeps_its_description(): void
    {
        // bez zapisu źródeł (store_sources) wersja lidera nie ma stron — członek nie ma czym potwierdzić karty
        config()->set('enrichment.store_sources', false);
        $leader = $this->coba('AF010001', 'Orthomat Standard Czarny 0.6m x 0.9m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        // twarda baza z czterema dowodami ze stron — fakty z cennika członka nie mogą jej zasłonić
        $based = $this->coba('AF060003C', 'Orthomat Standard Szary 0.9m x mb. (9.5mm)', [
            'enrichment_status' => Product::ENRICHMENT_QUEUED, 'description' => self::OLD_DESCRIPTION, 'enrichment_error' => 'Opis OK.',
        ]);
        app(DescriptionVersionStore::class)->record($based, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::OLD_DESCRIPTION,
            'primary_source_url' => 'https://www.coba.com/product/orthomat-standard-old',
            'identity_verdict' => 'hard',
            'evidence_count' => 4,
        ]);
        $fresh = $this->coba('AF060004', 'Orthomat Standard 0.9m x 1.5m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $batch = $this->batch($leader, [$based, $fresh], [$based->id => Product::ENRICHMENT_DONE]);
        $service = $this->service();
        $service->enrichProduct($leader, false, (int) $batch->id);
        $leaderVersion = $service->lastRunVersion();
        $this->assertNotNull($leaderVersion);
        $this->assertSame(0, ProductSourceDocument::query()->count());
        foreach ([$based, $fresh] as $member) {
            $service->recordBatchProduct($batch, $member, ProductEnrichmentBatchItem::STATUS_RUNNING);
        }

        // baza twarda, werdykt „none” (nie null) → propozycja, karta bez zmian
        $this->assertSame(ApplyModelDescriptionJob::RESULT_PROPOSED, $service->applyModelDescription($based->fresh(), $leaderVersion, (int) $batch->id));
        $based->refresh();
        $this->assertSame(self::OLD_DESCRIPTION, $based->description);
        $this->assertSame(Product::ENRICHMENT_DONE, $based->enrichment_status);
        $this->assertSame(Product::REVIEW_WORSE_VERSION, $based->review_reason);
        $proposal = ProductDescriptionVersion::query()->where('product_id', $based->id)->where('status', ProductDescriptionVersion::STATUS_PROPOSED)->sole();
        $this->assertSame('none', $proposal->identity_verdict);
        $this->assertStringContainsString('brak zapisanych stron', (string) $proposal->identity_reason);
        $payload = $proposal->enrichment_payload;
        $this->assertGreaterThan(0, $payload['evidence_summary']['price_list'] ?? 0, 'fakty z cennika liczone osobno');
        $fromPages = count(array_filter($payload['evidence'] ?? [], static fn (array $e): bool => ($e['status'] ?? null) === 'explicit' && ($e['source'] ?? null) !== 'price_list'));
        $this->assertSame($fromPages, $payload['evidence_summary']['explicit'] ?? null, 'explicit bez faktów z cennika');
        $this->assertSame($fromPages, (int) $proposal->evidence_count);
        $this->assertLessThan(4, (int) $proposal->evidence_count, 'bez stron lidera dowodów jest mniej niż w bazie');

        // bez bazy: zapis z powodem przeglądu identity_none
        $this->assertSame(ApplyModelDescriptionJob::RESULT_PUBLISHED, $service->applyModelDescription($fresh->fresh(), $leaderVersion, (int) $batch->id));
        $fresh->refresh();
        $this->assertSame(self::DESCRIPTION, $fresh->description);
        $this->assertSame('none', $fresh->enrichment_payload['identity']['verdict'] ?? null, json_encode($fresh->enrichment_payload['identity'] ?? null));
        $this->assertSame(Product::REVIEW_IDENTITY_NONE, $fresh->review_reason);
    }

    public function test_copied_manufacturer_norms_drop_the_leader_identity_key(): void
    {
        Queue::fake();
        // kolumna lidera „sprawdzona” jego kodem (norms:from-manufacturer-pages) — ten klucz nie przechodzi na członka
        $leaderNorms = ManufacturerNormFacts::build(
            [['label' => 'EN 13552', 'value' => 'klasa 2']],
            ManufacturerNormFacts::WEB_PAGE_CONNECTOR,
            'Coba',
            self::PAGE,
            null,
            ['kind' => 'page', 'identity' => ['by' => 'sku', 'value' => 'AF010001', 'where' => 'url']],
        );
        $leader = $this->coba('AF010001', 'Orthomat Standard Czarny 0.6m x 0.9m (9.5mm)', [
            'description' => self::DESCRIPTION, 'enrichment_status' => Product::ENRICHMENT_DONE, 'manufacturer_norms' => $leaderNorms,
        ]);
        $this->assertTrue(ManufacturerNormFacts::verified($leader->manufacturer_norms));
        $version = app(DescriptionVersionStore::class)->record($leader, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::DESCRIPTION,
            'primary_source_url' => self::PAGE,
            'identity_verdict' => 'hard',
            'enrichment_payload' => ['norms' => ['EN 13552'], 'source_urls' => [self::PAGE], 'primary_source_url' => self::PAGE, 'primary_source_kind' => 'manufacturer'],
        ]);
        $member = $this->coba('AF060004', 'Orthomat Standard 0.9m x 1.5m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $batch = $this->batch($leader, [$member], [], ProductEnrichmentBatchItem::STATUS_DONE);
        $service = app(ProductEnrichmentService::class);
        $service->recordBatchProduct($batch, $member, ProductEnrichmentBatchItem::STATUS_RUNNING);

        $this->assertSame(ApplyModelDescriptionJob::RESULT_PUBLISHED, $service->applyModelDescription($member->fresh(), $version, (int) $batch->id));

        $member->refresh();
        $this->assertSame($leaderNorms['rows'], $member->manufacturer_norms['rows'] ?? null, 'pary norm jak u lidera');
        $this->assertArrayNotHasKey('identity', $member->manufacturer_norms['source'] ?? ['identity' => null], 'klucz tożsamości lidera nie przechodzi');
        $this->assertSame((int) $leader->id, $member->manufacturer_norms['source']['copied_from_product_id'] ?? null);
        $this->assertFalse(ManufacturerNormFacts::verified($member->manufacturer_norms), 'kolumna członka nie jest „sprawdzona” cudzym kodem');
        $this->assertSame($leaderNorms, $leader->fresh()->manufacturer_norms, 'kolumna lidera bez zmian');
    }

    public function test_colour_rule_in_image_ranking_only_for_grouped_brands_and_by_colour_sets(): void
    {
        $service = $this->service();
        $pick = new ReflectionMethod(ProductEnrichmentService::class, 'pickPrimaryImageUrls');
        $rank = static fn (Product $p, array $urls): array => $pick->invoke($service, $urls, [], (string) $p->sku, (string) $p->name, $p);

        // marka bez grupowania: nazwa z kolorem i plik o innym zapisie koloru — bez kary (jedyny kandydat zostaje)
        $jacket = Product::query()->create(['sku' => 'C466', 'name' => 'Kurtka ostrzegawcza żółto-granatowa', 'manufacturer' => 'Portwest']);
        $this->assertSame(['https://sklep.example/img/jacket-navy-yellow.jpg'], $rank($jacket, ['https://sklep.example/img/jacket-navy-yellow.jpg']));
        $gloves = Product::query()->create(['sku' => 'TX4521', 'name' => 'Rękawice nitrylowe czarne', 'manufacturer' => 'Testex']);
        $this->assertSame(['https://sklep.example/img/glove-on-white-table.jpg'], $rank($gloves, ['https://sklep.example/img/glove-on-white-table.jpg']));
        $twoTone = Product::query()->create(['sku' => 'TX4522', 'name' => 'Rękawice szaro-czarne', 'manufacturer' => 'Testex']);
        $this->assertSame(['https://sklep.example/img/glove-black-grey.jpg'], $rank($twoTone, ['https://sklep.example/img/glove-black-grey.jpg']));

        // Coba (opis wspólny modelu): zbiór kolorów karty — ten sam zbiór wygrywa, wspólny kolor bez zmian, bez wspólnego odpada
        $deckplate = $this->coba('SD010701', 'Deckplate Czarny/Żółte krawędzie 0.6m x 0.9m (15mm)');
        $ranked = $rank($deckplate, [
            'https://www.coba.com/uploads/deckplate-grey-1.jpg',
            'https://www.coba.com/uploads/deckplate-black-1.jpg',
            'https://www.coba.com/uploads/deckplate-black-yellow-1.jpg',
        ]);
        $this->assertSame('https://www.coba.com/uploads/deckplate-black-yellow-1.jpg', $ranked[0] ?? null);
        $this->assertContains('https://www.coba.com/uploads/deckplate-black-1.jpg', $ranked);
        $this->assertNotContains('https://www.coba.com/uploads/deckplate-grey-1.jpg', $ranked);
        // jednobarwna karta: plik innego koloru odpada (etap 2b — −50 nie wystarczało przy słowach nazwy w adresie)
        $single = $this->coba('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)');
        $this->assertSame([self::IMAGE_GREY], $rank($single, [self::IMAGE_BLACK, self::IMAGE_GREY]));
        $this->assertSame([], $rank($single, [self::IMAGE_BLACK]), 'jedyny kandydat w innym kolorze też odpada');
    }

    public function test_release_stale_hands_over_members_when_leader_item_is_stuck_running(): void
    {
        Queue::fake();
        $service = app(ProductEnrichmentService::class);
        $user = User::factory()->create();

        // zadanie lidera zniknęło: pozycja została „running”, kartę zwolnił już harmonogram do „failed”; wersja z tej
        // partii jest — członkowie dostają ją (numer wpisany do pozycji), nie sztafetę
        $leader = $this->coba('AF010001', 'Orthomat Standard Czarny 0.6m x 0.9m (9.5mm)', ['description' => self::DESCRIPTION, 'enrichment_status' => Product::ENRICHMENT_FAILED]);
        $members = [
            $this->coba('AF060003C', 'Orthomat Standard Szary 0.9m x mb. (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]),
            $this->coba('AF060004', 'Orthomat Standard 0.9m x 1.5m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]),
        ];
        $batch = $this->batch($leader, $members, [], ProductEnrichmentBatchItem::STATUS_RUNNING, $user, force: true);
        $version = app(DescriptionVersionStore::class)->record($leader, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::DESCRIPTION, 'primary_source_url' => self::PAGE, 'identity_verdict' => 'hard', 'batch_id' => $batch->id,
        ]);
        ProductEnrichmentBatchItem::query()->where('batch_id', $batch->id)->where('product_id', '!=', $leader->id)
            ->update(['updated_at' => now()->subMinutes(20)]);

        // ten sam stan, ale wersja lidera tylko z innej partii — nie jest podstawą, więc sztafeta
        $lost = $this->coba('DP0106', 'Deckplate Czarny 0.6m x 0.9m (15mm)', ['description' => self::DESCRIPTION, 'enrichment_status' => Product::ENRICHMENT_FAILED]);
        $cut = $this->coba('DP010915C', 'Deckplate Czarny 0.9m x mb. (15mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $next = $this->coba('DP010915', 'Deckplate Czarny 0.9m x 1.5m (15mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $batch2 = $this->batch($lost, [$cut, $next], [], ProductEnrichmentBatchItem::STATUS_RUNNING, $user, 'coba|DP|deckplate', force: true);
        app(DescriptionVersionStore::class)->record($lost, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::DESCRIPTION, 'primary_source_url' => self::PAGE, 'identity_verdict' => 'hard', 'batch_id' => $batch->id,
        ]);
        ProductEnrichmentBatchItem::query()->where('batch_id', $batch2->id)->where('product_id', '!=', $lost->id)
            ->update(['updated_at' => now()->subMinutes(20)]);

        // lider naprawdę w przebiegu (pozycja i karta „running”) — nietknięty
        $alive = $this->coba('TR060001', 'Toughrib Szary 0.6m x 0.9m', ['enrichment_status' => Product::ENRICHMENT_RUNNING]);
        $waiting = $this->coba('TR060002', 'Toughrib Szary 0.9m x 1.5m', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $batch3 = $this->batch($alive, [$waiting], [], ProductEnrichmentBatchItem::STATUS_RUNNING, $user, 'coba|TR|toughrib');
        ProductEnrichmentBatchItem::query()->where('batch_id', $batch3->id)->update(['updated_at' => now()->subMinutes(20)]);

        $service->releaseStaleRunningProducts();

        Queue::assertPushed(ApplyModelDescriptionJob::class, 1);
        Queue::assertPushed(ApplyModelDescriptionJob::class, static fn (ApplyModelDescriptionJob $job): bool => $job->batchId === (int) $batch->id
            && $job->leaderId === (int) $leader->id && $job->leaderVersionId === (int) $version->id);
        foreach ($members as $member) {
            $this->assertSame((int) $version->id, (int) ProductEnrichmentBatchItem::query()->where('batch_id', $batch->id)->where('product_id', $member->id)->value('model_leader_version_id'));
        }
        Queue::assertPushed(PrefetchProductSourcesJob::class, 1);
        Queue::assertPushed(PrefetchProductSourcesJob::class, static fn (PrefetchProductSourcesJob $job): bool => $job->productId === (int) $next->id
            && $job->batchId === (int) $batch2->id);
        $this->assertSame(ProductEnrichmentBatchItem::STATUS_QUEUED, ProductEnrichmentBatchItem::query()->where('product_id', $waiting->id)->value('status'));
        $this->assertSame((int) $alive->id, (int) ProductEnrichmentBatchItem::query()->where('product_id', $waiting->id)->value('model_leader_id'), 'żywy lider zostaje liderem');
    }

    public function test_leader_description_passes_on_full_name_when_stem_loses_the_deciding_dimension(): void
    {
        // pilotaż 08.10.2026 (#499): lider „CCLIP-38” z rdzeniem bez „38mm” — „38” czytane jako rozmiar buta, wymagany typ
        // „obuwie”, poprawny opis uchwytu odrzucony; pełna nazwa karty ma „38mm” i przechodzi
        $card = $this->coba('CCLIP-38', 'Akcesoria Krata GRP - Uchwyt typu C - 38mm');
        $description = 'Uchwyt typu C Coba CCLIP do mocowania kraty COBAGRiP GRP o grubości 38 mm do konstrukcji stalowej. '
            .'Wykonany ze stali nierdzewnej, zapewnia stabilne i trwałe połączenie kraty z belką nośną oraz łatwy montaż.';
        $service = $this->service();
        $context = new ReflectionProperty(ProductEnrichmentService::class, 'modelContext');
        $context->setValue($service, ['key' => 'coba|CCLIP|akcesoria krata grp uchwyt typu c', 'stem' => 'Akcesoria Krata GRP Uchwyt typu C', 'members' => []]);
        $stemOnly = new ReflectionMethod(ProductEnrichmentService::class, 'isUsableProductDescription');
        $stemCard = (new ReflectionMethod(ProductEnrichmentService::class, 'modelIdentityCard'))->invoke($service, $card);
        $usable = new ReflectionMethod(ProductEnrichmentService::class, 'isUsableModelDescription');

        $this->assertFalse($stemOnly->invoke($service, $description, $stemCard, []), 'sam rdzeń odrzuca opis (stan sprzed poprawki)');
        $this->assertTrue($stemOnly->invoke($service, $description, $card, []), 'pełna nazwa przepuszcza');
        $this->assertTrue($usable->invoke($service, $description, $card, []));
    }

    public function test_rejected_leader_version_is_no_basis_for_members(): void
    {
        $leader = $this->coba('AF010001', 'Orthomat Standard Czarny 0.6m x 0.9m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_DONE, 'description' => self::DESCRIPTION]);
        $member = $this->coba('AF060003C', 'Orthomat Standard Szary 0.9m x mb. (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $batch = $this->batch($leader, [$member]);
        // handlowiec odrzucił opis lidera („cudza strona”), zanim zadanie członków doszło do głosu
        $rejected = app(DescriptionVersionStore::class)->record($leader, ProductDescriptionVersion::STATUS_REJECTED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::DESCRIPTION,
            'enrichment_payload' => ['primary_source_url' => self::PAGE, 'source_urls' => [self::PAGE]],
            'primary_source_url' => self::PAGE,
        ]);
        $service = $this->service();

        try {
            $service->applyModelDescription($member, $rejected, (int) $batch->id);
            $this->fail('odrzucona wersja lidera nie jest podstawą opisu członków');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('została odrzucona w przeglądzie', $e->getMessage());
        }
        $member->refresh();
        $this->assertNull($member->description);
        $this->assertSame(Product::ENRICHMENT_QUEUED, $member->enrichment_status, 'karta nietknięta — status ustawia wołające zadanie');
        $this->assertSame(0, ProductDescriptionVersion::query()->where('product_id', $member->id)->count());
    }

    public function test_leader_takes_gallery_image_in_its_colour_before_the_verifier(): void
    {
        // pilotaż #499 (krata GRP 11027): weryfikator zostawił tylko og:image w innym kolorze (atrapa modelu wizyjnego
        // nie potwierdza żadnego kandydata — zostaje tylko zaufany og:image), a reguła koloru je cięła — lider bez zdjęcia
        $gray = 'https://www.coba.com/wp-content/uploads/Gray.jpg';
        $green = 'https://www.coba.com/wp-content/uploads/Green-1.jpg';
        $greenStem = 'https://www.coba.com/wp-content/uploads/Orthomat-Standard-Green.jpg';
        $leader = $this->coba('AF010001', 'Orthomat Standard Zielony 0.6m x 0.9m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $member = $this->coba('AF060003C', 'Orthomat Standard Zielony 0.9m x mb. (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $batch = $this->batch($leader, [$member]);
        $service = $this->service(gallery: [$gray, $green, $greenStem]);

        $service->enrichProduct($leader, false, (int) $batch->id);

        $leader->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $leader->enrichment_status, (string) $leader->enrichment_error);
        $this->assertNull($leader->enrichment_error);
        $image = $leader->images()->sole();
        $this->assertSame(['green'], ColourWords::allInUrl((string) $image->source_url), 'zdjęcie w kolorze karty, nie szare og:image: '.$image->source_url);
        $steps = array_column($leader->enrichment_trace['steps'] ?? [], 'm');
        $this->assertNotEmpty(array_filter($steps, static fn (string $m): bool => str_starts_with($m, 'zdjęcie strony w kolorze karty')), json_encode($steps, JSON_UNESCAPED_UNICODE));
        $meta = app(DescriptionVersionStore::class)->meta($service->lastRunVersion());
        // ta sama galeria dla członków: zaufany og:image pierwszy, reszta w kolejności fetchera
        $this->assertSame($gray, $meta['page_image_urls'][0] ?? null);
        $this->assertEqualsCanonicalizing([$gray, $green, $greenStem], $meta['page_image_urls'] ?? null);
    }

    public function test_leader_without_gallery_in_its_colour_removes_old_image_in_other_colour_only_with_force(): void
    {
        // galeria strony tylko czarna, karta szara: bez nowego zdjęcia i bez szukania na kartach sklepów; stare czarne
        // zdjęcie z internetu znika przy pełnym pobraniu (force), bez force zostaje
        $leader = $this->coba('AF010001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_DONE, 'description' => self::OLD_DESCRIPTION]);
        $old = $this->webImage($leader, self::IMAGE_BLACK);
        $member = $this->coba('AF060003C', 'Orthomat Standard Szary 0.9m x mb. (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $batch = $this->batch($leader, [$member], [$leader->id => Product::ENRICHMENT_DONE], force: true);
        $service = $this->service(gallery: [self::IMAGE_BLACK]);

        $service->enrichProduct($leader, true, (int) $batch->id);

        $leader->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $leader->enrichment_status, (string) $leader->enrichment_error);
        $this->assertSame(self::DESCRIPTION, $leader->description);
        $this->assertSame(0, $leader->images()->count(), 'stare czarne zdjęcie usunięte, nowego brak');
        $this->assertFalse(Storage::disk('public')->exists($old->path));
        $this->assertSame('Opis OK, zdjęcie w innym kolorze usunięte, nowego brak (zdjęcia strony w innym kolorze).', $leader->enrichment_error);
        $steps = array_column($leader->enrichment_trace['steps'] ?? [], 'm');
        $this->assertContains('zdjęcia strony w innym kolorze — bez nowego', $steps);
        $this->assertContains('zdjęcie w innym kolorze usunięte: orthomat-standard-black-1.jpg — nowego brak', $steps);
        $this->assertNotContains('zdjęcie producenta nie pobrało się — szukam na kartach sklepów', $steps);

        // bez force: zdjęcie w innym kolorze zostaje, komunikat jak dotąd
        $plain = $this->coba('AF060003', 'Orthomat Standard Szary 0.9m x 18.3m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_NONE]);
        $kept = $this->webImage($plain, self::IMAGE_BLACK);
        $batch2 = $this->batch($plain, [$member]);

        $this->service(gallery: [self::IMAGE_BLACK])->enrichProduct($plain, false, (int) $batch2->id);

        $plain->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $plain->enrichment_status, (string) $plain->enrichment_error);
        $this->assertSame([(int) $kept->id], $plain->images()->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertSame('Opis OK, nie udało się pobrać zdjęcia (zdjęcia strony w innym kolorze).', $plain->enrichment_error);
    }

    public function test_member_with_force_loses_old_image_in_other_colour_but_keeps_b2b_and_manual_images(): void
    {
        $leader = $this->coba('AF010001', 'Orthomat Standard Czarny 0.6m x 0.9m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        // członek żółty: galeria lidera (czarne, szare) bez żółtego — bez nowego zdjęcia; stare szare z internetu znika
        // przy partii z force, zdjęcie z B2B i ręczne (bez adresu) zostają
        $yellow = $this->coba('AF060003C', 'Orthomat Standard Żółty 0.9m x mb. (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $staleGrey = $this->webImage($yellow, self::IMAGE_GREY);
        $account = B2bAccount::query()->create(['username' => 'konto', 'password' => 'sekret', 'sites' => ['b2b.example.test']]);
        $fromB2b = $this->webImage($yellow, self::IMAGE_BLACK, ['b2b_account_id' => $account->id]);
        $manual = $this->webImage($yellow, null);
        $batch = $this->batch($leader, [$yellow], force: true);
        $service = $this->service();
        $service->enrichProduct($leader, false, (int) $batch->id);
        $leaderVersion = $service->lastRunVersion();
        $this->assertNotNull($leaderVersion);
        $service->recordBatchProduct($batch, $yellow, ProductEnrichmentBatchItem::STATUS_RUNNING);

        $this->assertSame(ApplyModelDescriptionJob::RESULT_PUBLISHED, $service->applyModelDescription($yellow->fresh(), $leaderVersion, (int) $batch->id));

        $yellow->refresh();
        $this->assertSame(self::DESCRIPTION, $yellow->description);
        $this->assertEqualsCanonicalizing([(int) $fromB2b->id, (int) $manual->id], $yellow->images()->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertFalse(Storage::disk('public')->exists($staleGrey->path));
        $this->assertTrue(Storage::disk('public')->exists($fromB2b->path));
        $this->assertSame('Opis OK, zdjęcie w innym kolorze usunięte, nowego brak (zdjęcie strony w innym kolorze).', $yellow->enrichment_error);
        $this->assertContains('zdjęcie w innym kolorze usunięte: orthomat-standard-grey-1.jpg — nowego brak', array_column($yellow->enrichment_trace['steps'] ?? [], 'm'));

        // bez force (partia bez force): stare szare zostaje
        $other = $this->coba('AF060004', 'Orthomat Standard Żółty 0.9m x 1.5m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $keptGrey = $this->webImage($other, self::IMAGE_GREY);
        $batch2 = $this->batch($leader, [$other], [], ProductEnrichmentBatchItem::STATUS_DONE);
        $service->recordBatchProduct($batch2, $other, ProductEnrichmentBatchItem::STATUS_RUNNING);

        $this->assertSame(ApplyModelDescriptionJob::RESULT_PUBLISHED, $service->applyModelDescription($other->fresh(), $leaderVersion, (int) $batch2->id));

        $other->refresh();
        $this->assertSame([(int) $keptGrey->id], $other->images()->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertSame('Opis OK, nie udało się pobrać zdjęcia (zdjęcie strony w innym kolorze).', $other->enrichment_error);
    }

    public function test_member_falls_back_to_leader_image_copy_when_gallery_url_fails(): void
    {
        $missing = 'https://www.coba.com/wp-content/uploads/orthomat-standard-grey-missing.jpg';
        $leader = $this->coba('AF010001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)', ['description' => self::DESCRIPTION, 'enrichment_status' => Product::ENRICHMENT_DONE]);
        // plik lidera bez koloru w nazwie — kolor z nazwy karty lidera (szary) = kolor członka
        $leaderImage = $this->webImage($leader, 'https://www.coba.com/wp-content/uploads/orthomat-standard-1.jpg');
        $version = app(DescriptionVersionStore::class)->record($leader, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::DESCRIPTION,
            'primary_source_url' => self::PAGE,
            'identity_verdict' => 'hard',
            'enrichment_payload' => ['norms' => [], 'source_urls' => [self::PAGE], 'primary_source_url' => self::PAGE],
            DescriptionVersionStore::META_KEY => ['page_image_urls' => [$missing]],
        ]);
        $member = $this->coba('AF060003C', 'Orthomat Standard Szary 0.9m x mb. (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $batch = $this->batch($leader, [$member], [], ProductEnrichmentBatchItem::STATUS_DONE);
        Http::fake(['*' => Http::response('', 404)]);
        $service = app(ProductEnrichmentService::class);
        $service->recordBatchProduct($batch, $member, ProductEnrichmentBatchItem::STATUS_RUNNING);

        $this->assertSame(ApplyModelDescriptionJob::RESULT_PUBLISHED, $service->applyModelDescription($member->fresh(), $version, (int) $batch->id));

        $member->refresh();
        $image = $member->images()->sole();
        $this->assertSame($leaderImage->checksum, $image->checksum, 'kopia pliku lidera');
        $this->assertNull($member->enrichment_error);
        $this->assertContains('adres z galerii nie pobrał się — kopia zdjęcia lidera', array_column($member->enrichment_trace['steps'] ?? [], 'm'));
    }

    public function test_leader_proposal_with_force_keeps_image_in_other_colour_and_trace_says_so(): void
    {
        // twarda baza z dużą liczbą dowodów — nowy opis zostaje propozycją; galeria tylko czarna, karta szara
        $leader = $this->coba('AF010001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_DONE, 'description' => self::OLD_DESCRIPTION]);
        app(DescriptionVersionStore::class)->record($leader, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::OLD_DESCRIPTION, 'primary_source_url' => self::PAGE, 'identity_verdict' => 'hard', 'evidence_count' => 50,
        ]);
        $old = $this->webImage($leader, self::IMAGE_BLACK);
        $member = $this->coba('AF060003C', 'Orthomat Standard Szary 0.9m x mb. (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $batch = $this->batch($leader, [$member], [$leader->id => Product::ENRICHMENT_DONE], force: true);
        $service = $this->service(gallery: [self::IMAGE_BLACK]);

        $service->enrichProduct($leader, true, (int) $batch->id);

        $leader->refresh();
        $this->assertSame(self::OLD_DESCRIPTION, $leader->description);
        $this->assertSame([(int) $old->id], $leader->images()->pluck('id')->map(static fn ($id): int => (int) $id)->all(), 'przy propozycji nic nie znika');
        $this->assertTrue(Storage::disk('public')->exists($old->path));
        $proposal = ProductDescriptionVersion::query()->where('product_id', $leader->id)->where('status', ProductDescriptionVersion::STATUS_PROPOSED)->sole();
        $steps = array_column($proposal->enrichment_trace['steps'] ?? [], 'm');
        $this->assertContains('zdjęcie w innym kolorze zostaje — opis czeka w przeglądzie', $steps);
        $this->assertEmpty(array_filter($steps, static fn (string $m): bool => str_contains($m, 'usunięte')), json_encode($steps, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('usunięte', (string) $leader->enrichment_error);
    }

    public function test_image_ranking_uses_colour_from_price_list_attribute(): void
    {
        $service = $this->service();
        $pick = new ReflectionMethod(ProductEnrichmentService::class, 'pickPrimaryImageUrls');
        // kolor tylko w kolumnie cennika, nie w nazwie — ranking bierze te same kolory co picker i usuwanie zdjęć
        $card = $this->coba('AF060001', 'Orthomat Standard 0.6m x 0.9m (9.5mm)', ['price_list_attributes' => ['kolor' => 'Szary']]);

        $this->assertSame([self::IMAGE_GREY], $pick->invoke($service, [self::IMAGE_BLACK, self::IMAGE_GREY], [], (string) $card->sku, (string) $card->name, $card));
    }

    /**
     * Zdjęcie karty sprzed przebiegu z plikiem na dysku: z internetu (adres źródła), ręczne (bez adresu) albo z B2B.
     *
     * @param  array<string, mixed>  $extra
     */
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

    public function test_leader_without_sources_leaves_no_run_version(): void
    {
        $leader = $this->coba('AF010001', 'Orthomat Standard Czarny 0.6m x 0.9m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $member = $this->coba('AF060003C', 'Orthomat Standard Szary 0.9m x mb. (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $batch = $this->batch($leader, [$member]);
        $service = $this->service(searchResults: []);

        try {
            $service->enrichProduct($leader, false, (int) $batch->id);
            $this->fail('bez wyników wyszukiwania przebieg kończy się brakiem karty');
        } catch (ProductSourcesNotFoundException $e) {
            // Coba „tylko od producenta” (decyzja właściciela 08.10.2026): brak źródeł = brak strony producenta
            $this->assertInstanceOf(ManufacturerPageMissingException::class, $e);
            $this->assertStringContainsString('Strony producenta nie znaleziono', $e->getMessage());
        }

        // sztafetę (nextLeader + prefetch) robi EnrichProductJob — tu tylko brak wersji
        $this->assertNull($service->lastRunVersion());
        $this->assertNull($service->lastProposalNote());
        $this->assertSame(Product::ENRICHMENT_QUEUED, $member->fresh()->enrichment_status, 'członek nietknięty');
    }

    public function test_sku_cache_is_neither_read_nor_written_for_grouped_brand_and_still_read_for_others(): void
    {
        // Coba: wpis pamięci SKU dla kodu lidera — przebieg idzie pełną ścieżką, wpis zostaje nieruszony
        $leader = $this->coba('AF010001', 'Orthomat Standard Czarny 0.6m x 0.9m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_FAILED]);
        ProductEnrichmentCache::query()->create([
            ...ProductEnrichmentCache::normalizeKey('Coba', 'AF010001'),
            'description' => 'Stary opis z pamięci SKU, który nie powinien wejść na kartę Coby ani zostać nadpisany.',
            'enrichment_payload' => ['norms' => [], 'confidence' => 0.9, 'features' => [], 'specs' => [], 'primary_source_url' => self::PAGE],
            'image_urls' => [],
            'source_urls' => [self::PAGE],
        ]);
        $service = $this->service();

        $service->enrichProduct($leader);

        $leader->refresh();
        $this->assertSame(self::DESCRIPTION, $leader->description);
        $this->assertSame(ProductDescriptionVersion::ORIGIN_ENRICHMENT, ProductDescriptionVersion::query()->where('product_id', $leader->id)->sole()->origin);
        $cache = ProductEnrichmentCache::query()->sole();
        $this->assertStringStartsWith('Stary opis z pamięci SKU', (string) $cache->description, 'wpis nie nadpisany');
        $this->assertContains('pamięć SKU pominięta — opis wspólny modelu', array_column($leader->enrichment_trace['steps'] ?? [], 'm'));
        // (e) bez noty modelu: karta bez partii — treść polecenia jak dotąd
        $this->assertStringNotContainsString('Opis wspólny dla', (string) $this->lastUserPrompt);
        // bez partii klucz modelu dalej w payloadzie: karta jest własnym liderem, bez noty — jedna karta modelu
        $this->assertSame(self::MODEL_KEY, $leader->enrichment_payload['model_group']['key'] ?? null);
        $this->assertSame([false, 1, (int) $leader->id], [
            $leader->enrichment_payload['model_group']['shared'] ?? null,
            $leader->enrichment_payload['model_group']['members'] ?? null,
            $leader->enrichment_payload['model_group']['leader_product_id'] ?? null,
        ]);

        // marka bez profilu grupowania: pamięć SKU czytana jak dotąd
        $other = Product::query()->create([
            'sku' => 'TX4521', 'name' => 'Rękawice montażowe Testex TX4521', 'manufacturer' => 'Testex',
            'enrichment_status' => Product::ENRICHMENT_FAILED,
        ]);
        ProductEnrichmentCache::query()->create([
            ...ProductEnrichmentCache::normalizeKey('Testex', 'TX4521'),
            'description' => 'Rękawice montażowe Testex TX4521 z dzianiny poliestrowej powlekanej nitrylem na części chwytnej, do prac montażowych.',
            'enrichment_payload' => ['norms' => [], 'confidence' => 0.9, 'features' => [], 'specs' => []],
            'image_urls' => [],
            'source_urls' => ['https://sklep.example/rekawice-testex-tx4521'],
        ]);

        $this->service(searchResults: [])->enrichProduct($other);

        $this->assertSame(ProductDescriptionVersion::ORIGIN_SKU_CACHE, ProductDescriptionVersion::query()->where('product_id', $other->id)->sole()->origin);
        $this->assertSame(Product::ENRICHMENT_DONE, $other->fresh()->enrichment_status);
    }

    public function test_release_stale_hands_over_members_of_a_lost_leader(): void
    {
        Queue::fake();
        $service = app(ProductEnrichmentService::class);
        $user = User::factory()->create();

        // lider gotowy z wersją, członkowie „queued” z numerem wersji w pozycji, bez zadania — zadanie opisu członków od nowa
        $leader = $this->coba('AF010001', 'Orthomat Standard Czarny 0.6m x 0.9m (9.5mm)', ['description' => self::DESCRIPTION, 'enrichment_status' => Product::ENRICHMENT_DONE]);
        $version = app(DescriptionVersionStore::class)->record($leader, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::DESCRIPTION, 'primary_source_url' => self::PAGE, 'identity_verdict' => 'hard',
        ]);
        $members = [
            $this->coba('AF060003C', 'Orthomat Standard Szary 0.9m x mb. (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]),
            $this->coba('AF060004', 'Orthomat Standard 0.9m x 1.5m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]),
        ];
        $batch = $this->batch($leader, $members, [], ProductEnrichmentBatchItem::STATUS_DONE, $user, force: true);
        ProductEnrichmentBatchItem::query()->where('batch_id', $batch->id)->where('product_id', '!=', $leader->id)
            ->update(['model_leader_version_id' => $version->id, 'updated_at' => now()->subMinutes(20)]);

        // lider padł bez wersji, członkowie bez numeru — sztafeta: kolejny członek liderem i prefetch
        $lost = $this->coba('DP0106', 'Deckplate Czarny 0.6m x 0.9m (15mm)', ['enrichment_status' => Product::ENRICHMENT_FAILED]);
        $cut = $this->coba('DP010915C', 'Deckplate Czarny 0.9m x mb. (15mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $next = $this->coba('DP010915', 'Deckplate Czarny 0.9m x 1.5m (15mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $batch2 = $this->batch($lost, [$cut, $next], [], ProductEnrichmentBatchItem::STATUS_FAILED, $user, 'coba|DP|deckplate', force: true);
        ProductEnrichmentBatchItem::query()->where('batch_id', $batch2->id)->where('product_id', '!=', $lost->id)
            ->update(['updated_at' => now()->subMinutes(20)]);

        // świeża pozycja (zadanie w drodze) i lider wciąż w przebiegu — nietknięte
        $fresh = $this->coba('TR060001', 'Toughrib Szary 0.6m x 0.9m', ['enrichment_status' => Product::ENRICHMENT_RUNNING]);
        $waiting = $this->coba('TR060002', 'Toughrib Szary 0.9m x 1.5m', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $this->batch($fresh, [$waiting], [], ProductEnrichmentBatchItem::STATUS_RUNNING, $user, 'coba|TR|toughrib');

        $this->assertSame(0, $service->releaseStaleRunningProducts(), 'żadna karta w przebiegu nie jest przeterminowana');

        Queue::assertPushed(ApplyModelDescriptionJob::class, 1);
        Queue::assertPushed(ApplyModelDescriptionJob::class, static fn (ApplyModelDescriptionJob $job): bool => $job->batchId === (int) $batch->id
            && $job->leaderId === (int) $leader->id && $job->leaderVersionId === (int) $version->id);
        Queue::assertPushed(PrefetchProductSourcesJob::class, 1);
        Queue::assertPushed(PrefetchProductSourcesJob::class, static fn (PrefetchProductSourcesJob $job): bool => $job->productId === (int) $next->id
            && $job->batchId === (int) $batch2->id && $job->force === true);
        $this->assertSame((int) $next->id, (int) ProductEnrichmentBatchItem::query()->where('batch_id', $batch2->id)->where('product_id', $cut->id)->value('model_leader_id'), 'SKU bazowe przed cięciem „C”');
        $this->assertSame(ProductEnrichmentBatchItem::STATUS_QUEUED, ProductEnrichmentBatchItem::query()->where('product_id', $waiting->id)->value('status'));

        // kolejne odpytanie panelu nie dubluje zlecenia (Cache::add na partię i lidera)
        $service->releaseStaleRunningProducts();
        Queue::assertPushed(ApplyModelDescriptionJob::class, 1);
        Queue::assertPushed(PrefetchProductSourcesJob::class, 1);
    }

    public function test_enqueue_plans_batch_by_models_and_cuts_limit_in_whole_models(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $o1 = $this->coba('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)');
        $o2 = $this->coba('AF060003C', 'Orthomat Standard Szary 0.9m x mb. (9.5mm)');
        $d1 = $this->coba('DP0106', 'Deckplate Czarny 0.6m x 0.9m (15mm)');
        $o3 = $this->coba('AF010001', 'Orthomat Standard Czarny 0.9m x 1.5m (9.5mm)');
        $d2 = $this->coba('DP010915', 'Deckplate Czarny 0.9m x 1.5m (15mm)');
        $ids = [(int) $o1->id, (int) $o2->id, (int) $d1->id, (int) $o3->id, (int) $d2->id];
        $service = app(ProductEnrichmentService::class);

        $result = $service->enqueueProductIds($ids, $user);

        $this->assertSame(2, $result['models']);
        $this->assertSame([(int) $o1->id, (int) $o2->id, (int) $o3->id, (int) $d1->id, (int) $d2->id], $result['product_ids'], 'karty modelu razem, lider pierwszy');
        $this->assertSame('W kolejce: 5 produktów (2 modele)', $result['batch']->message);
        Queue::assertPushed(PrefetchProductSourcesJob::class, 2);
        Queue::assertPushed(PrefetchProductSourcesJob::class, static fn (PrefetchProductSourcesJob $job): bool => $job->productId === (int) $o1->id);
        Queue::assertPushed(PrefetchProductSourcesJob::class, static fn (PrefetchProductSourcesJob $job): bool => $job->productId === (int) $d1->id);
        $items = ProductEnrichmentBatchItem::query()->where('batch_id', $result['batch']->id)->get()->keyBy('product_id');
        $this->assertCount(5, $items);
        foreach ([$o1, $o2, $o3] as $card) {
            $this->assertSame(self::MODEL_KEY, $items[$card->id]->model_key);
            $this->assertSame((int) $o1->id, (int) $items[$card->id]->model_leader_id);
            $this->assertNull($items[$card->id]->model_leader_version_id);
        }
        foreach ([$d1, $d2] as $card) {
            $this->assertSame('coba|DP|deckplate', $items[$card->id]->model_key);
            $this->assertSame((int) $d1->id, (int) $items[$card->id]->model_leader_id);
        }
        $this->assertSame(Product::ENRICHMENT_QUEUED, $o2->fresh()->enrichment_status, 'członek w kolejce bez własnego zadania');

        // limit tnie całymi modelami: 4 karty z 5 — drugi model (2 karty) nie mieści się po pierwszym (3)
        config(['ai.enrichment_batch_limit' => 4]);
        $cut = $service->enqueueProductIds($ids, $user, force: true);
        $this->assertSame(1, $cut['models']);
        $this->assertSame([(int) $o1->id, (int) $o2->id, (int) $o3->id], $cut['product_ids']);
        $this->assertSame('W kolejce: 3/5 (limit 4 — Ustawienia AI) (1 model)', $cut['batch']->message);
        Queue::assertPushed(PrefetchProductSourcesJob::class, 3);

        // marka bez grupowania: karta = model, bez klucza, lider = sama, kolejność jak dotąd
        $mapa = [
            Product::query()->create(['sku' => '34115', 'name' => 'VITAL 115', 'manufacturer' => 'MAPA']),
            Product::query()->create(['sku' => '34117', 'name' => 'VITAL 117', 'manufacturer' => 'MAPA']),
        ];
        $plain = $service->enqueueProductIds([(int) $mapa[1]->id, (int) $mapa[0]->id], $user);
        $this->assertSame(2, $plain['models']);
        $this->assertSame([(int) $mapa[1]->id, (int) $mapa[0]->id], $plain['product_ids']);
        $this->assertSame('W kolejce: 2 produktów', $plain['batch']->message);
        $item = ProductEnrichmentBatchItem::query()->where('batch_id', $plain['batch']->id)->where('product_id', $mapa[0]->id)->sole();
        $this->assertNull($item->model_key);
        $this->assertSame((int) $mapa[0]->id, (int) $item->model_leader_id);
    }

    /**
     * Partia z liderem i członkami w pozycjach (jak po enqueueProductIds); lider z podanym statusem pozycji,
     * członkowie „queued” z poprzednim stanem karty (domyślnie bez opisu).
     *
     * @param  list<Product>  $members
     * @param  array<int, string>  $previousStatus  id karty => stan sprzed kolejki
     */
    private function batch(
        Product $leader,
        array $members,
        array $previousStatus = [],
        string $leaderStatus = ProductEnrichmentBatchItem::STATUS_QUEUED,
        ?User $user = null,
        string $key = self::MODEL_KEY,
        bool $force = false,
    ): ProductEnrichmentBatch {
        $user ??= User::factory()->create();
        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRICE_LIST, 'scope_id' => 14, 'total' => 1 + count($members), 'done' => 0, 'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_RUNNING, 'created_by' => $user->id, 'force' => $force,
        ]);
        foreach ([$leader, ...$members] as $product) {
            ProductEnrichmentBatchItem::query()->create([
                'batch_id' => $batch->id, 'product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name,
                'status' => $product->id === $leader->id ? $leaderStatus : ProductEnrichmentBatchItem::STATUS_QUEUED,
                'previous_status' => $previousStatus[$product->id] ?? Product::ENRICHMENT_NONE,
                // komunikat karty sprzed kolejki — jak seedBatchItems (propozycja przywraca go razem ze stanem)
                'previous_error' => isset($previousStatus[$product->id]) ? $product->enrichment_error : null,
                'model_key' => $key, 'model_leader_id' => $leader->id,
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

    /**
     * Serwis z atrapami wyszukiwarki i modelu: jedna strona modelu coba.com (tytuł, og:image czarne, galeria czarne
     * i szare, opis, ramka norm, tabela części z kodami lidera „AF010001” i członka „AF060003”, pełna nazwa wariantu
     * „AF060004” bez kodu); filtr stron oddaje fakty, model oddaje DESCRIPTION z normą EN 13552.
     *
     * @param  list<array<string, string>>|null  $searchResults
     */
    /**
     * @param  list<array<string, string>>|null  $searchResults
     * @param  list<string>|null  $gallery  adresy `<img>` strony (domyślnie czarne i szare); og:image = pierwszy z nich
     */
    private function service(?array $searchResults = null, ?array $gallery = null): ProductEnrichmentService
    {
        $gallery ??= [self::IMAGE_BLACK, self::IMAGE_GREY];
        $facts = 'Orthomat Standard to mata antyzmęczeniowa Coba z pianki PVC o zamkniętych komórkach, z fakturą bąbelkową i skośnymi '
            .'krawędziami, do stanowisk pracy stojącej w suchych pomieszczeniach. Zmniejsza zmęczenie nóg i pleców, poprawia krążenie. '
            .'Norma EN 13552. Dostępne warianty: Orthomat Standard 0.9m x 1.5m (9.5mm), rolki cięte na metry.';
        $html = '<html><head><title>Orthomat Standard | COBA Europe</title>'
            .'<meta property="og:image" content="'.$gallery[0].'"></head><body><h1>Orthomat Standard</h1>'
            .implode('', array_map(static fn (string $url): string => '<img src="'.$url.'" alt="Orthomat Standard">', $gallery))
            .'<div class="product-description"><p>'.$facts.'</p>'
            .'<p>Producent: Coba Europe. Mata o grubości 9,5 mm, lekka i łatwa do przycięcia, utrzymuje czystość na stanowisku.</p>'
            .'<table><tr><th>Kod</th><th>Wymiary</th></tr>'
            .'<tr><td>AF010001</td><td>0.6m x 0.9m</td></tr>'
            .'<tr><td>AF060003</td><td>0.9m x metr bieżący</td></tr></table></div>'
            .'<ul class="normy"><li>EN 13552<br>klasa 2</li></ul>'
            .'</body></html>';

        $search = Mockery::mock(HybridWebSearchService::class);
        $search->shouldReceive('searchBothPhases')->zeroOrMoreTimes()->andReturn([
            'results' => $searchResults ?? [['url' => self::PAGE, 'title' => 'Orthomat Standard | COBA Europe', 'snippet' => '']],
            'errors' => [],
        ]);
        $search->shouldReceive('dropListingResults')->zeroOrMoreTimes()->andReturnUsing(static fn (array $results): array => $results);
        $search->shouldReceive('moreCatalogHits')->zeroOrMoreTimes()->andReturn([]);
        $search->shouldReceive('searchMappedRetailers')->zeroOrMoreTimes()->andReturn([]);
        $search->shouldReceive('searchWebWithoutLocalIndex')->zeroOrMoreTimes()->andReturn(['results' => [], 'images' => [], 'errors' => []]);
        $search->shouldReceive('forgetProductCache')->zeroOrMoreTimes();
        $search->shouldReceive('shopCardsForImage')->zeroOrMoreTimes()->andReturn([]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $handler = function (array $messages) use ($facts): array {
            $system = (string) ($messages[0]['content'] ?? '');
            if (str_contains($system, 'filtrem treści')) {
                return ['pages' => [['url' => self::PAGE, 'text' => $facts]]];
            }
            $this->lastUserPrompt = (string) ($messages[1]['content'] ?? '');

            return [
                'features' => ['Pianka PVC o zamkniętych komórkach', 'Skośne krawędzie'],
                // nota modelu każe wpisywać warianty do specs — etykiety kolidujące z faktami z cennika członka i jedna neutralna
                'specs' => ['Grubość: 9,5 mm', 'Kolor: Czarny', 'Wymiary: 0,6 × 0,9 m', 'Faktura: bąbelkowa'],
                'norms' => ['EN 13552'],
                'certificates' => [], 'materials' => ['PVC'], 'use_cases' => ['stanowiska pracy stojącej'],
                'image_urls' => [], 'source_urls' => [self::PAGE], 'description' => self::DESCRIPTION, 'confidence' => 0.9,
            ];
        };
        $llm->shouldReceive('chatJsonEnrichment')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJsonWithImages')->zeroOrMoreTimes()->andReturn(['candidates' => []]);

        Http::fake(function (Request $request) use ($html) {
            $url = $request->url();
            if (str_starts_with($url, 'https://www.coba.com/wp-content/uploads/') && str_ends_with(mb_strtolower($url), '.jpg')) {
                return Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']);
            }
            if (str_contains($url, 'r.jina.ai')) {
                return Http::response("Title: x\n\nMarkdown Content:\nundefined", 200, ['Content-Type' => 'text/plain']);
            }
            if (str_starts_with($url, self::PAGE)) {
                return Http::response($html, 200, ['Content-Type' => 'text/html']);
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
     * Runda 2 etapu 3 (5), decyzja właściciela 08.10.2026 — Coba tylko od producenta: lider modelu HR Matting ze stroną
     * tylko w sklepie fachhandel.pl (kod HR060003 na stronie, tekst wycieraczki) kończy się brakiem strony producenta;
     * model nie pisze opisu, wersji lidera nie ma (sztafeta jak przy braku wersji), członkowie zostają bez opisu sklepu.
     */
    public function test_coba_leader_with_only_shop_page_gets_manufacturer_missing_and_members_no_shop_description(): void
    {
        $shop = 'https://www.fachhandel.pl/coba-hr-matting-hr060003';
        $leader = $this->coba('HR060003', 'HR Matting Czarny 0.9m x 18.3m', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $member = $this->coba('HR060003C', 'HR Matting Czarny 0.9m x mb.', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $batch = $this->batch($leader, [$member], key: 'coba|HR|hr matting');
        $html = '<html><head><title>Coba HR Matting HR060003 Wycieraczka</title></head><body><h1>Coba HR Matting HR060003</h1>'
            .'<div class="product-description"><p>Coba HR Matting HR060003 — wycieraczka wejściowa z gumy do zatrzymywania brudu '
            .'i wilgoci przy wejściu do budynku, z otworami drenażowymi, do stref wejściowych i korytarzy. Kod: HR060003. '
            .str_repeat('Wycieraczka gumowa z otworami, łatwa do czyszczenia, do wejść i ciągów komunikacyjnych. ', 10).'</p></div></body></html>';
        $service = $this->shopOnlyService($shop, $html);

        try {
            $service->enrichProduct($leader, false, (int) $batch->id);
            $this->fail('lider Coby bez strony producenta nie dostaje opisu ze sklepu');
        } catch (ManufacturerPageMissingException $e) {
            $this->assertStringContainsString('coba.com', $e->getMessage());
        }

        $leader->refresh();
        $this->assertNull($leader->description);
        $this->assertSame(Product::REVIEW_MANUFACTURER_MISSING, $leader->review_reason);
        $this->assertSame(Product::ENRICHMENT_MANUAL, $leader->enrichment_status);
        $this->assertNull($service->lastRunVersion(), 'bez wersji lidera — EnrichProductJob przekazuje model kolejnemu członkowi');
        $this->assertNull($this->lastUserPrompt, 'model opisu nie jest wołany');
        $member->refresh();
        $this->assertNull($member->description);
        $this->assertSame(Product::ENRICHMENT_QUEUED, $member->enrichment_status);
        $this->assertSame(0, ProductDescriptionVersion::query()->count());
    }

    /**
     * Runda 2 etapu 3 (6): nota modelu zakazuje w tekście opisu wartości jednego wariantu (audyt Coby 08.10.2026:
     * COBAswitch 9,5 mm z parametrami 6 mm, Tough Lock pomarańczowy „w kolorze żółtym”, listwy GRP z wymiarem i wagą
     * jednego wariantu) — wartości wariantów tylko w specs z kodem wariantu.
     */
    public function test_model_note_forbids_single_variant_values_in_description_text(): void
    {
        $leader = $this->coba('AF010001', 'Orthomat Standard Czarny 0.6m x 0.9m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $member = $this->coba('AF060003C', 'Orthomat Standard Szary 0.9m x mb. (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $batch = $this->batch($leader, [$member]);
        $service = $this->service();

        $service->enrichProduct($leader, false, (int) $batch->id);

        $prompt = (string) $this->lastUserPrompt;
        $this->assertStringContainsString('Opis wspólny dla 2 wariantów', $prompt);
        $this->assertStringContainsString('nie podawaj wartości jednego wariantu', $prompt);
        foreach (['wymiarów', 'grubości', 'koloru', 'wagi', 'napięcia', 'długości rolki', 'tylko cechy wspólne'] as $word) {
            $this->assertStringContainsString($word, $prompt);
        }
        $this->assertStringContainsString('wyłącznie w specs, każdą z kodem wariantu', $prompt);
    }

    /**
     * Runda 2 etapu 3 (7): zdjęcie wybrane przez picker lidera (w kolorze karty, bez weryfikatora) przechodzi przez W5 —
     * plik „…-Green-20kv.jpg” przy karcie 30 kV odpada jak każda inna kandydatura.
     */
    public function test_leader_colour_pick_goes_through_foreign_image_gate(): void
    {
        $gray = 'https://www.coba.com/wp-content/uploads/Gray.jpg';
        $green20kv = 'https://www.coba.com/wp-content/uploads/Orthomat-Standard-Green-20kv.jpg';
        $leader = $this->coba('AF010001', 'Orthomat Standard Zielony 0.6m x 0.9m (9.5mm) 30 kV', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $member = $this->coba('AF060003C', 'Orthomat Standard Zielony 0.9m x mb. (9.5mm) 30 kV', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $batch = $this->batch($leader, [$member]);
        $service = $this->service(gallery: [$gray, $green20kv]);

        $service->enrichProduct($leader, false, (int) $batch->id);

        $leader->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $leader->enrichment_status, (string) $leader->enrichment_error);
        $this->assertNotContains($green20kv, $leader->images()->pluck('source_url')->all(), 'zdjęcie ze sprzeczną cechą nie wchodzi jako wybór pickera');
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), 'Green-20kv.jpg'));
        $steps = json_encode($leader->enrichment_trace, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('sprzeczna cecha', (string) $steps);
    }

    /**
     * Zamiennik z karty rodzeństwa (ModelImagePicker::pickFromModelSiblings, audyt Coby 08.10.2026): członek żółty,
     * galeria lidera bez żółtego — zamiast braku zdjęcia kopia żółtego zdjęcia innej karty tego modelu w partii.
     */
    public function test_member_without_gallery_in_its_colour_copies_image_of_sibling_card_in_batch(): void
    {
        $leader = $this->coba('AF010001', 'Orthomat Standard Czarny 0.6m x 0.9m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $yellow = $this->coba('AF060003C', 'Orthomat Standard Żółty 0.9m x mb. (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $sibling = $this->coba('AF060004', 'Orthomat Standard Żółty 0.9m x 1.5m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_DONE]);
        $siblingImage = $this->webImage($sibling, 'https://www.coba.com/wp-content/uploads/orthomat-standard-yellow-1.jpg');
        $batch = $this->batch($leader, [$yellow, $sibling]);
        $service = $this->service();
        $service->enrichProduct($leader, false, (int) $batch->id);
        $leaderVersion = $service->lastRunVersion();
        $this->assertNotNull($leaderVersion);
        $service->recordBatchProduct($batch, $yellow, ProductEnrichmentBatchItem::STATUS_RUNNING);

        $this->assertSame(ApplyModelDescriptionJob::RESULT_PUBLISHED, $service->applyModelDescription($yellow->fresh(), $leaderVersion, (int) $batch->id));

        $yellow->refresh();
        $image = $yellow->images()->sole();
        $this->assertSame($siblingImage->checksum, $image->checksum, 'kopia pliku karty-rodzeństwa');
        $this->assertSame($siblingImage->source_url, $image->source_url);
        $this->assertNull($yellow->enrichment_error);
        $this->assertNotEmpty(array_filter(
            array_column($yellow->enrichment_trace['steps'] ?? [], 'm'),
            static fn (string $m): bool => str_starts_with($m, ModelImagePicker::REASON_SIBLING_COPY)
        ));
        $this->assertSame(1, $sibling->images()->count(), 'zdjęcie rodzeństwa zostaje u niego');
    }

    /** Adres z galerii nie pobrał się — zamiennik z karty rodzeństwa przed kopią zdjęcia lidera. */
    public function test_member_gallery_failure_prefers_sibling_copy_over_leader_copy(): void
    {
        $missing = 'https://www.coba.com/wp-content/uploads/orthomat-standard-grey-missing.jpg';
        $leader = $this->coba('AF010001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)', ['description' => self::DESCRIPTION, 'enrichment_status' => Product::ENRICHMENT_DONE]);
        $this->webImage($leader, 'https://www.coba.com/wp-content/uploads/orthomat-standard-1.jpg');
        $version = app(DescriptionVersionStore::class)->record($leader, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::DESCRIPTION,
            'primary_source_url' => self::PAGE,
            'identity_verdict' => 'hard',
            'enrichment_payload' => ['norms' => [], 'source_urls' => [self::PAGE], 'primary_source_url' => self::PAGE],
            DescriptionVersionStore::META_KEY => ['page_image_urls' => [$missing]],
        ]);
        $member = $this->coba('AF060003C', 'Orthomat Standard Szary 0.9m x mb. (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $sibling = $this->coba('AF060004', 'Orthomat Standard Szary 0.9m x 1.5m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_DONE]);
        $siblingImage = $this->webImage($sibling, 'https://www.coba.com/wp-content/uploads/AF060004_Orthomat_Grey.jpg');
        $batch = $this->batch($leader, [$member, $sibling], [], ProductEnrichmentBatchItem::STATUS_DONE);
        Http::fake(['*' => Http::response('', 404)]);
        $service = app(ProductEnrichmentService::class);
        $service->recordBatchProduct($batch, $member, ProductEnrichmentBatchItem::STATUS_RUNNING);

        $this->assertSame(ApplyModelDescriptionJob::RESULT_PUBLISHED, $service->applyModelDescription($member->fresh(), $version, (int) $batch->id));

        $member->refresh();
        $this->assertSame($siblingImage->checksum, $member->images()->sole()->checksum, 'kopia pliku rodzeństwa w kolorze karty, nie lidera bez koloru');
        $this->assertContains('adres z galerii nie pobrał się — '.ModelImagePicker::REASON_SIBLING_COPY, array_column($member->enrichment_trace['steps'] ?? [], 'm'));
    }

    /** Lider z galerią strony tylko w innym kolorze (Entra-Plush Szary 11162 ← 11163) — zamiennik z karty modelu w partii. */
    public function test_leader_with_gallery_in_other_colour_copies_image_of_sibling_card(): void
    {
        $leader = $this->coba('AF010001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $sibling = $this->coba('AF060003', 'Orthomat Standard Szary 0.9m x 18.3m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $siblingImage = $this->webImage($sibling, self::IMAGE_GREY);
        $batch = $this->batch($leader, [$sibling]);
        $service = $this->service(gallery: [self::IMAGE_BLACK]);

        $service->enrichProduct($leader, false, (int) $batch->id);

        $leader->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $leader->enrichment_status, (string) $leader->enrichment_error);
        $this->assertNull($leader->enrichment_error);
        $image = $leader->images()->sole();
        $this->assertSame($siblingImage->checksum, $image->checksum);
        $this->assertSame(self::IMAGE_GREY, $image->source_url);
        Http::assertNotSent(static fn (Request $r): bool => $r->url() === self::IMAGE_BLACK);
    }

    /**
     * Usuwanie zdjęć w złym kolorze: plik dwubarwny „BlackYellow” na karcie Żółtej to zdjęcie innego wariantu (inny zbiór
     * kolorów), plik „Yellow” zostaje, plik bez koloru zostaje.
     */
    public function test_images_in_other_colour_include_two_colour_file_on_single_colour_card(): void
    {
        $card = $this->coba('AF060003C', 'Orthomat Standard Żółty 0.9m x mb. (9.5mm)');
        $twoColour = $this->webImage($card, 'https://www.coba.com/wp-content/uploads/Orthomat-Standard-BlackYellow.jpg');
        $yellow = $this->webImage($card, 'https://www.coba.com/wp-content/uploads/Orthomat-Standard-Yellow.jpg');
        $plain = $this->webImage($card, 'https://www.coba.com/wp-content/uploads/Orthomat-Standard.jpg');
        $service = $this->service();
        $method = new ReflectionMethod(ProductEnrichmentService::class, 'imagesInOtherColour');

        $out = $method->invoke($service, $card, ['yellow'], [(int) $twoColour->id, (int) $yellow->id, (int) $plain->id]);

        $this->assertSame([(int) $twoColour->id], array_keys($out));
    }

    /**
     * Runda 3: członek Coby (marka tylko od producenta) z opisem ze sklepu o werdykcie hard, opis lidera z coba.com daje
     * mu werdykt soft — opis producenta jest publikowany (wersja sklepu do historii jako cofnięta), nie propozycja.
     */
    public function test_coba_member_with_shop_description_gets_leader_description_published(): void
    {
        $shop = 'https://www.fachhandel.pl/coba-orthomat-af060004';
        $shopText = 'Mata Orthomat Standard AF060004 z opisem ze sklepu fachhandel.pl — opis bazowy z twardym werdyktem tożsamości ze sklepu.';
        $leader = $this->coba('AF010001', 'Orthomat Standard Czarny 0.6m x 0.9m (9.5mm)', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        // bez kodu na stronie lidera, pełna nazwa w tekście — werdykt miękki
        $member = $this->coba('AF060004', 'Orthomat Standard 0.9m x 1.5m (9.5mm)', [
            'enrichment_status' => Product::ENRICHMENT_QUEUED,
            'description' => $shopText,
            'enrichment_payload' => ['primary_source_url' => $shop, 'primary_source_kind' => 'shop', 'source_urls' => [$shop]],
        ]);
        $shopVersion = app(DescriptionVersionStore::class)->record($member, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => $shopText,
            'primary_source_url' => $shop,
            'identity_verdict' => 'hard',
            'evidence_count' => 1,
        ]);
        $batch = $this->batch($leader, [$member], [$member->id => Product::ENRICHMENT_DONE]);
        $service = $this->service();
        $service->enrichProduct($leader, false, (int) $batch->id);
        $leaderVersion = $service->lastRunVersion();
        $this->assertNotNull($leaderVersion);
        $service->recordBatchProduct($batch, $member, ProductEnrichmentBatchItem::STATUS_RUNNING);

        $this->assertSame(ApplyModelDescriptionJob::RESULT_PUBLISHED, $service->applyModelDescription($member->fresh(), $leaderVersion, (int) $batch->id));

        $member->refresh();
        $this->assertSame(self::DESCRIPTION, $member->description);
        $this->assertSame('soft', $member->enrichment_payload['identity']['verdict'] ?? null);
        $this->assertSame(Product::REVIEW_IDENTITY_SOFT, $member->review_reason);
        $shopVersion->refresh();
        $this->assertSame(ProductDescriptionVersion::STATUS_SUPERSEDED, $shopVersion->status);
        $this->assertSame('opis ze sklepu zastąpiony stroną producenta', app(DescriptionVersionStore::class)->meta($shopVersion)['withdrawn_reason'] ?? null);
        $this->assertSame(0, ProductDescriptionVersion::query()->where('product_id', $member->id)->where('status', ProductDescriptionVersion::STATUS_PROPOSED)->count());
    }

    /** Runda 3: zamiennik z karty rodzeństwa przechodzi przez W5 — plik „…-Yellow-20kv.jpg” przy karcie 30 kV nie jest kopiowany. */
    public function test_sibling_copy_skips_image_with_conflicting_safety_feature(): void
    {
        $leader = $this->coba('AF010001', 'Orthomat Standard Czarny 0.6m x 0.9m (9.5mm) 30 kV', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $yellow = $this->coba('AF060003C', 'Orthomat Standard Żółty 0.9m x mb. (9.5mm) 30 kV', ['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $sibling = $this->coba('AF060004', 'Orthomat Standard Żółty 0.9m x 1.5m (9.5mm) 30 kV', ['enrichment_status' => Product::ENRICHMENT_DONE]);
        $this->webImage($sibling, 'https://www.coba.com/wp-content/uploads/orthomat-standard-yellow-20kv.jpg');
        $batch = $this->batch($leader, [$yellow, $sibling]);
        $service = $this->service();
        $service->enrichProduct($leader, false, (int) $batch->id);
        $leaderVersion = $service->lastRunVersion();
        $this->assertNotNull($leaderVersion);
        $service->recordBatchProduct($batch, $yellow, ProductEnrichmentBatchItem::STATUS_RUNNING);

        $this->assertSame(ApplyModelDescriptionJob::RESULT_PUBLISHED, $service->applyModelDescription($yellow->fresh(), $leaderVersion, (int) $batch->id));

        $yellow->refresh();
        $this->assertSame(0, $yellow->images()->count(), 'zdjęcie 20 kV z karty rodzeństwa nie trafia na kartę 30 kV');
        $this->assertStringContainsString('sprzeczna cecha', (string) json_encode($yellow->enrichment_trace, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Serwis jak service(), ale wyszukiwarka zwraca jedną stronę sklepu $url z treścią $html (reszta 404).
     */
    private function shopOnlyService(string $url, string $html): ProductEnrichmentService
    {
        $search = Mockery::mock(HybridWebSearchService::class);
        $search->shouldReceive('searchBothPhases')->zeroOrMoreTimes()->andReturn([
            'results' => [['url' => $url, 'title' => 'Coba HR Matting HR060003 Wycieraczka', 'snippet' => 'HR060003']],
            'errors' => [],
        ]);
        $search->shouldReceive('dropListingResults')->zeroOrMoreTimes()->andReturnUsing(static fn (array $results): array => $results);
        $search->shouldReceive('moreCatalogHits')->zeroOrMoreTimes()->andReturn([]);
        $search->shouldReceive('searchMappedRetailers')->zeroOrMoreTimes()->andReturn([]);
        $search->shouldReceive('searchWebWithoutLocalIndex')->zeroOrMoreTimes()->andReturn(['results' => [], 'images' => [], 'errors' => []]);
        $search->shouldReceive('forgetProductCache')->zeroOrMoreTimes();
        $search->shouldReceive('shopCardsForImage')->zeroOrMoreTimes()->andReturn([]);
        $search->shouldReceive('catalogHitsOnHosts')->zeroOrMoreTimes()->andReturn([]);
        $search->shouldReceive('searchOnHosts')->zeroOrMoreTimes()->andReturn([]);
        $search->shouldReceive('lastHostSearchErrors')->zeroOrMoreTimes()->andReturn([]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $handler = function (array $messages): array {
            $system = (string) ($messages[0]['content'] ?? '');
            $user = (string) ($messages[1]['content'] ?? '');
            if (str_contains($system, 'filtrem treści')) {
                $out = [];
                $at = strpos($user, "Strony:\n");
                foreach ((array) (json_decode($at === false ? '' : substr($user, $at + 8), true) ?? []) as $page) {
                    if (is_array($page)) {
                        $out[] = ['url' => $page['url'] ?? '', 'text' => $page['text'] ?? ''];
                    }
                }

                return ['pages' => $out];
            }
            $this->lastUserPrompt = $user;

            return [
                'description' => 'Wycieraczka wejściowa Coba HR Matting z gumy z otworami drenażowymi, zatrzymuje brud i wilgoć przy wejściu do budynku. Łatwa do czyszczenia.',
                'features' => [], 'specs' => [], 'norms' => [], 'certificates' => [], 'materials' => ['guma'], 'use_cases' => [],
                'image_urls' => [], 'source_urls' => [], 'confidence' => 0.9,
            ];
        };
        $llm->shouldReceive('chatJsonEnrichment')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJsonWithImages')->zeroOrMoreTimes()->andReturn(['candidates' => []]);

        Http::fake(static function (Request $request) use ($url, $html) {
            if (str_starts_with($request->url(), $url)) {
                return Http::response($html, 200, ['Content-Type' => 'text/html']);
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
