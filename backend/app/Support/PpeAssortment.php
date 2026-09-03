<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;

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
        $s = strtr($s, $map);

        return preg_replace('/[^a-z0-9\s]/', ' ', $s) ?? $s;
    }

    public function family(string $text): ?string
    {
        $t = $this->normalize($text);

        if (preg_match('/\b(rekawic|glove|handschuh)\w*/u', $t) === 1) {
            return self::FAMILY_GLOVES;
        }

        if (preg_match(
            '/\b(polmask|respirator|aparat\w*\s+oddech|drog[iy]\s+oddech|filtrow?\w*\s+oddech'
            .'|maska\s+(twarzow|pelnotwarz|filtruj|przeciwpyl)|czesc\s+twarzow'
            .'|pochlaniacz|filtropochlaniacz|ffp[123]?)\w*/u',
            $t
        ) === 1) {
            return self::FAMILY_RESPIRATORY;
        }

        $hasHelm = preg_match('/\b(helm|kask)\w*/u', $t) === 1;
        if (! $hasHelm && preg_match(
            '/\b(przylbic|oslon\w{0,10}\s+\w{0,16}twarz|twarz\w{0,8}\s+\w{0,12}oslon'
            .'|oslona\s+twarzy|face\s*shield|siatk\w*\s+(na\s+)?twarz|maska\s+spawal)\w*/u',
            $t
        ) === 1) {
            return self::FAMILY_FACE;
        }

        if (preg_match('/\b(okular|gogl|szyba\s+ochronn)\w*/u', $t) === 1) {
            return self::FAMILY_EYES;
        }

        if (preg_match(
            '/\b(nausznik|ochronnik\w*\s+sluch|czasze\s+przeciwhal|wkladk\w*\s+sluch'
            .'|stoper\w*|ochrona\s+sluchu|sluchawk\w*\s+ochron)\w*/u',
            $t
        ) === 1) {
            return self::FAMILY_HEARING;
        }

        if (preg_match(
            '/\b(szelk|linka\s+bezpieczen|amortyzator|asekurac|urzadzeni\w*\s+samoham|lonza'
            .'|ewakuac|podnoszac|opuszczaj|wciagark)\w*/u',
            $t
        ) === 1) {
            return self::FAMILY_FALL;
        }

        if (preg_match('/\b(nakolann|ochrona\s+kolan|knee\s*pad)\w*/u', $t) === 1) {
            return self::FAMILY_KNEE;
        }

        if (preg_match(
            '/\b(odziez|kurtk|spodn|podnie|kombinezon|kamizelk|kamizelak|softshell|fartuch|kitel|bluza'
            .'|kaleson|ogrodniczk|park[ae]|peleryn)\w*/u',
            $t
        ) === 1) {
            return self::FAMILY_APPAREL;
        }

        if (preg_match(
            '/\b(kominiark|czapk|helm|kask|czepek|balaclava|liner)\w*'
            .'|(wkladk\w*.{0,24}(helm|kask))/u',
            $t
        ) === 1) {
            return self::FAMILY_HEAD;
        }

        if (preg_match(
            '/\b(trzewik|sztyblet|polbut|mokasyn|sandal|obuwie|buty|butow|footwear|podeszw|podnosek'
            .'|\bs1p?\b|\bs[2-5]\b|\bo[1-5]\b)\b/u',
            $t
        ) === 1) {
            return self::FAMILY_FOOTWEAR;
        }

        return $this->familyFromNorms($t);
    }

    private function familyFromNorms(string $normalized): ?string
    {
        if (preg_match('/\b(388|420|511|21420)\b/u', $normalized) === 1) {
            return self::FAMILY_GLOVES;
        }
        if (preg_match('/\b(20345|20347)\b/u', $normalized) === 1) {
            return self::FAMILY_FOOTWEAR;
        }
        if (preg_match('/\b166\b/u', $normalized) === 1) {
            return self::FAMILY_EYES;
        }
        if (preg_match('/\b352\b/u', $normalized) === 1) {
            return self::FAMILY_HEARING;
        }
        if (preg_match('/\b(149|140|143)\b/u', $normalized) === 1) {
            return self::FAMILY_RESPIRATORY;
        }
        if (preg_match('/\b(361|358)\b/u', $normalized) === 1) {
            return self::FAMILY_FALL;
        }
        if (preg_match('/\b397\b/u', $normalized) === 1) {
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
        if (preg_match('/\b(ubranie ochron|komplet|zestaw)\w*/u', $t) === 1
            && preg_match('/\b(bluza|kurtk).{0,24}spodn|spodn.{0,24}(bluza|kurtk)/u', $t) === 1) {
            return 'set';
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
            default => $family === null ? $this->footwearType($t) : null,
        };
    }

    private function footwearType(string $t): ?string
    {
        if (preg_match('/\b(kalosz|wellington|gumowc|gumiak|purofort|wader|gumboot)\w*/u', $t) === 1) {
            return self::TYPE_KALOSZ;
        }
        if (preg_match('/\b(sandal)\w*/u', $t) === 1) {
            return self::TYPE_SANDAL;
        }
        if (preg_match('/\b(sztyblet|chelsea)\w*/u', $t) === 1) {
            return self::TYPE_SZTYBLET;
        }
        if (preg_match('/\b(trzewik|ankle\s*boot)\w*/u', $t) === 1) {
            return self::TYPE_TRZEWIK;
        }
        if (preg_match('/\b(mokasyn|polbut|polbuty|low\s*shoe)\w*/u', $t) === 1) {
            return self::TYPE_POLBUT;
        }

        return null;
    }

    private function respiratoryType(string $t): ?string
    {
        $isFilterNoun = preg_match(
            '/\b(pochlaniacz|filtropochlaniacz|wklad\w*|element\w*\s+oczyszcz)\w*/u',
            $t
        ) === 1;
        $isMaskNoun = preg_match('/\b(polmask|maska|ffp|pelnotwarz|respirator)\w*/u', $t) === 1;
        if ($isFilterNoun && ! $isMaskNoun) {
            return 'filter';
        }

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

    private function eyeType(string $t): ?string
    {
        if (preg_match('/\b(gogl)\w*/u', $t) === 1) {
            return 'goggles';
        }
        if (preg_match('/\b(okular)\w*/u', $t) === 1) {
            return 'glasses';
        }

        return null;
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
            return true;
        }
        if ($this->isUnderHelmetType($reqType) && $this->isUnderHelmetType($prodType)) {
            return true;
        }

        return $reqType === $prodType;
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
        $t = $this->normalize($text);
        if (preg_match('/\b20471\b|odblask|ostrzegawcz|hi.?vis|wysokiej widzial/u', $t) === 1) {
            return 'hivis';
        }
        if (preg_match('/\bspawal|11611|welding|welder/u', $t) === 1) {
            return 'welding';
        }
        if (preg_match('/\beletryk|1149|61482|lukiem|antystatyczn/u', $t) === 1) {
            return 'electric';
        }
        if (preg_match('/\bzaroodporn|11612\b/u', $t) === 1) {
            return 'heat';
        }
        if (preg_match('/\bwodoochron|przeciwdeszcz|\b343\b|deszczow/u', $t) === 1) {
            return 'rain';
        }

        return null;
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
        if ($reqFamily !== $prodFamily) {
            if (! $this->helmetMountAllows($requirement, $reqFamily, $prodFamily)) {
                return false;
            }
        }
        if ($reqFamily === self::FAMILY_APPAREL) {
            return $this->apparelCompatible($requirement, $productText);
        }
        if ($reqFamily === self::FAMILY_HEAD) {
            return $this->headCompatible($requirement, $productText);
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

        return $this->resolveFamily($familyText, $kat);
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
            self::TYPE_KALOSZ => ['kalosz', 'wellington', 'gumowc', 'gumiak', 'purofort'],
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

        if ($prodFamily !== null && $reqFamily !== $prodFamily) {
            if (! $this->helmetMountAllows($requirement, $reqFamily, $prodFamily)) {
                return false;
            }
        }
        if ($reqFamily === self::FAMILY_APPAREL) {
            return $this->apparelCompatible($requirement, $this->productFullText($product));
        }
        if ($reqFamily === self::FAMILY_GLOVES && ! $this->isArmSleeve($requirement)
            && $this->isArmSleeve($this->productIdentityText($product))) {
            return false;
        }
        if ($reqFamily === self::FAMILY_HEAD) {
            return $this->headCompatible($requirement, $this->productIdentityText($product));
        }
        if ($reqFamily === self::FAMILY_HEARING) {
            return $this->hearingCompatible($requirement, $product);
        }

        return true;
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

        return true;
    }

    /** Adapter / mocowanie „do hełmu” to zwykle osłona twarzy albo nauszniki, nie sam kask. */
    private function helmetMountAllows(string $requirement, string $reqFamily, string $prodFamily): bool
    {
        if ($reqFamily !== self::FAMILY_HEAD) {
            return false;
        }
        $t = $this->normalize($requirement);
        if (preg_match('/\b(adapter|przejsc|mocowan)\w*/u', $t) !== 1) {
            return false;
        }

        return in_array($prodFamily, [self::FAMILY_FACE, self::FAMILY_HEARING], true);
    }

    /** Naramiennik / zarękawek — nie jest rękawicą (dłoń zostaje odkryta). */
    public function isArmSleeve(string $text): bool
    {
        $t = $this->normalize($text);

        return preg_match(
            '/\b(naramiennik|zarekawk|arm\s*sleeves?|armguards?|arm\s*guards?|manchon)\w*/u',
            $t
        ) === 1;
    }

    private function productIdentityText(Product $product): string
    {
        return trim(implode(' ', array_filter([
            (string) $product->name,
            (string) ($product->category ?? ''),
        ])));
    }

    private function productFullText(Product $product): string
    {
        return trim(implode(' ', array_filter([
            $this->productIdentityText($product),
            (string) ($product->description ?? ''),
            (string) ($product->norms ?? ''),
        ])));
    }

    private function apparelCompatible(string $req, string $prodText): bool
    {
        $reqRole = $this->role($req);
        $prodRole = $this->role($prodText);
        if ($reqRole !== null && $prodRole !== null && $reqRole !== $prodRole) {
            return false;
        }

        $reqGarment = $this->garment($req);
        $prodGarment = $this->garment($prodText);
        if ($reqGarment !== null && $prodGarment !== null && $reqGarment !== $prodGarment) {
            return false;
        }

        $reqNorm = $this->normalize($req);
        $prodNorm = $this->normalize($prodText);
        $reqSet = preg_match('/\b(bluza.{0,12}spodn|spodn.{0,12}bluza|ubranie ochron|komplet|zestaw)\w*/u', $reqNorm) === 1;
        $prodSet = preg_match('/\b(spodn|komplet|zestaw|ubranie)\w*/u', $prodNorm) === 1;
        if ($reqSet && preg_match('/\b(bluz|kurtk)\w*/u', $prodNorm) === 1 && ! $prodSet) {
            return false;
        }

        return true;
    }
}
