<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Rodziny PPE + krój odzieży — kamizelka ≠ osłona twarzy, kurtka ≠ kalesony.
 */
final class PpeAssortment
{
    public const FAMILY_GLOVES = 'gloves';

    public const FAMILY_FOOTWEAR = 'footwear';

    public const FAMILY_APPAREL = 'apparel';

    public const FAMILY_HEAD = 'head';

    public const FAMILY_FACE = 'face';

    public const FAMILY_EYES = 'eyes';

    public const FAMILY_HEARING = 'hearing';

    public const MOUNT_HELMET = 'helmet';

    public const MOUNT_HEADBAND = 'headband';

    public const HARNESS_FASTRAC = 'fastrac';

    public const HARNESS_PUSHKEY = 'pushkey';

    public const VENT_OPEN = 'vent';

    public const FAMILY_RESPIRATORY = 'respiratory';

    public const FAMILY_FALL = 'fall';

    public const FAMILY_KNEE = 'knee';

    public const TYPE_KALOSZ = 'kalosz';

    public const TYPE_TRZEWIK = 'trzewik';

    public const TYPE_SZTYBLET = 'sztyblet';

    public const TYPE_POLBUT = 'polbut';

    public const TYPE_SANDAL = 'sandal';

    /** @var array<string, string> */
    private const KATEGORIA_TO_FAMILY = [
        'rekawice' => self::FAMILY_GLOVES,
        'obuwie' => self::FAMILY_FOOTWEAR,
        'odziez' => self::FAMILY_APPAREL,
        'ochrona_glowy' => self::FAMILY_HEAD,
        'ochrona_twarzy' => self::FAMILY_FACE,
        'ochrona_oczu' => self::FAMILY_EYES,
        'ochrona_sluchu' => self::FAMILY_HEARING,
        'drogi_oddechowe' => self::FAMILY_RESPIRATORY,
        'asekuracja' => self::FAMILY_FALL,
        'ochrona_kolan' => self::FAMILY_KNEE,
    ];

    /** @var array<string, string> */
    /** Klasy wytrzymałości mechanicznej EN 166 od najniższej. */
    private const IMPACT_CLASS_RANK = ['F' => 1, 'B' => 2, 'A' => 3];

    private const FAMILY_TO_KATEGORIA = [
        self::FAMILY_GLOVES => 'rekawice',
        self::FAMILY_FOOTWEAR => 'obuwie',
        self::FAMILY_APPAREL => 'odziez',
        self::FAMILY_HEAD => 'ochrona_glowy',
        self::FAMILY_FACE => 'ochrona_twarzy',
        self::FAMILY_EYES => 'ochrona_oczu',
        self::FAMILY_HEARING => 'ochrona_sluchu',
        self::FAMILY_RESPIRATORY => 'drogi_oddechowe',
        self::FAMILY_FALL => 'asekuracja',
        self::FAMILY_KNEE => 'ochrona_kolan',
    ];

    /** Grube kategorie — tekst produktu może być dokładniejszy. */
    private const COARSE_FAMILIES = [self::FAMILY_HEAD];

    /**
     * Słowa z nazwy, które nie identyfikują karty w opisie (jak w AuditProductDescriptionsCommand).
     *
     * @var list<string>
     */
    private const DESCRIPTION_STOP_TOKENS = [
        'robocze', 'ochronne', 'ochronna', 'ochronny', 'meskie', 'damskie', 'czarne', 'czarny',
        'granatowe', 'granatowy', 'niebieskie', 'zielone', 'szare', 'biale', 'guma', 'skora',
        'rozmiar', 'komplet', 'zestaw', 'para', 'sztuka', 'linia', 'seria', 'model', 'size',
    ];

    /** Zawór i klasa FFP liczone tą samą regułą co w porównywarce. */
    private ?BhpAttributeNormalizer $attributes = null;

    /**
     * Goła „półmaska” bez słów o wielorazowości — półmaska nieznanej konstrukcji (FFP albo
     * elastomerowa). Zgodna z `ffp` i `reusable_half`, sprzeczna z pochłaniaczem i maską pełnotwarzową.
     */
    private const RESPIRATORY_HALF_UNKNOWN = 'half';

    /** @var list<string> */
    private const SPECIFIC_HEAD_SPLIT = [
        self::FAMILY_FACE,
        self::FAMILY_EYES,
        self::FAMILY_HEARING,
        self::FAMILY_RESPIRATORY,
    ];

