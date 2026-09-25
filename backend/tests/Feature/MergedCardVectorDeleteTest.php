<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CardMatchCandidate;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CardMatchFinder;
use App\Services\Catalog\CardMatchMerger;
use App\Services\ProductSizeMergeService;
use DomainException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\FakeQdrant;
use Tests\TestCase;

/**
 * Wektor karty usuniętej przy scalaniu (plan łączenia kart, etap C2 pkt 5): ProductSizeMergeService kasuje go w Qdrant
 * po commit — automatyczne łączenie rozmiarów (merge), „Połącz” (mergeDuplicate) i łączenie rozmiarów z ekranu
 * (mergeSizeCards). Wektor bez karty nie wraca w wynikach wyszukiwania, ale zajmuje miejsce w puli wektorowej tuż przy
 * karcie, która zostaje. Pełna ścieżka „Połącz rozmiary” — CardMatchSizeMergerTest.
 */
final class MergedCardVectorDeleteTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, int> karta dystrybutora => karta producenta, którą potwierdza atrapa evaluate() */
    private array $targets = [];

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        // kopia zapasowa „Połącz” w katalogu tego testu — testy równoległe mają te same numery propozycji
        $this->storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'merged-card-vector-'.uniqid('', true);
        mkdir($this->storage.DIRECTORY_SEPARATOR.'app', 0775, true);
        $this->app->useStoragePath($this->storage);

        $test = $this;
        $this->app->instance(CardMatchFinder::class, new class($test)
        {
            public function __construct(private readonly MergedCardVectorDeleteTest $test) {}

            /** @return array<string, mixed>|null */
            public function evaluate(Product $source): ?array
            {
                $target = $this->test->targetFor((int) $source->id);

                return $target === null ? null : ['status' => 'pending', 'kind' => 'merge', 'target_product_id' => $target];
            }
        });
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function targetFor(int $sourceId): ?int
    {
        return $this->targets[$sourceId] ?? null;
    }

    public function test_automatic_size_merge_deletes_vectors_of_absorbed_cards_only(): void
    {
        FakeQdrant::enable();
        [$kept, $small, $large] = $this->ansellSizes();

        $result = app(ProductSizeMergeService::class)->merge('Ansell', false);

        $this->assertSame(2, $result['deleted']);
        $this->assertNotNull($kept->fresh());
        $this->assertSame([(int) $small->id, (int) $large->id], FakeQdrant::deletedIds());
    }

    public function test_card_match_merge_deletes_distributor_card_vector_after_outer_commit(): void
    {
        FakeQdrant::enable();
        $user = User::factory()->create();
        [$target, $source] = $this->cards();
        $candidate = $this->candidate($source, $target);

        DB::transaction(function () use ($candidate, $user, $source): void {
            app(CardMatchMerger::class)->merge($candidate, $user);
            $this->assertNull(Product::query()->find($source->id));
            // karta skasowana w transakcji wywołującego — wycofanie by ją przywróciło, więc wektor czeka na commit
            $this->assertSame([], FakeQdrant::deletedIds());
        });

        $this->assertSame([(int) $source->id], FakeQdrant::deletedIds());
        $this->assertSame(CardMatchCandidate::STATUS_MERGED, $candidate->fresh()->status);
    }

    public function test_rolled_back_size_cards_merge_keeps_cards_and_their_vectors(): void
    {
        FakeQdrant::enable();
        [$s, $m, $l] = $this->halfMasks();

        try {
            DB::transaction(static function () use ($s, $m, $l): void {
                app(ProductSizeMergeService::class)->mergeSizeCards($s, [$m, $l], '6X00 Półmaska 3M 6000', 'Rozmiary: S; M; L');
                // jak w CardMatchSizeMerger, gdy po łączeniu rozmiarów karta dystrybutora nie daje pewnej pary
                throw new DomainException('Po połączeniu rozmiarów karta dystrybutora nie daje pewnej pary.');
            });
            $this->fail('Oczekiwano wycofania łączenia.');
        } catch (DomainException) {
        }

        $this->assertNotNull($m->fresh());
        $this->assertNotNull($l->fresh());
        $this->assertSame([], FakeQdrant::deletedIds());
    }

    public function test_qdrant_error_is_logged_and_merge_stays(): void
    {
        FakeQdrant::enable(500);
        Log::spy();
        [$kept, $small, $large] = $this->ansellSizes();

        $result = app(ProductSizeMergeService::class)->merge('Ansell', false);

        $this->assertSame(2, $result['deleted']);
        $this->assertSame([], $result['errors']);
        $this->assertNull($small->fresh());
        $this->assertNull($large->fresh());
        $this->assertNotNull($kept->fresh());
        // błąd pierwszej karty nie zatrzymuje kasowania wektora drugiej
        $this->assertSame([(int) $small->id, (int) $large->id], FakeQdrant::deletedIds());
        foreach ([$small, $large] as $card) {
            Log::shouldHaveReceived('warning')
                ->with('Product embedding delete failed', Mockery::on(static fn (array $context): bool => $context['product_id'] === (int) $card->id))
                ->once();
        }
    }

    /**
     * Trzy rozmiary rękawic Ansell w jednej cenie — zostaje karta z opisem (preferredKeeper), dwie pozostałe znikają.
     *
     * @return array{0: Product, 1: Product, 2: Product} karta, która zostaje, i dwie scalane
     */
    private function ansellSizes(): array
    {
        $cards = [];
        foreach ([['37695VP090', '9.0', true], ['37695VP070', '7.0', false], ['37695VP100', '10.0', false]] as [$sku, $size, $described]) {
            $cards[] = Product::query()->create([
                'sku' => $sku,
                'name' => 'AlphaTec 37695VP Size '.$size,
                'manufacturer' => 'Ansell',
                'catalog_price_net' => 2.85,
                'purchase_price' => 2.85,
            ] + ($described ? [
                'description' => str_repeat('Rękawice chemiczne Ansell AlphaTec. ', 3),
                'enrichment_status' => Product::ENRICHMENT_DONE,
            ] : []));
        }

        return [$cards[0], $cards[1], $cards[2]];
    }

    /**
     * Karta producenta ANRO (powiązanie konta Anro — właściciel) i karta dystrybutora P4S tego samego wyrobu.
     *
     * @return array{0: Product, 1: Product}
     */
    private function cards(): array
    {
        $anro = B2bAccount::query()->create(['username' => 'anro', 'password' => 'x', 'sites' => ['b2b.anro.pl'], 'connector' => 'anro']);
        $p4s = B2bAccount::query()->create(['username' => 'p4s', 'password' => 'x', 'sites' => ['b2b.p4s.pl'], 'connector' => 'p4s']);
        $target = Product::query()->create([
            'sku' => 'IF/016/F/PS', 'name' => 'Półmaska ANRO IF/016/F/PS', 'manufacturer' => 'ANRO',
            'catalog_price_net' => 8.69, 'purchase_price' => 8.69, 'currency' => 'PLN',
        ]);
        $source = Product::query()->create([
            'sku' => 'ZPPV99C', 'name' => 'Półmaska P4S IF/016/F/PS', 'manufacturer' => 'ANRO',
            'catalog_price_net' => 7.90, 'purchase_price' => 7.90, 'currency' => 'PLN',
        ]);
        B2bProductLink::query()->create(['b2b_account_id' => $anro->id, 'remote_id' => 'IF/016/F/PS', 'product_id' => $target->id]);
        B2bProductLink::query()->create(['b2b_account_id' => $p4s->id, 'remote_id' => '99254', 'product_id' => $source->id]);

        return [$target, $source];
    }

    private function candidate(Product $source, Product $target): CardMatchCandidate
    {
        $this->targets[$source->id] = (int) $target->id;

        return CardMatchCandidate::query()->create([
            'source_product_id' => $source->id, 'target_product_id' => $target->id,
            'status' => CardMatchCandidate::STATUS_PENDING, 'matched_by' => CardMatchCandidate::BY_MANUFACTURER_CODE,
            'matched_value' => 'IF016FPS', 'brand' => 'anro', 'hits' => 1, 'positions' => 1,
        ]);
    }

    /**
     * Karty rozmiarów 3M (6100 S, 6200 M, 6300 L) w jednej cenie.
     *
     * @return array{0: Product, 1: Product, 2: Product}
     */
    private function halfMasks(): array
    {
        $cards = [];
        foreach ([['7000146845', '6100 S'], ['7000146847', '6200 M'], ['7000146849', '6300 L']] as [$sku, $size]) {
            $cards[] = Product::query()->create([
                'sku' => $sku, 'name' => 'Półmaska 3M '.$size, 'manufacturer' => '3M',
                'catalog_price_net' => 61.38, 'purchase_price' => 61.38, 'currency' => 'PLN',
            ]);
        }

        return [$cards[0], $cards[1], $cards[2]];
    }
}
