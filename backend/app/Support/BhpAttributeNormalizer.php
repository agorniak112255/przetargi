<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Support\RequirementCheck\En388Code;

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
     * Tylko wielkimi literami — dla wpisów list norm i fragmentów tekstu, gdzie „sb” czy „o2” w zwykłym tekście
     * (rozmiary, skróty) to nie klasa. Zapis klasy przy normie dostawcy piszą zawsze wielkimi literami.
     */
    private const FOOTWEAR_CLASS_UPPER_RE = '/(?<![\p{L}\d])('.self::FOOTWEAR_CLASS.')(?![\p{L}\d])/u';

    /**
     * Klasa w kodzie (SKU), w kolumnie norm i w wierszu tabelki dostawcy: wielkimi literami i jako osobny
     * wyraz — przed nią i za nią tylko spacja, przecinek, średnik, dwukropek albo nawias. Myślnik, ukośnik,
     * cyfra czy litera obok to część kodu: „BRS-s1-42” (rozmiar 42 wariantu), „OB-4512” (indeks) nie niosą klasy.
     * Spacja wewnątrz zapisu klasy („S1 P L”) należy do wzorca, więc „… 618080 S1 PL ESD” to nadal S1PL.
     */
    private const FOOTWEAR_CLASS_CODE_RE = '/(?<![^\s,;:(])('.self::FOOTWEAR_CLASS.')(?![^\s,;:)])/u';

    /**
     * SNR na karcie, liczba w grupie 1 — wspólny odczyt snrRating i wiersza „Tłumienie SNR” weryfikacji karty.
     * „SNR 31 dB”, „SNR=27”, „SNR of 31dB”, ale nie ogon kodu: w „31dB SNR AEB020-0AY-900” (nazwa i SKU JSP)
     * to nie SNR 20. Także liczba 15–45 przed „dB SNR” („Sonis®2 — 31dB SNR”, „27 dB SNR - szaro-zielone”), ale nie
     * wartość L z tabeli HML („H 32 dB M 29 dB L 22 dB SNR 31 dB” to SNR 31, „32/29/22 dB SNR” i „L-22 dB SNR” to
     * nie SNR 22) ani liczba przed „SNR”, za którym stoi jego własna liczba. Zakres w tej gałęzi, żeby „hałas do
     * 85 dB SNR wg normy: 28 dB” nie zjadał „SNR” przed prawdziwą wartością. M4 z planu napraw 25.09.2026: Sonis 2
     * odpadał przy „SNR min. 30 dB” jako SNR 20.
     */
    public const SNR_RE = '/(?|(?<![\p{L}\d=.,\/])(?<!\/\s)(?<!\b[hml]\s)(?<!\b[hml][:=-])(?<!\b[hml][:=-]\s)(?<!\b[hml]\s-\s)'
        .'(1[5-9]|[23]\d|4[0-5])\s*dB\s*SNR(?![\p{L}\d])(?![^\p{L}\d]{0,4}\d)'
        .'|(?<![\p{L}\d])SNR(?![\p{L}\d])[^0-9]{0,12}(?<![\p{L}\d])(\d{2,3})(?!\d)(?:\s*dB)?)/iu';

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
        // Kategoria tylko jako dowód: ścieżka dobrana automatem (presta_rewrite) to zgadnięcie, nie opis wyrobu —
        // „Kombinezony bawełniane” przy kurtce dawały bawełnę, rodzinę i szablon.
        $categoryEvidence = $product->categoryAsEvidence();
        $haystack = trim(implode("\n", array_filter([
            (string) ($product->name ?? ''),
            (string) ($product->description ?? ''),
            // Tabelka z karty dostawcy: od etapu 2 normy i materiały stoją poza opisem (u Protektu nigdy nie były
            // prozą opisu), więc bez tego źródła wykrywanie norm i materiałów gubiłoby je razem z przeprowadzką.
            (string) ($product->shop_fields_summary ?? ''),
            $categoryEvidence,
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
                'category' => $categoryEvidence,
                'sku' => (string) ($product->sku ?? ''),
                'name' => (string) ($product->name ?? ''),
                'description' => (string) ($product->description ?? ''),
                // Osobny klucz, nie doklejenie do opisu — to dane dostawcy, a nie opis wyrobu.
                'shop_fields' => (string) ($product->shop_fields_summary ?? ''),
                'norms_column' => (string) ($product->norms ?? ''),
                // Parametry wypisane w samym cenniku dostawcy — dokument producenta, nie odczyt ze strony.
                'price_list' => is_array($product->price_list_attributes) ? $product->price_list_attributes : [],
                // Normy z karty wyrobu u jego producenta — jedyne źródło poziomów EN 388, któremu wolno
                // przebić opis: opisy powstają ze sklepów, a te podają poziomy cudzych albo starych wersji.
                'manufacturer' => ManufacturerNormFacts::context($product->manufacturer_norms),
            ]
        );
    }

    /**
     * Pola, które karta w panelu i opis na sklep biorą z przeliczenia (forProduct): te mają hierarchię źródeł
     * (producent, cennik, nazwa, tabelka dostawcy biją opis), a zapisane w payloadzie bywają cudzym wariantem.
     * Reszta zostaje zapisana: przeliczone materiały czy typ wyrobu to odczyt z całej prozy, razem ze zdaniami
     * przeczącymi („bez lateksu” → lateks, karta 11202000).
     */
    private const DISPLAY_RECOMPUTED = ['klasa_ochrony', 'oznaczenia', 'poziomy_en388', 'kod_producenta', 'przeznaczenie'];

    /**
     * Atrybuty i normy do pokazania na karcie w panelu i w opisie na nasz sklep: zapisane atrybuty, na nich pola
     * z hierarchią z przeliczenia (DISPLAY_RECOMPUTED), a normy z displayedNorms — w obu miejscach te same.
     *
     * @return array{attributes: array<string, mixed>, norms: list<string>}
     */
    public function forDisplay(Product $product): array
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $stored = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
        $computed = $this->forProduct($product);
        $norms = $this->displayedNorms($product, $computed);

        return [
            'attributes' => array_merge(
                $stored,
                array_intersect_key($computed, array_flip(self::DISPLAY_RECOMPUTED)),
                ['normy_en' => $norms],
            ),
            'norms' => $norms,
        ];
    }

    /**
     * Normy do pokazania na karcie w panelu i w opisie na nasz sklep — nie to samo co normy_en z forProduct.
     * forProduct dokłada normy wyczytane z całego opisu, także ze zdań przeczących („Źródła nie podają
     * zgodności z EN 407 ani EN ISO 374-1”); dla dopasowania to tylko kandydat do sprawdzenia, ale na karcie
     * i na sklepie wyszłoby jako fakt. Pokazujemy więc to, co dotąd: normy zapisane przez wzbogacanie (lista
     * norm i atrybut normy_en), bez zapisów cudzego wariantu obuwia (klasa z cennika, nazwy albo tabelki
     * dostawcy — jak w normalize), a do tego normy z kolumny cennika i z tabelki dostawcy.
     *
     * Karta z normami producenta wyrobu pokazuje przeliczone normy_en, jak dotąd: tam kolumna producenta bije
     * resztę i to jej poziomy mają wyjść na kartę (App\Support\ManufacturerNormFacts).
     *
     * @param  array{kategoria_bhp: ?string, normy_en: list<string>}  $computed  wynik forProduct($product)
     * @return list<string>
     */
    private function displayedNorms(Product $product, array $computed): array
    {
        if (ManufacturerNormFacts::norms($product->manufacturer_norms) !== []) {
            return $computed['normy_en'];
        }

        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
        $priceList = is_array($product->price_list_attributes) ? $product->price_list_attributes : [];
        $shopFields = (string) ($product->shop_fields_summary ?? '');
        $name = (string) ($product->name ?? '');
        $sku = (string) ($product->sku ?? '');
        $priceListKlasa = $this->nullableString($priceList['klasa_ochrony'] ?? null);
        $identitySources = $this->footwearIdentitySources($name, $sku, (string) ($product->norms ?? ''), $shopFields);
        // kategoria-dowód, jak w normalize: ścieżka drzewa dobrana automatem nie czyni karty obuwiem
        $katText = $product->categoryAsEvidence().' '.$name.' '.$sku.' '.($product->description ?? '');
        $footwear = $this->mentionsFootwear($this->normalizeText($katText))
            || $this->normalizeKategoria($this->nullableString($attrs['kategoria_bhp'] ?? null)) === 'obuwie';
        // Bez klasy z payloadu: ta bywa cudzą kartą, więc nie jej używamy do przesiewu (jak w normalize).
        $klasa = $this->footwearClassByHierarchy($priceListKlasa, $identitySources, null, $footwear)['klasa'];
        $cardRecord = $klasa === null
            ? null
            : $this->cardFootwearClassRecord($klasa, [[$priceListKlasa ?? '', self::FOOTWEAR_CLASS_RE], ...$identitySources]);

        return NormCode::dedupe(array_merge(
            $this->splitNormsColumn((string) ($priceList['normy'] ?? '')),
            $this->withoutForeignFootwearNorms(array_merge(
                $this->stringList($payload['norms'] ?? null),
                $this->stringList($attrs['normy_en'] ?? null),
                $this->detectNormsFromText($shopFields),
            ), $cardRecord),
        ));
    }

    /**
     * `category` w kontekście to kategoria-dowód (Product::categoryAsEvidence): ścieżki dobranej automatem
     * wywołujący nie podaje — z niej szły kategoria BHP, rodzina, typ i bramka zamienników.
     *
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
     *     price_list?: array<string, string>,
     *     manufacturer?: array{en388?: string, normy?: list<string>}
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
        $assortment = new PpeAssortment;
        // Mata z nazwy i maska do resuscytacji nie są ŚOI żadnej rodziny — model wpisywał im „drogi_oddechowe”
        // (CEDERROTH 26604 „Maska oddechowa”, opis „resuscytacja usta-usta”) albo obuwie z „czyszczenia obuwia”.
        $outsidePpe = $assortment->namesFloorMat((string) ($context['name'] ?? ''))
            || $assortment->isResuscitationMask($identity, (string) ($context['description'] ?? ''));

        // Poza tym kategoria od modelu zostaje pierwsza: rzeczownik z nazwy przed nią psuł więcej, niż naprawiał
        // („Shoe cover”, „Low softshell footwear” — przegląd ręcznych cenników 22.09). Bez niej tożsamość, a na końcu
        // opis bez klas obuwia („klasa Dfl-s1” maty to nie S1).
        $out['kategoria_bhp'] = $outsidePpe ? 'inne' : $this->normalizeKategoria(
            $this->nullableString($raw['kategoria_bhp'] ?? null)
            ?? $this->detectKategoria($identity)
            ?? $assortment->kategoriaFromFamily($assortment->familyFromDescription($katText))
        );

        $kod = $this->nullableString($raw['kod_producenta'] ?? null);
        $name = (string) ($context['name'] ?? '');
        $sku = (string) ($context['sku'] ?? '');
        if ($kod !== null && $this->isColourCodeInSku($kod, $sku)) {
            // Sam kod koloru z pełnego kodu producenta w SKU — kodem wyrobu jest całe SKU. Bez
            // catalogCodeFromIdentity: to ono sklejało „TEGRO 250 3021 S3” z nazwy.
            $kod = trim($sku);
        } elseif ($kod !== null && $this->codeConflictsWithIdentity($kod, $name, $sku)) {
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

        // Klasa obuwia według hierarchii źródeł, zanim zbierzemy normy i oznaczenia — od niej zależy, które
        // wpisy z tabelki dostawcy i z payloadu opisują ten wyrób, a które jego wariant o innej klasie.
        $identityText = trim($name.' '.$sku.' '.($context['norms_column'] ?? ''));
        $shopFields = (string) ($context['shop_fields'] ?? '');
        $priceListKlasa = $this->nullableString($priceList['klasa_ochrony'] ?? null);
        $rawKlasa = $this->nullableString($raw['klasa_ochrony'] ?? null);
        // Obuwie tylko ze słowa obuwniczego w tekście albo z jawnej kategorii payloadu — sama wyliczona kategoria
        // nie wystarcza: statyw „TM 14-SB” czy instrukcja „OB 750 A” dostawały klasę obuwia z nazwy.
        $footwear = $this->mentionsFootwear($this->normalizeText($katText))
            || $this->normalizeKategoria($this->nullableString($raw['kategoria_bhp'] ?? null)) === 'obuwie';
        $identitySources = $this->footwearIdentitySources($name, $sku, (string) ($context['norms_column'] ?? ''), $shopFields);
        $footwearClass = $this->footwearClassByHierarchy($priceListKlasa, $identitySources, $rawKlasa, $footwear);
        $classTexts = [[$priceListKlasa ?? '', self::FOOTWEAR_CLASS_RE], ...$identitySources];
        $trustedRecord = $footwearClass['trusted'] && $footwearClass['klasa'] !== null
            ? $this->cardFootwearClassRecord($footwearClass['klasa'], $classTexts)
            : null;

        $manufacturer = is_array($context['manufacturer'] ?? null) ? $context['manufacturer'] : [];
        // Pary z karty producenta; kontekst bez par (sama lista norm producenta) — każda pozycja jako para bez
        // wartości: wchodzi na listę, ale niczego nie wypiera.
        $manufacturerRows = is_array($manufacturer['rows'] ?? null)
            ? $manufacturer['rows']
            : array_map(static fn (string $norm): array => ['label' => $norm, 'value' => ''], $this->stringList($manufacturer['normy'] ?? null));
        $fromOthers = array_merge(
            // normy z cennika idą pierwsze — przy skracaniu listy zostają te z dokumentu producenta
            $this->splitNormsColumn((string) ($priceList['normy'] ?? '')),
            // Payload, odczyt z tekstów i tabelka dostawcy mogą opisywać wariant o innej klasie („ARMEN 900
            // 6060 O1 FO” z normą „EN ISO 20345:2011 S2 CI SRC” ze sklepu) — taki zapis nie trafia do norm.
            // Cennik i kolumna norm to tożsamość wyrobu, więc ich nie przesiewamy.
            $this->withoutForeignFootwearNorms(array_merge(
                $this->stringList($raw['normy_en'] ?? null),
                $this->stringList($context['norms'] ?? null),
                $this->detectNormsFromText($shopFields),
            ), $trustedRecord),
            $this->splitNormsColumn($context['norms_column'] ?? ''),
        );
        // Normę, którą karta producenta podaje wprost z oznaczeniem, opisujemy JEGO zapisem — to samo rozstrzygnięcie
        // co lista norm zapisywana przy wzbogacaniu (ManufacturerNormFacts::resolveAgainstRows).
        $normy = $this->collapseNormVariants(array_values(array_unique(
            ManufacturerNormFacts::resolveAgainstRows(array_values(array_unique($fromOthers)), $manufacturerRows)
        )));
        $out['normy_en'] = $normy;

        // Tożsamość i tabelka dostawcy idą pierwsze: klasę i poziomy czytamy do pierwszego trafienia, a pierwsze
        // ma być z opisu TEGO wyrobu, nie ze specyfikacji zebranej ze sklepów.
        $blobParts = array_merge(
            [$name, (string) ($context['norms_column'] ?? ''), $shopFields],
            $normy,
            $this->stringList($context['specs'] ?? null),
            $this->stringList($context['certificates'] ?? null),
            $this->stringList($context['use_cases'] ?? null),
            [(string) ($context['description'] ?? '')],
        );
        $descBlob = implode(' ', $blobParts);

        // Klasa z cennika bije nazwę, nazwa bije tabelkę dostawcy, a tabelka payload wzbogacania — patrz
        // footwearClassByHierarchy. Klasy nieobuwnicze (FFP, kat. ŚOI) czytamy jak dotąd: FFP z zapisanego pola,
        // reszta z tekstu karty, który zaczyna się od tożsamości.
        $explicitKlasa = $priceListKlasa ?? $rawKlasa;
        // U obuwia bez klasy w hierarchii nie czytamy luźnym wzorcem SKU ani tabelki dostawcy — te źródła hierarchia
        // już przeczytała ściśle, a luźno „BRS-s1-42” dawałby S1, a „Indeks: OB-4512” klasę OB. Nazwa i kolumna
        // norm stoją w descBlob.
        $fallbackBlob = $footwear
            ? implode(' ', array_diff_key($blobParts, [2 => true]))
            : $identityText.' '.$descBlob;
        $out['klasa_ochrony'] = $this->extractFfpClass($explicitKlasa ?? '')
            ?? $footwearClass['klasa']
            ?? $this->detectKlasa(trim(($explicitKlasa ?? '').' '.$fallbackBlob));

        // Oznaczenia przy klasie obuwia czytamy z tekstów pociętych na fragmenty (wpis listy, wiersz tabelki,
        // zdanie opisu), żeby odsiać fragmenty o innej klasie — patrz segmentsOfFootwearClass.
        if ($this->extractFootwearClass($out['klasa_ochrony'] ?? '') === null) {
            $out['oznaczenia'] = $this->extractMarkings(trim(($priceListKlasa ?? '').' '.($rawKlasa ?? '').' '.$descBlob));
        } else {
            $segments = [$priceListKlasa ?? '', $rawKlasa ?? ''];
            foreach ($blobParts as $part) {
                array_push($segments, ...$this->textSegments($part));
            }
            $out['oznaczenia'] = $this->extractMarkings(implode(' ', $this->segmentsOfFootwearClass(
                $segments,
                $this->cardFootwearClassRecord((string) $out['klasa_ochrony'], [...$classTexts, [$rawKlasa ?? '', self::FOOTWEAR_CLASS_RE]]),
            )));
        }

        // Rozmiar z cennika bije resztę; bez niego pojedynczy rozmiar z nazwy („SIZE XXL”) bije zakres z opisu.
        $priceListRozmiar = $this->nullableString($priceList['rozmiar'] ?? null);
        $out['rozmiar'] = $this->detectRozmiar(
            implode(' ', $this->stringList($context['specs'] ?? null)).' '.($context['description'] ?? ''),
            $priceListRozmiar ?? $this->nullableString($raw['rozmiar'] ?? null),
            $out['kategoria_bhp'],
            $priceListRozmiar === null ? $name : null,
        );

        // Poziomy EN 388 z karty producenta biją i zapisany atrybut, i odczyt z tekstu: kod z opisu
        // sklepowego bywa cudzym wyrobem albo starym wydaniem normy, a od niego zależy dopasowanie
        // do wymagania przetargu (App\Support\ManufacturerNormFacts).
        // Zapisany atrybut i tekst karty czyta ten sam czytnik co sprawdzanie wymagań (En388Code) — dawny wzorzec brał
        // rok albo ucięty kod za poziomy („EN 388 2016” → „2016”, „EN 388 211” → „211”), a nie widział „EN 388: 4121X”
        // ani „EN 388 (4121X)”. Zapis słowny bywa częściowy („ścieranie 4”), więc pola nie wypełnia.
        $out['poziomy_en388'] = $this->nullableString($manufacturer['en388'] ?? null)
            ?? $this->en388Code('EN 388 '.($this->nullableString($raw['poziomy_en388'] ?? null) ?? ''))
            ?? $this->en388Code($descBlob);

        $typeBlob = $identity.' '.$descBlob;
        $family = $assortment->familyFromKategoria($out['kategoria_bhp']);
        if ($family === null && ! $outsidePpe) {
            $family = $assortment->family($identity) ?? $assortment->familyFromDescription($typeBlob);
        }
        $nameSku = trim($name.' '.$sku);
        // Typ z nazwy bije typ od modelu, a ten odczyt z całego tekstu: model dawał „kalosz” skórzanym
        // trzewikom Canis („Ankle leather footwear … gumowa podeszwa”) i „ffp” masce do resuscytacji.
        // Po nazwie kategoria-dowód (kolumna cennika, B2B, ręczna): „POWLEKANE” przy Polstar COVENT czy G-REX P01
        // to powlekane, a nie „welding” ze zdania „nie stosować przy pracach spawalniczych” w opisie. Kategoria
        // dobrana automatem tu nie dociera (context['category'] jest już bez niej) — z nią typ kłamał
        // („Kombinezony bawełniane” przy kurtce, „Półmaski filtrujące FFP1” przy pochłaniaczu).
        $out['typ_wyrobu'] = $outsidePpe ? null : (
            $assortment->articleType($nameSku, $family)
            ?? $assortment->articleType($identity, $family)
            ?? $this->nullableString($raw['typ_wyrobu'] ?? null)
            ?? $assortment->articleType($typeBlob, $family)
        );
        // Zapisanego `przeznaczenie` nie czytamy dla żadnej rodziny: model go nie zwraca (nie ma go w schemacie
        // odpowiedzi), więc w payloadzie leży nasze dawne wyliczenie — „electric” przy butach antystatycznych czy
        // „agriculture” z „farmaceutycznego” przyklejały się na zawsze, także po poprawce reguły (odrzucane
        // w porównywarce zamienników). Tożsamość idzie pierwsza: „Kurtka ostrzegawcza” to hivis, choćby lista
        // zastosowań wymieniała rolnictwo.
        // Antystatyka w nazwie rozstrzyga się dopiero w całym tekście: „Kurtka wodoochronna antystatyczna” jest
        // electric z EN 1149-5 z norm i opisu (sama antystatyka nie wystarcza — PpeAssortment::purpose), a sama
        // nazwa dałaby jej „rain”.
        $nameDefersPurpose = preg_match('/antysta|antista|\besd\b/iu', $nameSku) === 1;
        $out['przeznaczenie'] = $outsidePpe ? null : (
            ($nameDefersPurpose ? null : $assortment->purpose($nameSku, $family))
            ?? $assortment->purpose($typeBlob, $family)
        );
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
     * Jedna hierarchia klasy obuwia: kolumna cennika → nazwa → kod (SKU) → kolumna norm → tabelka z karty
     * dostawcy → payload wzbogacania (źródła tożsamości i tabelki: footwearIdentitySources). Klasę daje pierwsze źródło, które ją podaje; niższe mogą ją wyłącznie uszczegółowić
     * w obrębie tej samej bazy (footwearClassBase): „S1 P” + „S1 PL” to S1PL, „S3” + „S3 L” to S3L.
     *
     * Inna baza niżej to nie fakt, tylko sprzeczność do sprawdzenia — pokazuje ją LevelChecker::cardConflicts
     * (tabelka ARTRA „ARMEN 900 6060 O1 FO” podaje „EN ISO 20345:2011 S1 P SRC”, a empik w payloadzie
     * „S2 CI SRC”; karta zostaje O1). Payload bywa cudzą kartą, więc liczy się dopiero, gdy wyżej wszystko
     * milczy. Stary zapis zdegradowany przez parser („S1” zamiast „S1 PL” z nazwy) naprawia się sam, bo nazwa
     * stoi wyżej.
     *
     * Klasę z tożsamości i tabelki bierzemy tylko u obuwia: „SB”, „S2” czy „O2” trafiają się w kodach innych
     * wyrobów. Prozy opisu tu nie ma wcale — zdanie „dostępny też w wersji S1P” nadałoby karcie wkładkę
     * antyprzebiciową, której ten but nie ma.
     *
     * @param  list<array{0: string, 1: string}>  $identitySources  tekst i wzorzec klasy (footwearIdentitySources)
     * @return array{klasa: ?string, trusted: bool} trusted — klasa z cennika, tożsamości albo tabelki, nie z payloadu
     */
    private function footwearClassByHierarchy(
        ?string $priceListKlasa,
        array $identitySources,
        ?string $rawKlasa,
        bool $footwear,
    ): array {
        $fromPayload = $this->extractFootwearClass($rawKlasa ?? '');
        $levels = [array_values(array_filter([$this->extractFootwearClass($priceListKlasa ?? '')]))];
        foreach ($identitySources as [$text, $pattern]) {
            $levels[] = $footwear ? $this->footwearClassesIn($text, $pattern) : [];
        }
        $payloadLevel = count($levels);
        $levels[] = $fromPayload !== null ? [$fromPayload] : [];

        $klasa = null;
        $trusted = false;
        foreach ($levels as $level => $classes) {
            if ($classes !== []) {
                $klasa = $classes[0];
                $trusted = $level < $payloadLevel;
                break;
            }
        }
        if ($klasa === null) {
            return ['klasa' => null, 'trusted' => false];
        }

        $base = $this->footwearClassBase($klasa);
        foreach (array_merge(...$levels) as $candidate) {
            if (mb_strlen($candidate) > mb_strlen($klasa) && $this->footwearClassBase($candidate) === $base) {
                $klasa = $candidate;
            }
        }

        return ['klasa' => $klasa, 'trusted' => $trusted];
    }

    /**
     * Źródła klasy z tożsamości wyrobu i z tabelki dostawcy, w kolejności hierarchii, każde z własnym wzorcem:
     * nazwa jak dotąd (także małymi literami, granica: brak litery i cyfry obok), a kod, kolumna norm i tabelka
     * tylko wielkimi literami i jako osobny wyraz (FOOTWEAR_CLASS_CODE_RE). Złączony tekst „nazwa SKU normy”
     * czytany jednym luźnym wzorcem brał „s1” z kodu „BRS-s1-42” i bił nim prawdziwe S3.
     *
     * Z tabelki bierzemy tylko wiersze o normie albo klasie („norma: EN ISO 20345:2022 S1 PL FO SR”, „Klasa: S3”) —
     * „Indeks: OB-4512” czy „Zobacz także: … S3” to nie twierdzenie o klasie tego wyrobu.
     *
     * @return list<array{0: string, 1: string}> tekst i wzorzec klasy
     */
    private function footwearIdentitySources(string $name, string $sku, string $normsColumn, string $shopFields): array
    {
        $tableLines = array_filter(
            preg_split('/\R/u', $shopFields) ?: [],
            static fn (string $line): bool => preg_match('/\bnorm\w*\s*:|2034[57]|\bklas/iu', $line) === 1,
        );

        return [
            [$name, self::FOOTWEAR_CLASS_RE],
            [$sku, self::FOOTWEAR_CLASS_CODE_RE],
            [$normsColumn, self::FOOTWEAR_CLASS_CODE_RE],
            [implode("\n", $tableLines), self::FOOTWEAR_CLASS_CODE_RE],
        ];
    }

    /**
     * Klasa obuwia z kodu wyrobu (SKU) — wielkimi literami i jako osobny wyraz: „ARYEL 320 Air 618080 S1 PL ESD”
     * → S1PL, ale „BRS-s1-42” czy „SB-123” → null. Wspólne dla hierarchii klasy karty i sita stron wzbogacania
     * (ProductSearchIdentity), żeby obie czytały kod tak samo.
     */
    public function footwearClassFromCode(string $sku): ?string
    {
        return $this->footwearClassesIn($sku, self::FOOTWEAR_CLASS_CODE_RE)[0] ?? null;
    }

    /** @return list<string> klasy obuwia z tekstu w kolejności wystąpienia, bez spacji („S1 PL” → S1PL) */
    private function footwearClassesIn(string $text, string $pattern): array
    {
        if (trim($text) === '' || preg_match_all($pattern, $text, $m) < 1) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (string $hit): string => mb_strtoupper(preg_replace('/\s+/u', '', $hit) ?? $hit),
            $m[1],
        )));
    }

    /**
     * Zapisy klasy obuwia w tekście razem z oznaczeniami stojącymi tuż za klasą (do 3 tokenów): „EN ISO
     * 20345:2022 S7S CI SR” → „S7S CI SR”. Oznaczenie liczy się tylko przy tokenie klasy — „Hi” z marki czy
     * „CI” z innego zdania nie należą do zapisu klasy.
     *
     * @return list<string> klasa bez spacji i oznaczenia wielkimi literami („S3 WR SRC”)
     */
    private function footwearClassRecords(string $text, string $pattern): array
    {
        if (trim($text) === '' || preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }
        $flags = str_ends_with($pattern, 'iu') ? 'iu' : 'u';
        $out = [];
        foreach ($m[1] as [$hit, $offset]) {
            $record = mb_strtoupper(preg_replace('/\s+/u', '', $hit) ?? $hit);
            $after = substr($text, $offset + strlen($hit));
            if (preg_match('/^(?:[\h,]+(?:SRA|SRB|SRC|HRO|WRU|WR|CI|HI|FO|AN|NR|ESD|SR)(?![\p{L}\d])){1,3}/'.$flags, $after, $mm) === 1) {
                $record .= ' '.mb_strtoupper(trim(preg_replace('/[\h,]+/u', ' ', $mm[0]) ?? $mm[0]));
            }
            $out[] = $record;
        }

        return array_values(array_unique($out));
    }

    /**
     * Zapis klasy karty razem z oznaczeniami przy niej w źródłach tożsamości (cennik, nazwa/kod, tabelka):
     * „Trzewik uvex 2 S3 WR SRC” → „S3 WR SRC”. Od oznaczeń przy klasie zależy równoważność wydań normy
     * (sameFootwearVariantClass: S3 WR ≡ S7), więc samo „S3” by jej nie widziało. Każde źródło czytamy jego
     * wzorcem z footwearIdentitySources — kod i tabelkę tylko wielkimi literami, jak przy samej klasie.
     *
     * @param  list<array{0: string, 1: string}>  $sources  tekst i wzorzec klasy
     */
    private function cardFootwearClassRecord(string $klasa, array $sources): string
    {
        $base = $this->footwearClassBase($klasa);
        $markings = [];
        foreach ($sources as [$text, $pattern]) {
            foreach ($this->footwearClassRecords($text, $pattern) as $record) {
                $parts = explode(' ', $record);
                if ($this->footwearClassBase($parts[0]) === $base) {
                    array_push($markings, ...array_slice($parts, 1));
                }
            }
        }

        return trim($klasa.' '.implode(' ', array_values(array_unique($markings))));
    }

    /**
     * Czy zapis klasy z tekstu opisuje wariant karty: ta sama baza klasy („S1 P” = „S1PL”, „S3” = „S3L”) albo
     * ten sam wariant po przecertyfikowaniu na wydanie 2022 („S3 WR” karty = „S7S” ze strony producenta).
     * Brak WR przy „S3” w tekście nie przeczy karcie „S3 WR” — brak oznaczenia to nie dowód.
     */
    private function footwearRecordFitsCard(string $record, string $cardRecord): bool
    {
        $class = $this->extractFootwearClass($record);
        $cardClass = $this->extractFootwearClass($cardRecord);
        if ($class === null || $cardClass === null) {
            return false;
        }

        return $this->footwearClassBase($class) === $this->footwearClassBase($cardClass)
            || $this->sameFootwearVariantClass($cardRecord, $record);
    }

    /**
     * Wpisy norm obuwia, które opisują wariant o innej klasie niż karta. Zapis z klasą („EN ISO 20345:2011
     * S2 CI SRC”) odpada, gdy żaden podany w nim zapis klasy nie pasuje do karty (footwearRecordFitsCard);
     * zapis bez klasy odpada tylko przy sprzecznej rodzinie — EN ISO 20345 to obuwie S (bezpieczne), EN ISO
     * 20347 to O (zawodowe). Wpis wymieniający obie normy zostaje: to lista, nie twierdzenie o tym wyrobie.
     * Klasa rozstrzyga przed numerem normy, bo to ona jest twierdzeniem o wyrobie (numer normy model myli
     * częściej niż klasę).
     *
     * @param  list<string>  $norms
     * @param  ?string  $cardRecord  zapis klasy karty z oznaczeniami (cardFootwearClassRecord)
     * @return list<string>
     */
    private function withoutForeignFootwearNorms(array $norms, ?string $cardRecord): array
    {
        $cardClass = $cardRecord === null ? null : $this->extractFootwearClass($cardRecord);
        if ($cardRecord === null || $cardClass === null) {
            return $norms;
        }
        $family = $this->footwearClassBase($cardClass)[0];

        return array_values(array_filter($norms, function (string $norm) use ($cardRecord, $family): bool {
            // także okruch „klasa S3)” z listy norm pociętej przez model — klasa bez numeru normy
            $records = $this->footwearClassRecords($norm, self::FOOTWEAR_CLASS_UPPER_RE);
            if ($records !== []) {
                foreach ($records as $record) {
                    if ($this->footwearRecordFitsCard($record, $cardRecord)) {
                        return true;
                    }
                }

                return false;
            }
            $safety = preg_match('/20345/u', $norm) === 1;
            $occupational = preg_match('/20347/u', $norm) === 1;
            if ($safety === $occupational) {
                return true;
            }

            return $family === 'S' ? $safety : $occupational;
        }));
    }

    /**
     * Fragmenty, z których wolno czytać oznaczenia (FO, CI, SRC, ESD…) karty o danej klasie obuwia: fragment
     * podający klasę innego wariantu opisuje inny wyrób — „S2 CI SRC” z empiku przy sandale O1 dałby mu
     * izolację od zimna i antypoślizg SRC, których jego karta nie podaje, a natare „O1 FO ESD” przy „S1 P
     * ESD” — FO. Oznaczenie należy do zapisu klasy konkretnego wyrobu, więc wędruje razem z nim. Fragment bez
     * klasy zostaje (brak klasy nie dowodzi, że to inny wyrób), a zapis po przecertyfikowaniu („S7S CI SR”
     * przy „S3 WR”) — też, bo to ten sam but.
     *
     * @param  list<string>  $segments
     * @param  string  $cardRecord  zapis klasy karty z oznaczeniami (cardFootwearClassRecord)
     * @return list<string>
     */
    private function segmentsOfFootwearClass(array $segments, string $cardRecord): array
    {
        return array_values(array_filter($segments, function (string $segment) use ($cardRecord): bool {
            $records = $this->footwearClassRecords($segment, self::FOOTWEAR_CLASS_UPPER_RE);
            if ($records === []) {
                return true;
            }
            foreach ($records as $record) {
                if ($this->footwearRecordFitsCard($record, $cardRecord)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /** @return list<string> wiersze i zdania tekstu — granica fragmentu przy przesiewie oznaczeń */
    private function textSegments(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\R+|(?<=[.;!?])\s+/u', $text) ?: []),
            static fn (string $s): bool => $s !== '',
        ));
    }

    /** Słowa obuwia w tekście po normalizeText — wspólne dla odczytu klasy z tekstu i z nazwy. */
    private function mentionsFootwear(string $normalized): bool
    {
        return preg_match(
            '/\b(trzewik|sztyblet|polbut|mokasyn|sandal|obuwie|buty|footwear|podeszw|podnosek|kalosz|purofort)\w*/u',
            $normalized
        ) === 1;
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

    /**
     * Czy dwa zapisy klasy obuwia opisują ten sam wariant (zapis może nieść oznaczenia: „S3 WR SRC”, „S7S CI SR”).
     * Porównanie po bazie klasy, a do tego równoważność wydań normy: wodoodporność całego wyrobu, w wydaniu 2011
     * oznaczenie WR przy S3/S2 (O3/O2), w wydaniu 2022 weszła do klasy jako S7/S6 (O7/O6) — „uvex 2 S3 WR SRC”
     * przecertyfikowany na „S7L” to ten sam but.
     */
    public function sameFootwearVariantClass(string $a, string $b): bool
    {
        $keyA = $this->footwearVariantKey($a);
        $keyB = $this->footwearVariantKey($b);

        return $keyA !== null && $keyA === $keyB;
    }

    /** Baza klasy z pierwszego zapisu klasy w tekście, z WR przy S2/S3/O2/O3 podniesionym do S6/S7/O6/O7. */
    private function footwearVariantKey(string $text): ?string
    {
        $class = $this->extractFootwearClass($text);
        if ($class === null) {
            return null;
        }
        $base = $this->footwearClassBase($class);
        if (preg_match('/(?<![\p{L}\d])WR(?![\p{L}\d])/u', mb_strtoupper($text)) === 1) {
            $base = match ($base) {
                'S2' => 'S6',
                'S3' => 'S7',
                'O2' => 'O6',
                'O3' => 'O7',
                default => $base,
            };
        }

        return $base;
    }

    /**
     * „S3 L” / „S3L” → S3: typ wkładki odcinamy, bo hierarchia klas go nie dotyczy. Publiczne, bo tak samo
     * porównuje klasę sito stron wzbogacania i audyt tożsamości obuwia — „S1 P” i „S1PL” to jeden wyrób.
     */
    public function footwearClassBase(string $class): string
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

    /**
     * SNR z nazwy / opisu karty („SNR 31 dB”, „31dB SNR”) — pierwszy zapis w tekście z wartością 15–45 dB, więc przy
     * tekście z nazwą na początku (filterHaystack) wartość z nazwy wygrywa ze szczytowym SNR serii z opisu
     * („szczytowa wartość współczynnika SNR wynosi 37” na karcie Sonis 1 o 27 dB).
     */
    public function snrRating(string $text): ?int
    {
        if (preg_match_all(self::SNR_RE, $text, $m) < 1) {
            return null;
        }
        foreach ($m[1] as $n) {
            if (($snr = $this->snrInRange((int) $n)) !== null) {
                return $snr;
            }
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
            if ($this->mentionsFootwear($norm) && ! $respiratory) {
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

    private function detectRozmiar(
        string $text,
        ?string $claimed = null,
        ?string $category = null,
        ?string $name = null,
    ): ?string {
        return (new ProductSizeVariant)->labelFromTexts($claimed, $text, $category, $name);
    }

    /**
     * Pierwszy kod EN 388 zapisany kodem (nie słowami), zwarty: „EN 388:2016 (4 1 2 1 X)” → „4121X”. Tekst idzie
     * w kolejności źródeł karty, więc pierwszy odczyt jest z najmocniejszego źródła.
     */
    private function en388Code(string $text): ?string
    {
        foreach (En388Code::allIn($text) as $code) {
            $compact = $code->compact();
            if ($compact !== null && $compact !== '') {
                return $compact;
            }
        }

        return null;
    }

    /**
     * „EN 14126” i „EN 14126 (ochrona przed czynnikami biologicznymi)” to jedna norma w dwóch
     * zapisach — zostaje ten z objaśnieniem. NormCode::dedupe tego nie zwija, bo zapisu
     * z nawiasem w ogóle nie uznaje za oznaczenie normy, a `array_unique` widzi dwa różne teksty.
     *
     * Rok, poprawkę i poziomy zwija NormCode — tutaj zwijamy WYŁĄCZNIE dopisek w nawiasie.
     * Sam prefiks nie wystarczy: „EN 374” i „EN 374-1” to różne wymagania w przetargu,
     * a „EN 1497” tylko zaczyna się jak „EN 149”. „EN 374-1 (Typ A)” i „EN 374-1 (Typ B)”
     * nie są swoimi prefiksami i zostają obie.
     *
     * @param  list<string>  $items
     * @return list<string>
     */
    private function collapseNormVariants(array $items): array
    {
        $out = [];
        foreach (NormCode::dedupe($items) as $item) {
            $text = trim($item);
            if ($text === '') {
                continue;
            }
            $merged = false;
            foreach ($out as $i => $known) {
                if ($this->normWithGloss($known, $text)) {
                    $out[$i] = $text;
                    $merged = true;
                    break;
                }
                if ($this->normWithGloss($text, $known)) {
                    $merged = true;
                    break;
                }
            }
            if (! $merged) {
                $out[] = $text;
            }
        }

        return array_values($out);
    }

    /** Czy `$zObjasnieniem` to `$goly` z dopiskiem w nawiasie („EN 407” → „EN 407 (poziom 1)”). */
    private function normWithGloss(string $goly, string $zObjasnieniem): bool
    {
        if ($goly === '' || mb_strlen($zObjasnieniem) <= mb_strlen($goly)) {
            return false;
        }
        if (mb_stripos($zObjasnieniem, $goly) !== 0) {
            return false;
        }

        return preg_match('/^\s*[(\[]/u', mb_substr($zObjasnieniem, mb_strlen($goly))) === 1;
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

    /**
     * Sam numer, który w SKU stoi osobno ZA parą model–numer, to kod koloru albo wariantu, a pełnym kodem
     * producenta jest SKU: ARTRA „ARYEL 320 Air 618080 S1 PL ESD” (SKU = nazwa) z kodem „618080” ze sklepu
     * („Kod produktu: 618080”). Rozstrzyga wyłącznie SKU — sama nazwa tego nie potwierdza: „Trzewik Tegro 250
     * 3021 S3 SRC” z SKU „TG250-42” ma kod 3021, a „Rękawice uvex unidur 6648 60942” z SKU 6094209 kod 60942.
     * Numer równy numerowi pary („ARGON 8229” i kod 8229) to kod modelu, nie koloru.
     */
    private function isColourCodeInSku(string $kod, string $sku): bool
    {
        $kod = trim($kod);
        if (preg_match('/^\d{4,}$/u', $kod) !== 1 || trim($sku) === '') {
            return false;
        }
        $kodAt = preg_match('/(?<![\p{L}\d])'.preg_quote($kod, '/').'(?![\p{L}\d])/u', $sku, $m, PREG_OFFSET_CAPTURE) === 1
            ? (int) $m[0][1]
            : null;
        if ($kodAt === null) {
            return false;
        }
        foreach ((new ProductModelFuzzy)->catalogModelWordDigitPairs($sku) as [$word, $num]) {
            if ($num === $kod) {
                continue;
            }
            if (preg_match(
                '/(?<![\p{L}\d])'.preg_quote($word, '/').'\h+'.preg_quote($num, '/').'(?![\p{L}\d])/iu',
                $sku,
                $pm,
                PREG_OFFSET_CAPTURE
            ) === 1 && (int) $pm[0][1] < $kodAt) {
                return true;
            }
        }

        return false;
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
