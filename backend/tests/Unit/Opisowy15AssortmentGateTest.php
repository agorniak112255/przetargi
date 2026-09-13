<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ProductMatchService;
use App\Support\PpeAssortment;
use App\Support\ProductModelFuzzy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * Zestaw „przetarg opisowy 15” — część deterministyczna, bez modelu: bramka rodzaju wyrobu,
 * explainMatch, igły modelu, producent z SIWZ. Karty z fixture bez zapisu do bazy.
 *
 * A1 (rodzaj wyrobu): gdzie błąd produkcji to inny rodzaj (rękaw ≠ rękawica, fartuch ≠
 * szelki, gogle ≠ półmaska). A2 (atrybut/ranking): ten sam rodzaj, ale wybrana karta nie
 * spełnia wymagania (bez zaworu, zwykłe OB, inna grubość) — porządek explain i brak
 * „modelu” wyczytanego z liczb w opisie.
 */
final class Opisowy15AssortmentGateTest extends TestCase
{
    /*
     * Tylko dla `requestedManufacturerFromSiwz`: kandydaci kodów z opisu są porównywani
     * z listą producentów katalogu (zapytanie do `products`). Pusty katalog = puste
     * dopasowanie, więc asercja pilnuje samego regexu `prod.` (P10 z audytu). Karty
     * produktów nadal nie trafiają do bazy.
     */
    use RefreshDatabase;

    /**
     * Pozycje, dla których dana grupa asercji jest dziś zielona. Pozostałe przypadki stoją
     * w data providerach z komentarzem „czerwony do scalenia …” i nie trafiają do PHPUnit
     * (bez markTestSkipped). Koordynator dopisuje numer pozycji po scaleniu pakietu (PLAN §3).
     *
     * @var array<string, list<int>>
     */
    private const ENABLED_LINES = [
        // A1 — właściwa karta przechodzi bramkę
        'gate_expected' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
        // A1 — karta innego rodzaju odrzucona. Wyłączone: 3 — AROX 733 bez rzeczownika typu w nazwie i pierwszym zdaniu opisu (D6, krucho), 6 — okulary nakładkowe bezbarwne vs smoke: kolor soczewki (lensTint) odłożony do Fali 2
        'gate_wrong' => [1, 4, 5, 8, 9, 12, 13],
        // A1 — rodzina wymagania po rzeczowniku głównym
        'family' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
        // A2 — explain właściwej > błędnej. Wyłączone: 2 — karta 34837018 ma opis kategorii sklepu (dane), 56‑426 legalnie punktuje EN 388
        'explain_order' => [1, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
        // A2 — brak fuzzy_model dla opisu bez kodu
        'no_fuzzy_model' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
        // A2 — catalogModelNeedles() === []
        'needles' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
        // A2 — requestedManufacturerFromSiwz() === null
        'manufacturer' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
    ];

    private const ALL_LINES = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15];

    private PpeAssortment $assortment;

    private ProductMatchService $matcher;

