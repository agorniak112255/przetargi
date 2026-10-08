<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Support\ColourWords;
use App\Support\ProductSizeVariant;

/**
 * Klucz modelu karty liczony w locie (etap 2 opisów z cenników): `marka | rodzina SKU | rdzeń nazwy` — tylko dla
 * marki z profilem `model.group = name_stem` (config/manufacturer_profiles.php; etap 2: Coba). Karty cenników
 * z plików nie mają wariantów — każdy wymiar i kolor to osobna karta, a strona producenta jest jedna na model.
 * Rodzina SKU = przechwyt model_regex z przyrostkiem po cyfrach („ST”, „ST/B1”) — przyrostek rozdziela wyroby
 * o tej samej nazwie w innym wykonaniu (family()).
 *
 * Rdzeń = nazwa po zdjęciu rozmiarów odzieżowych (ProductSizeVariant::stripSizeFromName), wyrażeń wymiarowych
 * („0.9m x 18.3m (9.5mm)”, „x mb.”, „- maks. 10m”, „2000mm x 1000mm x 25mm”) i słów koloru (ColourWords).
 * Nic więcej nie jest zdejmowane — każde inne słowo rozdziela modele: „Deckplate Czarny/Żółte krawędzie” osobno od
 * „Deckplate Czarny”, „Premier Track Otwarta” ≠ „Premier Track Pełna”, „Senso Runner” ≠ „Senso Runner ESD”,
 * „COBAGRiP Krata GRP” / „COBAGRiP Light” / „COBAGRiP Nakładka na schody” / „COBAGRiP Osłona krawędzi” to cztery
 * modele. Sam prefiks SKU skleiłby różne wyroby (CCLIP25 to uchwyt, nie krata) — jest tylko składnikiem klucza.
 */
final class ProductModelKey
{
    public const GROUP_NAME_STEM = 'name_stem';

    private const UNIT = '(?:mm|cm|m|kg|g|ml|l)';

    /** jedno wyrażenie wymiarowe: liczba z jednostką („0.9m”, „~18.3 m”, „3 m”) albo „mb.” (metr bieżący) */
    private const DIM = '(?:~?\d+(?:[.,]\d+)?\s*'.self::UNIT.'(?![\p{L}\d])|mb\.?(?![\p{L}\d]))';

    /** liczba bez jednostki przed „x” („0.9 x 1.5m” — jednostka tylko przy drugim wymiarze) */
    private const BARE = '~?\d+(?:[.,]\d+)?';

    /** „maks. 10m”, „max. 10m”, „max. długość 6m” */
    private const MAX = '(?:ma(?:ks|x)\.?\s*(?:d[łl]ugo[śs][ćc]\s*)?)?';

    /**
     * Łańcuch wymiarów z nazwy cennika: „0.9m x 18.3m” (nawias „(9.5mm)” to osobny łańcuch), „2000mm x 1000mm x 25mm”,
     * „0.9 x 1.5m”, „1m x mb.”, „Krawędź x mb.” (wiszące „x” przed „mb.”), „- maks. 10m”, „- max. długość 6m”. Liczba
     * z jednostką jak w regule nazwy bez wymiarów w ProductEnrichmentService::descriptionMentionsProduct; goła liczba
     * tylko przed „x” — „2222” w „COBAmat Standard 2222” to numer modelu. Używa też PriceListCardFacts (specs z nazwy).
     */
    public const DIMENSIONS = '/(?<![\p{L}\d])(?:(?<=\s)[x×]\s*)?'.self::MAX.'(?:'.self::BARE.'\s*[x×]\s*)*'.self::DIM.'(?:\s*[x×]\s*'.self::DIM.')*/iu';

    /** znak spoza liter i cyfr w miejscu wymiaru na czas zdejmowania rozmiarów */
    private const MARK = "\u{E000}";

    /** znak przed literą typu albo numerem modelu („typu L”, „Model 5”) na czas zdejmowania rozmiarów */
    private const GUARD = "\u{E001}";

    /**
     * „Uchwyt typu L” / „typu M” to typ uchwytu, „Mata Model 5” / „Model 10” to numer modelu — bez ochrony
     * ProductSizeVariant wzięłoby końcową literę albo liczbę za rozmiar odzieżowy i skleiło różne wyroby.
     */
    private const GUARDED = '/\b(typu?|model(?:u|em)?)\s+(?=(?:[A-Z]|\d{1,2})(?![\p{L}\d]))/iu';

    public function __construct(
        private readonly ManufacturerProfiles $profiles,
        private readonly ProductSizeVariant $sizes,
    ) {}

