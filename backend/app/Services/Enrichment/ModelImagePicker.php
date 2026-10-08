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
 * cudzych wyrobów, więc adres liczy się tylko, gdy nazwa pliku mówi o modelu (fileNameNamesModel): zawiera słowo
 * rdzenia modelu („orthomat” z „Orthomat Standard”) ALBO — tylko na hoście producenta z profilu karty — składa się
 * wyłącznie ze słów koloru (nie samego „clear”/„transparent”), cyfr i tokenów technicznych („Yellow-1.jpg”, „Gray.jpg”,
 * „black-grey-2-scaled.jpg” — tak coba.com nazywa zdjęcia wariantów kolorystycznych na stronie modelu; bez rdzenia —
 * bez tego warunku). Próbki kolorów („/swatch/”) i małe miniatury („-300x300.jpg”) nie liczą się nigdy
 * (isSwatchOrThumbnail). Adres z innym słowem wyrobu nie liczy się wcale,
 * nawet jako „inny kolor” („cobagrip-grey.jpg” na stronie Orthomata nie idzie na szarą kartę Orthomata). Gdy liczące
 * się adresy mówią tylko o innych kolorach — żadnego nowego zdjęcia (grupa 2 pomiaru „przed”: 57 kart ze zdjęciem
 * w innym kolorze); gdy nie mówią o kolorze — kopia pierwszego zdjęcia lidera bez sieci (ProductWebFileCopier). Kolory
 * kopii lidera to kolory z nazwy jego pliku, a bez nich kolory z nazwy karty lidera — zdjęcie lidera „Czarny/Brązowy”
 * nie idzie na kartę „Czarny/Niebieski”, lider bez żadnego koloru oddaje kopię. Karta bez koloru bierze kopię zdjęcia
 * lidera.
 */
final class ModelImagePicker
{
    public const REASON_PAGE_IN_COLOUR = 'zdjęcie strony w kolorze karty';

    public const REASON_PAGE_OTHER_COLOUR = 'zdjęcie strony w innym kolorze';

    public const REASON_LEADER_OTHER_COLOUR = 'zdjęcie lidera w innym kolorze';

    public const REASON_LEADER_COPY = 'kopia zdjęcia lidera';

    public const REASON_LEADER_NONE = 'lider bez zdjęcia';

