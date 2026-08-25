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
            .'|maska\s+(twarzow|pelnotwarz)|czesc\s+twarzow)\w*/u',
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
            '/\b(nausznik|wkladk\w*\s+sluch|stoper\w*|ochrona\s+sluchu|sluchawk\w*\s+ochron)\w*/u',
            $t
        ) === 1) {
            return self::FAMILY_HEARING;
        }

        if (preg_match(
            '/\b(szelk|linka\s+bezpieczen|amortyzator|asekurac|urzadzeni\w*\s+samoham|lonza)\w*/u',
            $t
        ) === 1) {
            return self::FAMILY_FALL;
        }

        if (preg_match('/\b(nakolann|ochrona\s+kolan|knee\s*pad)\w*/u', $t) === 1) {
            return self::FAMILY_KNEE;
        }

        if (preg_match(
            '/\b(odziez|kurtk|spodn|kombinezon|kamizelk|softshell|fartuch|kitel|bluza'
            .'|kaleson|ogrodniczk|park[ae]|peleryn)\w*/u',
            $t
        ) === 1) {
            return self::FAMILY_APPAREL;
        }

        if (preg_match('/\b(kominiark|czapk|helm|kask|czepek|balaclava)\w*/u', $t) === 1) {
            return self::FAMILY_HEAD;
        }

        if (preg_match(
            '/\b(trzewik|polbut|sandal|obuwie|buty|butow|footwear|podeszw|podnosek'
            .'|\bs1p?\b|\bs3\b)\b/u',
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
            return false;
        }
        if ($reqFamily === self::FAMILY_APPAREL) {
            return $this->apparelCompatible($requirement, $productText);
        }

        return true;
    }

    public function compatibleProduct(string $requirement, Product $product): bool
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
        $kat = is_string($attrs['kategoria_bhp'] ?? null) ? $attrs['kategoria_bhp'] : null;

        $identity = trim(implode(' ', array_filter([
            (string) $product->name,
            (string) ($product->category ?? ''),
        ])));
        $full = trim(implode(' ', array_filter([
            $identity,
            (string) ($product->description ?? ''),
            (string) ($product->norms ?? ''),
        ])));

        // Nazwa i kategoria mówią, czym produkt JEST. Opis wymienia też akcesoria i
        // sąsiednie środki ochrony („kieszenie na nakolanniki” w spodniach), więc o
        // rodzinie decyduje dopiero wtedy, gdy tamte milczą.
        $familyText = $this->family($identity) !== null ? $identity : $full;

        $reqFamily = $this->family($requirement);
        if ($reqFamily === null) {
            return true;
        }
        $prodFamily = $this->resolveFamily($familyText, $kat);
        if ($prodFamily === null || $reqFamily !== $prodFamily) {
            return false;
        }
        if ($reqFamily === self::FAMILY_APPAREL) {
            return $this->apparelCompatible($requirement, $full);
        }

        return true;
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
