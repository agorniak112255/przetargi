<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Models\ProductImage;
use App\Support\ColourWords;
use Illuminate\Support\Str;

/**
 * Zdjęcie dla członka modelu (etap 2 opisów z cenników). Strona modelu coba.com pokazuje zwykle jeden kolor (czarny),
 * a w nazwach plików galerii bywa kolor („…-workplace-matting-black-1.jpg”, „Gray.jpg”) — członek z kolorami karty
 * (PriceListCardFacts: zbiór kolorów kanonicznych) dostaje do pobrania adres, którego nazwa pliku ma ten sam zbiór
 * kolorów (ColourWords::sameSet — karta dwubarwna „Czarny/Niebieski” nie bierze pliku „…-black.jpg”, a jednobarwna
 * „Czarny” pliku „…-black-blue.jpg”). Galeria stron lidera (page_image_urls) to wszystkie `<img>` tych stron, także
 * cudzych wyrobów, więc adres liczy się tylko, gdy nazwa pliku zawiera słowo rdzenia modelu („orthomat” z „Orthomat
 * Standard”; bez rdzenia — bez tego warunku); adres bez słowa rdzenia nie liczy się wcale, nawet jako „inny kolor”
 * („cobagrip-grey.jpg” na stronie Orthomata nie idzie na szarą kartę Orthomata). Gdy adresy z rdzeniem mówią tylko
 * o innych kolorach — żadnego nowego zdjęcia (grupa 2 pomiaru „przed”: 57 kart ze zdjęciem w innym kolorze); gdy nie
 * mówią o kolorze — kopia pierwszego zdjęcia lidera bez sieci (ProductWebFileCopier). Kolory kopii lidera to kolory
 * z nazwy jego pliku, a bez nich kolory z nazwy karty lidera — zdjęcie lidera „Czarny/Brązowy” nie idzie na kartę
 * „Czarny/Niebieski”, lider bez żadnego koloru oddaje kopię. Karta bez koloru bierze kopię zdjęcia lidera.
 */
final class ModelImagePicker
{
    public const REASON_PAGE_IN_COLOUR = 'zdjęcie strony w kolorze karty';

    public const REASON_PAGE_OTHER_COLOUR = 'zdjęcie strony w innym kolorze';

    public const REASON_LEADER_OTHER_COLOUR = 'zdjęcie lidera w innym kolorze';

    public const REASON_LEADER_COPY = 'kopia zdjęcia lidera';

    public const REASON_LEADER_NONE = 'lider bez zdjęcia';

    /**
     * @param  list<string>  $memberColours  kolory karty członka — kanoniczne (PriceListCardFacts::for()['colours']); słowo
     *                                       z nazwy też przejdzie, słowo spoza słownika nie liczy się; [] = karta bez koloru
     * @param  list<string>  $pageImageUrls  adresy zdjęć ze stron źródeł lidera (_version.page_image_urls), zaufane pierwsze
     * @param  list<ProductImage>  $leaderImages  zdjęcia lidera w kolejności karty
     * @param  string|null  $modelStem  rdzeń nazwy modelu (ModelKey::$stem) — adres galerii musi mieć jego pierwsze słowo
     *                                  w nazwie pliku; null albo pusty = bez tego warunku
     * @return array{url: ?string, copy_of: ?ProductImage, reason: string} url — do pobrania (downloadMany);
     *                                                                     copy_of — do skopiowania; oba null = bez zdjęcia
     */
    public function pickFor(Product $member, array $memberColours, array $pageImageUrls, array $leaderImages, ?string $modelStem): array
    {
        $colours = self::canonicalSet($memberColours);

        if ($colours !== []) {
            $stemWord = self::stemWord($modelStem);
            $otherColours = false;
            foreach ($pageImageUrls as $url) {
                if (! is_string($url) || ! str_starts_with($url, 'http')) {
                    continue;
                }
                if ($stemWord !== null && ! str_contains(self::fileName($url), $stemWord)) {
                    continue;
                }
                $inFile = ColourWords::allInUrl($url);
                if ($inFile === []) {
                    continue;
                }
                if (ColourWords::sameSet($inFile, $colours)) {
                    return ['url' => $url, 'copy_of' => null, 'reason' => self::REASON_PAGE_IN_COLOUR];
                }
                $otherColours = true;
            }
            if ($otherColours) {
                return ['url' => null, 'copy_of' => null, 'reason' => self::REASON_PAGE_OTHER_COLOUR];
            }
        }

        $first = null;
        foreach ($leaderImages as $image) {
            if ($image instanceof ProductImage) {
                $first = $image;
                break;
            }
        }
        if ($first === null) {
            return ['url' => null, 'copy_of' => null, 'reason' => self::REASON_LEADER_NONE];
        }
        if ($colours !== []) {
            $leaderColours = ColourWords::allInUrl((string) $first->source_url);
            if ($leaderColours === []) {
                $leaderColours = $this->leaderNameColours($first);
            }
            if ($leaderColours !== [] && ! ColourWords::sameSet($leaderColours, $colours)) {
                return ['url' => null, 'copy_of' => null, 'reason' => self::REASON_LEADER_OTHER_COLOUR];
            }
        }

        return ['url' => null, 'copy_of' => $first, 'reason' => self::REASON_LEADER_COPY];
    }

    /**
     * Kolory karty jako zbiór kanoniczny bez powtórzeń („Szary” → „grey”); słowo spoza słownika kolorów nie jest
     * kolorem (jak w PriceListCardFacts) — karta tylko z takim słowem liczy się jak karta bez koloru.
     *
     * @param  list<string>  $colours
     * @return list<string>
     */
    private static function canonicalSet(array $colours): array
    {
        $out = [];
        foreach ($colours as $colour) {
            $canonical = is_string($colour) ? ColourWords::canonical($colour) : null;
            if ($canonical !== null && ! in_array($canonical, $out, true)) {
                $out[] = $canonical;
            }
        }

        return $out;
    }

    /**
     * Pierwsze słowo rdzenia dłuższe niż 2 znaki, małymi literami bez polskich znaków („Orthomat Standard” → „orthomat”,
     * „COBAGRiP Krata GRP” → „cobagrip”, „Krawędź/narożnik 'męski'” → „krawedz”); null = rdzeń pusty albo bez takiego słowa.
     */
    private static function stemWord(?string $stem): ?string
    {
        foreach (preg_split('/[^\p{L}\p{N}]+/u', (string) $stem, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $folded = mb_strtolower(Str::ascii($word), 'UTF-8');
            if (mb_strlen($folded, 'UTF-8') > 2) {
                return $folded;
            }
        }

        return null;
    }

    /** Nazwa pliku z adresu (bez katalogów i zapytania), małymi literami bez polskich znaków — do szukania słowa rdzenia. */
    private static function fileName(string $url): string
    {
        $path = (string) (parse_url(trim($url), PHP_URL_PATH) ?? '');

        return mb_strtolower(Str::ascii(rawurldecode(basename($path))), 'UTF-8');
    }

    /**
     * Kolory z nazwy karty lidera (właściciela zdjęcia); relacja już załadowana nie kosztuje zapytania.
     *
     * @return list<string>
     */
    private function leaderNameColours(ProductImage $image): array
    {
        $name = $image->relationLoaded('product')
            ? (string) ($image->product?->name ?? '')
            : (string) (Product::query()->whereKey((int) $image->product_id)->value('name') ?? '');

        return $name !== '' ? ColourWords::allInName($name) : [];
    }
}
