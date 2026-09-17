<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;

/**
 * Mini-schemat atrybutów BHP (kanoniczne pola w enrichment_payload.attributes).
 */
final class BhpAttributeNormalizer
{
    /** @var list<string> */
    public const KATEGORIE = [
        'rekawice', 'obuwie', 'odziez',
        'ochrona_glowy', 'ochrona_twarzy', 'ochrona_oczu', 'ochrona_sluchu',
        'drogi_oddechowe', 'asekuracja', 'ochrona_kolan', 'inne',
    ];

    /**
     * Klasy obuwia z EN ISO 20345 / 20347 (wydania 2011 i 2022) — treść wzorca wspólna dla parsera
     * kartotek i dla LevelCheckera, żeby oba czytały tę samą klasę z tego samego napisu.
     *
     * Spacja przed P i przed literą wkładki jest w zapisach dostawców („ARMEN 9007 6660 S1 P”,
     * „ARDEUS 350 Air 618080 S1 PL”) — bez niej sandały antyprzebiciowe udawały S1. Litera L/S na końcu
     * to typ wkładki antyprzebiciowej z wydania 2022 (S1PL, S3S, S5L, S7L); klasy bez wkładki
     * (SB, S1, S2, S4, S6 i ich odpowiedniki O) tego sufiksu nie biorą, więc „S2 L” to nadal S2.
     */
    public const FOOTWEAR_CLASS = 'S1\h?P\h?[LS]?|S[357]\h?[LS]?|S[B1246]|O1\h?P\h?[LS]?|O[357]\h?[LS]?|O[B1246]';

    /** Ten sam wzorzec w wersji dla kartotek: także małymi literami, bo opisy bywają pisane zwykłym tekstem. */
    private const FOOTWEAR_CLASS_RE = '/(?<![\p{L}\d])('.self::FOOTWEAR_CLASS.')(?![\p{L}\d])/iu';

    /**
     * @return array{
     *     kategoria_bhp: ?string,
     *     kod_producenta: ?string,
     *     material: ?string,
     *     materialy: list<string>,
     *     normy_en: list<string>,
     *     klasa_ochrony: ?string,
     *     rozmiar: ?string,
     *     poziomy_en388: ?string,
     *     typ_wyrobu: ?string,
     *     przeznaczenie: ?string,
     *     oznaczenia: list<string>,
     *     rodzina_materialu: ?string
     * }
     */
    public function empty(): array
    {
        return [
            'kategoria_bhp' => null,
            'kod_producenta' => null,
            'material' => null,
            'materialy' => [],
            'normy_en' => [],
            'klasa_ochrony' => null,
            'rozmiar' => null,
            'poziomy_en388' => null,
            'typ_wyrobu' => null,
            'przeznaczenie' => null,
            'oznaczenia' => [],
            'rodzina_materialu' => null,
        ];
    }

    /**
     * Atrybuty z produktu: zapisane w payload lub wyprowadzone z list enrichment.
     *
     * @return array{
     *     kategoria_bhp: ?string,
     *     kod_producenta: ?string,
     *     material: ?string,
     *     materialy: list<string>,
     *     normy_en: list<string>,
     *     klasa_ochrony: ?string,
     *     rozmiar: ?string,
     *     poziomy_en388: ?string,
     *     typ_wyrobu: ?string,
     *     przeznaczenie: ?string,
     *     oznaczenia: list<string>,
     *     rodzina_materialu: ?string
     * }
     */
    public function forProduct(Product $product): array
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $useCases = $this->stringList($payload['use_cases'] ?? null);
        $features = $this->stringList($payload['features'] ?? null);
        $haystack = trim(implode("\n", array_filter([
            (string) ($product->name ?? ''),
            (string) ($product->description ?? ''),
            // Tabelka z karty dostawcy: od etapu 2 normy i materiały stoją poza opisem (u Protektu nigdy nie były
            // prozą opisu), więc bez tego źródła wykrywanie norm i materiałów gubiłoby je razem z przeprowadzką.
            (string) ($product->shop_fields_summary ?? ''),
            (string) ($product->category ?? ''),
            (string) ($product->norms ?? ''),
            ...$useCases,
            ...$features,
        ])));