    public function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];
        // czeskie/niemieckie znaki (ř, ý, ě, ů, ü…) rozpadały się na spacje: „brýle” → „br le”, „přilba” → „p ilba”
        $s = Str::ascii(strtr($s, $map));

        return preg_replace('/[^a-z0-9\s]/', ' ', $s) ?? $s;
    }

    /**
     * Rzeczowniki rodzin w kolejności rozstrzygającej remisy (ta sama pozycja w tekście).
     * Spodniobuty i wodery to odzież wodoochronna, nie obuwie — „kalosz” w ich nazwie
     * nie może przerzucić ich do obuwia.
     *
     * @var array<string, string>
     */
    /**
     * Cenniki Canis/CXS mają nazwy czeskie („Rukavice CERRO”, „Polobotka”) i angielskie
     * („Men´s jacket”, „Low ankle shoe”, „Spectacles”); bez tych rzeczowników karta
     * dostawała rodzinę z kategorii importu („Odzież” dla rękawic) albo z numeru w SKU.
     */
    private const FAMILY_PATTERNS = [
        self::FAMILY_GLOVES => '/\b(rekawic|glove|handschuh|rukavic|mitten)\w*/u',
        self::FAMILY_RESPIRATORY => '/\b(polmask|polomask|respirator|aparat\w*\s+oddech|drog[iy]\s+oddech|filtrow?\w*\s+oddech'
            .'|maska\s+(twarzow|pelnotwarz|filtruj|przeciwpyl)|czesc\s+twarzow|semi-?mask|half\s*mask|dust\s*mask'
            .'|pochlaniacz|filtropochlaniacz|ffp[123]?)\w*/u',
        self::FAMILY_FACE => '/\b(przylbic|oslon\w{0,10}\s+\w{0,16}twarz|twarz\w{0,8}\s+\w{0,12}oslon'
            .'|oslona\s+twarzy|face\s*shield|siatk\w*\s+(na\s+)?twarz|maska\s+spawal)\w*/u',
        self::FAMILY_EYES => '/\b(okular|gogl|szyba\s+ochronn|spectacle|eyewear|bryl)\w*|\bglasses\b/u',
        self::FAMILY_HEARING => '/\b(nausznik|ochronnik\w*\s+sluch|czasze\s+przeciwhal|wkladk\w*\s+sluch'
            .'|stoper\w*|ochrona\s+sluchu|sluchawk\w*\s+ochron|ear\s*(muff|plug|defender)|earmuff|earplug'
            .'|chranic\w*\s+sluchu|sluchatk)\w*/u',
        self::FAMILY_FALL => '/\b(szelk|linka\s+bezpieczen|amortyzator|asekurac|urzadzeni\w*\s+samoham|lonza'
            .'|ewakuac|podnoszac|opuszczaj|wciagark|harness|lanyard|postroj)\w*/u',
        self::FAMILY_KNEE => '/\b(nakolann|ochrona\s+kolan|knee\s*pad)\w*/u',
        self::FAMILY_APPAREL => '/\b(odziez|kurtk|spodn|podnie|kombinezon|kamizelk|kamizelak|softshell|fartuch|kitel|bluza'
            .'|kaleson|ogrodniczk|park[ae]|peleryn|spodniobut|woder|wader'
            .'|jacket|trousers|coverall|cvrl|overall|apron|sweatshirt|t-?shirt|fleece|waistcoat|hoodie|raincoat'
            .'|kalhoty|bunda|vesta|kombinez|zaster|mikina|tricko|triko|monterk|kosile|svetr)\w*'
            .'|\b(pants|vests?|shirts?)\b/u',
        // „liner” tylko przy hełmie — „poly liner” to warstwa taśmy, nie wkładka pod kask.
        self::FAMILY_HEAD => '/\b(kominiark|czapk|helm|kask|czepek|balaclava|prilb|cepic|kukl|beanie)\w*'
            .'|\bcaps?\b|\bhard\s*hats?\b|\bhelmet\s*liner|\bliner\w*\s+(pod\s+)?(helm|kask)|(wkladk\w*.{0,24}(helm|kask))/u',
        // Rzeczowniki obuwia bez kotwicy na końcu („trzewiki”, „półbuty”, „sandały”);
        // „buty” zostaje całym słowem, bo inaczej łapie „butylowe”.
        self::FAMILY_FOOTWEAR => '/\b(trzewik|sztyblet|polbut|mokasyn|sandal|obuwi|kalosz|gumowc|gumiak|wellington'
            .'|footwear|podeszw|podnosek|polobotk|holink|kotnikov)\w*|\b(buty|butow|obuv|boty|bota|shoes?|boots?)\b'
            .'|\bs1p?\b|\bs[2-5]\b|\bo[1-5]\b/u',
    ];

    /**
     * Rodzinę wskazuje rzeczownik główny — pierwszy w tekście, nie pierwszy na liście.
     * Opis SIWZ wymienia dalej akcesoria i kompatybilności („wymienne szelki” przy
     * spodniobutach, „łącznie z półmaskami” przy goglach), które nie zmieniają wyrobu.
     * Remis pozycji rozstrzyga kolejność FAMILY_PATTERNS.
     */
    public function family(string $text): ?string
    {
        $t = $this->normalize($text);
        $hasHelm = preg_match('/\b(helm|kask)\w*/u', $t) === 1;

        $best = null;
        $bestAt = PHP_INT_MAX;
        foreach (self::FAMILY_PATTERNS as $family => $pattern) {
            // „Osłona twarzy do hełmu” to akcesorium hełmu — twarz pomijamy, gdy w tekście jest hełm.
            if ($family === self::FAMILY_FACE && $hasHelm) {
                continue;
            }
            $at = $this->firstWordOffset($pattern, $t);
            if ($at !== null && $at < $bestAt) {
                $best = $family;
                $bestAt = $at;
            }
        }

        return $best ?? $this->familyFromNorms($t);
    }

    /**
     * Rzeczownik rodziny z nagłówka wymagania w brzmieniu oryginału — chip „Gogle” w weryfikacji
     * karty. `stem` („Gogl”) liczy też „gogli” w opisie. Dopasowujemy po słowach, nie po offsetach:
     * normalize() zmienia długość tekstu („ß” → „ss”, „½” → „1 2”), więc offset znormalizowanego
     * tekstu nie wskazuje miejsca w oryginale. Wygrywa pierwsze trafienie — przy goglach dalsze
     * „okularach korekcyjnych” to ta sama rodzina, ale nie rzeczownik wyrobu. Gdy brzmienia nie
     * da się odtworzyć bez wątpliwości, zwracamy null: lepiej brak chipu niż zły chip.
     *
     * @return array{label: string, stem: string}|null
     */
    public function familyNoun(string $head, string $family): ?array
    {
        $pattern = self::FAMILY_PATTERNS[$family] ?? null;
        if ($pattern === null || preg_match_all('/[\p{L}\p{N}]+/u', $head, $words, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }
        $words = $words[0];
        $collapse = static fn (string $s): string => trim((string) preg_replace('/\s+/', ' ', $s));

        foreach (array_slice($words, 0, 16) as $i => [$word, $start]) {
            // Litera, której Str::ascii nie zapisze, przesunęłaby dopasowanie na następne słowo.
            if (trim($this->normalize($word)) === '') {
                continue;
            }
            $suffix = ltrim($this->normalize(substr($head, $start)));
            if (preg_match($pattern, $suffix, $m, PREG_OFFSET_CAPTURE) !== 1 || $m[0][1] !== 0) {
                continue;
            }

            $match = $collapse($m[0][0]);
            $last = $i + count(explode(' ', $match)) - 1;
            if ($match === '' || ! isset($words[$last])) {
                return null;
            }
            $label = substr($head, $start, $words[$last][1] + strlen($words[$last][0]) - $start);
            // Dopasowanie kończące się w środku słowa albo rozjechane z oryginałem — bez chipu.
            if ($collapse($this->normalize($label)) !== $match) {
                return null;
            }

            return ['label' => $label, 'stem' => $last === $i ? $this->familyNounStem($word, $m) : $label];
        }

        return null;
    }

    /**
     * Rdzeń to prefiks słowa o długości pierwszej grupy wzorca („rekawic” → „Rękawic”) — tylko
     * gdy znaki mapują się 1:1; „Fußschutz” → „fussschutz” nie ma bezpiecznego prefiksu.
     * Grupa krótsza od słowa o więcej niż końcówkę to wcześniejsza alternatywa złożenia:
     * „Spodniobuty” łapie „spodn”, a taki rdzeń liczyłby w opisie „spodnie”. Wtedy rdzeniem
     * jest słowo bez końcowych samogłosek („Spodniobut” liczy też „spodniobutów”).
     *
     * @param  array<int, array{0: string, 1: int}>  $m
     */
    private function familyNounStem(string $word, array $m): string
    {
        $normalized = $this->normalize($word);
        if (mb_strlen($word) !== strlen($normalized)) {
            return $word;
        }
        foreach (array_slice($m, 1) as [$group, $offset]) {
            if ($group === '' || $offset < 0) {
                continue;
            }
            if ($offset !== 0 || ! str_starts_with($normalized, $group)) {
                return $word;
            }
            $prefix = strlen($normalized) - strlen($group) > 3 ? rtrim($normalized, 'aeiouy') : $group;
            $stem = mb_substr($word, 0, strlen($prefix));

            return strlen($prefix) >= strlen($group) && $this->normalize($stem) === $prefix ? $stem : $word;
        }

        return $word;
    }

    /**
     * Numer normy liczy się tylko z oznaczeniem normy przed liczbą („EN 388”, „PN-EN ISO
     * 20345”) — „ULTRANITRIL 358”, „TITAN 397”, „EDGE 48-140” i SKU „3410-140-410-00” to
     * numery modeli i kodów, a robiły z rękawic asekurację, hełmy i ochronę dróg oddechowych.
     */
    private function familyFromNorms(string $normalized): ?string
    {
        $norm = static fn (string $numbers): string => '/\b(?:pn[\s-]*)?(?:en|iso|din|csn)(?:[\s-]*iso)?[\s-]*(?:'.$numbers.')\b(?![\d\-.])/u';
        if (preg_match($norm('388|420|511|21420'), $normalized) === 1) {
            return self::FAMILY_GLOVES;
        }
        if (preg_match($norm('20345|20347'), $normalized) === 1) {
            return self::FAMILY_FOOTWEAR;
        }
        if (preg_match($norm('166'), $normalized) === 1) {
            return self::FAMILY_EYES;
        }
        if (preg_match($norm('352'), $normalized) === 1) {
            return self::FAMILY_HEARING;
        }
        if (preg_match($norm('149|140|143'), $normalized) === 1) {
            return self::FAMILY_RESPIRATORY;
        }
        if (preg_match($norm('361|358'), $normalized) === 1) {
            return self::FAMILY_FALL;
        }
        if (preg_match($norm('397'), $normalized) === 1) {
            return self::FAMILY_HEAD;
        }

        return null;
    }

    public function familyFromKategoria(?string $kategoria): ?string
    {
        if ($kategoria === null || $kategoria === 'inne') {
            return null;
        }

        return self::KATEGORIA_TO_FAMILY[$kategoria] ?? null;
    }

    public function kategoriaFromFamily(?string $family): ?string
    {
        if ($family === null) {
            return null;
        }

        return self::FAMILY_TO_KATEGORIA[$family] ?? null;
    }

    public function kategoria(string $text): ?string
    {
        return $this->kategoriaFromFamily($this->family($text));
    }

    public function resolveFamily(string $text, ?string $kategoriaBhp = null): ?string
    {
        $fromText = $this->family($text);
        $fromKat = $this->familyFromKategoria($kategoriaBhp);
        if ($fromText !== null && $fromKat !== null && $fromText !== $fromKat) {
            if (in_array($fromKat, self::COARSE_FAMILIES, true)
                && in_array($fromText, self::SPECIFIC_HEAD_SPLIT, true)) {
                return $fromText;
            }

            return $fromText;
        }

        return $fromText ?? $fromKat;
    }

    public function garment(string $text): ?string
    {
        $t = $this->normalize($text);
        if (preg_match('/\b(kamizelk|waistcoat)\w*/u', $t) === 1) {
            return 'vest';
        }
        if (preg_match('/\b(kaleson|podkoszul)\w*/u', $t) === 1) {
            return 'underwear';
        }
        if (preg_match('/\b(fartuch|kitel)\w*/u', $t) === 1) {
            return 'coat';
        }
        if (preg_match('/\b(kombinezon)\w*/u', $t) === 1) {
            return 'coverall';
        }
        if ($this->isApparelSet($text)) {
            return 'set';
        }
        // Spodniobuty / wodery przed „spodn” — spodnie do pasa z szelkami to inny wyrób.
        if (preg_match('/\b(spodniobut|woder|wader)\w*/u', $t) === 1) {
            return 'waders';
        }
        if (preg_match('/\b(spodn|ogrodniczk)\w*/u', $t) === 1) {
            return 'pants';
        }
        if (preg_match('/\b(kurtk|softshell|park[ae]|bluza|peleryn)\w*/u', $t) === 1) {
            return 'jacket';
        }
        if (preg_match('/\b(koszul)\w*/u', $t) === 1) {
            return 'shirt';
        }

        return null;
    }

    /**
     * Krój / konstrukcja: kalosz ≠ trzewik, kurtka ≠ kalesony.
     */
    /**
     * Nazwa/SKU decydują, czym produkt JEST. Opis często wymienia kompatybilne
     * półmaski albo filtry — to nie zmienia typu.
     */
    public function articleTypePreferIdentity(string $identity, string $fullText, ?string $family = null): ?string
    {
        $family ??= $this->family($identity) ?? $this->family($fullText);

        return $this->articleType($identity, $family)
            ?? $this->articleType($fullText, $family);
    }

    public function articleType(string $text, ?string $family = null): ?string
    {
        $family ??= $this->family($text);
        $t = $this->normalize($text);

        return match ($family) {
            self::FAMILY_APPAREL => $this->garment($text),
            self::FAMILY_FOOTWEAR => $this->footwearType($t),
            self::FAMILY_RESPIRATORY => $this->respiratoryType($t),
            self::FAMILY_GLOVES => $this->gloveType($t),
            self::FAMILY_EYES => $this->eyeType($t),
            self::FAMILY_HEARING => $this->hearingType($t),
            self::FAMILY_HEAD => $this->headType($t),
            self::FAMILY_FACE => $this->faceType($t),
            self::FAMILY_FALL => $this->fallType($t),
            self::FAMILY_KNEE => 'kneepad',
            // Bez rodziny tylko jawne słowo obuwia daje typ obuwia. Samo „guma” zrobiło
            // „kalosz” z maty DeckStep (specyfikacja „Materiał: guma (winyl)”, batch #298).
            default => $family === null && $this->mentionsWellingtonWord($t) ? $this->footwearType($t) : null,
        };
    }

    /** Wyrazy obuwia spoza wzorca rodziny (Purofort, wodery, spodniobuty) — bez samego „guma”. */
    private function mentionsWellingtonWord(string $t): bool
    {
        return preg_match('/\b(kalosz|wellington|gumowc|gumiak|purofort|wader|gumboot|spodniobut|woder)\w*/u', $t) === 1;
    }

    private function footwearType(string $t): ?string
    {
        if (preg_match(
            '/\b(mata|arkusz|tasm|taśm|stolow|podlogow|wkladk|filtr|pasek|linka)\w*/u',
            $t
        ) === 1
            && preg_match('/\b(kalosz|buty|obuwie|trzewik|sztyblet|polbut|mokasyn)\w*/u', $t) !== 1) {
            return null;
        }
        if (preg_match('/\b(kalosz|wellington|gumowc|gumiak|purofort|wader|gumboot|gumow\w*|spodniobut|woder)\w*/u', $t) === 1) {
            return self::TYPE_KALOSZ;
        }
        if (preg_match('/\bguma\b/u', $t) === 1
            && preg_match('/\b(mata|arkusz|tasm|taśm)\w*/u', $t) !== 1) {
            return self::TYPE_KALOSZ;
        }
        // Nazwy Canis/CXS są angielskie i czeskie: „Low perforated leather footwear” to półbut,
        // „Ankle boot”/„kotníková obuv” to trzewik — bez tego sandały dostawały zimowe trzewiki.
        if (preg_match('/\b(sandal)\w*/u', $t) === 1) {
            return self::TYPE_SANDAL;
        }
        if (preg_match('/\b(sztyblet|chelsea)\w*/u', $t) === 1) {
            return self::TYPE_SZTYBLET;
        }
        if (preg_match('/\b(trzewik|ankle\s*(boot|shoe|footwear)|kotnikov|high\s*(shoe|footwear|boot)|winter\s*(boot|footwear))\w*/u', $t) === 1) {
            return self::TYPE_TRZEWIK;
        }
        if (preg_match('/\b(mokasyn|polbut|polbuty|polobot|low\s*(?:\w+\s+){0,2}(shoe|footwear))\w*/u', $t) === 1) {
            return self::TYPE_POLBUT;
        }

        return null;
    }

    private function respiratoryType(string $t): ?string
    {
        $filterAt = $this->firstWordOffset(
            '/\b(pochlaniacz|filtropochlaniacz|wklad\w*|element\w*\s+oczyszcz)\w*|\bfiltr(y|a|u|ow|em|ami|ach)?\b/u',
            $t
        );
        $maskAt = $this->firstWordOffset('/\b(polmask|maska|ffp|pelnotwarz|respirator)\w*/u', $t);
        // Rzeczownik na początku mówi, czym wyrób jest: „Pochłaniacz … na półmaskach i maskach
        // pełnotwarzowych” to filtr, „Półmaska … z filtrami” to półmaska.
        if ($filterAt !== null && ($maskAt === null || $filterAt < $maskAt)) {
            return 'filter';
        }
        $isFilterNoun = $filterAt !== null;

        if (preg_match('/\b(pelnotwarz|full\s*face)\w*/u', $t) === 1) {
            return 'fullface';
        }
        if (preg_match('/\b(ffp[123]?|jednorazow|przeciwpyl|filtrujac)\w*/u', $t) === 1) {
            return 'ffp';
        }
        if (preg_match('/\b(polmask|czesci?\s+twarzow|elastomer|silikon)\w*/u', $t) === 1) {
            return 'reusable_half';
        }
        if ($isFilterNoun) {
            return 'filter';
        }

        return null;
    }

    private function gloveType(string $t): ?string
    {
        if (preg_match('/\b(jednorazow|winyl|vinyl|examinat)\w*/u', $t) === 1) {
            return 'disposable';
        }
        if (preg_match('/\b(nitryl|nitrile)\w*/u', $t) === 1) {
            return 'nitrile';
        }
        if (preg_match('/\bspawal|11611|welding/u', $t) === 1) {
            return 'welding';
        }
        if (preg_match('/\b(przeciec|cut|hppe|dyneema|powermask)\w*/u', $t) === 1) {
            return 'cut';
        }
        if (preg_match('/\b(chemiczn|374|kwasow)\w*/u', $t) === 1) {
            return 'chemical';
        }
        if (preg_match('/\b(skorz|leather|welur|licow)\w*/u', $t) === 1) {
            return 'leather';
        }
        if (preg_match('/\b(powlek|powlok)\w*/u', $t) === 1) {
            return 'coated';
        }

        return null;
    }

    /** SIWZ na odporność na przecięcie — nie mylić z samą powłoką nitrylową. */
    public function wantsCutResistance(string $text): bool
    {
        $t = $this->normalize($text);

        return preg_match('/\b(antyprzeciec|przecieciow)\w*/u', $t) === 1
            || preg_match('/\bcut\s*(resist|protect)\w*/u', $t) === 1;
    }

    /**
     * Najniższy poziom cięcia ISO 13997 (A–F) z wymagania. Poziom podany wprost („2.X.4.2.C”, „4X43D” przy EN 388,
     * „przecięcie wg metody ISO – poziom B”) wygrywa. Rękawice „odporne na przecięcie” bez poziomu — co najmniej B:
     * decyzja użytkownika 14.09 po pomiarze, w którym MAPA ULTRANE 681 (EN 388 4X21A, poziom A) wygrała poz. 2
     * (prace ze szkłem, skalpelem, nożem drukarskim). Poza rękawicami (rodzina gloves, także rękawy) — brak progu.
     */
    public function requiredCutLevel(string $requirement): ?string
    {
        $t = $this->normalize($requirement);
        $explicit = $this->cutLevelsIn($requirement);
        if ($explicit !== []) {
            return min($explicit);
        }
        if ($this->family($requirement) !== self::FAMILY_GLOVES) {
            return null;
        }

        return $this->wantsCutResistance($t)
            || preg_match('/\b(odporn\w*\s+na\s+przeci|(?:ochron\w*\s+)?(?:dloni\s+)?przed\s+przeci|ryzyk\w*\s+przeci)\w*/u', $t) === 1
            ? 'B'
            : null;
    }

    /** Najwyższy poziom cięcia ISO 13997 podany na karcie (kod EN 388:2016 albo słownie); null, gdy karta go nie podaje. */
    public function cutLevel(string $productText): ?string
    {
        $levels = $this->cutLevelsIn($productText);

        return $levels === [] ? null : max($levels);
    }

    /**
     * Litery poziomu cięcia ISO 13997. Kod EN 388:2016 („4331B”, „4X21A”, „2.X.4.2.C”) — z tekstu po normalize(), tylko
     * w pobliżu numeru normy 388, więc „2021A” w nazwie modelu to nie poziom. Zapis słowny — z oryginalnego tekstu, tylko
     * wielka litera A–F po „ISO 13997”, „EN ISO” albo po „przecięcie … poziom”: polskie „a” ani litera przed kolejną normą
     * („B, EN 407”) nie mylą odczytu. Literówka 13977 zamiast 13997 jest w opisach kart ATG i MAPA.
     *
     * @return list<string>
     */
    public function cutLevelsIn(string $text): array
    {
        $levels = [];
        if (preg_match_all('/388[^a-z]{0,1}.{0,80}?\b(?=[0-5x\s]*\d)([0-5x])\s?([0-5x])\s?([0-5x])\s?([0-5x])\s?([a-f])\b/u', $this->normalize($text), $m) > 0) {
            $levels = array_merge($levels, array_map('strtoupper', $m[5]));
        }
        $worded = [
            // „przecięcie ISO 13977: B”, „przecięcie wg ISO 13977 - B”, „ISO 13997 poziom C”
            '/(?i:iso)\s*139[79]7\s*[:\-–—]?\s*(?:(?i:poziom)\w*\s*)?([A-F])\b/u',
            // „odporność na przecięcie poziom C”, „przecięcie wg metody ISO – poziom B”
            '/(?i:przecię|przecie|przeciec)\w*[^0-9\n]{0,30}?(?i:poziom)\w*\s*[:\-–—]?\s*([A-F])\b/u',
            // „ochrona przed przecięciem EN ISO B”
            '/(?i:en\s*iso)\s+([A-F])\b/u',
        ];
        foreach ($worded as $pattern) {
            if (preg_match_all($pattern, $text, $m) > 0) {
                $levels = array_merge($levels, $m[1]);
            }
        }

        return array_values(array_unique($levels));
    }

    /**
     * Najniższa klasa wytrzymałości mechanicznej EN 166 z wymagania: F (45 m/s, niska energia), B (120 m/s, średnia),
     * A (190 m/s, wysoka). Decyzja użytkownika 14.09 po pomiarze 20260914_205010: poz. 9 wymaga soczewki „do 120 m/s”,
     * a 3M 2890 z zaciemnieniem 5.0 (1406213) ma tylko FT — raz dostawała 95, raz 50.
     */
    public function requiredImpactClass(string $requirement): ?string
    {
        $classes = $this->impactClassesIn($requirement);
        if ($classes === []) {
            return null;
        }
        usort($classes, static fn (string $a, string $b): int => self::IMPACT_CLASS_RANK[$a] <=> self::IMPACT_CLASS_RANK[$b]);

        return $classes[0];
    }

    /**
     * Najwyższa klasa wytrzymałości mechanicznej EN 166 podana na karcie; null, gdy karta jej nie podaje. Oprawka BT
     * z soczewką FT daje B — łagodnie, bo brak pewności to nie sprzeczność.
     */
    public function impactClass(string $productText): ?string
    {
        $classes = $this->impactClassesIn($productText);
        if ($classes === []) {
            return null;
        }
        usort($classes, static fn (string $a, string $b): int => self::IMPACT_CLASS_RANK[$b] <=> self::IMPACT_CLASS_RANK[$a]);

        return $classes[0];
    }

    /** Okulary, gogle, osłony twarzy: karta z niższą klasą uderzenia niż wymagana odpada; karta bez klasy zostaje. */
    public function meetsRequiredImpactClass(string $requirement, Product $product): bool
    {
        $family = $this->family($requirement);
        if ($family !== self::FAMILY_EYES && $family !== self::FAMILY_FACE) {
            return true;
        }

        return $this->impactClassAllows($requirement, $this->productFullText($product));
    }

    /**
     * Okulary, gogle, osłony twarzy do spawania z podanym zaciemnieniem: karta, która wprost podaje bezbarwną lub przezroczystą
     * szybę i żadnego filtra spawalniczego, odpada. Pomiar 20260914_191625 poz. 9: po odrzuceniu gogli z FT model dał 90
     * bezbarwnym 3M 2891S-SGAF, uznając „brak zaciemnienia 5.0” za warunek drugorzędny.
     */
    public function meetsRequiredWeldingFilter(string $requirement, Product $product): bool
    {
        $family = $this->family($requirement);
        if ($family !== self::FAMILY_EYES && $family !== self::FAMILY_FACE) {
            return true;
        }

        return $this->weldingFilterAllows($requirement, $this->productFullText($product));
    }

    /** Wymaganie spawalnicze ze stopniem zaciemnienia („spawalnicze, z zaciemnieniem 5.0”, „filtr spawalniczy 5”, EN 169). */
    public function requiresWeldingFilter(string $requirement): bool
    {
        $t = $this->normalize($requirement);

        return preg_match('/\bspawal/u', $t) === 1 && $this->showsWeldingFilter($t);
    }

    private function weldingFilterAllows(string $requirement, string $productText): bool
    {
        if (! $this->requiresWeldingFilter($requirement)) {
            return true;
        }
        $t = $this->normalize($productText);
        if ($this->showsWeldingFilter($t)) {
            return true;
        }

        return preg_match('/\b(bezbarwn|przezroczyst|transparentn|clear)\w*/u', $t) !== 1;
    }

    /**
     * Filtr spawalniczy w tekście po normalize(): zaciemnienie z numerem („zaciemnieniem spawalniczym 5 0”, „zaciemnienie nr 5”),
     * normy filtrów EN 169 i EN 379, DIN z numerem, filtr samościemniający. Samo „zaciemnienie 3” okularów przeciwsłonecznych
     * liczy się tylko przy słowie „spawal” w wymaganiu — karta z takim zapisem zostaje, bo to brak sprzeczności, nie dowód.
     */
    private function showsWeldingFilter(string $normalized): bool
    {
        return preg_match(
            '/\bzaciemn\w*\s+(?:spawal\w*\s+)?(?:nr\s+|stopni\w*\s+|o\s+stopniu\s+)?\d|\bstopni\w*\s+zaciemn\w*\s+\d|\b(?:en\s*)?(?:169|379)\b'
            .'|\bdin\s*\d|\bsamosciemn|\bfiltr\w*\s+spawal|\bspawal\w*\s+(?:filtr|szyb|soczew|wizjer)\w*\s+\d|\bshade\s*\d/u',
            $normalized
        ) === 1;
    }

    private function impactClassAllows(string $requirement, string $productText): bool
    {
        $min = $this->requiredImpactClass($requirement);
        if ($min === null) {
            return true;
        }
        $have = $this->impactClass($productText);

        return $have === null || self::IMPACT_CLASS_RANK[$have] >= self::IMPACT_CLASS_RANK[$min];
    }

    /**
     * Klasy uderzenia z tekstu. Z tekstu po normalize(): prędkość („do 120 m/s”) i energia przy uderzeniu lub cząstkach
     * („uderzenia przy niskiej energii”) — „promieniowanie o wysokiej energii” to nie klasa. Z oryginalnego tekstu, tylko
     * wielkie litery: oznaczenie przy EN 166 („EN 166 FT”, „EN166:BT”, „EN 166:2001 B”, „EN166 3 4 BT”), samodzielne
     * FT/BT/AT oraz „klasa F”, „oznaczenia FT”. Klasa S (5,1 m/s) nie jest odczytywana.
     *
     * @return list<string>
     */
    private function impactClassesIn(string $text): array
    {
        $classes = [];
        $t = $this->normalize($text);
        if (preg_match_all('/\b(45|120|190)\s*m\s*s\b/u', $t, $m) > 0) {
            foreach ($m[1] as $speed) {
                $classes[] = ['45' => 'F', '120' => 'B', '190' => 'A'][$speed];
            }
        }
        $energy = '(nisk|mal|sredni|wysok|duz)\w*\s+energi\w*';
        if (preg_match_all('/\b(?:uderz|czastk|odprysk)\w*[a-z0-9\s]{0,40}?\b'.$energy.'|\b'.$energy.'\s+(?:kinetyczn\w*\s+)?(?:uderz|czastk)/u', $t, $m) > 0) {
            foreach (array_merge($m[1], $m[2]) as $word) {
                if ($word !== '') {
                    $classes[] = in_array($word, ['nisk', 'mal'], true) ? 'F' : ($word === 'sredni' ? 'B' : 'A');
                }
            }
        }
        $marks = [
            '/\b(?:EN\s?166|166)(?::\s?2001)?\s*[:\-–]?\s*(?:[0-9]\s+){0,4}([FBA])T?\b/u',
            '/\b([FBA])T\b/u',
            '/(?i:klas|oznacz)\w*\s*[:\-–]?\s*\(?([FBA])T?\)?(?![\p{L}\d])/u',
        ];
        foreach ($marks as $pattern) {
            if (preg_match_all($pattern, $text, $m) > 0) {
                $classes = array_merge($classes, $m[1]);
            }
        }

        return array_values(array_unique($classes));
    }

    /** Przymiotnik albo włókno na nazwie/SKU (XtremCut, HPPE). Opisu nie czytamy. */
    public function showsCutResistance(string $text): bool
    {
        $t = $this->normalize($text);
        if ($this->wantsCutResistance($t)) {
            return true;
        }

        return preg_match(
            '/\b(xtremcut|xtrem\s+cut|hppe|dyneema|nocut|powercut|krytech|unidur|powermask)\w*/u',
            $t
        ) === 1
            || preg_match('/\bcut\s*(resist|protect|touch)\w*/u', $t) === 1;
    }

    /** SIWZ: kombinezon z wgrzanymi / zintegrowanymi kaloszami — nie sama kurtka ani spodniobuty. */
    public function wantsWeldedBootsCoverall(string $text): bool
    {
        $t = $this->normalize($text);
        if ($this->garment($t) !== 'coverall') {
            return false;
        }
        if (preg_match('/\b(kalosz)\w*/u', $t) !== 1) {
            return false;
        }

        return $this->hasWeldedBootWording($t);
    }

    /** Nazwa/SKU: kombinezon + kalosz. Opisu nie czytamy. */
    public function showsAttachedBootsCoverall(string $text): bool
    {
        $t = $this->normalize($text);

        return $this->garment($t) === 'coverall'
            && preg_match('/\b(kalosz)\w*/u', $t) === 1;
    }

    /** Wgrzane / zintegrowane — mocniejszy ślad konstrukcji niż samo „z kaloszami”. */
    public function showsWeldedBootsCoverall(string $text): bool
    {
        $t = $this->normalize($text);

        return $this->showsAttachedBootsCoverall($t) && $this->hasWeldedBootWording($t);
    }

    private function hasWeldedBootWording(string $normalized): bool
    {
        return preg_match(
            '/\b(wgrzan|zintegrowan|przyspawan|na\s+stale|welded|integrated)\w*/u',
            $normalized
        ) === 1;
    }

    private function eyeType(string $t): ?string
    {
        $gogl = $this->firstWordOffset('/\b(gogl)\w*/u', $t);
        $okul = $this->firstWordOffset('/\b(okular)\w*/u', $t);
        if ($gogl !== null && $okul !== null) {
            return $okul <= $gogl ? 'glasses' : 'goggles';
        }
        if ($gogl !== null) {
            return 'goggles';
        }
        if ($okul !== null) {
            return 'glasses';
        }

        return null;
    }

    private function firstWordOffset(string $pattern, string $text): ?int
    {
        if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return (int) $m[0][1];
    }

    private function hearingType(string $t): ?string
    {
        if (preg_match('/\b(nausznik|ochronnik\w*\s+sluch|czasze\s+przeciwhal|sluchawk\w*\s+ochron)\w*/u', $t) === 1) {
            return 'earmuff';
        }
        if (preg_match('/\b(wkladk\w*\s+sluch|stoper)\w*/u', $t) === 1) {
            return 'earplug';
        }

        return null;
    }

    private function headType(string $t): ?string
    {
        if (preg_match('/\b(kominiark|balaclava)\w*/u', $t) === 1) {
            return 'balaclava';
        }
        if (preg_match('/\b(czepek|czapk)\w*/u', $t) === 1) {
            return 'cap';
        }
        if (preg_match('/\b(wkladk|liner)\w*/u', $t) === 1
            && preg_match('/\b(helm|kask)\w*/u', $t) === 1) {
            return 'liner';
        }
        if (preg_match('/\b(helm|kask)\w*/u', $t) === 1) {
            return 'helmet';
        }

        return null;
    }

    /** Czepek / wkładka pod hełm — nie zwykła czapka i nie kurtka ESD. */
    public function isUnderHelmetLiner(string $text): bool
    {
        $t = $this->normalize($text);
        if (preg_match('/\b(czepek)\w*/u', $t) === 1) {
            return true;
        }
        if (preg_match('/\b(wkladk|liner|czapk|kominiark|balaclava)\w*/u', $t) !== 1) {
            return false;
        }

        return preg_match('/\b(helm|kask)\w*/u', $t) === 1;
    }

    private function isUnderHelmetType(?string $type): bool
    {
        return in_array($type, ['liner', 'cap', 'balaclava'], true);
    }

    private function headCompatible(string $requirement, string $productText): bool
    {
        $reqType = $this->articleType($requirement, self::FAMILY_HEAD);
        $prodType = $this->articleType($productText, self::FAMILY_HEAD);
        if ($this->isUnderHelmetLiner($requirement)) {
            if ($prodType === 'helmet') {
                return false;
            }
            $pt = $this->normalize($productText);

            return $this->isUnderHelmetLiner($productText)
                || preg_match('/\b(czepek|wkladk|liner|kominiark|balaclava)\w*/u', $pt) === 1;
        }
        if ($reqType === null || $prodType === null) {
            return $this->helmetSpecAllows($requirement, $productText);
        }
        if ($this->isUnderHelmetType($reqType) && $this->isUnderHelmetType($prodType)) {
            return true;
        }

        return $reqType === $prodType && $this->helmetSpecAllows($requirement, $productText);
    }

    /** Odrzuca tylko pewną niezgodność rodziny; nieznany tytuł zostaje. */
    public function compatibleOrUnknown(string $requirement, string $productText, ?string $kategoriaBhp = null): bool
    {
        $reqFamily = $this->family($requirement);
        if ($reqFamily === null) {
            return true;
        }
        $prodFamily = $this->resolveFamily($productText, $kategoriaBhp);
        if ($prodFamily === null) {
            return true;
        }

        return $this->compatible($requirement, $productText, $kategoriaBhp);
    }

    private function faceType(string $t): ?string
    {
        if (preg_match('/\b(przylbic|maska\s+spawal)\w*/u', $t) === 1) {
            return 'welding_helmet';
        }
        if (preg_match('/\b(oslon\w*\s+twarz|face\s*shield)\w*/u', $t) === 1) {
            return 'shield';
        }

        return null;
    }

    private function fallType(string $t): ?string
    {
        if (preg_match('/\b(ewakuac|podnoszac|opuszczaj|wciagark)\w*/u', $t) === 1) {
            return 'rescue';
        }
        if (preg_match('/\b(szelk)\w*/u', $t) === 1) {
            return 'harness';
        }
        if (preg_match('/\b(linka|lonza|amortyzator)\w*/u', $t) === 1) {
            return 'lanyard';
        }

        return null;
    }

    /**
     * Przeznaczenie / branża — spawanie ≠ rolnictwo.
     */
    public function purpose(string $text): ?string
    {
        $t = $this->normalize($text);
        if (preg_match('/\bspawal|11611|welding|welder/u', $t) === 1) {
            return 'welding';
        }
        if (preg_match('/\b(rolnict|agro|ogrodnict|gospodarstw|gnojow|farma|mleczar)/u', $t) === 1) {
            return 'agriculture';
        }
        if (preg_match('/\b(spozywc|food|haccp|gastronom|miesn)/u', $t) === 1) {
            return 'food';
        }
        if (preg_match('/\b(chemiczn|kwasow|rozpuszczaln)/u', $t) === 1) {
            return 'chemical';
        }

        return $this->role($text);
    }

    public function role(string $text): ?string
    {
        $roles = $this->roles($text);

        return $roles[0] ?? null;
    }

    /**
     * Wszystkie role z tekstu — karta z 11611+1149+20471 w normach nie jest „tylko hivis”.
     *
     * @return list<string>
     */
    public function roles(string $text): array
    {
        $t = $this->normalize($text);
        $out = [];
        if (preg_match('/\b20471\b|odblask|ostrzegawcz|hi.?vis|wysokiej widzial/u', $t) === 1) {
            $out[] = 'hivis';
        }
        if (preg_match('/\bspawal|11611|welding|welder/u', $t) === 1) {
            $out[] = 'welding';
        }
        if (preg_match('/\beletryk|1149|61482|lukiem|antystatyczn/u', $t) === 1) {
            $out[] = 'electric';
        }
        if (preg_match('/\bzaroodporn|11612\b/u', $t) === 1) {
            $out[] = 'heat';
        }
        if (preg_match('/\bwodoochron|przeciwdeszcz|\b343\b|deszczow/u', $t) === 1) {
            $out[] = 'rain';
        }

        return $out;
    }

    public function isApparelSet(string $text): bool
    {
        $t = $this->normalize($text);

        return preg_match(
            '/\b(bluza|kurtk).{0,32}(spodn|ogrodniczk)|(spodn|ogrodniczk).{0,32}(bluza|kurtk)/u',
            $t
        ) === 1;
    }

    public function isEyeWearSet(string $text): bool
    {
        $t = $this->normalize($text);

        return preg_match(
            '/\b(okular|gogl).{0,48}(etui|futeral|case)|(etui|futeral|case).{0,48}(okular|gogl)/u',
            $t
        ) === 1;
    }

    /** @return 'glasses'|'case'|null */
    public function eyeWearRole(string $text): ?string
    {
        if ($this->isEyeWearAccessory($text)) {
            return 'case';
        }
        $type = $this->eyeType($this->normalize($text));
        if ($type === 'glasses' || $type === 'goggles') {
            return 'glasses';
        }

        return null;
    }

    public function isCatalogNounStep(string $step): bool
    {
        if ($this->catalogNounLikes($step) !== []) {
            return true;
        }
        $t = $this->normalize($step);

        return preg_match(
            '/\b(scierk|scierecz|czyszciw|ubran|bluz|spodn|ogrodniczk|kurtk|rekawic|buty|trzewik)\w*/u',
            $t
        ) === 1;
    }

    public function compatible(string $requirement, string $productText, ?string $kategoriaBhp = null): bool
    {
        $reqFamily = $this->family($requirement);
        $prodFamily = $this->resolveFamily($productText, $kategoriaBhp);
        if ($reqFamily === null) {
            return true;
        }
        if ($prodFamily === null) {
            return false;
        }
        $helmetMount = false;
        if ($reqFamily !== $prodFamily) {
            if (! $this->helmetMountAllows($requirement, $reqFamily, $prodFamily)) {
                return false;
            }
            $helmetMount = true;
        }
        if ($reqFamily === self::FAMILY_APPAREL) {
            return $this->apparelCompatible($requirement, $productText, $productText);
        }
        if ($reqFamily === self::FAMILY_HEAD) {
            return $helmetMount || $this->headCompatible($requirement, $productText);
        }
        if ($reqFamily === self::FAMILY_HEARING) {
            if ($this->isHearingHygieneKit($productText) && ! $this->isHearingHygieneKit($requirement)) {
                return false;
            }
            $reqType = $this->articleType($requirement, self::FAMILY_HEARING);
            $prodType = $this->articleType($productText, self::FAMILY_HEARING);
            if ($reqType !== null && $prodType !== null && $reqType !== $prodType) {
                return false;
            }
        }
        if ($reqFamily === self::FAMILY_EYES) {
            return $this->eyeCompatible($requirement, $productText);
        }

        return true;
    }

    /**
     * Rodzina, do której produkt należy — niezależna od wymagania, więc da się ją
     * policzyć raz przy zapisie i trzymać w indeksowanej kolumnie zamiast liczyć
     * dla każdego wiersza przy każdym wyszukiwaniu.
     */
    public function productFamily(Product $product): ?string
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
        $kat = is_string($attrs['kategoria_bhp'] ?? null) ? $attrs['kategoria_bhp'] : null;

        // Nazwa i kategoria mówią, czym produkt JEST. Opis wymienia też akcesoria i
        // sąsiednie środki ochrony („kieszenie na nakolanniki” w spodniach), więc o
        // rodzinie decyduje dopiero wtedy, gdy tamte milczą.
        $fromName = $this->family((string) $product->name);
        if ($fromName !== null) {
            return $fromName;
        }

        $identity = $this->productIdentityText($product);
        $familyText = $this->family($identity) !== null ? $identity : $this->productFullText($product);

        $resolved = $this->resolveFamily($familyText, $kat);
        // Rękaw / ochraniacz przedramienia bez rzeczownika rodziny i bez normy rękawic w nazwie i opisie („HyFlex 11202 SIZE 19''”,
        // polski opis „rękaw ochronny”, normy EN 407 i ISO 13997) zostawał bez rodziny. Wyszukiwanie tekstowe całej rodziny
        // „rękawice” go pomijało, a bramka „rękaw ≠ rękawica” obsługuje go właśnie w tej rodzinie. Przetarg 1 poz. 1 (debug 14.09):
        // karta była tylko w źródle kart bez rodziny, na 77. miejscu, i wypadała przy przycięciu puli.
        if ($resolved === null && $this->productIsArmSleeve($product) && $this->sleeveShowsProtection($product)) {
            return self::FAMILY_GLOVES;
        }

        return $resolved;
    }

    /**
     * Rękaw jako środek ochrony, nie osprzęt: przebudowa indeksu 14.09 dopisała do rękawic „Rękaw termokurczliwy 3M HDCW”
     * (osprzęt kablowy, bez opisu) i „3M Sorbent do substancji ropopochodnych rękaw”. Rękawy ochronne mają dowód ochrony
     * w nazwie, opisie albo normach (przecięcie, przedramię, spawanie, EN 388/407); osprzęt i sorbenty go nie mają.
     */
    private function sleeveShowsProtection(Product $product): bool
    {
        $text = $this->productFullText($product);
        $t = $this->normalize($text);
        if (preg_match('/\b(termokurcz|sorbent|kabl|kablow|przewod|rura|rurk|mufa|osprzet)\w*/u', $t) === 1) {
            return false;
        }

        return $this->showsCutResistance($text)
            || preg_match(
                '/\b(ochronn\w*\s+(na\s+)?(rek|ram|przedram)|przedrami|antyprzeci|przeciwprzeci|zarekaw|narekaw|spawal'
                .'|arm\s*(protector|guard|sleeve)|cut[\s-]*resist|en\s*(iso\s*)?(388|407|13997|11611|11612))\w*/u',
                $t
            ) === 1;
    }

    /**
     * Wąski krój z wymagania — LIKE po nazwie/SKU, bez czekania na model.
     *
     * @return list<string>
     */
    public function catalogNounLikes(string $text): array
    {
        $family = $this->family($text);
        $type = $this->articleType($text, $family);
        if ($type === null) {
            return [];
        }

        return match ($type) {
            'balaclava' => ['kominiark', 'balaclava'],
            'cap' => ['czepek', 'czepk', 'czapk'],
            'liner' => ['wkladk', 'wkładk', 'liner'],
            'helmet' => ['helm', 'hełm', 'kask'],
            'vest' => ['kamizelk', 'waistcoat'],
            'underwear' => ['kaleson', 'podkoszul'],
            'coverall' => ['kombinezon'],
            self::TYPE_KALOSZ => ['kalosz', 'wellington', 'gumowc', 'gumiak', 'purofort', 'gumow', 'guma'],
            self::TYPE_SANDAL => ['sandal'],
            self::TYPE_SZTYBLET => ['sztyblet', 'chelsea'],
            self::TYPE_TRZEWIK => ['trzewik'],
            self::TYPE_POLBUT => ['mokasyn', 'polbut'],
            'goggles' => ['gogl'],
            'glasses' => ['okular'],
            'earmuff' => ['nausznik', 'ochronnik', 'czasze'],
            'earplug' => ['stoper'],
            'kneepad' => ['nakolann'],
            'welding_helmet' => ['przylbic'],
            'shield' => ['oslona twarz', 'osłona twarz', 'face shield'],
            'harness' => ['szelk'],
            'rescue' => ['ewakuac', 'podnosz', 'opuszcz'],
            'lanyard' => ['lonza', 'amortyzator'],
            'disposable' => ['jednorazow', 'winyl', 'vinyl'],
            'nitrile' => ['nitryl', 'nitrile'],
            'welding' => $family === self::FAMILY_GLOVES ? ['spawal'] : [],
            default => [],
        };
    }

    /**
     * Dwie trzecie katalogu nie ma opisu, z którego dałoby się odczytać rodzinę —
     * zostaje sam kod producenta. Odrzucamy więc tylko wtedy, gdy wiemy, że produkt
     * należy do innej rodziny; nierozpoznana rodzina to brak wiedzy, nie niezgodność.
     * O takich kartach rozstrzyga trafienie w tekst i model, nie ta bramka.
     */
    public function compatibleProduct(string $requirement, Product $product): bool
    {
        $reqFamily = $this->family($requirement);
        if ($reqFamily === null) {
            return true;
        }

        $fromName = $this->family((string) $product->name);
        $stored = $product->ppe_family !== null && $product->ppe_family !== ''
            ? (string) $product->ppe_family
            : null;
        $prodFamily = $fromName ?? $stored ?? $this->productFamily($product);

        $helmetMount = false;
        if ($prodFamily !== null && $reqFamily !== $prodFamily) {
            if (! $this->helmetMountAllows($requirement, $reqFamily, $prodFamily)) {
                return false;
            }
            $helmetMount = true;
        }
        if (($reqFamily === self::FAMILY_EYES || $reqFamily === self::FAMILY_FACE)
            && (! $this->impactClassAllows($requirement, $this->productFullText($product))
                || ! $this->weldingFilterAllows($requirement, $this->productFullText($product)))) {
            return false;
        }
        if ($reqFamily === self::FAMILY_APPAREL) {
            $identity = $this->productNameText($product);
            $roleText = trim($identity.' '.(string) ($product->norms ?? ''));

            return $this->apparelCompatible($requirement, $roleText, $identity);
        }
        if ($reqFamily === self::FAMILY_GLOVES) {
            // Rękaw (ochraniacz przedramienia) ≠ rękawica — w obie strony.
            if ($this->isArmSleeve($requirement) !== $this->productIsArmSleeve($product)) {
                return false;
            }
            if (! $this->productMeetsElectricalInsulationRequirement($requirement, $product)) {
                return false;
            }
        }
        if ($reqFamily === self::FAMILY_HEAD) {
            return $helmetMount || $this->headCompatible($requirement, $this->productIdentityText($product));
        }
        if ($reqFamily === self::FAMILY_HEARING) {
            return $this->hearingCompatible($requirement, $product);
        }
        if ($reqFamily === self::FAMILY_EYES) {
            return $this->eyeCompatible(
                $requirement,
                $this->productNameText($product),
                $this->productIdentityText($product),
                $this->productFullText($product),
            );
        }
        if ($reqFamily === self::FAMILY_FOOTWEAR) {
            return $this->footwearCompatible(
                $requirement,
                $this->productIdentityText($product),
                $this->productFullText($product),
                $this->productDescriptionFootwearType($product),
            );
        }
        if ($reqFamily === self::FAMILY_RESPIRATORY) {
            return $this->respiratoryCompatible($requirement, $product);
        }

        return true;
    }

    /**
     * Drogi oddechowe: półmaska wielorazowa ≠ FFP ≠ maska pełnotwarzowa ≠ pochłaniacz.
     * Odrzucamy tylko pewną sprzeczność — obie strony znane i różne; nieznany podtyp,
     * zawór czy klasa po którejkolwiek stronie to brak wiedzy, nie niezgodność.
     */
    private function respiratoryCompatible(string $requirement, Product $product): bool
    {
        $identity = $this->productIdentityText($product);
        if ($this->respiratoryTypesConflict($this->knownRespiratoryType($requirement), $this->productRespiratoryType($product))) {
            return false;
        }

        $attrs = $this->attributes();
        // Wymagany zawór wydechowy, a nazwa/identyfikator mówi wprost „bez zaworu”.
        if ($attrs->valveState($requirement) === 1 && $attrs->valveState($identity) === 0) {
            return false;
        }
        // Klasa FFP niższa niż wymagana (FFP1 przy wymaganej FFP2).
        if (! $attrs->ffpClassMeets($requirement, $identity)) {
            return false;
        }
        // Pochłaniacz gazów (A2) to nie filtr cząstek (P1 R) — klasy elementu oczyszczającego obu stron znane.
        if ($this->filterClassesConflict($requirement, $identity)) {
            return false;
        }

        return true;
    }

    /**
     * Klasy elementu oczyszczającego wg EN 14387 / EN 143: gazowe (A1‑A3, B, E, K, AX, SX, Hg)
     * i cząstek (P1‑P3). Wymagana klasa gazowa, a karta ma tylko klasę cząstek (albo odwrotnie),
     * lub klasa tej samej litery niższa — sprzeczność. Brak klas na karcie = brak wiedzy.
     */
    private function filterClassesConflict(string $requirement, string $productIdentity): bool
    {
        [$needGas, $needParticle] = $this->filterClasses($requirement);
        if ($needGas === [] && $needParticle === null) {
            return false;
        }
        [$haveGas, $haveParticle] = $this->filterClasses($productIdentity);
        if ($haveGas === [] && $haveParticle === null) {
            return false;
        }
        if ($needGas !== [] && $haveGas === []) {
            return true;
        }
        foreach ($needGas as $letter => $level) {
            if (! isset($haveGas[$letter]) || $haveGas[$letter] < $level) {
                return true;
            }
        }
        if ($needParticle !== null && $haveParticle === null && $haveGas !== []) {
            return true;
        }
        if ($needParticle !== null && $haveParticle !== null && $haveParticle < $needParticle) {
            return true;
        }

        return false;
    }

    /**
     * @return array{0: array<string, int>, 1: int|null} klasy gazowe (litera => poziom) i klasa cząstek
     */
    private function filterClasses(string $text): array
    {
        $t = $this->normalize($text);
        $gas = [];
        if (preg_match_all('/\b(?:a[1-3]?b[1-3]?e[1-3]?k[1-3]?|a[1-3]|b[1-3]|e[1-3]|k[1-3]|ax|sx|hg)\b(?![\s-]*(?:mm|cm|m\b))/u', $t, $m) > 0) {
            foreach ($m[0] as $code) {
                if (preg_match_all('/(a|b|e|k|ax|sx|hg)([1-3])?/u', $code, $parts, PREG_SET_ORDER) > 0) {
                    foreach ($parts as $part) {
                        $level = isset($part[2]) && $part[2] !== '' ? (int) $part[2] : 1;
                        $gas[$part[1]] = max($gas[$part[1]] ?? 0, $level);
                    }
                }
            }
        }
        $particle = null;
        if (preg_match_all('/\bp([1-3])\b(?![\s-]*(?:mm|cm|m\b))/u', $t, $pm) > 0) {
            $particle = max(array_map('intval', $pm[1]));
        }

        return [$gas, $particle];
    }

    /**
     * Podtyp dróg oddechowych „znany” na potrzeby bramki. Samo „półmaska” obejmuje też
     * FFP („3M Aura 9322+ półmaska”), więc `reusable_half` liczy się tylko przy jawnych
     * słowach o wielorazowości / konstrukcji elastomerowej.
     */
    private function knownRespiratoryType(string $text): ?string
    {
        $t = $this->normalize($text);
        $type = $this->respiratoryType($t);
        if ($type === 'reusable_half' && ! $this->showsReusableHalfMask($t)) {
            return self::RESPIRATORY_HALF_UNKNOWN;
        }

        return $type;
    }

    /** Obie strony znane i różne → sprzeczność; półmaska nieznanej konstrukcji pasuje do FFP i do wielorazowej. */
    private function respiratoryTypesConflict(?string $required, ?string $have): bool
    {
        if ($required === null || $have === null || $required === $have) {
            return false;
        }
        $half = ['ffp', 'reusable_half', self::RESPIRATORY_HALF_UNKNOWN];
        if (in_array($required, $half, true) && in_array($have, $half, true)
            && ($required === self::RESPIRATORY_HALF_UNKNOWN || $have === self::RESPIRATORY_HALF_UNKNOWN)) {
            return false;
        }

        return true;
    }

    private function showsReusableHalfMask(string $normalized): bool
    {
        return preg_match(
            '/\b(wielokrotn|wieloraz|elastomer|silikon|bagnet|czesc\w*\s+twarzow|wymienn\w*\s+(pochlaniacz|filtr))\w*/u',
            $normalized
        ) === 1;
    }

    /**
     * Podtyp karty z nazwy, potem z identyfikatora (kategoria „Półmaski wielokrotnego użytku”
     * doprecyzowuje gołą „półmaskę”) — opisu nie czytamy, bo wymienia kompatybilne maski.
     */
    private function productRespiratoryType(Product $product): ?string
    {
        $fromName = $this->knownRespiratoryType($this->productNameText($product));
        if ($fromName !== null && $fromName !== self::RESPIRATORY_HALF_UNKNOWN) {
            return $fromName;
        }

        return $this->knownRespiratoryType($this->productIdentityText($product)) ?? $fromName;
    }

    /**
     * Sprzeczność podtypów w tej samej rodzinie (FFP vs półmaska wielorazowa, gogle vs
     * okulary, spodniobuty vs spodnie) — dla punktacji „ten sam typ”. Typy rękawic to
     * cechy (nitryl, powlekane, antyprzecięciowe), które się nakładają — nie porównujemy.
     */
    public function subtypesConflict(string $requirement, Product $product): bool
    {
        $family = $this->family($requirement);
        if ($family === null || $family === self::FAMILY_GLOVES) {
            return false;
        }
        if ($family === self::FAMILY_RESPIRATORY) {
            return $this->respiratoryTypesConflict($this->knownRespiratoryType($requirement), $this->productRespiratoryType($product));
        }
        $reqType = $this->articleType($requirement, $family);
        $prodType = $this->articleTypePreferIdentity(
            $this->productNameText($product),
            $this->productIdentityText($product),
            $family
        );
        if ($reqType === null || $prodType === null || $reqType === $prodType) {
            return false;
        }
        if ($family === self::FAMILY_APPAREL && $reqType === 'set' && in_array($prodType, ['jacket', 'pants'], true)) {
            return false;
        }
        if ($family === self::FAMILY_HEAD && $this->isUnderHelmetType($reqType) && $this->isUnderHelmetType($prodType)) {
            return false;
        }

        return true;
    }

    /** SIWZ na obuwie / rękawice elektroizolacyjne (EN 50321, EN 60903, kV, klasa 0–4 AC). */
    public function requiresElectricalInsulation(string $text): bool
    {
        $t = $this->normalize($text);

        return preg_match(
            '/\b(elektroizolac\w*|dielektr\w*|en\s*50321|en\s*60903|\d+\s*kv\b|klasa\s*[0-4]\s*ac\b)/u',
            $t
        ) === 1;
    }

    /** Karta pokazuje elektroizolację (nazwa+SKU+kategoria+normy+opis). „Elektrostatyczne” / ESD to nie to. */
    public function productShowsElectricalInsulation(string $text): bool
    {
        $t = $this->normalize($text);

        return preg_match(
            '/\b(elektroizolac\w*|dielektr\w*|50321|60903|\d+\s*kv\b|antyamper)/u',
            $t
        ) === 1;
    }

    /**
     * Elektroizolacja to cecha bezpieczeństwa: wymagana, a niepokazana na karcie = odrzuć
     * (jak antystatyka). Zwykłe OB przy „półbuty elektroizolacyjne 20 kV” nie przechodzi.
     */
    public function productMeetsElectricalInsulationRequirement(string $requirement, Product $product): bool
    {
        if (! $this->requiresElectricalInsulation($requirement)) {
            return true;
        }

        return $this->productShowsElectricalInsulation($this->productFullText($product));
    }

    private function attributes(): BhpAttributeNormalizer
    {
        return $this->attributes ??= new BhpAttributeNormalizer;
    }

    /** Getry / nogawki — nie buty ani kalosze. */
    public function isFootwearLegwear(string $text): bool
    {
        $t = $this->normalize($text);

        return preg_match('/\b(getry|gaiter|nogawki|chaps|stirrup)\w*/u', $t) === 1;
    }

    /**
     * Typ obuwia z opisu karty — tylko gdy opis nazywa ten model (SKU, marka albo słowo z nazwy)
     * i typ stoi w pierwszym zdaniu („Trzewik bezpieczny ARDEUS 350 Air…”). Dalsze zdania
     * porównują z innymi modelami, więc ich nie czytamy. Wynik liczy się dopiero, gdy nazwa
     * karty to goły kod bez typu (W3‑10).
     */
    private function productDescriptionFootwearType(Product $product): ?string
    {
        if (! $this->descriptionNamesProduct($product)) {
            return null;
        }
        $description = trim((string) ($product->description ?? ''));
        $lead = preg_split('/(?<=[.!?])\s+/u', $description, 2)[0] ?? $description;

        return $this->footwearType($this->normalize(mb_substr($lead, 0, 200)));
    }

    private function footwearCompatible(
        string $requirement,
        string $productText,
        ?string $productEvidenceText = null,
        ?string $descriptionType = null,
    ): bool {
        $evidence = $productEvidenceText ?? $productText;
        if ($this->isFootwearLegwear($productText) && ! $this->isFootwearLegwear($requirement)) {
            $reqShoe = preg_match(
                '/\b(buty|obuwie|kalosz|trzewik|sztyblet|polbut|mokasyn|sandal|gumow\w*)\w*/u',
                $this->normalize($requirement)
            ) === 1;
            if ($reqShoe) {
                return false;
            }
        }
        if ($this->requiresAntistatic($requirement) && ! $this->productShowsAntistatic($evidence)) {
            return false;
        }
        // Półbuty OB z ESD to nie półbuty elektroizolacyjne 20 kV — brak dowodu = odrzuć, jak przy antystatyce.
        if ($this->requiresElectricalInsulation($requirement) && ! $this->productShowsElectricalInsulation($evidence)) {
            return false;
        }
        // Klasa z nazwy niższa niż wymagana (AROX „S1 ESD” przy sandałach S1 P) — obie strony znane.
        // Dotąd pilnowała tego tylko wyszukiwarka, przetarg brał tańszą kartę niższej klasy.
        $wantClass = $this->attributes()->footwearClass($requirement);
        if ($wantClass !== null) {
            $haveClass = $this->attributes()->footwearClass($productText);
            if ($haveClass !== null && ! $this->attributes()->footwearClassMeets($wantClass, $haveClass)) {
                return false;
            }
        }
        // Sandały (odkryta cholewka) nie spełnią S2+/O2+ — te klasy wymagają cholewki odpornej na wodę.
        // Karta bez typu w nazwie z klasą S3 (AROSERIO 750 618080 S3 ESD) to zakryte obuwie; karta, która
        // sama nazywa się sandałem, zostaje (klasa z nazwy i typ nie są tu rozstrzygane przeciwko sobie).
        if ($this->articleType($requirement, self::FAMILY_FOOTWEAR) === self::TYPE_SANDAL
            && $this->articleType($productText, self::FAMILY_FOOTWEAR) !== self::TYPE_SANDAL) {
            $productClass = $this->attributes()->footwearClass($productText);
            // S6/S7 (wydanie 2022) to S2/S3 z wodoodpornością — sandał tym bardziej ich nie spełni.
            if ($productClass !== null && preg_match('/^[SO][2-7]/u', mb_strtoupper($productClass)) === 1) {
                return false;
            }
        }
        $reqType = $this->articleType($requirement, self::FAMILY_FOOTWEAR);
        if ($reqType === null) {
            return true;
        }
        $prodType = $this->articleType($productText, self::FAMILY_FOOTWEAR);
        if ($prodType === null) {
            if ($reqType !== null) {
                // Nazwa to goły kod („ARDEUS 350 Air 618080 S1 PL ESD”), a własny opis karty nazywa
                // inny typ („Trzewik bezpieczny…”) — to nie sandały. Brak typu w opisie = brak wiedzy.
                if ($descriptionType !== null && $descriptionType !== $reqType
                    && ! $this->rubberBootMeetsAntistaticAsOtherType($requirement, $reqType, $descriptionType, $evidence)) {
                    return false;
                }
                $t = $this->normalize($productText);
                if ($reqType === self::TYPE_KALOSZ) {
                    if (preg_match(
                        '/\b(mata|arkusz|tasm|taśm|stolow|podlogow)\w*/u',
                        $t
                    ) === 1) {
                        return false;
                    }

                    return preg_match(
                        '/\b(kalosz|wellington|gumowc|gumiak|gumow\w*|guma|spodniobut|woder|overshoe)\w*/u',
                        $t
                    ) === 1;
                }

                return $this->productLooksLikeFootwear($productText);
            }

            return true;
        }

        if ($reqType !== $prodType) {
            return $this->rubberBootMeetsAntistaticAsOtherType($requirement, $reqType, $prodType, $evidence);
        }

        return true;
    }

    /** Antystatyczne „buty gumowe” z SIWZ mogą być trzewikiem / półbutem z gumy — jedyny wyjątek od „typ vs typ”. */
    private function rubberBootMeetsAntistaticAsOtherType(string $requirement, string $reqType, string $prodType, string $evidence): bool
    {
        return $this->requiresAntistatic($requirement)
            && $reqType === self::TYPE_KALOSZ
            && preg_match('/\b(gumow\w*|guma)\b/u', $this->normalize($evidence)) === 1
            && in_array($prodType, [self::TYPE_TRZEWIK, self::TYPE_POLBUT, self::TYPE_SZTYBLET], true);
    }

    /** Obuwie w katalogu bez typu w nazwie — S1/S3, „obuwie”, kalosz itd. */
    private function productLooksLikeFootwear(string $productText): bool
    {
        $t = $this->normalize($productText);
        if (preg_match(
            '/\b(mata|arkusz|tasm|taśm|stolow|podlogow|senso\s+dial)\w*/u',
            $t
        ) === 1) {
            return false;
        }

        // Klasy z wydania 2022 („S3L”, „S1 PL”, „S7”) też są dowodem, że karta bez typu to obuwie.
        return preg_match(
            '/\b(buty|obuwie|kalosz|trzewik|sztyblet|polbut|mokasyn|sandal|footwear'
            .'|\bs1\h?p?[ls]?\b|\bs[2-7][ls]?\b|\bo1\h?p?[ls]?\b|\bo[2-7][ls]?\b|\bsrc\b|\bfo\b|\bsr\b)\b/u',
            $t
        ) === 1;
    }

    public function requiresAntistatic(string $text): bool
    {
        $t = $this->normalize($text);

        return preg_match(
            '/\b(esd|antyelektrostat|antystatyczn|en\s*1149|1149[\s-]*5|61340)\w*/u',
            $t
        ) === 1;
    }

    public function productShowsAntistatic(string $text): bool
    {
        $t = $this->normalize($text);

        // „antistatic” / „anti-static”: karty z angielskim opisem (Ansell HyFlex 11-202: „extra features: antistatic”).
        return preg_match(
            '/\b(esd|antyelektrostat|antystatyczn|anti\s?static|en\s*1149|1149[\s-]*5|61340)\w*/u',
            $t
        ) === 1;
    }

    /** Obuwie: S1P + „antystatyczna podeszwa” ≠ ESD z SIWZ — stosuj przy dopisywaniu katalogu (PHP %), nie przy ocenie modelu. */
    public function productMeetsAntistaticRequirement(string $requirement, Product $product): bool
    {
        if (! $this->requiresAntistatic($requirement)) {
            return true;
        }
        if ($this->family($requirement) !== self::FAMILY_FOOTWEAR) {
            return $this->productShowsAntistatic($this->productCatalogEvidenceText($product));
        }

        return $this->footwearMeetsAntistaticRequirement($product);
    }

    private function footwearMeetsAntistaticRequirement(Product $product): bool
    {
        $identity = trim(implode(' ', array_filter([
            (string) $product->name,
            (string) $product->sku,
            (string) ($product->norms ?? ''),
            (string) ($product->category ?? ''),
        ])));
        $idN = $this->normalize($identity);
        if (preg_match('/\b(esd|antyelektrostat|1149[\s-]*5|61340)\b/u', $idN) === 1) {
            return true;
        }
        $desc = $this->normalize((string) ($product->description ?? ''));
        // Opis mówi wprost „ESD” / „antyelektrostatyczne” — to dowód (sandały ARMEN S1 P ESD bez ESD w nazwie).
        if (preg_match('/\b(esd|antyelektrostat)\w*/u', $desc) === 1) {
            return true;
        }
        // Sama „antystatyczna podeszwa” w opisie wystarcza tylko kaloszom / obuwiu gumowemu.
        if (preg_match('/\bantystatyczn\w*/u', $desc) !== 1) {
            return false;
        }

        return preg_match('/\b(gumow|guma|kalosz|wellington|gumowc|gumiak)\w*/u', $idN) === 1;
    }

    private function productCatalogEvidenceText(Product $product): string
    {
        return trim(implode(' ', array_filter([
            (string) $product->name,
            (string) $product->sku,
            (string) ($product->norms ?? ''),
            (string) ($product->description ?? ''),
        ])));
    }

    /** Etui / pojemnik — nie okulary ani gogle, nawet gdy w nazwie jest „okulary”. */
    public function isEyeWearAccessory(string $text): bool
    {
        $t = $this->normalize($text);

        return preg_match(
            '/\b(etui|futeral|case|pojemnik|pudelko|box|woreczek|pokrowiec|saszetk|wkladk\w*\s+piank)\w*/u',
            $t
        ) === 1;
    }

    private function eyeCompatible(
        string $requirement,
        string $productText,
        ?string $fullText = null,
        ?string $descriptionText = null,
    ): bool {
        $nameText = $productText;
        $hay = $fullText ?? $productText;
        if ($this->isEyeWearSet($requirement)) {
            return $this->eyeWearRole($nameText) !== null
                || $this->eyeWearRole($hay) !== null;
        }
        if ($this->isEyeWearAccessory($nameText) && ! $this->isEyeWearAccessory($requirement)) {
            return false;
        }
        $reqType = $this->articleType($requirement, self::FAMILY_EYES);
        if ($reqType === null) {
            return true;
        }
        $prodType = $this->articleTypePreferIdentity($nameText, $hay, self::FAMILY_EYES);
        if ($prodType === null) {
            // Nazwa handlowa bez rzeczownika („Przyciemnione (smoke) soczewki PC…”): typ z opisu,
            // ale tylko gdy identyfikator nie nazywa żadnej rodziny. Nieznany typ ≠ sprzeczność.
            if ($descriptionText !== null && $this->family($hay) === null) {
                $prodType = $this->articleType($descriptionText, self::FAMILY_EYES);
            }
            if ($prodType === null) {
                return true;
            }
        }

        return $reqType === $prodType;
    }

    /** Wkładki / komplet higieniczny do nauszników — nie jest ochronnikiem słuchu. */
    public function isHearingHygieneKit(string $text): bool
    {
        $t = $this->normalize($text);

        return preg_match(
            '/\b(komplet\s+higien|zestaw\s+higien|wkladk\w*\s+higien|higieniczn\w*\s+(komplet|zestaw|wklad)'
            .'|hygiene\s+kit|poduszk\w*\s+higien|cushion\s+kit|hygiene\s+pad)\w*/u',
            $t
        ) === 1;
    }

    private function hearingCompatible(string $requirement, Product $product): bool
    {
        $identity = $this->productIdentityText($product);
        if ($this->isHearingHygieneKit($identity) && ! $this->isHearingHygieneKit($requirement)) {
            return false;
        }
        $fromName = $this->family((string) $product->name);
        $prodFamily = $fromName
            ?? ($product->ppe_family !== null && $product->ppe_family !== '' ? (string) $product->ppe_family : null)
            ?? $this->productFamily($product);
        if ($prodFamily === null && $this->articleType($identity, self::FAMILY_HEARING) === null) {
            return false;
        }
        $reqType = $this->articleType($requirement, self::FAMILY_HEARING);
        $prodType = $this->articleType($identity, self::FAMILY_HEARING);
        if ($reqType !== null && $prodType !== null && $reqType !== $prodType) {
            return false;
        }
        $reqMount = $this->hearingMount($requirement);
        $prodMount = $this->hearingMount($identity);
        if ($reqMount !== null && $prodMount !== null && $reqMount !== $prodMount) {
            return false;
        }

        return true;
    }

    /**
     * Więźba i wentylacja z SIWZ — na nazwie, nie w kategorii.
     * „Fas-Trac” / „wentylowany” wygrywa z samym modelem V-Gard 500.
     */
    public function helmetSpecAllows(string $requirement, string $identity): bool
    {
        $wantHarness = $this->helmetHarness($requirement);
        $haveHarness = $this->helmetHarness($identity);
        if ($wantHarness !== null && $haveHarness !== null && $wantHarness !== $haveHarness) {
            return false;
        }
        if ($this->helmetVent($requirement) === self::VENT_OPEN
            && $this->helmetVent($identity) !== self::VENT_OPEN) {
            return false;
        }

        return true;
    }

    public function helmetHarness(string $text): ?string
    {
        $t = $this->normalize($text);
        if (preg_match('/\bfas\s*trac\b/u', $t) === 1) {
            return self::HARNESS_FASTRAC;
        }
        if (preg_match('/\bpush\s*key\b/u', $t) === 1) {
            return self::HARNESS_PUSHKEY;
        }

        return null;
    }

    public function helmetVent(string $text): ?string
    {
        $t = $this->normalize($text);
        if (preg_match('/\bwentylowan\w*/u', $t) === 1) {
            return self::VENT_OPEN;
        }

        return null;
    }

    /** Nahełmowe / do hełmu vs nagłowne / na pałąku. */
    public function hearingMount(string $text): ?string
    {
        $t = $this->normalize($text);
        if ($this->isHearingHygieneKit($t)) {
            return null;
        }
        if (preg_match('/\bnahelmow\w*/u', $t) === 1) {
            return self::MOUNT_HELMET;
        }
        if (preg_match('/\bnaglown\w*/u', $t) === 1) {
            return self::MOUNT_HEADBAND;
        }
        if (preg_match(
            '/\b(do\s+helm|na\s+helm|montowan\w*\s+(na\s+)?helm|na\s+palak|palak)\w*/u',
            $t
        ) === 1) {
            return str_contains($t, 'palak') ? self::MOUNT_HEADBAND : self::MOUNT_HELMET;
        }
        if (preg_match('/p3e/u', $t) === 1 && $this->hearingType($t) === 'earmuff') {
            return self::MOUNT_HELMET;
        }

        return null;
    }

    /** Adapter / mocowanie „do hełmu” to zwykle osłona twarzy albo nauszniki, nie sam kask. */
    private function helmetMountAllows(string $requirement, string $reqFamily, string $prodFamily): bool
    {
        if ($reqFamily !== self::FAMILY_HEAD) {
            return false;
        }
        $t = $this->normalize($requirement);
        if (preg_match('/\b(adapter|przejsc|mocowan|nosnik|laczen)\w*/u', $t) !== 1) {
            return false;
        }

        return in_array($prodFamily, [self::FAMILY_FACE, self::FAMILY_HEARING], true);
    }

    /**
     * Naramiennik / zarękawek / ochraniacz przedramienia („rękaw”) — nie jest rękawicą
     * (dłoń zostaje odkryta). Rękawica „z rękawem 40 cm” to nadal rękawica, a rękawy
     * kurtki czy fartucha to odzież — tam rzeczownik „rękaw” nic nie znaczy.
     */
    public function isArmSleeve(string $text): bool
    {
        $t = $this->normalize($text);
        $sleeveAt = $this->firstWordOffset(
            '/\b(naramiennik|narekawnik|zarekaw|arm\s*sleeves?|armguards?|arm\s*guards?|arm\s*protectors?|manchon'
            .'|ochraniacz\w*\s+(przed)?ramien|cut[\s-]*resistant\s+sleeves?)\w*'
            .'|\brekaw(y|a|u|ow|em|ie|ach|om|ami)?\b|primacuff|\bcuffs\b/u',
            $t
        );
        if ($sleeveAt === null) {
            return false;
        }
        // „Rękawice … z rękawem” — rzeczownik główny stoi pierwszy; folder „Rękawice” za nazwą zarękawka nie liczy się.
        $gloveAt = $this->firstWordOffset('/\brekawic\w*/u', $t);
        if ($gloveAt !== null && $gloveAt < $sleeveAt) {
            return false;
        }

        return $this->family($t) !== self::FAMILY_APPAREL;
    }

    /**
     * Rękaw po stronie karty: z identyfikatora; z opisu tylko gdy identyfikator to goły kod
     * bez rzeczownika rodziny („HyFlex 11202 SIZE 19''”) i opis nazywa ten model.
     */
    private function productIsArmSleeve(Product $product): bool
    {
        $identity = $this->productIdentityText($product);
        if ($this->isArmSleeve($identity)) {
            return true;
        }
        if ($this->family($identity) !== null || ! $this->descriptionNamesProduct($product)) {
            return false;
        }

        return $this->isArmSleeve((string) ($product->description ?? ''));
    }

    /**
     * Opis nazywa kartę po imieniu (SKU, marka albo słowo z nazwy ≥ 4 znaki) — inaczej to
     * cudzy opis i nie może świadczyć o typie. Wzór: AuditProductDescriptionsCommand::descriptionSharesToken.
     */
    private function descriptionNamesProduct(Product $product): bool
    {
        $hay = mb_strtolower((string) ($product->description ?? ''));
        if (trim($hay) === '') {
            return false;
        }
        foreach ([(string) $product->sku, (string) ($product->manufacturer ?? '')] as $value) {
            $value = mb_strtolower(trim($value));
            if ($value !== '' && str_contains($hay, $value)) {
                return true;
            }
        }
        foreach (preg_split('/[\s\-®™\/_,.]+/u', mb_strtolower((string) $product->name)) ?: [] as $token) {
            $token = trim($token);
            if (mb_strlen($token) < 4 || in_array($token, self::DESCRIPTION_STOP_TOKENS, true)) {
                continue;
            }
            if (str_contains($hay, $token)) {
                return true;
            }
        }

        return false;
    }

    private function productNameText(Product $product): string
    {
        return trim(implode(' ', array_filter([
            (string) $product->name,
            (string) $product->sku,
        ])));
    }

    private function productIdentityText(Product $product): string
    {
        return trim(implode(' ', array_filter([
            $this->productNameText($product),
            (string) ($product->category ?? ''),
        ])));
    }

    private function productFullText(Product $product): string
    {
        return trim(implode(' ', array_filter([
            $this->productIdentityText($product),
            (string) ($product->description ?? ''),
            // Karta dostawcy: po wyprowadzeniu tabelek z opisu to jedyne miejsce z normą czy materiałem,
            // a z tego tekstu liczy się ppe_family i bramki asortymentu.
            (string) ($product->shop_fields_summary ?? ''),
            (string) ($product->norms ?? ''),
        ])));
    }

    private function apparelCompatible(string $req, string $roleText, ?string $identity = null): bool
    {
        $identity ??= $roleText;
        $reqRoles = $this->roles($req);
        $prodRoles = $this->roles($roleText);
        if ($reqRoles !== [] && $prodRoles !== [] && array_intersect($reqRoles, $prodRoles) === []) {
            return false;
        }

        $reqGarment = $this->garment($req);
        $prodGarment = $this->garment($identity);
        if ($this->isApparelAccessory($identity) && in_array($reqGarment, ['set', 'jacket', 'pants'], true)) {
            return false;
        }
        if ($reqGarment === 'set') {
            if (in_array($prodGarment, ['set', 'jacket', 'pants'], true)) {
                return true;
            }

            return $prodGarment === null
                && preg_match('/\b(bluz|kurtk|spodn|ogrodniczk|ubran)\w*/u', $this->normalize($identity)) === 1;
        }
        if ($reqGarment !== null && $prodGarment !== null && $reqGarment !== $prodGarment) {
            return false;
        }

        $reqNorm = $this->normalize($req);
        $prodNorm = $this->normalize($identity);
        $reqSet = $this->isApparelSet($req);
        $prodSet = preg_match('/\b(spodn|komplet|zestaw|ubran)\w*/u', $prodNorm) === 1;
        if ($reqSet && preg_match('/\b(bluz|kurtk)\w*/u', $prodNorm) === 1 && ! $prodSet) {
            return false;
        }

        return true;
    }

    private function isApparelAccessory(string $text): bool
    {
        $t = $this->normalize($text);
        if (preg_match('/\b(kaptur|czapk|czepek|kominiark|balaclava)\w*/u', $t) !== 1) {
            return false;
        }

        return preg_match('/\b(kurtk|bluz|spodn|ogrodniczk|kombinezon)\w*/u', $t) !== 1;
    }
}
