<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\CardMatchCandidate;
use App\Models\CardRedirect;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductPriceHistory;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRemoteShopField;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\Catalog\CardMatchFinder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * „Rozdziel” od początku do końca (plan łączenia kart, krok 7): zastane wiersze z przebiegów 3M (konto B2B, osobna
 * karta na każdy kolor hełmu) i P4S (jedna karta grupy z czterema kolorami), propozycja split z sygnałem koloru, decyzja
 * na ekranie, potem KOLEJNE przebiegi P4S i 3M — zwykła droga grupy przepinałaby rozdzielone kolory z powrotem na jedną
 * kartę albo zakładała kartę dystrybutora od nowa.
 * Przypadek z produkcji: P4S „2365X0 Hełm ochronny 3M SecureFit serii X5000VE-CE 1000V z wentylacją (LD)” (391,60 PLN)
 * z pozycjami 236510 biały, 236520 żółty, 236540 niebieski i 236570 czerwony; 3M ma kartę na każdy kolor po 269,99 PLN:
 * 7100175101 (X5001VE-CE), 7100175511 (X5002VE-CE), 7100175512 (X5003VE-CE), 7100175534 (X5005VE-CE).
 */
final class CardMatchSplitEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const P4S_SKU = '2365X0';

    private const P4S_NAME = 'Hełm ochronny 3M SecureFit serii X5000VE-CE 1000V z wentylacją (LD)';

    /** Pozycja P4S => [kolor, kod producenta, dostępność pozycji u P4S]; 236580 — piąty kolor, bez karty 3M. */
    private const POSITIONS = [
        '236510' => ['biały', '7100175101', 'Produkt dostępny'],
        '236520' => ['żółty', '7100175511', 'Produkt dostępny'],
        '236540' => ['niebieski', '7100175512', 'Produkt niedostępny'],
        '236570' => ['czerwony', '7100175534', 'Produkt dostępny'],
        '236580' => ['zielony', '7100175999', 'Produkt dostępny'],
    ];

    /** Kolory z kartą 3M — grupa P4S z chwili decyzji. */
    private const COLORS = ['236510', '236520', '236540', '236570'];

    private const GREEN = '236580';

    /** Kod producenta => model w nazwie karty 3M. */
    private const MODELS = [
        '7100175101' => 'X5001VE-CE',
        '7100175511' => 'X5002VE-CE',
        '7100175512' => 'X5003VE-CE',
        '7100175534' => 'X5005VE-CE',
    ];

    private User $admin;

    private B2bAccount $mmm;

    private B2bAccount $p4s;

    private SplitE2eProducerConnector $producer;

    private SplitE2eDistributorConnector $distributor;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'card-match-split-e2e-'.uniqid('', true);
        mkdir($this->storage.DIRECTORY_SEPARATOR.'app', 0775, true);
        $this->app->useStoragePath($this->storage);

        $this->admin = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($this->admin);
        $this->mmm = $this->account('mmm', '3m');
        $this->p4s = $this->account('p4s', 'p4s');
        $this->producer = new SplitE2eProducerConnector;
        $this->distributor = new SplitE2eDistributorConnector;
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_split_colors_stay_on_3m_cards_through_next_p4s_and_3m_runs(): void
    {
        [$candidate, $cards] = $this->splitColors();
        $expected = $this->ids($cards);

        // 3) dwa kolejne przebiegi P4S (drugi z nową ceną): grupa z wpisami „split” idzie trybem pojedynczym — każdy
        // kolor zostaje na karcie 3M swojego koloru, bez nowej karty i bez powrotu na jedną kartę
        foreach ([1 => 391.60, 2 => 399.00] as $pass => $price) {
            $when = 'przebieg P4S '.$pass.' po rozdzieleniu';
            $this->distributor->price = $price;
            $run = $this->syncDistributor();
            $this->assertSame(0, $run['created'], $when);
            $this->assertSame(0, $run['skipped'], $when);
            $this->assertSame([], $run['errors'], $when);
            $this->assertSame(4, Product::query()->count(), $when);
            $this->assertSame($expected, $this->p4sLinks(), $when);
            $this->assertSame($expected, $this->p4sIdentifiers(), $when);
            $this->assertSame([], array_values(array_filter(
                $this->logTexts($run),
                static fn (string $text): bool => str_contains($text, 'wraca na kartę'),
            )), $when);
            $money = number_format($price, 2, '.', '');
            $this->assertSame(array_fill(0, 4, $money), $this->p4sLinkPrices(), $when);
            foreach ($cards as $position => $card) {
                // cena grupy odświeżona na każdej karcie, dostępność już tylko tego koloru; slot 3M bez zmian
                $slot = $this->slot($card, $this->p4sKey());
                $this->assertSame($money, $slot->purchase_price, $when.': karta '.$card->sku);
                $this->assertSame(self::POSITIONS[$position][2], $slot->availability, $when.': karta '.$card->sku);
                $this->assertSame('269.99', $this->slot($card, $this->mmmKey())->purchase_price, $when.': karta '.$card->sku);
            }
            $this->assertProducerCards($cards, '269.99', $when);
        }

        // 4) P4S dodaje piąty kolor bez karty 3M i bez wpisu mapy — jedna nowa karta tej pozycji; cztery kolory zostają
        // na kartach 3M
        $this->distributor->items = [$this->helmetGroup([...self::COLORS, self::GREEN])];
        $run = $this->syncDistributor();
        $this->assertSame(1, $run['created']);
        $this->assertSame(0, $run['skipped']);
        $this->assertSame(5, Product::query()->count());
        $green = Product::query()->where('sku', self::GREEN)->sole();
        $expected += [self::GREEN => (int) $green->id];
        $this->assertSame($expected, $this->p4sLinks());
        $this->assertSame(self::P4S_NAME.', kolor zielony', $green->name);
        $this->assertSame('3M', $green->manufacturer);
        $this->assertNull($green->variant_summary);
        $this->assertSame('399.00', $this->slot($green, $this->p4sKey())->purchase_price);
        $this->assertProducerCards($cards, '269.99', 'przebieg P4S z piątym kolorem');

        // 5) przebieg 3M po rozdzieleniu (nowa cena producenta): 0 nowych kart, powiązania, identyfikatory i sloty P4S
        // na kartach 3M nietknięte; cenę kart dalej ustala konto producenta
        foreach (self::COLORS as $position) {
            $this->producer->prices[self::POSITIONS[$position][1]] = 279.99;
        }
        $run = $this->syncProducer();
        $this->assertSame(0, $run['created']);
        $this->assertSame(5, Product::query()->count());
        $this->assertSame($expected, $this->p4sLinks());
        $this->assertSame($expected, $this->p4sIdentifiers());
        foreach ($cards as $card) {
            $this->assertSame('279.99', $this->slot($card, $this->mmmKey())->purchase_price, 'karta '.$card->sku);
            $this->assertSame('399.00', $this->slot($card, $this->p4sKey())->purchase_price, 'karta '.$card->sku);
            $this->assertSame(1, B2bProductLink::query()->where('b2b_account_id', $this->p4s->id)->where('product_id', $card->id)->count());
        }
        $this->assertProducerCards($cards, '279.99', 'przebieg 3M po rozdzieleniu');

        // 6) odświeżenie propozycji po wszystkim: karty 3M z pozycjami P4S nie wracają jako propozycja, ślad decyzji zostaje
        app(CardMatchFinder::class)->refresh();
        $this->assertSame([], CardMatchCandidate::query()
            ->whereIn('status', [CardMatchCandidate::STATUS_PENDING, CardMatchCandidate::STATUS_CONFLICT])
            ->get()
            ->map(static fn (CardMatchCandidate $c): string => $c->kind.' #'.$c->source_product_id.' → '.$c->targets_key.': '.$c->reason)
            ->all());
        $this->assertSame(CardMatchCandidate::STATUS_MERGED, CardMatchCandidate::query()->find($candidate->id)?->status);
    }

    /**
     * Zastane wiersze i decyzja: przebieg 3M (cztery karty kolorów z opisem, ceną i kodem producenta), przebieg P4S
     * (karta 2365X0 z czterema powiązaniami, slotem, opisem i tabelką sklepu), odświeżenie propozycji i „Rozdziel”.
     *
     * @return array{0: CardMatchCandidate, 1: array<int, Product>} propozycja po decyzji i karty 3M po pozycji P4S
     */
    private function splitColors(): array
    {
        // 1) 3M: osobna karta na każdy kolor
        $this->producer->items = array_map(fn (string $position): B2bRemoteProduct => $this->producerPosition($position), self::COLORS);
        foreach (self::COLORS as $position) {
            $code = self::POSITIONS[$position][1];
            $this->producer->prices[$code] = 269.99;
            $this->producer->descriptions[$code] = $this->producerDescription($position);
        }
        $this->assertSame(4, $this->syncProducer()['created']);
        $cards = [];
        foreach (self::COLORS as $position) {
            $cards[$position] = Product::query()->where('sku', self::POSITIONS[$position][1])->sole();
        }

        // P4S: jedna karta grupy z czterema kolorami — kod producenta i etykieta koloru przy każdej pozycji
        $this->distributor->items = [$this->helmetGroup(self::COLORS)];
        $this->assertSame(1, $this->syncDistributor()['created']);
        $this->assertSame(5, Product::query()->count());
        $source = Product::query()->where('sku', self::P4S_SKU)->sole();
        $this->assertSame(array_fill_keys(self::COLORS, (int) $source->id), $this->p4sLinks());
        $this->assertProducerCards($cards, '269.99', 'przed rozdzieleniem');

        app(CardMatchFinder::class)->refresh();
        $candidate = CardMatchCandidate::query()->where('source_product_id', $source->id)->sole();
        $this->assertSame(CardMatchCandidate::KIND_SPLIT, $candidate->kind);
        $this->assertSame(CardMatchCandidate::STATUS_PENDING, $candidate->status, (string) $candidate->reason);
        $this->assertSame(CardMatchCandidate::SIGNAL_COLOR, $candidate->plan['signal']);
        $ids = array_values($this->ids($cards));
        $labels = array_map(static fn (string $position): string => 'kolor '.self::POSITIONS[$position][0], self::COLORS);
        $this->assertSame($ids, array_column($candidate->plan['positions'], 'target_product_id'));
        $this->assertSame($labels, array_column($candidate->plan['positions'], 'label'));

        // karta dystrybutora przed decyzją: slot i tabelka konta P4S mają przejść na każdą kartę 3M
        $sourceSlot = $this->slot($source, $this->p4sKey());
        $this->assertSame('391.60', $sourceSlot->purchase_price);
        $this->assertSame('Produkt dostępny: kolor biały, kolor żółty, kolor czerwony; Produkt niedostępny: kolor niebieski', $sourceSlot->availability);
        $shopFields = ProductShopCard::query()->where('product_id', $source->id)->where('b2b_account_id', $this->p4s->id)->first()?->fields;
        $this->assertNotEmpty($shopFields);

        // 2) „Rozdziel” godzinę po przebiegu — checked_at slotu P4S ma przyjść z karty dystrybutora, nie z chwili decyzji
        $this->travel(1)->hours();
        $response = $this->postJson('/api/card-matches/'.$candidate->id.'/split', [
            'plan_hash' => $candidate->plan_hash,
            'confirm_split' => true,
        ]);

        $sorted = $ids;
        sort($sorted);
        $response->assertOk()
            ->assertJsonPath('status', CardMatchCandidate::STATUS_MERGED)
            ->assertJsonPath('kind', CardMatchCandidate::KIND_SPLIT)
            ->assertJsonPath('signal', CardMatchCandidate::SIGNAL_COLOR)
            ->assertJsonPath('source', null)
            ->assertJsonPath('source_snapshot.sku', self::P4S_SKU)
            ->assertJsonPath('source_snapshot.name', self::P4S_NAME)
            ->assertJsonPath('decided_by.id', (int) $this->admin->id)
            ->assertJsonPath('decision_input.kind', CardMatchCandidate::KIND_SPLIT)
            ->assertJsonPath('decision_input.plan_hash', $candidate->plan_hash)
            ->assertJsonPath('decision_input.source_product_id', (int) $source->id)
            ->assertJsonPath('decision_input.target_product_ids', $sorted)
            // cena kart 3M bez zmian — producent ma pierwszeństwo przed P4S
            ->assertJsonPath('decision_input.card_changes', [])
            ->assertJsonPath('decision_input.slots_replaced', [])
            ->assertJsonPath('decision_input.redirects_orphaned', [])
            ->assertJsonPath('decision_input.identifiers_moved', 4);
        $this->assertNotNull($response->json('decided_at'));
        $positions = $response->json('decision_input.positions');
        $this->assertSame(self::COLORS, array_column($positions, 'position_key'));
        $this->assertSame(self::COLORS, array_column($positions, 'remote_sku'));
        $this->assertSame($labels, array_column($positions, 'label'));
        $this->assertSame($ids, array_column($positions, 'target_product_id'));
        $this->assertSame(
            array_map(static fn (string $position): string => self::POSITIONS[$position][1], self::COLORS),
            array_column($positions, 'target_sku'),
        );
        $this->assertSame(array_fill(0, 4, $this->p4sKey()), array_column($positions, 'source_key'));
        $cardsBefore = $response->json('decision_input.cards_before');
        $this->assertSame([(int) $source->id, ...$sorted], array_column($cardsBefore, 'id'));
        $this->assertSame(self::P4S_SKU, $cardsBefore[0]['sku'] ?? null);

        // karta dystrybutora usunięta; każda pozycja P4S (powiązanie i identyfikatory) na karcie 3M swojego koloru
        $expected = $this->ids($cards);
        $this->assertNull(Product::query()->find($source->id));
        $this->assertSame(4, Product::query()->count());
        $this->assertSame($expected, $this->p4sLinks());
        $this->assertFalse(B2bProductLink::query()->where('b2b_account_id', $this->p4s->id)->whereNotNull('merged_at')->exists());
        $this->assertSame($expected, $this->p4sIdentifiers());

        // mapa połączeń: wiersz na pozycję, powód „split”, bez pozycji wiodącej
        $rows = CardRedirect::query()->orderBy('position_key')->get();
        $this->assertSame($expected, $rows->pluck('product_id', 'position_key')->map(static fn ($id): int => (int) $id)->all());
        $this->assertSame([$this->p4sKey()], $rows->pluck('source_key')->unique()->values()->all());
        $this->assertSame([CardRedirect::REASON_SPLIT], $rows->pluck('reason')->unique()->values()->all());
        $this->assertSame([false], $rows->pluck('is_anchor')->unique()->values()->all());

        // slot, historia ceny i tabelka konta P4S na każdej karcie 3M; slot 3M, nazwa, opis i cena karty bez zmian
        foreach ($cards as $card) {
            $slot = $this->slot($card, $this->p4sKey());
            $this->assertSame('391.60', $slot->purchase_price, 'karta '.$card->sku);
            $this->assertSame(
                $sourceSlot->checked_at?->toDateTimeString(),
                $slot->checked_at?->toDateTimeString(),
                'karta '.$card->sku.': checked_at slotu P4S z karty dystrybutora',
            );
            // cztery kolory na karcie dystrybutora — dostępność grupy nie opisuje jednego koloru
            $this->assertNull($slot->availability, 'karta '.$card->sku);
            $this->assertSame('269.99', $this->slot($card, $this->mmmKey())->purchase_price, 'karta '.$card->sku);
            $this->assertSame(
                ['269.99', '391.60'],
                ProductPriceHistory::query()->where('product_id', $card->id)->orderBy('id')->pluck('purchase_price')->all(),
                'karta '.$card->sku.': historia cen',
            );
            $this->assertSame(
                $shopFields,
                ProductShopCard::query()->where('product_id', $card->id)->where('b2b_account_id', $this->p4s->id)->first()?->fields,
                'karta '.$card->sku.': tabelka sklepu P4S',
            );
        }
        $this->assertProducerCards($cards, '269.99', 'po rozdzieleniu');

        // propozycja połączona, z kopią zapasową
        $merged = CardMatchCandidate::query()->findOrFail($candidate->id);
        $this->assertSame(CardMatchCandidate::STATUS_MERGED, $merged->status);
        $this->assertSame((int) $this->admin->id, (int) $merged->decided_by);
        $this->assertFileExists((string) $merged->backup_path);
        $this->assertStringContainsString('card-match-split-'.$candidate->id.'-', (string) $merged->backup_path);

        return [$merged, $cards];
    }

    /**
     * Karty 3M: kod, nazwa, producent i opis producenta bez zmian, bez listy kolorów grupy P4S, cena karty z konta 3M.
     *
     * @param  array<int, Product>  $cards  pozycja P4S → karta 3M
     */
    private function assertProducerCards(array $cards, string $price, string $when): void
    {
        foreach ($cards as $position => $card) {
            $fresh = Product::query()->find($card->id);
            $this->assertNotNull($fresh, $when.': karta #'.$card->id.' usunięta');
            $this->assertSame(
                [
                    self::POSITIONS[$position][1],
                    $this->producerName((string) $position),
                    '3M',
                    $this->producerDescription((string) $position),
                    null,
                    $price,
                ],
                [$fresh->sku, $fresh->name, $fresh->manufacturer, $fresh->description, $fresh->variant_summary, $fresh->purchase_price],
                $when.': karta '.$card->sku,
            );
        }
    }

    /**
     * Grupa P4S „2365X0”: pozycja na kolor z kodem P4S, nazwą i dostępnością pozycji, kod producenta z etykietą koloru.
     *
     * @param  list<string>  $positions
     */
    private function helmetGroup(array $positions): B2bRemoteProduct
    {
        $members = [];
        $identifiers = [];
        $availability = [];
        foreach ($positions as $position) {
            [$color, $code, $status] = self::POSITIONS[$position];
            $members[] = ['remote_id' => $position, 'sku' => $position, 'name' => self::P4S_NAME.', kolor '.$color, 'availability' => $status];
            $identifiers[] = new B2bRemoteIdentifier(
                type: ProductIdentifier::TYPE_MANUFACTURER_CODE,
                value: $code,
                remoteId: $position,
                label: 'kolor '.$color,
                field: 'manufacturerCode',
            );
            $availability[$status][] = 'kolor '.$color;
        }

        return new B2bRemoteProduct(
            remoteId: $members[0]['remote_id'],
            sku: self::P4S_SKU,
            name: self::P4S_NAME,
            availability: implode('; ', array_map(
                static fn (string $status, array $colors): string => $status.': '.implode(', ', $colors),
                array_keys($availability),
                $availability,
            )),
            variantSummary: 'Kolory: '.implode('; ', array_map(static fn (string $position): string => self::POSITIONS[$position][0], $positions)),
            members: $members,
            identifiers: $identifiers,
        );
    }

    private function producerPosition(string $position): B2bRemoteProduct
    {
        $code = self::POSITIONS[$position][1];

        return new B2bRemoteProduct(
            remoteId: $code,
            sku: $code,
            name: $this->producerName($position),
            identifiers: [new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MANUFACTURER_CODE, value: $code, remoteId: $code, field: 'code')],
        );
    }

    private function producerName(string $position): string
    {
        [$color, $code] = self::POSITIONS[$position];

        return 'Hełm ochronny 3M™ SecureFit™ X5000, wentylowany, 1000 V, CE, '.$color.', '.self::MODELS[$code];
    }

    private function producerDescription(string $position): string
    {
        [$color, $code] = self::POSITIONS[$position];

        return 'Opis 3M pozycji '.$code.' — hełm SecureFit X5000 z wentylacją, kolor '.$color.'.';
    }

    /**
     * @param  array<int, Product>  $cards
     * @return array<int, int> pozycja P4S → id karty
     */
    private function ids(array $cards): array
    {
        return array_map(static fn (Product $card): int => (int) $card->id, $cards);
    }

    /**
     * @return array<int, int> powiązania konta P4S: pozycja → karta
     */
    private function p4sLinks(): array
    {
        return B2bProductLink::query()
            ->where('b2b_account_id', $this->p4s->id)
            ->orderBy('remote_id')
            ->pluck('product_id', 'remote_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<string|null> ostatnia cena przy każdym powiązaniu P4S, w kolejności pozycji
     */
    private function p4sLinkPrices(): array
    {
        return B2bProductLink::query()
            ->where('b2b_account_id', $this->p4s->id)
            ->orderBy('remote_id')
            ->pluck('last_purchase_price')
            ->all();
    }

    /**
     * @return array<int, int> aktywne identyfikatory konta P4S: pozycja → karta
     */
    private function p4sIdentifiers(): array
    {
        return ProductIdentifier::query()
            ->where('source_key', $this->p4sKey())
            ->whereNull('removed_at')
            ->orderBy('position_key')
            ->pluck('product_id', 'position_key')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function slot(Product $card, string $sourceKey): ProductSourcePrice
    {
        $slot = ProductSourcePrice::query()->where('product_id', $card->id)->where('source_key', $sourceKey)->first();
        $this->assertNotNull($slot, 'karta '.$card->sku.': brak slotu '.$sourceKey);

        return $slot;
    }

    private function p4sKey(): string
    {
        return ProductSourcePrice::b2bKey((int) $this->p4s->id);
    }

    private function mmmKey(): string
    {
        return ProductSourcePrice::b2bKey((int) $this->mmm->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function syncProducer(): array
    {
        return app(B2bAccountSyncRunner::class)->run($this->mmm->fresh(), delayMs: 0, connector: $this->producer);
    }

    /**
     * @return array<string, mixed>
     */
    private function syncDistributor(): array
    {
        return app(B2bAccountSyncRunner::class)->run($this->p4s->fresh(), delayMs: 0, connector: $this->distributor);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function logTexts(array $result): array
    {
        return array_column(B2bSyncRun::query()->findOrFail($result['sync_run_id'])->log, 'text');
    }

    private function account(string $username, string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $username, 'password' => 'sekret', 'sites' => ['b2b.'.$connector.'.example.test'], 'connector' => $connector,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
        ]);
    }
}

/** Łącznik testowy producenta jak 3M: pozycje pojedyncze (kod pozycji = kod producenta), cena i opis według pozycji. */
final class SplitE2eProducerConnector implements B2bConnector, B2bManufacturerSite
{
    /** @var list<B2bRemoteProduct> */
    public array $items = [];

    /** @var array<string, float> */
    public array $prices = [];

    /** @var array<string, string> */
    public array $descriptions = [];

    public static function key(): string
    {
        return 'split-e2e-producer-fake';
    }

    public static function label(): string
    {
        return 'Producent — test rozdzielania';
    }

    public static function host(): string
    {
        return 'split-producer.example.test';
    }

    public static function ownBrand(): string
    {
        return '3M';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        yield from $this->items;
    }

    public function totalProducts(): int
    {
        return count($this->items);
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return '3M';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        $net = $this->prices[$product->remoteId] ?? null;

        return $net !== null ? new B2bRemotePrice(net: $net) : null;
    }

    public function description(B2bRemoteProduct $product): string
    {
        return $this->descriptions[$product->remoteId] ?? '';
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }
}

/**
 * Łącznik testowy P4S: grupa hełmów z pozycją na kolor w jednej cenie, opis i tabelka sklepu grupy. Opis dystrybutora
 * nie może trafić na kartę producenta po rozdzieleniu.
 */
final class SplitE2eDistributorConnector implements B2bConnector, B2bShopFieldSource
{
    /** @var list<B2bRemoteProduct> */
    public array $items = [];

    public float $price = 391.60;

    public static function key(): string
    {
        return 'split-e2e-distributor-fake';
    }

    public static function label(): string
    {
        return 'Dystrybutor — test rozdzielania';
    }

    public static function host(): string
    {
        return 'split-distributor.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        yield from $this->items;
    }

    public function totalProducts(): int
    {
        return count($this->items);
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return '3M';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return new B2bRemotePrice(net: $this->price);
    }

    public function description(B2bRemoteProduct $product): string
    {
        return 'Opis P4S grupy '.$product->sku.' — hełm 3M SecureFit X5000 z wentylacją w kilku kolorach.';
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }

    public function shopFields(B2bRemoteProduct $product): array
    {
        return [
            new B2bRemoteShopField('Informacje', 'Kod P4S', $product->sku),
            new B2bRemoteShopField('Informacje', 'Jednostka', 'szt.'),
        ];
    }
}