        return $this->normalize(
            is_array($payload['attributes'] ?? null) ? $payload['attributes'] : null,
            [
                'materials' => array_values(array_unique(array_merge(
                    $this->stringList($payload['materials'] ?? null),
                    $this->detectMaterialsFromText($haystack),
                ))),
                'norms' => array_values(array_unique(array_merge(
                    $this->stringList($payload['norms'] ?? null),
                    $this->detectNormsFromText($haystack),
                ))),
                'specs' => $this->stringList($payload['specs'] ?? null),
                'certificates' => $this->stringList($payload['certificates'] ?? null),
                'use_cases' => $useCases,
                'category' => (string) ($product->category ?? ''),
                'sku' => (string) ($product->sku ?? ''),
                'name' => (string) ($product->name ?? ''),
                'description' => (string) ($product->description ?? ''),
                // Osobny klucz, nie doklejenie do opisu — to dane dostawcy, a nie opis wyrobu.
                'shop_fields' => (string) ($product->shop_fields_summary ?? ''),
                'norms_column' => (string) ($product->norms ?? ''),
                // Parametry wypisane w samym cenniku dostawcy — dokument producenta, nie odczyt ze strony.
                'price_list' => is_array($product->price_list_attributes) ? $product->price_list_attributes : [],
            ]
        );
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @param  array{
     *     materials?: list<string>,
     *     norms?: list<string>,
     *     specs?: list<string>,
     *     certificates?: list<string>,
     *     category?: string,
     *     sku?: string,
     *     name?: string,
     *     description?: string,
     *     shop_fields?: string,
     *     norms_column?: string,
     *     price_list?: array<string, string>
     * }  $context
     * @return array{
     *     kategoria_bhp: ?string,
     *     kod_producenta: ?string,
     *     material: ?string,
     *     materialy: list<string>,
     *     normy_en: list<string>,
     *     klasa_ochrony: ?string,
     *     rozmiar: ?string,
     *     poziomy_en388: ?string,
     *     typ_wyrobu: ?string,
     *     przeznaczenie: ?string,
     *     oznaczenia: list<string>,
     *     rodzina_materialu: ?string
     * }
     */
    public function normalize(?array $raw, array $context = []): array
    {
        $out = $this->empty();
        $raw = $raw ?? [];

        $identity = ($context['category'] ?? '').' '
            .($context['name'] ?? '').' '
            .($context['sku'] ?? '');
        $katText = $identity.' '.($context['description'] ?? '');

        $out['kategoria_bhp'] = $this->normalizeKategoria(
            $this->nullableString($raw['kategoria_bhp'] ?? null)
            ?? $this->detectKategoria($katText)
        );

        $kod = $this->nullableString($raw['kod_producenta'] ?? null);
        $name = (string) ($context['name'] ?? '');
        $sku = (string) ($context['sku'] ?? '');
        if ($kod !== null && $this->codeConflictsWithIdentity($kod, $name, $sku)) {
            $kod = $this->catalogCodeFromIdentity($name, $sku);
        }
        $out['kod_producenta'] = $kod
            ?? $this->catalogCodeFromIdentity($name, $sku)
            ?? $this->nullableString($sku !== '' ? $sku : null);

        $priceList = is_array($context['price_list'] ?? null) ? $context['price_list'] : [];
        $materials = array_values(array_unique(array_merge(
            $this->stringList($raw['materialy'] ?? null),
            $this->stringList($context['materials'] ?? null),
        )));
        $primary = $this->nullableString($priceList['material'] ?? null)
            ?? $this->nullableString($raw['material'] ?? null);
        if ($primary !== null && ! in_array($primary, $materials, true)) {
            array_unshift($materials, $primary);
        }
        if ($primary === null && $materials !== []) {
            $primary = $materials[0];
        }
        $out['material'] = $primary;
        $out['materialy'] = $materials;

        $normy = array_values(array_unique(array_merge(
            // normy z cennika idą pierwsze — przy skracaniu listy zostają te z dokumentu producenta
            $this->splitNormsColumn((string) ($priceList['normy'] ?? '')),
            $this->stringList($raw['normy_en'] ?? null),
            $this->stringList($context['norms'] ?? null),
            $this->splitNormsColumn($context['norms_column'] ?? ''),
        )));
        $out['normy_en'] = $normy;

        $descBlob = implode(' ', array_merge(
            $normy,
            $this->stringList($context['specs'] ?? null),
            $this->stringList($context['certificates'] ?? null),
            $this->stringList($context['use_cases'] ?? null),
            // Wiersze z karty dostawcy obok opisu — klasa ochrony, poziomy EN 388 i oznaczenia bywają tylko tam.
            [
                $context['name'] ?? '',
                $context['description'] ?? '',
                $context['shop_fields'] ?? '',
                $context['norms_column'] ?? '',
            ],
        ));

        // Klasa z cennika dostawcy bije to, co model wyczytał ze stron: cennik jest dokumentem producenta
        // z datą obowiązywania, a strona sklepu bywa cudzą kartą. Doprecyzowanie zapisanej klasy bierzemy
        // wyłącznie z tożsamości wyrobu (nazwa, kod, kolumna norm), nigdy z prozy opisu — zdanie „dostępny
        // też w wersji S1P” nadałoby karcie wkładkę antyprzebiciową, której ten but nie ma.
        $parsed = $this->parseKlasaAndMarkings(
            $this->nullableString($priceList['klasa_ochrony'] ?? null)
                ?? $this->nullableString($raw['klasa_ochrony'] ?? null),
            $descBlob,
            trim(($context['name'] ?? '').' '.($context['sku'] ?? '').' '.($context['norms_column'] ?? ''))
        );
        $out['klasa_ochrony'] = $parsed['klasa'];
        $out['oznaczenia'] = $parsed['oznaczenia'];

        $out['rozmiar'] = $this->detectRozmiar(
            implode(' ', $this->stringList($context['specs'] ?? null)).' '.($context['description'] ?? ''),
            $this->nullableString($priceList['rozmiar'] ?? null) ?? $this->nullableString($raw['rozmiar'] ?? null),
            $out['kategoria_bhp']
        );

        $out['poziomy_en388'] = $this->nullableString($raw['poziomy_en388'] ?? null)
            ?? $this->detectEn388($descBlob);

        $assortment = new PpeAssortment;
        $typeBlob = $identity.' '.$descBlob;
        $family = $assortment->familyFromKategoria($out['kategoria_bhp']);
        $out['typ_wyrobu'] = $this->nullableString($raw['typ_wyrobu'] ?? null)
            ?? $assortment->articleTypePreferIdentity($identity, $typeBlob, $family);
        $out['przeznaczenie'] = $this->nullableString($raw['przeznaczenie'] ?? null)
            ?? $assortment->purpose($typeBlob);
        $out['rodzina_materialu'] = $this->materialFamily($primary, $materials, $typeBlob);

        return $out;
    }

