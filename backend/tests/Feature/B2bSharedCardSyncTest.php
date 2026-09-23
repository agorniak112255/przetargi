<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductPriceHistory;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Dwa kody jednego konta na jednej karcie — rozmiary scalone w jedną kartę (ProductSizeMergeService przenosi
 * powiązania scalanej karty na docelową). Łącznik bez grup rozmiarów (members) idzie ścieżką pojedynczej
 * pozycji: każdy kod zapisywał na wspólną kartę swój opis (witryna producenta zastępuje opis zawsze) i swoją
 * cenę do jednego slotu konta — opis przeskakiwał między kodami co przebieg, z nowym replaced_description
 * i reindeksem za każdym razem. Kartę zapisuje pierwszy kod przebiegu; kolejny odświeża tylko swoje powiązanie.
 */
final class B2bSharedCardSyncTest extends TestCase
{
    use RefreshDatabase;

    private const FIRST = 'Trzewik ARTRA ARMEN z podnoskiem kompozytowym, cholewka ze skóry licowej, rozmiar 40.';

    private const SECOND = 'Trzewik ARTRA ARMEN S3 z wkładką antyprzebiciową, podeszwa PU/TPU, rozmiar 41.';

    private User $user;

    private B2bAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
        $this->account = B2bAccount::query()->create([
            'username' => 'jan',
            'password' => 'sekret',
            'sites' => [SharedCardMfrConnector::host()],
            'connector' => SharedCardMfrConnector::key(),
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    public function test_second_code_on_a_merged_card_does_not_flip_description_or_account_price(): void
    {
        $card = $this->mergedCard();
        $connector = new SharedCardMfrConnector;

        $first = $this->sync($connector);
        $afterFirst = $card->fresh();

        // opis pierwszego kodu zostaje — drugi kod go nie zastępuje ani nie odkłada do replaced_description
        $this->assertSame(self::FIRST, $afterFirst?->description);
        $this->assertArrayNotHasKey('replaced_description', (array) $afterFirst?->enrichment_payload);
        $this->assertSame(0, $first['descriptions']);
        // slot konta z ceną pierwszego kodu; jeden wiersz historii (punkt odniesienia konta), bez zmian ceny
        $slot = ProductSourcePrice::query()->where('source_key', ProductSourcePrice::b2bKey((int) $this->account->id))->sole();
        $this->assertSame(120.0, (float) $slot->purchase_price);
        $this->assertSame(1, ProductPriceHistory::query()->where('product_id', $card->id)->count());
        // inna cena drugiego kodu nie ginie po cichu — ostrzeżenie w dzienniku przebiegu
        $this->assertNotEmpty($this->warningsAbout('P1-41', $first));

        Queue::fake();
        $second = $this->sync($connector);
        $afterSecond = $card->fresh();

        $this->assertSame(self::FIRST, $afterSecond?->description);
        $this->assertSame($afterFirst?->updated_at?->toIso8601String(), $afterSecond?->updated_at?->toIso8601String());
        $this->assertSame(0, $second['descriptions']);
        $this->assertSame(0, $second['prices_changed']);
        $this->assertSame(1, ProductPriceHistory::query()->where('product_id', $card->id)->count());
        Queue::assertNotPushed(ReindexProductEmbeddingJob::class);

        // oba kody dalej wskazują kartę, a powiązanie drugiego jest odświeżone (ostatnio widziany w B2B)
        $links = B2bProductLink::query()->orderBy('remote_id')->get();
        $this->assertSame([(int) $card->id, (int) $card->id], $links->pluck('product_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertTrue($links[1]->last_seen_at?->gt(CarbonImmutable::parse('2026-01-02')));
        // odcisk drugiego kodu bez zmian — jego opisu na karcie nie ma
        $this->assertSame(sha1(self::SECOND), (string) $links[1]->description_hash);
        // identyfikatory należą do pozycji — drugi kod zapisuje swój EAN mimo pominiętego opisu i ceny
        $this->assertSame(
            ['P1-40' => '5711074644834', 'P1-41' => '5901234123457'],
            ProductIdentifier::query()->where('product_id', $card->id)->where('type', ProductIdentifier::TYPE_EAN)
                ->orderBy('position_key')->pluck('value', 'position_key')->all(),
        );
    }

    /**
     * Stan po scaleniu rozmiarów: karta z opisem kodu 40, powiązania obu kodów (41 przeniesione ze scalonej karty,
     * z odciskiem jej opisu).
     */
    private function mergedCard(): Product
    {
        $card = Product::query()->create([
            'sku' => 'P1-40',
            'name' => 'Trzewik ARMEN',
            'manufacturer' => 'ARTRA',
            'description' => self::FIRST,
        ]);
        foreach (['P1-40' => self::FIRST, 'P1-41' => self::SECOND] as $code => $text) {
            B2bProductLink::query()->create([
                'b2b_account_id' => $this->account->id,
                'remote_id' => $code,
                'product_id' => $card->id,
                'remote_sku' => $code,
                'remote_name' => 'Trzewik ARMEN',
                'manufacturer' => 'ARTRA',
                'description_hash' => sha1($text),
                'last_seen_at' => CarbonImmutable::parse('2026-01-01'),
            ]);
        }

        return $card;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function warningsAbout(string $code, array $result): array
    {
        $run = B2bSyncRun::query()->findOrFail($result['sync_run_id']);

        return array_values(array_filter(
            array_column(array_filter($run->log, static fn (array $row): bool => $row['level'] === 'warn'), 'text'),
            static fn (string $text): bool => str_starts_with($text, $code.':'),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function sync(B2bConnector $connector): array
    {
        return app(B2bAccountSyncRunner::class)->run($this->account->fresh(), delayMs: 0, connector: $connector);
    }
}

/** Witryna producenta bez grup rozmiarów — każdy kod to osobna pozycja z własnym opisem i ceną. */
final class SharedCardMfrConnector implements B2bConnector, B2bManufacturerSite
{
    private const DESCRIPTIONS = [
        'P1-40' => 'Trzewik ARTRA ARMEN z podnoskiem kompozytowym, cholewka ze skóry licowej, rozmiar 40.',
        'P1-41' => 'Trzewik ARTRA ARMEN S3 z wkładką antyprzebiciową, podeszwa PU/TPU, rozmiar 41.',
    ];

    private const PRICES = ['P1-40' => 120.0, 'P1-41' => 125.0];

    private const EANS = ['P1-40' => '5711074644834', 'P1-41' => '5901234123457'];

    public static function ownBrand(): string
    {
        return 'ARTRA';
    }

    public static function key(): string
    {
        return 'sharedcardmfr';
    }

    public static function label(): string
    {
        return 'Producent testowy (scalone rozmiary)';
    }

    public static function host(): string
    {
        return 'sharedcardmfr.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        foreach (array_keys(self::DESCRIPTIONS) as $code) {
            yield new B2bRemoteProduct(
                remoteId: $code,
                sku: $code,
                name: 'Trzewik ARMEN',
                sourceUrl: 'https://sharedcardmfr.example.test/p/'.$code,
                identifiers: [new B2bRemoteIdentifier(ProductIdentifier::TYPE_EAN, self::EANS[$code])],
            );
        }
    }

    public function totalProducts(): int
    {
        return count(self::DESCRIPTIONS);
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'ARTRA';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return new B2bRemotePrice(net: self::PRICES[$product->remoteId]);
    }

    public function description(B2bRemoteProduct $product): string
    {
        return self::DESCRIPTIONS[$product->remoteId];
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }
}