    /** null = marka bez grupowania (profil bez model.group) albo pusty rdzeń nazwy. */
    public function for(Product $p, ?ManufacturerProfile $prof = null): ?ModelKey
    {
        $prof ??= $this->profiles->for($p);
        if ($prof === null || $prof->modelGroup !== self::GROUP_NAME_STEM) {
            return null;
        }
        $stem = self::stem((string) $p->name, $this->sizes);
        if ($stem === '') {
            return null;
        }
        $family = self::family((string) $p->sku, $prof->modelRegex);
        // w kluczu pisownia i interpunkcja nie rozdzielają („COBAGRIP Light” = „COBAGRiP Light”); rdzeń zostaje jak w nazwie
        $folded = trim((string) preg_replace('/[^\p{L}\d]+/u', ' ', mb_strtolower($stem, 'UTF-8')));

        return new ModelKey(
            key: mb_substr($prof->brandKey.'|'.$family.'|'.$folded, 0, 160, 'UTF-8'),
            brandKey: $prof->brandKey,
            family: $family,
            stem: $stem,
        );
    }

    /**
     * Rdzeń nazwy: bez rozmiarów odzieżowych, wymiarów, „x mb.”, „maks.” i słów koloru. Wymiary idą najpierw pod
     * znacznik, bo zdejmowanie rozmiarów wzięłoby „L” z „Uchwyt typu L - 38mm” albo „m” z „CablePro GP1 Czarny - 3 m”
     * za rozmiar; litera typu i numer modelu na końcu nazwy („Uchwyt typu L”, „Mata Model 5”) dostają na ten czas
     * strażnika (GUARDED). Francuski znacznik rozmiaru „T5” zostaje regułą rozmiarów — „Uchwyt T5” i „Uchwyt T8” mają
     * ten sam rdzeń (u Coby takich nazw nie ma; rozdziela je co najwyżej rodzina SKU). Separatory, które zostały bez
     * słowa z którejś strony („Deckplate / krawędzie”, „Otwarta -”), znikają, a te między słowami („Krawędź/narożnik”,
     * „First-Step”, „P249-C63-C09”) zostają.
     */
    public static function stem(string $name, ProductSizeVariant $sizes): string
    {
        $t = trim($name);
        if ($t === '') {
            return '';
        }
        $t = preg_replace(self::DIMENSIONS, ' '.self::MARK.' ', $t) ?? $t;
        $t = preg_replace(self::GUARDED, '$1 '.self::GUARD, $t) ?? $t;
        $t = $sizes->stripSizeFromName($t);
        $t = str_replace([self::MARK, self::GUARD], [' ', ''], $t);
        $t = preg_replace_callback(
            '/\p{L}+/u',
            static fn (array $m): string => ColourWords::is($m[0]) ? ' ' : $m[0],
            $t
        ) ?? $t;
        // puste nawiasy po „(9.5mm)” i „(przezroczysty)”
        $t = preg_replace('/\(\s*\)/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        $t = preg_replace('/(?<![\p{L}\d])[\-–—\/,;:]+|[\-–—\/,;:]+(?![\p{L}\d])/u', ' ', $t) ?? $t;

        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /**
     * Rodzina z SKU: przechwyt model_regex profilu („AF” z „AF060001” dla Coby `/^([A-Z]+)\d/`; bez przechwytu całe
     * dopasowanie) plus literowo-cyfrowy przyrostek po literach i cyfrach, gdy jest („ST/B1” z „ST010001B1”, „SS/MN”
     * z „SS070002MN”, „GRP/G” z „GRP040001G”). Sam przechwyt sklejał inne wyroby o tej samej nazwie: ST010001 (Solid
     * Fatigue-Step) z ST010001B1 (wersja nitrylowa — inna odporność na oleje), SS070002MN z SS070002B1M, SS070002FN
     * z SS070002B1F; na 714 kartach Coby (08.10.2026) przyrostek rozdziela dokładnie te trzy pary. '' gdy brak wzorca
     * albo SKU nie pasuje („LCLIP-38”) — wtedy rozstrzyga sam rdzeń nazwy.
     */
    public static function family(string $sku, ?string $modelRegex): string
    {
        $sku = strtoupper(trim($sku));
        if ($sku === '' || $modelRegex === null || $modelRegex === '') {
            return '';
        }
        if (preg_match($modelRegex, $sku, $m) !== 1) {
            return '';
        }
        $family = trim((string) ($m[1] ?? $m[0]));
        $suffix = self::skuSuffix($sku);

        return $suffix === '' ? $family : $family.'/'.$suffix;
    }

    /**
     * Przyrostek SKU po literach i cyfrach, bez końcówki rozmiaru „-N” („LCLIP-38”, „SD0107-6”) i bez pojedynczego „C”
     * po cyfrze (postać sprzedaży na metry: AF060003C = AF060003 — jak variant_suffixes w SourceIdentity::skuForms):
     * „ST010001B1” → „B1”, „SS070002B1M” → „B1M”, „PT010601C” → '', „P249-C63-C” → '' (przyrostek kończy się na
     * pierwszym znaku spoza liter i cyfr).
     */
    private static function skuSuffix(string $sku): string
    {
        $base = preg_replace('/-\d+$/', '', $sku) ?? $sku;
        $base = preg_replace('/(?<=\d)C$/', '', $base) ?? $base;

        return preg_match('/^[A-Z]+\d+([A-Z\d]*)/', $base, $m) === 1 ? $m[1] : '';
    }
}
