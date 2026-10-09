<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use App\Services\Enrichment\PartsTable\PartsTableImages;
use App\Services\Enrichment\PartsTable\PartsTablePin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Zdjęcia karty przypiętej do tabeli części coba.com (decyzja właściciela 10.10.2026): zdjęcie z tabeli główne,
 * zdjęcia z internetu spoza coba.com i banery odrzucone na stałe, inne zdjęcia coba.com usunięte zwykle, zdjęcia
 * z konta B2B i wgrane ręcznie zostają (tylko nie są główne). Stan z pomiaru kart cennika 14: icd.pl, baner
 * „Stand up for health”, zdjęcie P4S jako główne.
 */
final class PartsTableImagesTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE_IMAGE = 'https://www.coba.com/pl/wp-content/uploads/sites/6/2020/02/af-orthomat-standard-workplace-matting-style-safety-2-750x750.jpg';

    /** ProductImageDownloader::preferFullSizeUrl pobiera plik bez „-750x750” (miniatura WordPressa) i pod nim zapisuje */
    private const TABLE_IMAGE_FILE = 'https://www.coba.com/pl/wp-content/uploads/sites/6/2020/02/af-orthomat-standard-workplace-matting-style-safety-2.jpg';

    private const OLD_COBA = 'https://www.coba.com/pl/wp-content/uploads/sites/6/2020/02/af-orthomat-standard-workplace-matting-black-1.jpg';

    private const FOREIGN = 'https://icd.pl/media/catalog/product/orthomat.jpg';

    private const BANNER = 'https://www.coba.com/pl/wp-content/uploads/sites/6/2023/05/StandUpforHealth-PL.png';

    private const B2B = 'https://b2b.p4s.pl/img/orthomat-af0107.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_table_image_becomes_primary_foreign_and_banner_rejected_b2b_and_manual_stay(): void
    {
        Http::fake([self::TABLE_IMAGE_FILE => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
        [$card, $ids] = $this->cardWithGallery();

        $result = app(PartsTableImages::class)->apply($card, $this->pin());

        $table = ProductImage::query()->where('product_id', $card->id)->where('source_url', self::TABLE_IMAGE_FILE)->firstOrFail();
        $this->assertSame((int) $table->id, $result['downloaded']);
        $this->assertTrue((bool) $table->is_primary);
        $this->assertSame(0, (int) $table->sort_order);
        Storage::disk('public')->assertExists($table->path);

        $removed = array_column($result['removed'], 'reason', 'id');
        $this->assertSame([
            $ids['coba'] => PartsTableImages::REASON_OTHER_MANUFACTURER,
            $ids['foreign'] => PartsTableImages::REASON_FOREIGN,
            $ids['banner'] => PartsTableImages::REASON_BANNER,
        ], $removed);
        $this->assertEqualsCanonicalizing([$ids['b2b'], $ids['manual']], $result['kept']);

        $left = ProductImage::query()->where('product_id', $card->id)->orderBy('sort_order')->get();
        $this->assertSame([(int) $table->id, $ids['b2b'], $ids['manual']], $left->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([true, false, false], $left->pluck('is_primary')->map(fn ($p) => (bool) $p)->all());
        $this->assertSame([0, 1, 2], $left->pluck('sort_order')->map(fn ($s) => (int) $s)->all());

        // spoza coba.com i baner nie wrócą przy następnym przebiegu; zwykłe zdjęcie coba.com nie jest odrzucone
        $rejected = ProductImageRejection::query()->where('product_id', $card->id)->pluck('reason', 'source_url')->all();
        $this->assertSame([self::FOREIGN => ProductImageRejection::REASON_PARTS_TABLE, self::BANNER => ProductImageRejection::REASON_PARTS_TABLE], $rejected);

        // drugi raz bez zmian i bez ponownego pobierania
        $again = app(PartsTableImages::class)->apply($card, $this->pin());
        $this->assertSame((int) $table->id, $again['downloaded']);
        $this->assertSame([], $again['removed']);
        $this->assertSame(3, ProductImage::query()->where('product_id', $card->id)->count());
        Http::assertSentCount(1);
    }

    public function test_dry_run_changes_nothing(): void
    {
        Http::fake();
        [$card, $ids] = $this->cardWithGallery();
        $before = ProductImage::query()->orderBy('id')->get(['id', 'is_primary', 'sort_order'])->toArray();

        $result = app(PartsTableImages::class)->apply($card, $this->pin(), dryRun: true);

        $this->assertNull($result['downloaded']);
        $this->assertEqualsCanonicalizing([$ids['coba'], $ids['foreign'], $ids['banner']], array_column($result['removed'], 'id'));
        $this->assertSame($before, ProductImage::query()->orderBy('id')->get(['id', 'is_primary', 'sort_order'])->toArray());
        $this->assertSame(0, ProductImageRejection::query()->count());
        Http::assertNothingSent();
    }

    /** Zdjęcie z tabeli się nie pobrało: obce i baner znikają, zdjęcie coba.com zostaje (karta nie zostaje bez zdjęcia producenta). */
    public function test_failed_table_download_keeps_other_manufacturer_images(): void
    {
        Http::fake([self::TABLE_IMAGE_FILE => Http::response('', 404)]);
        [$card, $ids] = $this->cardWithGallery();

        $result = app(PartsTableImages::class)->apply($card, $this->pin());

        $this->assertNull($result['downloaded']);
        // przegląd 10.10: bez zdjęcia z tabeli na karcie zdjęcie spoza producenta zostaje (bez odrzucenia sumy kontrolnej —
        // sklep bywa z tym samym plikiem co coba.com); baner znika zawsze
        $this->assertEqualsCanonicalizing([$ids['banner']], array_column($result['removed'], 'id'));
        $this->assertEqualsCanonicalizing(
            [$ids['coba'], $ids['foreign'], $ids['b2b'], $ids['manual']],
            ProductImage::query()->where('product_id', $card->id)->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
        $this->assertSame(1, ProductImageRejection::query()->count(), 'odrzucony tylko baner');
        $this->assertSame(1, ProductImage::query()->where('product_id', $card->id)->where('is_primary', true)->count());
    }

    /**
     * @return array{0: Product, 1: array{coba: int, foreign: int, banner: int, b2b: int, manual: int}}
     */
    private function cardWithGallery(): array
    {
        $card = Product::query()->create([
            'sku' => 'AF0107', 'name' => 'Orthomat Standard Czarny/Żółte krawędzie 1.2m x 18.3m (9.5mm)', 'manufacturer' => 'Coba',
            'catalog_price_net' => 10, 'purchase_price' => 8, 'stock' => 1,
        ]);
        $account = B2bAccount::query()->create(['username' => 'p4s@example.com', 'password' => 'sekret', 'sites' => ['https://b2b.p4s.pl']]);
        $ids = [];
        foreach ([
            'b2b' => [self::B2B, $account->id],
            'coba' => [self::OLD_COBA, null],
            'foreign' => [self::FOREIGN, null],
            'banner' => [self::BANNER, null],
            'manual' => [null, null],
        ] as $key => [$url, $accountId]) {
            $position = count($ids);
            $ids[$key] = (int) ProductImage::query()->create([
                'product_id' => $card->id, 'b2b_account_id' => $accountId, 'path' => 'products/'.$card->id.'/'.$key.'.jpg',
                'source_url' => $url, 'is_primary' => $position === 0, 'sort_order' => $position,
                'checksum' => hash('sha256', $key),
            ])->id;
        }

        return [$card, $ids];
    }

    private function pin(): PartsTablePin
    {
        return new PartsTablePin(
            brandKey: 'coba',
            pageUrl: 'https://www.coba.com/pl/produkt/orthomat',
            pageKey: 'orthomat',
            pageTitle: 'Orthomat® Standard',
            part: 'AF010706',
            size: '1,2 m x 18,3 m',
            colour: 'Czarny/Żółty',
            weightKg: 70.0,
            imageUrl: self::TABLE_IMAGE,
            imageReason: 'style',
            viaShortCode: true,
            cardCode: 'AF0107',
        );
    }

    private function jpeg(): string
    {
        $im = imagecreatetruecolor(400, 400);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 30, 30));
        ob_start();
        imagejpeg($im, null, 85);
        imagedestroy($im);

        return (string) ob_get_clean();
    }
}