    /**
     * Tokeny nazwy pliku, które nie mówią o wyrobie: rozszerzenia i dopiski techniczne (WordPress: „-1000x1000”,
     * „-scaled”, „-e1696234567”; „img”, „photo”, „thumb”). Litery porównujemy po fileName() — małe, bez polskich znaków.
     */
    private const FILE_NAME_TECH_TOKENS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'img', 'image', 'photo', 'scaled', 'thumb', 'large', 'small', 'min', 'x', 'e', 's'];

    /** Miniatura: rozmiar w nazwie pliku („-300x300.”) albo katalog rozmiaru („/265x265/”) mniejszy z obu stron niż ten. */
    private const THUMBNAIL_MAX_SIDE = 600;

    /** null = profil z kontenera przy pierwszym użyciu (testy tworzą klasę przez `new` bez argumentów). */
    public function __construct(private ?ManufacturerProfiles $profiles = null) {}

    /**
     * @param  list<string>  $memberColours  kolory karty członka — kanoniczne (PriceListCardFacts::for()['colours']); słowo
     *                                       z nazwy też przejdzie, słowo spoza słownika nie liczy się; [] = karta bez koloru
     * @param  list<string>  $pageImageUrls  adresy zdjęć ze stron źródeł lidera (_version.page_image_urls), zaufane pierwsze
     * @param  list<ProductImage>  $leaderImages  zdjęcia lidera w kolejności karty
     * @param  string|null  $modelStem  rdzeń nazwy modelu (ModelKey::$stem) — adres galerii musi mieć jego pierwsze słowo
     *                                  w nazwie pliku albo (host producenta) nazwę z samych słów koloru, cyfr i tokenów
     *                                  technicznych (fileNameNamesModel); null albo pusty = bez tego warunku
     * @return array{url: ?string, copy_of: ?ProductImage, reason: string} url — do pobrania (downloadMany);
     *                                                                     copy_of — do skopiowania; oba null = bez zdjęcia
     */
    public function pickFor(Product $member, array $memberColours, array $pageImageUrls, array $leaderImages, ?string $modelStem): array
    {
        $colours = self::canonicalSet($memberColours);

        if ($colours !== []) {
            $stemWord = self::stemWord($modelStem);
            $profile = $stemWord !== null ? ($this->profiles ??= app(ManufacturerProfiles::class))->for($member) : null;
            $otherColours = false;
            foreach ($pageImageUrls as $url) {
                if (! is_string($url) || ! str_starts_with($url, 'http') || self::isSwatchOrThumbnail($url)) {
                    continue;
                }
                if ($stemWord !== null
                    && ! self::fileNameNamesModel(self::fileName($url), $stemWord, $profile !== null && $profile->ownsUrl($url))) {
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
     * Nazwa pliku galerii (fileName) mówi o modelu: zawiera słowo rdzenia („af-orthomat-standard-…-grey-1.jpg”) albo —
     * tylko na hoście producenta z profilu karty ($onManufacturerHost) — składa się wyłącznie ze słów koloru, cyfr
     * i tokenów technicznych (FILE_NAME_TECH_TOKENS) z przynajmniej jednym kolorem innym niż „clear” — „Yellow-1.jpg”,
     * „Gray-1000x1000.jpg”, „black-grey-2-scaled.jpg” (pilotaż 08.10.2026: żółte kraty COBAGRiP bez zdjęcia, bo galeria
     * strony modelu na coba.com ma „Yellow-1.jpg” bez słowa „cobagrip”). Sklep nazywa tak próbki kolorów
     * („…/swatch/…/black.png”), a „transparent.png” / „clear.gif” to zwykle przezroczysty piksel albo zaślepka — tam
     * potrzebne słowo rdzenia. Nazwa z innym słowem („cobagrip-grey.jpg” na stronie Orthomata, „deckplate-yellow.jpg”)
     * nie mówi o modelu.
     */
    private static function fileNameNamesModel(string $fileName, string $stemWord, bool $onManufacturerHost): bool
    {
        if (str_contains($fileName, $stemWord)) {
            return true;
        }
        if (! $onManufacturerHost || preg_match_all('/[a-z]+|\d+/', $fileName, $m) < 1) {
            return false;
        }
        $hasColour = false;
        foreach ($m[0] as $token) {
            if (ctype_digit($token) || in_array($token, self::FILE_NAME_TECH_TOKENS, true)) {
                continue;
            }
            $colour = ColourWords::canonical($token);
            if ($colour === null) {
                return false;
            }
            $hasColour = $hasColour || $colour !== 'clear';
        }

        return $hasColour;
    }

    /**
     * Próbka koloru albo miniatura, nie zdjęcie wyrobu: człon ścieżki „swatch”/„swatches” (Magento
     * „/media/attribute/swatch/…”) albo rozmiar miniatury — w nazwie pliku („-300x300.jpg”, WordPress) lub jako katalog
     * („/cache/…/265x265/”) — mniejszy z obu stron niż THUMBNAIL_MAX_SIDE. Duże wersje („Gray-1000x1000.jpg”) zostają.
     */
    private static function isSwatchOrThumbnail(string $url): bool
    {
        $path = mb_strtolower(rawurldecode((string) (parse_url(trim($url), PHP_URL_PATH) ?? '')), 'UTF-8');
        if (preg_match('#(?:^|[/_\-.])swatch(?:es)?(?:$|[/_\-.])#', $path) === 1) {
            return true;
        }
        $file = basename($path);
        $sizes = [];
        if (preg_match('/-(\d{2,4})x(\d{2,4})\.[a-z0-9]+$/', $file, $m) === 1) {
            $sizes[] = [(int) $m[1], (int) $m[2]];
        }
        if (preg_match_all('#/(\d{2,4})x(\d{2,4})/#', $path, $dirs, PREG_SET_ORDER) > 0) {
            foreach ($dirs as $d) {
                $sizes[] = [(int) $d[1], (int) $d[2]];
            }
        }
        foreach ($sizes as [$w, $h]) {
            if (max($w, $h) < self::THUMBNAIL_MAX_SIDE) {
                return true;
            }
        }

        return false;
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