    /** @return list<string> */
    private function detectMaterialsFromText(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }
        $t = $this->normalizeText($text);
        $found = [];
        $map = [
            'nitryl' => 'nitryl',
            'nitrile' => 'nitryl',
            'nbr' => 'nitryl',
            'lateks' => 'lateks',
            'latex' => 'lateks',
            'nylon' => 'nylon',
            'spandex' => 'spandex',
            'hppe' => 'HPPE',
            'poliuretan' => 'PU',
            '\bpu\b' => 'PU',
            'skora' => 'skóra',
            'leather' => 'skóra',
            'bawelna' => 'bawełna',
            'cotton' => 'bawełna',
            'neopren' => 'neopren',
            'pvc' => 'PVC',
        ];
        foreach ($map as $needle => $label) {
            $pattern = str_starts_with($needle, '\\') ? '/'.$needle.'/u' : '/\b'.preg_quote($needle, '/').'\w*/u';
            if (preg_match($pattern, $t) === 1) {
                $found[] = $label;
            }
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    public function detectNormsFromText(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }
        if (preg_match_all('/\bEN(?:\s*ISO)?\s*\d{3,5}(?::\s*\d{4})?(?:\s*\+\s*A\d+)?\b/iu', $text, $m) < 1) {
            return [];
        }
        $out = [];
        foreach ($m[0] as $raw) {
            $norm = preg_replace('/\s+/', ' ', trim($raw));
            if (is_string($norm) && $norm !== '') {
                $out[] = $norm;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Płaski tekst do haystack / embedding.
     *
     * @param  array<string, mixed>  $attrs
     */
    public function toSearchText(array $attrs): string
    {
        $parts = [
            $attrs['kategoria_bhp'] ?? null,
            $attrs['kod_producenta'] ?? null,
            $attrs['material'] ?? null,
            $attrs['klasa_ochrony'] ?? null,
            $attrs['typ_wyrobu'] ?? null,
            $attrs['przeznaczenie'] ?? null,
            $attrs['rozmiar'] ?? null,
            $attrs['poziomy_en388'] ?? null,
        ];
        if (is_array($attrs['oznaczenia'] ?? null)) {
            $parts = array_merge($parts, $attrs['oznaczenia']);
        }
        if (is_array($attrs['materialy'] ?? null)) {
            $parts = array_merge($parts, $attrs['materialy']);
        }
        if (is_array($attrs['normy_en'] ?? null)) {
            $parts = array_merge($parts, $attrs['normy_en']);
        }

        return trim(implode(' ', array_filter(
            array_map(static fn ($v) => is_string($v) ? trim($v) : '', $parts),
            static fn (string $v): bool => $v !== ''
        )));
    }

    /**
     * Wpisy listy, które zawierają kod normy (EN, EN ISO, PN-EN, ISO z numerem) — w brzmieniu źródła.
     * Cechy z PrestaShop („1 sztuka”, „Silikon”, „Bagnetowe Secura”) to nie normy, a import wpisywał je
     * do normy_en i do kolumny norms (SECURA 3000 S56T0SM0, przetarg 1 poz. 13).
     *
     * @param  array<mixed>  $values
     * @return list<string>
     */
    public function normEntries(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = trim(is_scalar($value) ? (string) $value : '');
            if ($value !== '' && preg_match('/\b(?:EN|ISO)[\s-]*(?:ISO[\s-]*)?\d{3,5}/iu', $value) === 1) {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    private function normalizeKategoria(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = mb_strtolower(trim($value));
        $map = [
            'rekawice' => 'rekawice',
            'rękawice' => 'rekawice',
            'gloves' => 'rekawice',
            'obuwie' => 'obuwie',
            'buty' => 'obuwie',
            'footwear' => 'obuwie',
            'odziez' => 'odziez',
            'odzież' => 'odziez',
            'apparel' => 'odziez',
            'ochrona_glowy' => 'ochrona_glowy',
            'kask' => 'ochrona_glowy',
            'helmet' => 'ochrona_glowy',
            'ochrona_twarzy' => 'ochrona_twarzy',
            'oslona twarzy' => 'ochrona_twarzy',
            'osłona twarzy' => 'ochrona_twarzy',
            'face' => 'ochrona_twarzy',
            'ochrona_oczu' => 'ochrona_oczu',
            'gogle' => 'ochrona_oczu',
            'okulary' => 'ochrona_oczu',
            'ochrona_sluchu' => 'ochrona_sluchu',
            'nauszniki' => 'ochrona_sluchu',
            'drogi_oddechowe' => 'drogi_oddechowe',
            'polmaska' => 'drogi_oddechowe',
            'półmaska' => 'drogi_oddechowe',
            'asekuracja' => 'asekuracja',
            'szelki' => 'asekuracja',
            'ochrona_kolan' => 'ochrona_kolan',
            'inne' => 'inne',
        ];

        return $map[$v] ?? (in_array($v, self::KATEGORIE, true) ? $v : null);
    }

    private function detectKategoria(string $text): ?string
    {
        return (new PpeAssortment)->kategoria($text);
    }

    /**
     * @return array{klasa: ?string, oznaczenia: list<string>}
     */
    private function parseKlasaAndMarkings(?string $rawKlasa, string $blob, string $identity = ''): array
    {
        $hay = trim(($rawKlasa ?? '').' '.$blob);
        $oznaczenia = $this->extractMarkings($hay);
        $klasa = $this->extractFfpClass($rawKlasa ?? '')
            ?? $this->footwearClassFromRawAndBlob($rawKlasa ?? '', $identity)
            ?? $this->detectKlasa($hay);

        return ['klasa' => $klasa, 'oznaczenia' => $oznaczenia];
    }

    /**
     * Klasa zapisana wcześniej w attributes bywa zdegradowana (karta „ARDEUS 350 Air 618080 S1 PL ESD”
     * miała w payloadzie samo „S1”, bo stary parser gubił sufiks wkładki). Dlatego czytamy oba źródła
     * i bierzemy zapis bardziej szczegółowy, ale wyłącznie w obrębie tej samej klasy: „S1” + „S1 PL” to
     * S1PL, natomiast „S3” w polu i „O1” w tożsamości zostaje S3 — drugie źródło nie podmienia klasy na inną.
     *
     * Drugim źródłem jest tożsamość wyrobu (nazwa, kod, kolumna norm), a nie cały opis: klasa wypisana
     * w nazwie dotyczy tego egzemplarza, klasa wspomniana w prozie może dotyczyć innego modelu.
     */
    private function footwearClassFromRawAndBlob(string $rawKlasa, string $identity): ?string
    {
        $raw = $this->extractFootwearClass($rawKlasa);
        if ($raw === null) {
            return null;
        }
        if (preg_match_all(self::FOOTWEAR_CLASS_RE, $identity, $m) < 1) {
            return $raw;
        }
        $best = $raw;
        foreach ($m[1] as $hit) {
            $candidate = mb_strtoupper(preg_replace('/\s+/u', '', (string) $hit) ?? (string) $hit);
            if (mb_strlen($candidate) > mb_strlen($best) && str_starts_with($candidate, $best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * SR bez SRA/SRB/SRC (granica słowa) i WRU przed WR — inaczej „SRC” dawałoby dodatkowo „SR”,
     * a „WRU” (odporność cholewki na wodę) przepadałoby jako niedopasowane „WR”.
     *
     * @return list<string>
     */
    private function extractMarkings(string $text): array
    {
        if (preg_match_all('/\b(SRA|SRB|SRC|HRO|WRU|WR|CI|HI|FO|AN|NR|ESD|SR)\b/u', $text, $m) < 1) {
            return [];
        }

        $out = [];
        foreach ($m[1] as $tag) {
            $out[] = mb_strtoupper((string) $tag);
        }

        return array_values(array_unique($out));
    }

    public function footwearClass(string $text): ?string
    {
        return $this->extractFootwearClass($text);
    }

    /**
     * Klasy, które spełniają wymaganą (bez niej samej) — po bazie klasy, czyli bez typu wkładki L/S.
     * Jedna tabela dla bramki przetargowej, porównywarki zamienników i prefiltru recall katalogu,
     * żeby SQL nie odsiewał kandydata, którego bramka i tak by przyjęła.
     *
     * Wydanie 2022: S6 = S2 + wodoodporność całego wyrobu, S7 = S3 + wodoodporność, więc S7 ⊇ S6 ⊇ S2.
     * S4/S5 (obuwie całogumowe) celowo stoją osobno: „S5 spełnia S3” byłoby prawdą tylko na papierze —
     * kalosz nie jest zamiennikiem trzewika, a typ wyrobu rozstrzyga się w PpeAssortment osobno.
     *
     * @var array<string, list<string>>
     */
    private const FOOTWEAR_SATISFIED_BY = [
        'SB' => ['S1', 'S1P', 'S2', 'S3', 'S6', 'S7'],
        'S1' => ['S1P', 'S2', 'S3', 'S6', 'S7'],
        'S1P' => ['S3', 'S7'],
        'S2' => ['S3', 'S6', 'S7'],
        'S3' => ['S7'],
        'S6' => ['S7'],
        'S7' => [],
        'S4' => ['S5'],
        'S5' => [],
        'OB' => ['O1', 'O1P', 'O2', 'O3', 'O6', 'O7'],
        'O1' => ['O1P', 'O2', 'O3', 'O6', 'O7'],
        'O1P' => ['O3', 'O7'],
        'O2' => ['O3', 'O6', 'O7'],
        'O3' => ['O7'],
        'O6' => ['O7'],
        'O7' => [],
        'O4' => ['O5'],
        'O5' => [],
    ];

    /**
     * S3L spełnia S3; S1P nie spełnia S3. Klasa wyższa w tej samej rodzinie spełnia niższą:
     * S3 ⊇ S2 ⊇ S1 ⊇ SB oraz S3 ⊇ S1P (S3 ma wkładkę antyprzebiciową), O3 ⊇ O2 ⊇ O1 ⊇ OB.
     * S4/S5 (obuwie całogumowe) i klasy S/O nie są wymienne.
     *
     * Typ wkładki (L/S z wydania 2022) nie rozstrzyga tutaj: wymaganie „S3L” wobec karty „S3” to brak
     * informacji, nie sprzeczność — werdykt „do sprawdzenia” wystawia LevelChecker, a bramka nie może
     * z tego powodu wyrzucić całej karty.
     */
    public function footwearClassMeets(string $required, string $have): bool
    {
        $required = $this->footwearClassBase($required);
        $have = $this->footwearClassBase($have);
        if ($have === $required) {
            return true;
        }

        return in_array($have, self::FOOTWEAR_SATISFIED_BY[$required] ?? [], true);
    }

    /**
     * Klasy dopuszczalne przy wymaganej (z nią samą) — dla prefiltrów, które muszą zapytać bazę
     * o wszystko, co bramka uzna za spełniające wymaganie.
     *
     * @return list<string>
     */
    public function footwearClassesSatisfying(string $required): array
    {
        $base = $this->footwearClassBase($required);

        return [$base, ...(self::FOOTWEAR_SATISFIED_BY[$base] ?? [])];
    }

    /** „S3 L” / „S3L” → S3: typ wkładki odcinamy, bo hierarchia klas go nie dotyczy. */
    private function footwearClassBase(string $class): string
    {
        $c = mb_strtoupper(preg_replace('/\s+/u', '', $class) ?? $class);

        return preg_replace('/^(S1P|S[B1-7]|O1P|O[B1-7])[LS]$/u', '$1', $c) ?? $c;
    }

    /** Próg SNR z wymagania („SNR minimum 30 dB”, „tłumienie min. 31 dB”). */
    public function requiredSnr(string $text): ?int
    {
        $norm = $this->normalizeText($text);
        if (preg_match(
            '/\bsnr\b[^0-9]{0,28}(?:min(?:imum|\.)?|co\s+najmniej|od|≥|>=)?\s*(\d{2,3})\s*(?:db)?/u',
            $norm,
            $m
        ) === 1) {
            return $this->snrInRange((int) $m[1]);
        }
        if (preg_match(
            '/tlumien\w*[^0-9]{0,32}(?:min(?:imum|\.)?|co\s+najmniej|od|≥|>=)?\s*(\d{2,3})\s*db/u',
            $norm,
            $m
        ) === 1) {
            return $this->snrInRange((int) $m[1]);
        }

        return null;
    }

    /** SNR z nazwy / opisu karty („SNR 31 dB”). */
    public function snrRating(string $text): ?int
    {
        if (preg_match('/\bsnr\b[^0-9]{0,12}(\d{2,3})\s*(?:db)?/iu', $text, $m) === 1) {
            return $this->snrInRange((int) $m[1]);
        }

        return null;
    }

    private function snrInRange(int $n): ?int
    {
        return $n >= 15 && $n <= 45 ? $n : null;
    }

    /** Próg °C z wymagania („przy 200 C”, „min. 250°C”). */
    public function requiredCelsius(string $text): ?int
    {
        $ratings = $this->celsiusRatings($text);

        return $ratings === [] ? null : max($ratings);
    }

    /** Najwyższa temperatura w karcie produktu. */
    public function maxCelsius(string $text, bool $ignoreShopCategoryLabels = false): ?int
    {
        $ratings = $this->celsiusRatings($text, $ignoreShopCategoryLabels);

        return $ratings === [] ? null : max($ratings);
    }

    /**
     * @return list<int>
     */
    public function celsiusRatings(string $text, bool $ignoreShopCategoryLabels = false): array
    {
        if (preg_match_all(
            '/(?<![0-9])([1-9][0-9]{1,3})\s*(°)?\s*(C\b|celsjusz\w*|st\.?\s*C|stopn(?:i|ie|ia)?)?/iu',
            $text,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        ) < 1) {
            return [];
        }

        $out = [];
        foreach ($matches as $hit) {
            $n = (int) $hit[1][0];
            if ($n < 80 || $n > 1500) {
                continue;
            }
            $bytePos = (int) $hit[1][1];
            $window = strtolower(substr($text, max(0, $bytePos - 40), 80));
            $after = strtolower(substr($text, $bytePos, 28));
            if (preg_match('/g\/m|gramatur|szt\.?|\bml\b|\bmm\b|\brok\b/u', $window) === 1) {
                continue;
            }
            if ($this->isCoverageDegrees($n, $window, $after)) {
                continue;
            }
            if (! $this->looksLikeCelsius($after, $window)) {
                continue;
            }
            if ($ignoreShopCategoryLabels && $this->isShopCategoryHeatLabel($window)) {
                continue;
            }
            $out[] = $n;
        }

        return array_values(array_unique($out));
    }

    /** „360° ochrony” / „ochrona 360 stopni” = dookoła, nie temperatura. */
    private function isCoverageDegrees(int $n, string $window, string $after): bool
    {
        $explicitCelsius = preg_match('/^\d+\s*°?\s*(c\b|celsjusz|st\.?\s*c|stopn\w*\s+c)/u', $after) === 1;
        if ($explicitCelsius) {
            return false;
        }
        if ($n === 360) {
            return true;
        }

        return preg_match(
            '/ochron\w*.{0,16}360|360.{0,16}(ochron|dookol|nadgarst|pokryc|coverage)/u',
            $window
        ) === 1;
    }

    private function looksLikeCelsius(string $after, string $window): bool
    {
        if (preg_match('/^\d+\s*°\s*c\b|^\d+\s+c\b|^\d+\s*st\.?\s*c|^\d+\s*celsjusz|^\d+\s*stopn/u', $after) === 1) {
            return true;
        }

        return preg_match('/termiczn|temperatur|kontakt|konwek|promieniow|piec|zaroodporn|ciepln|en\s*407/u', $window) === 1
            && preg_match('/^\d+\s*°/u', $after) === 1;
    }

    /** Folder sklepu „Rękawice termiczne 350°C”, bez EN 407 / kontaktu w karcie. */
    private function isShopCategoryHeatLabel(string $window): bool
    {
        if (preg_match('/termiczn\w*.{0,16}\d+|^\d+.{0,16}termiczn/u', $window) !== 1) {
            return false;
        }

        return preg_match(
            '/en\s*407|kontakt|konwek|promieniow|piec|hutnicz|termoochron|odporn\w*\s+termiczn|contact\s*heat/u',
            $window
        ) !== 1;
    }

    public function footwearClassToken(string $class): string
    {
        return 'klasa'.mb_strtolower(preg_replace('/\s+/u', '', $class) ?? $class);
    }

    private function extractFootwearClass(string $text): ?string
    {
        if (preg_match(self::FOOTWEAR_CLASS_RE, $text, $m) === 1) {
            return mb_strtoupper(preg_replace('/\s+/u', '', $m[1]) ?? $m[1]);
        }

        return null;
    }

    public function ffpClass(string $text): ?string
    {
        return $this->extractFfpClass($text);
    }

    /** FFP3 spełnia FFP2; FFP1 nie spełnia FFP2. */
    public function ffpClassMeets(string $required, string $have): bool
    {
        $req = $this->extractFfpClass($required);
        $has = $this->extractFfpClass($have);
        if ($req === null || $has === null) {
            return true;
        }

        return (int) substr($has, 3) >= (int) substr($req, 3);
    }

    /**
     * Zawór wydechowy: 1 = z zaworem, 0 = jawnie „bez zaworu”, null = karta milczy.
     * Wspólne dla porównywarki (ProductCrossRefFilters) i bramki asortymentu.
     */
    public function valveState(string $text): ?int
    {
        $hay = $this->normalizeText($text);
        if (preg_match('/\bbez\s+zawor/u', $hay) === 1) {
            return 0;
        }
        if (preg_match('/\b(zawor|valve|cool\s*flow)\w*/u', $hay) === 1) {
            return 1;
        }

        return null;
    }

    private function extractFfpClass(string $text): ?string
    {
        if (preg_match('/\bFFP\s*[-]?([123])\b/iu', $text, $m) === 1) {
            return 'FFP'.$m[1];
        }

        return null;
    }

    private function detectKlasa(string $text): ?string
    {
        $norm = $this->normalizeText($text);
        $respiratory = preg_match(
            '/\b(polmask|ffp|respirator|filtrujac|przeciwpyl|drog[iy]\s+oddech)\w*/u',
            $norm
        ) === 1;
        $ffp = $this->extractFfpClass($text);
        if ($ffp !== null && $respiratory) {
            return $ffp;
        }

        $footwearClass = $this->extractFootwearClass($text);
        if ($footwearClass !== null) {
            $footwear = preg_match(
                '/\b(trzewik|sztyblet|polbut|mokasyn|sandal|obuwie|buty|footwear|podeszw|podnosek|kalosz|purofort)\w*/u',
                $norm
            ) === 1;
            if ($footwear && ! $respiratory) {
                return $footwearClass;
            }
        }
        if (preg_match('/\bkat(?:egoria)?\.?\s*(I{1,3}|[123])\b/iu', $text, $m) === 1) {
            return 'kat. '.$m[1];
        }
        if (preg_match('/\bPPE\s*kat(?:egoria)?\.?\s*(I{1,3}|[123])\b/iu', $text, $m) === 1) {
            return 'PPE kat. '.$m[1];
        }

        return null;
    }

    /**
     * @param  list<string>  $materials
     */
    private function materialFamily(?string $primary, array $materials, string $text): ?string
    {
        $blob = $this->normalizeText(implode(' ', array_filter([$primary, ...$materials, $text])));
        // kalosz / Purofort zanim „PU w podeszwie” skórzanego trzewika
        if (preg_match('/\b(purofort|kalosz|wellington|gumowc|gumiak)\w*/u', $blob) === 1) {
            return 'guma';
        }
        if (preg_match('/\b(skora|leather|welur|licow|nubuk)\w*/u', $blob) === 1) {
            return 'skora';
        }
        if (preg_match('/\b(guma|rubber|pvc|eva|tpe)\w*/u', $blob) === 1) {
            return 'guma';
        }
        if (preg_match('/\b(nitryl|nitrile|nbr)\w*/u', $blob) === 1) {
            return 'nitryl';
        }
        if (preg_match('/\b(lateks|latex)\w*/u', $blob) === 1) {
            return 'lateks';
        }
        if (preg_match('/\b(hppe|dyneema)\w*/u', $blob) === 1) {
            return 'cut';
        }
        if (preg_match('/\b(wloknin|meltblown|polipropylen|polypropylen)\w*/u', $blob) === 1) {
            return 'wloknina';
        }
        if (preg_match('/\bpoliuretan\w*|\bpu\b/u', $blob) === 1) {
            return 'pu';
        }
        if (preg_match('/\b(bawelna|cotton|nylon|tekstyl)\w*/u', $blob) === 1) {
            return 'tkanina';
        }

        return null;
    }

    private function detectRozmiar(string $text, ?string $claimed = null, ?string $category = null): ?string
    {
        return (new ProductSizeVariant)->labelFromTexts($claimed, $text, $category);
    }

    private function detectEn388(string $text): ?string
    {
        // EN 388:2016 + A1:2018 - 4131A / EN 388 4X42C
        $patterns = [
            '/EN\s*388(?::\d{4})?(?:\s*\+\s*A\d+(?::\d+)?)?\s*[-–]\s*([0-9X]{3,5}[A-F]?)\b/iu',
            '/EN\s*388:\d{4}\s+([0-9X]{3,5}[A-F]?)\b/iu',
            '/EN\s*388\s+([0-9X]{3,5}[A-F]?)\b/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                return mb_strtoupper($m[1]);
            }
        }

        return null;
    }

    /** @return list<string> */
    private function splitNormsColumn(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $p): string => trim($p),
            preg_split('/[,;|]/u', $value) ?: []
        )));
    }

    /** @return list<string> */
    private function wordDigitPairKeys(string $text): array
    {
        $keys = [];
        foreach ((new ProductModelFuzzy)->catalogModelWordDigitPairs($text) as [$word, $num]) {
            $keys[] = $word.' '.$num;
        }

        return array_values(array_unique($keys));
    }

    private function codeConflictsWithIdentity(string $kod, string $name, string $sku): bool
    {
        $idPairs = $this->wordDigitPairKeys(trim($name.' '.$sku));
        $kodPairs = $this->wordDigitPairKeys($kod);
        if ($idPairs === [] || $kodPairs === []) {
            return false;
        }

        return array_intersect($idPairs, $kodPairs) === [];
    }

    private function catalogCodeFromIdentity(string $name, string $sku): ?string
    {
        $pairs = (new ProductModelFuzzy)->catalogModelWordDigitPairs($name);
        if ($pairs !== []) {
            [$word, $num] = $pairs[0];
            if (preg_match(
                '/\b('.preg_quote($word, '/').'\s+'.preg_quote($num, '/').'(?:\s+\d{4})?(?:\s+S[1-5][A-Z]{0,3})?)\b/iu',
                $name,
                $m
            ) === 1) {
                $code = trim((string) preg_replace('/\s+/u', ' ', $m[1]));

                return mb_strtoupper($code);
            }

            return mb_strtoupper($word.' '.$num);
        }

        return $this->nullableString($sku !== '' ? $sku : null);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $v = trim($value);

        return $v === '' ? null : $v;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn ($v): bool => is_string($v) && trim($v) !== ''
        ));
    }

    private function normalizeText(string $text): string
    {
        $t = mb_strtolower($text);
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

        return strtr($t, $map);
    }
}