    private ProductModelFuzzy $fuzzy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assortment = app(PpeAssortment::class);
        $this->matcher = app(ProductMatchService::class);
        $this->fuzzy = app(ProductModelFuzzy::class);
    }

    // ---------------------------------------------------------------- A1: rodzaj wyrobu

    #[Test]
    #[DataProvider('gateExpectedCases')]
    public function expected_card_passes_kind_gate(int $line): void
    {
        $requirement = Opisowy15Fixture::requirement($line);
        $expected = Opisowy15Fixture::product((string) Opisowy15Fixture::line($line)['expected_sku']);

        $this->assertTrue(
            $this->assortment->compatibleProduct($requirement, $expected),
            "poz. {$line}: bramka odrzuca właściwą kartę {$expected->sku}",
        );
        $explained = $this->matcher->explainMatch($requirement, $expected);
        $this->assertNotSame('asortyment_reject', $explained['reasons'][0]['code'] ?? null, "poz. {$line}: explainMatch odrzuca właściwą kartę");
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function gateExpectedCases(): array
    {
        return self::onlyEnabled('gate_expected', [
            'poz. 1 rękaw antyprzecięciowy 11202000' => [1],
            'poz. 2 rękawice antyprzecięciowe 34837018' => [2],
            'poz. 3 sandały S1P ESD ARMEN 9007' => [3],
            // czerwony do scalenia W3 — przyczyna: family(req) = fall („szeroka szelka” trafia regex asekuracji przed odzieżą, R0)
            'poz. 4 fartuch wodoochronny 202' => [4],
            // czerwony do scalenia W3 — przyczyna: family(req) = fall („wymienne szelki”, R0) + brak kroju waders (D2)
            'poz. 5 spodniobuty S5 SB04 AIR' => [5],
            // czerwony do scalenia W3 — przyczyna: eyeCompatible odrzuca kartę bez „okular|gogl” w nazwie (RUSHPTWI: „soczewki PC…”)
            'poz. 6 okulary smoke RUSHPTWI' => [6],
            'poz. 7 rękawice cut NBR 44-304' => [7],
            'poz. 8 półmaska FFP1 z zaworem 9914' => [8],
            // czerwony do scalenia W3 — przyczyna: family(req) = respiratory („łącznie z półmaskami” przed „gogle”, R0)
            'poz. 9 gogle spawalnicze 34340' => [9],
            'poz. 10 apteczka ścienna 191400' => [10],
            'poz. 11 płukanka 500 ml 7251' => [11],
            'poz. 12 półbuty elektroizolacyjne T5912100' => [12],
            'poz. 13 półmaska wielorazowa S56T0SM0' => [13],
            'poz. 14 pochłaniacz A2 S565A202' => [14],
            'poz. 15 rękawice lateks flok 87320100-BULK' => [15],
        ]);
    }

    #[Test]
    #[DataProvider('gateWrongCases')]
    public function wrong_kind_card_is_rejected_by_gate(int $line, string $wrongSku): void
    {
        $requirement = Opisowy15Fixture::requirement($line);
        $wrong = Opisowy15Fixture::product($wrongSku);

        $this->assertFalse(
            $this->assortment->compatibleProduct($requirement, $wrong),
            "poz. {$line}: bramka przepuszcza kartę innego rodzaju {$wrongSku}",
        );
        $explained = $this->matcher->explainMatch($requirement, $wrong);
        $this->assertSame(0, $explained['score'], "poz. {$line}: {$wrongSku} dostaje punkty mimo innego rodzaju");
        $this->assertSame('asortyment_reject', $explained['reasons'][0]['code'] ?? null, "poz. {$line}: brak asortyment_reject dla {$wrongSku}");
    }

    /**
     * Pary „wymaganie → karta innego rodzaju wyrobu”. Przypadki z produkcji są dziś czerwone;
     * pary kontrolne (jawnie inna rodzina) są zielone zawsze i pilnują, żeby poprawka rodziny
     * po rzeczowniku głównym (W3) nie rozszczelniła bramki tam, gdzie dziś działa.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function gateWrongCases(): array
    {
        $controls = [
            'kontrola: poz. 1 rękaw (gloves) vs sandały ARMEN 9007 (footwear)' => [1, 'ARMEN 9007 6660 S1 P'],
            'kontrola: poz. 8 półmaska (respiratory) vs gogle 34340 (eyes)' => [8, '34340'],
            'kontrola: poz. 12 półbuty (footwear) vs szelki AB178 (fall)' => [12, 'AB178'],
            'kontrola: poz. 15 rękawice (gloves) vs fartuch 202 (apparel)' => [15, '202'],
        ];

        return $controls + self::onlyEnabled('gate_wrong', [
            // czerwony do scalenia W3 — przyczyna: isArmSleeve nie zna „ochraniacz przedramienia / rękaw”, bramka jednokierunkowa (rękaw ≠ rękawica)
            'poz. 1 rękaw vs rękawice ESD 48130110' => [1, '48130110'],
            'poz. 1 rękaw vs rękawice EDGE 3440-003-100-00' => [1, '3440-003-100-00'],
            // czerwony do scalenia W3 (W3‑10, mile widziane) — przyczyna: AROX/ARDEUS bez typu w nazwie, footwearCompatible przepuszcza po „S1”; sandał ≠ półbut/trzewik; porządek explain (A2) pilnuje poz. 3 już dziś
            'poz. 3 sandały vs półbuty AROX 733' => [3, 'AROX 733 641460 S1 ESD'],
            'poz. 3 sandały vs trzewiki ARDEUS 350 Air' => [3, 'ARDEUS 350 Air 618080 S1 PL ESD'],
            // czerwony do scalenia W3 — przyczyna: family(req4) = fall, więc spodnie „z szelkami” (fall) przechodzą; po R0 fartuch (coat) ≠ spodnie (pants)
            'poz. 4 fartuch vs spodnie z szelkami 3112' => [4, '3112'],
            // czerwony do scalenia W3 — przyczyna: family(req5) = fall („wymienne szelki”); szelki bezpieczeństwa ≠ spodniobuty (apparel/waders)
            'poz. 5 spodniobuty vs szelki bezpieczeństwa AB178' => [5, 'AB178'],
            // czerwony do scalenia W3 — przyczyna: brak lensTint w eyeCompatible; nakładkowe bezbarwne (Visitor) ≠ smoke
            'poz. 6 okulary smoke vs nakładkowe bezbarwne 71347-00004M' => [6, '71347-00004M'],
            // czerwony do scalenia W3 — przyczyna: compatibleProduct nie ma gałęzi oddechowej; wymagany „z zaworem”, karta jawnie „bez zaworu”
            'poz. 8 FFP1 z zaworem vs 9310+ bez zaworu' => [8, '9310+'],
            'poz. 8 FFP1 z zaworem vs 8710E bez zaworu 7100329384' => [8, '7100329384'],
            // czerwony do scalenia W3 — przyczyna: family(req9) = respiratory („łącznie z półmaskami”); gogle (eyes) ≠ półmaska FFP
            'poz. 9 gogle vs półmaska FFP1 9310+' => [9, '9310+'],
            'poz. 9 gogle vs półmaska FFP1 9312+' => [9, '9312+'],
            // czerwony do scalenia W3 — przyczyna: wymagana elektroizolacja (EN 50321‑1, kV) nieobecna na karcie zwykłych OB → footwearCompatible ma odrzucać jak antystatykę
            'poz. 12 elektroizolacyjne vs zwykłe OB ART 702' => [12, 'ART 702 Air 6660 OB A E FO'],
            // czerwony do scalenia W3 — przyczyna: respiratoryType ffp vs reusable_half nie jest egzekwowany w compatibleProduct
            'poz. 13 półmaska wielorazowa vs FFP1 9310+' => [13, '9310+'],
        ]);
    }

    #[Test]
    #[DataProvider('familyCases')]
    public function requirement_family_follows_the_main_noun(int $line, ?string $family): void
    {
        $this->assertSame($family, $this->assortment->family(Opisowy15Fixture::requirement($line)), "poz. {$line}: rodzina wymagania");
    }

    /**
     * @return array<string, array{0: int, 1: string|null}>
     */
    public static function familyCases(): array
    {
        return self::onlyEnabled('family', [
            'poz. 1 rękaw → gloves (rękaw to podtyp rodziny rękawic)' => [1, PpeAssortment::FAMILY_GLOVES],
            'poz. 2 rękawice → gloves' => [2, PpeAssortment::FAMILY_GLOVES],
            'poz. 3 sandały → footwear' => [3, PpeAssortment::FAMILY_FOOTWEAR],
            // czerwony do scalenia W3 — przyczyna: R0 — „szeroka szelka” w treści fartucha daje fall zamiast apparel
            'poz. 4 fartuch → apparel' => [4, PpeAssortment::FAMILY_APPAREL],
            // czerwony do scalenia W3 — przyczyna: R0 + D2 — „wymienne szelki” dają fall; spodniobuty to odzież (waders)
            'poz. 5 spodniobuty → apparel' => [5, PpeAssortment::FAMILY_APPAREL],
            'poz. 6 okulary → eyes' => [6, PpeAssortment::FAMILY_EYES],
            'poz. 7 rękawice → gloves' => [7, PpeAssortment::FAMILY_GLOVES],
            'poz. 8 półmaska filtrująca → respiratory' => [8, PpeAssortment::FAMILY_RESPIRATORY],
            // czerwony do scalenia W3 — przyczyna: R0 — „do stosowania łącznie z półmaskami” wygrywa z „gogle”
            'poz. 9 gogle spawalnicze → eyes' => [9, PpeAssortment::FAMILY_EYES],
            'poz. 10 apteczka → brak rodziny ŚOI' => [10, null],
            'poz. 11 płukanka → brak rodziny ŚOI' => [11, null],
            'poz. 12 półbuty → footwear' => [12, PpeAssortment::FAMILY_FOOTWEAR],
            'poz. 13 półmaska wielorazowa → respiratory' => [13, PpeAssortment::FAMILY_RESPIRATORY],
            'poz. 14 pochłaniacz → respiratory' => [14, PpeAssortment::FAMILY_RESPIRATORY],
            'poz. 15 rękawice → gloves' => [15, PpeAssortment::FAMILY_GLOVES],
        ]);
    }

    // ---------------------------------------------------------------- A2: atrybut / ranking

    #[Test]
    #[DataProvider('explainOrderCases')]
    public function expected_card_explains_better_than_wrong_pick(int $line, string $wrongSku): void
    {
        $requirement = Opisowy15Fixture::requirement($line);
        $expectedSku = (string) Opisowy15Fixture::line($line)['expected_sku'];
        $expected = $this->matcher->explainMatch($requirement, Opisowy15Fixture::product($expectedSku));
        $wrong = $this->matcher->explainMatch($requirement, Opisowy15Fixture::product($wrongSku));

        $this->assertGreaterThan(
            $wrong['score'],
            $expected['score'],
            "poz. {$line}: explain {$expectedSku}={$expected['score']} nie przebija {$wrongSku}={$wrong['score']}",
        );
    }

    /**
     * Właściwa karta musi mieć wyższy explainMatch niż karta wybrana błędnie — także wtedy,
     * gdy różnica leży w bramce (0 pkt po odrzuceniu).
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function explainOrderCases(): array
    {
        return self::onlyEnabled('explain_order', [
            // czerwony do scalenia W3 — przyczyna: rękaw i rękawice ESD remisują (80 vs 81) — bramka rękaw/rękawica ma zerować 48130110
            'poz. 1 11202000 > 48130110' => [1, '48130110'],
            'poz. 1 11202000 > 3440-003-100-00' => [1, '3440-003-100-00'],
            // czerwony do scalenia W3 — przyczyna: typeNameScore +40 dla „Rękawica szczelna” (56‑426) bez zgodności podtypu; KRYTECH 837 nie ma rzeczownika w nazwie (62 vs 98); jeśli po W3 nadal czerwone → Fala 2 (retrieval P3)
            'poz. 2 34837018 > 56-426' => [2, '56-426'],
            // krucho: 77 vs 76 (D6) — trzyma się dziś; W3‑10 ma dać odrzucenie zamiast 1 pkt różnicy
            'poz. 3 ARMEN 9007 > AROX 733' => [3, 'AROX 733 641460 S1 ESD'],
            'poz. 3 ARMEN 9007 > ARDEUS 350 Air' => [3, 'ARDEUS 350 Air 618080 S1 PL ESD'],
            // czerwony do scalenia W3 — przyczyna: właściwa karta odrzucona przez bramkę (0 vs 99)
            'poz. 4 202 > 3112' => [4, '3112'],
            // czerwony do scalenia W3 — przyczyna: właściwa karta odrzucona przez bramkę (0 vs 99)
            'poz. 5 SB04 AIR > AB178' => [5, 'AB178'],
            // czerwony do scalenia W3 — przyczyna: właściwa karta odrzucona przez bramkę (0 vs 99)
            'poz. 6 RUSHPTWI > 71347-00004M' => [6, '71347-00004M'],
            'poz. 7 44-304 > 34557048' => [7, '34557048'],
            'poz. 7 44-304 > 34380358' => [7, '34380358'],
            // czerwony do scalenia W1 + W3 — przyczyna: remis 99 = 99 z fuzzy_model=94 dla każdej FFP1 (igły „ffp1”, „karton100”); zawór rozstrzyga dopiero w W3
            'poz. 8 9914 > 9310+' => [8, '9310+'],
            'poz. 8 9914 > 7100329384' => [8, '7100329384'],
            // czerwony do scalenia W3 — przyczyna: właściwa karta odrzucona przez bramkę (0 vs 58)
            'poz. 9 34340 > 9310+' => [9, '9310+'],
            'poz. 9 34340 > 9312+' => [9, '9312+'],
            'poz. 11 7251 > 7251-7200' => [11, '7251-7200'],
            'poz. 12 T5912100 > ART 702' => [12, 'ART 702 Air 6660 OB A E FO'],
            // czerwony do scalenia W3 — przyczyna: remis 99 = 99, podtyp ffp vs reusable_half nie jest egzekwowany
            'poz. 13 S56T0SM0 > 9310+' => [13, '9310+'],
            'poz. 15 87320100-BULK > 87063100BP' => [15, '87063100BP'],
        ]);
    }

    #[Test]
    #[DataProvider('noFuzzyModelCases')]
    public function description_without_model_code_does_not_get_fuzzy_model_reason(int $line, string $sku): void
    {
        $requirement = Opisowy15Fixture::requirement($line);
        $product = Opisowy15Fixture::product($sku);

        $this->assertLessThan(80, $this->fuzzy->score($requirement, $product), "poz. {$line}: fuzzy „model” dla {$sku}");
        $codes = array_column($this->matcher->explainMatch($requirement, $product)['reasons'], 'code');
        $this->assertNotContains('fuzzy_model', $codes, "poz. {$line}: „Model z SIWZ (literówka)” dla {$sku}, choć opis nie ma kodu");
    }

    /**
     * Żaden z 15 opisów nie zawiera kodu modelu, więc żadna karta (właściwa ani błędna)
     * nie może dostać „Model z SIWZ (literówka)”.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function noFuzzyModelCases(): array
    {
        $cases = [];
        foreach (Opisowy15Fixture::items() as $item) {
            $line = (int) $item['line_no'];
            $skus = [(string) $item['expected_sku'], ...array_map('strval', (array) $item['forbidden_skus'])];
            foreach ($skus as $sku) {
                // poz. 8 — czerwony do scalenia W1 — przyczyna: igły „klasyffp1”, „karton100”, „ffp1” dają fuzzy 94 dla każdej FFP1
                $cases["poz. {$line} {$sku}"] = [$line, $sku];
            }
        }

        return self::onlyEnabled('no_fuzzy_model', $cases);
    }

    #[Test]
    #[DataProvider('needlesCases')]
    public function description_without_model_code_has_no_catalog_model_needles(int $line): void
    {
        $this->assertSame(
            [],
            $this->fuzzy->catalogModelNeedles(Opisowy15Fixture::requirement($line)),
            "poz. {$line}: igły modelu z liczb w opisie",
        );
    }

    /**
     * Wejście do honorsSpecificModelCodes / etykiety „model: …” — dla opisu bez kodu ma być puste.
     *
     * @return array<string, array{0: int}>
     */
    public static function needlesCases(): array
    {
        return self::onlyEnabled('needles', [
            // czerwony do scalenia W1 — przyczyna: igły „kategoriiiii2003”, „iii2003”, „2x42c”, „100c”
            'poz. 1' => [1],
            'poz. 2' => [2],
            // czerwony do scalenia W1 — przyczyna: igła „kategoriis1”
            'poz. 3' => [3],
            // czerwony do scalenia W1 — przyczyna: igła „wymiary120”
            'poz. 4' => [4],
            // czerwony do scalenia W1 — przyczyna: igły „typus5”, „50c”
            'poz. 5' => [5],
            // czerwony do scalenia W1 — przyczyna: igła „55c”
            'poz. 6' => [6],
            // czerwony do scalenia W1 — przyczyna: igły „akredytacjadermatologiczna2016”, „100c”
            'poz. 7' => [7],
            // czerwony do scalenia W1 — przyczyna: igły „klasyffp1”, „karton100”, „ffp1”
            'poz. 8' => [8],
            // czerwony do scalenia W1 — przyczyna: igły „poluwidzenia180”, „widzenia180”
            'poz. 9' => [9],
            // czerwony do scalenia W1 — przyczyna: igła „4w1”
            'poz. 10' => [10],
            // czerwony do scalenia W1 — przyczyna: igła „pojemnosci500”
            'poz. 11' => [11],
            // czerwony do scalenia W1 — przyczyna: igła „norma2012”
            'poz. 12' => [12],
            // czerwony do scalenia W1 — przyczyna: igła „50c”
            'poz. 13' => [13],
            // czerwony do scalenia W1 — przyczyna: igły „klasya2”, „65c”
            'poz. 14' => [14],
            // czerwony do scalenia W1 — przyczyna: igły „rombowymdlugosc300”, „dlugosc300”
            'poz. 15' => [15],
        ]);
    }

    #[Test]
    #[DataProvider('manufacturerCases')]
    public function no_manufacturer_is_read_from_description_without_brand(int $line): void
    {
        // Metoda jest prywatna (W1 robi ją publiczną — refleksja działa w obu wariantach).
        $method = new ReflectionMethod(ProductMatchService::class, 'requestedManufacturerFromSiwz');

        $this->assertNull(
            $method->invoke($this->matcher, Opisowy15Fixture::requirement($line)),
            "poz. {$line}: z opisu bez marki wyczytano producenta",
        );
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function manufacturerCases(): array
    {
        $cases = [];
        foreach (self::ALL_LINES as $line) {
            // poz. 12 — czerwony do scalenia W1 — przyczyna: regex `\bprod\.?\s*` łapie „produkcja metodą…” → „ukcja”
            $cases["poz. {$line}"] = [$line];
        }

        return self::onlyEnabled('manufacturer', $cases);
    }

    // ---------------------------------------------------------------- spójność zestawu

    #[Test]
    public function fixture_is_consistent_with_itself(): void
    {
        $items = Opisowy15Fixture::items();
        $this->assertCount(15, $items);
        $this->assertSame(self::ALL_LINES, array_map(static fn (array $item): int => (int) $item['line_no'], $items));

        $cards = Opisowy15Fixture::cards();
        $skus = array_map(static fn (array $card): string => (string) $card['sku'], $cards);
        $this->assertSame($skus, array_values(array_unique($skus)), 'zdublowane SKU w products.json');
        foreach ($cards as $card) {
            $this->assertArrayNotHasKey('_meta', $card);
        }

        foreach ($items as $item) {
            $line = (int) $item['line_no'];
            $this->assertContains((string) $item['expected_sku'], $skus, "poz. {$line}: brak karty oczekiwanej w products.json");
            foreach ((array) $item['forbidden_skus'] as $forbidden) {
                $this->assertContains((string) $forbidden, $skus, "poz. {$line}: brak karty zakazanej {$forbidden} w products.json");
                $this->assertNotSame((string) $item['expected_sku'], (string) $forbidden, "poz. {$line}: karta oczekiwana na liście zakazanych");
            }
            $this->assertGreaterThanOrEqual(40, mb_strlen((string) $item['requirement']), "poz. {$line}: opis za krótki na pozycję opisową");
        }

        foreach (self::ENABLED_LINES as $group => $lines) {
            foreach ($lines as $line) {
                $this->assertContains($line, self::ALL_LINES, "ENABLED_LINES[{$group}] wskazuje nieistniejącą pozycję {$line}");
            }
            $this->assertSame($lines, array_values(array_unique($lines)), "ENABLED_LINES[{$group}] ma duplikaty");
        }
    }

    /**
     * @template T of array
     *
     * @param  array<string, T>  $cases  przypadek ma numer pozycji na indeksie 0
     * @return array<string, T>
     */
    private static function onlyEnabled(string $group, array $cases): array
    {
        $enabled = self::ENABLED_LINES[$group];

        return array_filter($cases, static fn (array $case): bool => in_array($case[0], $enabled, true));
    }
}
