<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\ManufacturerPart;
use App\Models\Product;
use App\Services\Enrichment\ManufacturerProfile;
use App\Services\Enrichment\PartsTable\PartsTablePin;
use App\Services\Enrichment\PartsTable\PartsTableResolver;
use App\Services\Enrichment\PartsTable\PartsTables;
use App\Services\Enrichment\PartsTable\PinResult;

/**
 * Atrapa resolvera tabeli części do testów przebiegu wzbogacania: przypięcie z mapy SKU => pin (albo powód
 * nierozwiązania), bez parsowania stron. install() wpina ją w profil Coby i zapisuje jeden wiersz manufacturer_parts
 * (PartsTables zwraca null, gdy marka nie ma żadnych wierszy).
 */
final class FakePartsTableResolver implements PartsTableResolver
{
    /** @var array<string, PartsTablePin|string> SKU => pin albo powód nierozwiązania */
    public static array $pins = [];

    /** @param  array<string, PartsTablePin|string>  $pins */
    public static function install(array $pins): void
    {
        self::$pins = $pins;
        config()->set('manufacturer_profiles.profiles.coba.resolver', self::class);
        if (! ManufacturerPart::query()->where('brand_key', 'coba')->exists()) {
            ManufacturerPart::query()->create([
                'brand_key' => 'coba',
                'page_url' => 'https://www.coba.com/pl/produkt/atrapa',
                'page_url_hash' => ManufacturerPart::hashFor('https://www.coba.com/pl/produkt/atrapa'),
                'part_code' => 'ATRAPA01',
                'part_label' => 'ATRAPA01',
                'page_sha' => sha1('atrapa'),
                'fetched_at' => now(),
            ]);
        }
        app(PartsTables::class)->flush();
    }

    public function parse(string $html, string $url): array
    {
        return ['title' => null, 'rows' => [], 'has_styles' => false];
    }

    public function pinFor(Product $product, ManufacturerProfile $profile, array $rows): PinResult
    {
        $pin = self::$pins[(string) $product->sku] ?? 'brak kodu w tabeli';

        return $pin instanceof PartsTablePin ? new PinResult($pin) : new PinResult(null, $pin);
    }

    /** Pin strony coba.com w kształcie z CobaPartsTable. */
    public static function pin(
        string $part,
        string $pageUrl,
        ?string $size = null,
        ?string $colour = null,
        ?float $weightKg = null,
        ?string $imageUrl = null,
        bool $viaShortCode = false,
        ?string $cardCode = null,
        ?string $pageTitle = 'Orthomat Standard',
    ): PartsTablePin {
        return new PartsTablePin(
            brandKey: 'coba',
            pageUrl: $pageUrl,
            pageKey: ManufacturerPart::pageKeyFor($pageUrl),
            pageTitle: $pageTitle,
            part: $part,
            size: $size,
            colour: $colour,
            weightKg: $weightKg,
            imageUrl: $imageUrl,
            imageReason: 'model',
            viaShortCode: $viaShortCode,
            cardCode: $cardCode,
        );
    }
}
