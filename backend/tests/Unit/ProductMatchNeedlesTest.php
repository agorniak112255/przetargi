<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\ProductMatchService;
use App\Support\ProductModelFuzzy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pozycje opisowe (bez marki i kodu) z przetargu testowego „opisowy 15”: oznaczenia klas,
 * lata norm, temperatury i miary nie są „modelem z SIWZ”, rzeczowniki rodzajowe w nazwie karty
 * nie są „marką”, a heurystyka „mocny SKU” rozstrzyga tylko na igłach z cyfrą / modelu z myślnikiem /
 * linii po marce. Karty błędnych wyborów z produkcji (wrong_picks.json) — tylko sku/nazwa/producent,
 * bo tyle czyta fuzzy.
 */
final class ProductMatchNeedlesTest extends TestCase
{
    // requestedManufacturerFromSiwz → CatalogManufacturerContext odpytuje tabelę products
    use RefreshDatabase;

    /** @var array<int, string> */
    private const REQUIREMENTS = [
        1 => 'Ochraniacz przedramienia (rękaw) chroniący przed przecięciem, długość ok. 475 mm (19\'\'), w kolorze fluorescencyjnym żółtym o wysokiej widzialności. Konstrukcja bezszwowa, dzianina z przędzy nylon/poliester/włókno szklane; regulowane zapięcie na rzep; wyrób antystatyczny, bez lateksu i bez silikonu; dopuszczony do kontaktu z żywnością (zgodność z wymaganiami FDA). Zastosowanie: obróbka blach i szkła, montaż i naprawa elementów karoserii, przemysł motoryzacyjny, metalowy, maszynowy. Wymagane: ŚOI kategorii III; EN 420:2003+A1:2009; EN 388 z poziomami min. 2.X.4.2.C (odporność na przecięcie poziom C, na rozdzieranie 4, na przekłucie 2); EN 407 – odporność na ciepło kontaktowe poziom 1 (do 100°C).',
        2 => 'Rękawice ochronne odporne na przecięcie, przeznaczone do prac z narzędziami tnącymi, ostrymi elementami i szkłem – m.in. w budownictwie, przemyśle szklarskim, chemicznym i motoryzacyjnym oraz przy pracach ze skalpelem lub nożem drukarskim. Wymagane: ochrona dłoni przed przecięciem i ścieraniem potwierdzona oznakowaniem zgodnie z EN 388; konstrukcja zapewniająca elastyczność, wygodę i precyzję pracy.',
        3 => 'Sandały ochronne (obuwie bezpieczne z odkrytą cholewką) kategorii S1 P wg EN ISO 20345, do prac w suchych pomieszczeniach – przemysł elektroniczny, logistyka, montaż. Wymagane: zabudowana pięta; podnosek ochronny; właściwości antyelektrostatyczne (ESD) umożliwiające kontrolę ładunków elektrostatycznych; podeszwa odporna na oleje i paliwa (FO) oraz antypoślizgowa; cholewka z materiałów przewiewnych; lekka konstrukcja odpowiednia do prac wymagających częstego przemieszczania się. Rozmiary: 35–48.',
        4 => 'Fartuch przedni wodoochronny, wymiary 120 × 75 cm, z lekkiej tkaniny poliestrowej powlekanej poliuretanem, we wzór w pionowe pasy. Wymagane: regulacja na pasku szyjnym; szeroka szelka i wiązanie z tyłu; ochrona przed wilgocią; zwiększona odporność na tłuszcze, enzymy, soki trawienne oraz słabe kwasy i zasady; materiał zachowujący właściwości również w niskich temperaturach; zgodność z EN ISO 13688 i EN 343. Zastosowanie: przetwórstwo spożywcze (owocowo-warzywne, mięsne, rybne), gastronomia, rolnictwo, ogrodnictwo, weterynaria, segregacja odpadów.',
        5 => 'Spodniobuty wodoochronne z wgrzanymi na stałe kaloszami – obuwie bezpieczne typu S5 SRC wg EN ISO 20345, z wkładką antyprzebiciową. Wymagane: tkanina na podkładzie poliestrowym, jednostronnie powlekana PVC, o zwiększonej widzialności (kolor fluorescencyjny) i odporności na rozdzieranie, spełniająca wymagania EN 343; szwy zgrzewane obustronnie (szczelność i wytrzymałość); wzmocnienia na kolanach; regulacja w pasie sznurkiem; wymienne szelki z szerokiej elastycznej gumy; materiał odporny na zginanie bez pękania w temperaturze do -50°C; przeznaczenie do pracy w warunkach ograniczonej widoczności. Rozmiary: 39–48.',
        6 => 'Okulary ochronne z przyciemnianymi (smoke) soczewkami poliwęglanowymi do pracy na zewnątrz w pełnym słońcu – ochrona mechaniczna oczu połączona z redukcją odblasków i ochroną przed promieniowaniem UV. Wymagane: soczewki z poliwęglanu o grubości 2,3 mm; powłoka przeciwmgielna i odporna na zarysowania; panoramiczna, silnie zakrzywiona konstrukcja osłaniająca oczy przed pyłem, odpryskami ciał stałych i wiatrem; bi-materiałowe zauszniki PC+TPR w kolorze czerwono-czarnym; konstrukcja lekka, bez elementów metalowych. Zgodność z EN 166, EN 172 oraz EN ISO 16321-1; oznaczenie soczewek: filtr przeciwsłoneczny o stopniu zaciemnienia 3, klasa optyczna 1, odporność na uderzenia cząstek o niskiej energii, odporność w temperaturach ekstremalnych od -5°C do +55°C (T). Środek ochrony indywidualnej kategorii II, oznakowanie CE.',
        7 => 'Rękawice ochronne antyprzecięciowe powlekane, do prac w środowisku zaolejonym i wilgotnym z ryzykiem przecięcia oraz kontaktu z gorącymi przedmiotami (ochrona przed ciepłem kontaktowym do 100°C przez 15 s). Wymagane: dzianina z przędzy UHMWPE, włókna szklanego, nylonu, poliestru i elastanu (spandex); powłoka ze spienionej gumy nitrylowo-butadienowej (NBR) na części chwytnej, o wykończeniu mikroporowatym zapewniającym pewny chwyt, odporna na ciecze i oleje; dodatkowe wzmocnienie między kciukiem a palcem wskazującym; mankiet ze ściągaczem; bez silikonu i substancji SVHC; rękawice wstępnie prane, z akredytacją dermatologiczną. EN 388:2016 – ścieranie 4, przecięcie (Coup Test) 3, rozdzieranie 4, przekłucie 1, przecięcie wg metody ISO – poziom B; EN 407:2004 – ciepło kontaktowe poziom 1 (pozostałe parametry nietestowane). Nie do kontaktu z otwartym ogniem.',
        8 => 'Półmaska filtrująca klasy FFP1 z zaworem wydechowym, specjalistyczna – do ochrony dróg oddechowych przed pyłami, mgłami oraz uciążliwym poziomem par organicznych. Wymagane: warstwa węgla aktywowanego eliminująca drażniące zapachy par organicznych poniżej NDS; zawór wydechowy odprowadzający nagromadzone ciepło i wydychane powietrze, ograniczający parowanie okularów; mocna konstrukcja kopułkowa dopasowująca się do większości kształtów i rozmiarów twarzy, nieodkształcająca się i niezapadająca; lekka, kompatybilna ze środkami ochrony wzroku i słuchu. Zakres stosowania: do 4 x NDS dla cząstek; poniżej NDS dla par organicznych. Zgodność z EN 149. Opakowanie: 10 szt., karton 100 szt.',
        9 => 'Gogle ochronne szczelne, spawalnicze, z zaciemnieniem 5.0 – do ochrony oczu podczas spawania gazowego i cięcia metalu oraz przed pyłem, odpryskami i chemikaliami. Wymagane: soczewka poliwęglanowa o odporności na uderzenia do 120 m/s; powłoka przeciwmgielna oraz powłoka odporna na zarysowania; wentylacja szczelna chroniąca przed drobnymi cząstkami pyłu i gazem, ograniczająca zamglenie; cylindryczna, zakrzywiona soczewka o polu widzenia 180° bez zniekształceń optycznych; wysoki profil umożliwiający noszenie na okularach korekcyjnych; szeroki, regulowany pasek nylonowy; miękka uszczelka z elastomeru; możliwość stosowania łącznie z półmaskami oddechowymi. Zgodność z EN 166, oznakowanie CE.',
        10 => 'Apteczka ścienna pierwszej pomocy (panel) w wersji mini, do mniejszych pomieszczeń (np. biura) lub jako uzupełnienie innych zestawów pierwszej pomocy. Wymagane: konstrukcja otwarta, niezamykana – natychmiastowa gotowość do użycia; stałe miejsce dla każdego elementu, każdy element opatrzony czytelną instrukcją użycia; dozownik na plastry wydający plaster częściowo odklejony (przyklejenie jedną ręką), z wkładem blokowanym w dozowniku i wyjmowanym wyłącznie dołączonym kluczem; możliwość uzupełniania poszczególnych wkładów. Wyposażenie: 1 szt. zestaw opatrunkowy 4-w-1 do tamowania krwi; 3 szt. zestaw opatrunkowy 4-w-1 mini do tamowania krwi; 1 dozownik z dwoma wkładami plastrów (tekstylne i plastikowe); 1 klucz do wkładów. Wyroby medyczne oznakowane CE. Kolor zielony; masa 1,981 kg.',
        11 => 'Płukanka do oczu – sterylny, izotoniczny roztwór soli fizjologicznej bez konserwantów, do jednorazowego płukania oczu w razie zaprószenia lub ochlapania substancjami niebezpiecznymi; roztwór neutralizujący kwasy i zasady (silniejsze działanie wobec zasad). Wymagane: butelka o pojemności 500 ml z końcówką w kształcie wanienki kierującą strumień roztworu do oka i pomagającą utrzymać powiekę otwartą; otwieranie jednym ruchem przez przekręcenie; zintegrowany korek chroniący wanienkę przed zanieczyszczeniem; możliwość montażu na stanowisku pracy, w tym w uchwycie ściennym; wymiary ok. Ø 6,6 x 23,5 cm; okres przydatności 5 lat; wyrób medyczny oznakowany znakiem CE. Zastosowanie: laboratoria, magazyny chemiczne, zakłady przemysłowe; także jako uzupełnienie oczomyjek zasilanych bieżącą wodą.',
        12 => 'Półbuty elektroizolacyjne do prac przy urządzeniach i instalacjach elektroenergetycznych o napięciu przemiennym do 17 kV, przeznaczone do nakładania na inne obuwie robocze i stosowania wyłącznie w strefach zagrożeń elektrycznych jako dodatkowy środek ochrony przed porażeniem prądem. Wymagane: klasa 2 AC zgodnie z normą EN 50321-1; wykonanie z gumy naturalnej z dodatkiem antystarzeniowym o podwyższonej odporności elektroizolacyjnej; produkcja metodą konfekcjonowania ręcznego i wulkanizacji; każda para oznaczona numerem seryjnym i datą produkcji umożliwiającymi kontrolę i badania okresowe; zgodność z normą EN 20347:2012 dla obuwia zawodowego kategorii OB; odporność na poślizg SRA. Rozmiary 41–45. Badania okresowe w laboratorium nie rzadziej niż co 12 miesięcy; oględziny przed każdym użyciem.',
        13 => 'Półmaska wielokrotnego użytku do ochrony układu oddechowego – po skompletowaniu z odpowiednimi elementami oczyszczającymi chroni przed aerozolami (pyły, dymy, mgły), parami i gazami oraz obiema postaciami łącznie. Wymagane: korpus z dwoma zaworami wdechowymi wyposażonymi w łączniki bagnetowe do montażu elementów oczyszczających; zawór wydechowy z pokrywą; jednoczęściowe nagłowie tekstylne łączone z półmaską za pomocą zapinek i zaczepów pierścienia; możliwość rozłożenia do czyszczenia i odkażania ciepłą wodą z mydłem (poniżej 50°C); trwałość 5 lat przechowywania w opakowaniu fabrycznym, min. 3 lata bezpiecznego użytkowania po rozpakowaniu; zgodność z rozporządzeniem (UE) 2016/425; zgodność z normą PN-EN 140:2004 (EN 140:1998); oznakowanie CE.',
        14 => 'Pochłaniacz gazów i par klasy A2 – element oczyszczający do sprzętu ochrony układu oddechowego, chroniący przed gazami i parami substancji organicznych o temperaturze wrzenia powyżej 65°C (m.in. alkohole, aldehydy, estry, etery, ketony, kwasy organiczne, styren) przy łącznym stężeniu objętościowym w powietrzu do 0,5% (5000 ppm). Wymagane: klasa A2 zgodnie z normą EN 14387; obudowa z tworzywa sztucznego; bagnetowy system mocowania zapewniający dokładne i bezpieczne osadzenie na półmaskach i maskach pełnotwarzowych ze złączem bagnetowym; możliwość łączenia z filtrami cząstek tej samej serii; stosowany w komplecie po 2 sztuki na maskę lub półmaskę. Zastosowanie: przemysł chemiczny, lakierniczy, prace z rozpuszczalnikami organicznymi.',
        15 => 'Rękawice ochronne z lateksu naturalnego, flokowane, do lekkich prac o niskim stopniu zagrożenia oraz do kontaktu z żywnością (przetwórstwo spożywcze, owoce i warzywa, sprzątanie laboratoriów, prace porządkowe). Wymagane: powłoka z lateksu naturalnego w kolorze pomarańczowym, chlorowana; wyściółka (podszewka) w 100% z bawełny, flokowana; budowa bez usztywnienia; mankiet zawinięty (rolowany) ułatwiający zakładanie i zdejmowanie oraz zapewniający odporność na rozdarcia; chwytność w układzie rombowym; długość 300 mm; grubość w części dłoniowej 0,45 mm; poziom AQL 1,5; zgodność z przepisami dotyczącymi kontaktu z żywnością; odporność na ścieranie; rozmiary 7, 8, 9, 10. Opakowanie zbiorcze: 12 par w woreczku, 12 woreczków w kartonie.',
    ];

    /**
     * Błędne wybory z produkcji (wrong_picks.json + top-1 wyszukiwarki): pozycja → [sku, nazwa, producent].
     *
     * @var array<int, list<array{0: string, 1: string, 2: string}>>
     */
    private const WRONG_PICKS = [
        1 => [
            ['48130110', 'HyFlex 48130', 'Ansell'],
            ['3440-003-100-00', 'Gloves EDGE 48-140 ESD seamless polyester and carbon fiber, PU coating', 'Canis'],
        ],
        2 => [['56-426', 'Rękawica szczelna, 26 cm', 'ATG']],
        3 => [
            ['AROX 733 641460 S1 ESD', 'AROX 733 641460 S1 ESD', 'ARTRA'],
            ['ARDEUS 350 611460 S3L ESD', 'ARDEUS 350 611460 S3L ESD', 'ARTRA'],
        ],
        4 => [['3112', 'Spodnie do pasa z szelkami Extreme', 'AJ GROUP']],
        5 => [
            ['AB178', 'Szelki bezpieczeństwa typu kamizelka 3M™ Protecta® FIRST, kolor niebieski, rozmiar uniwersalny, AB17510CE', '3M'],
            ['3112', 'Spodnie do pasa z szelkami Extreme', 'AJ GROUP'],
        ],
        6 => [['71347-00004M', '3M™ Visitor Okulary ochronne nakładkowe, przezroczyste soczewki, 71448-00001, 20 szt./opakowanie', '3M']],
        7 => [
            ['34557048', 'KRYTECH 557', 'MAPA'],
            ['34380358', 'KRYTECH 380', 'MAPA'],
        ],
        8 => [
            ['9310+', '3M™ Aura™ półmaska filtrująca, FFP1, bez zaworu, 9310+', '3M'],
            ['7100329384', '3M™ półmaska do cząstek stałych 8710E, FFP1, bez zaworu, 3 szt./opakowanie', '3M'],
        ],
        9 => [
            ['9310+', '3M™ Aura™ półmaska filtrująca, FFP1, bez zaworu, 9310+', '3M'],
            ['9312+', '3M™ Aura™ półmaska filtrująca, FFP1, z zaworem, 9312+ (LANG B)', '3M'],
        ],
        11 => [['7251-7200', 'Płukanka Cederroth z uchwytem 7200', 'CEDERROTH']],
        12 => [['ART 702 Air 6660 OB A E FO', 'ART 702 Air 6660 OB A E FO', 'ARTRA']],
        13 => [['9310+', '3M™ Aura™ półmaska filtrująca, FFP1, bez zaworu, 9310+', '3M']],
        15 => [['87063100BP', 'AlphaTec 87063', 'Ansell']],
    ];

    private ProductMatchService $matcher;

    private ProductModelFuzzy $fuzzy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = app(ProductMatchService::class);
        $this->fuzzy = new ProductModelFuzzy;
    }

    /** @return iterable<string, array{0: int}> */
    public static function descriptiveLines(): iterable
    {
        foreach (array_keys(self::REQUIREMENTS) as $line) {
            yield "poz. {$line}" => [$line];
        }
    }

    /** @return iterable<string, array{0: int, 1: string, 2: string, 3: string}> */
    public static function wrongPicks(): iterable
    {
        foreach (self::WRONG_PICKS as $line => $cards) {
            foreach ($cards as [$sku, $name, $manufacturer]) {
                yield "poz. {$line} vs {$sku}" => [$line, $sku, $name, $manufacturer];
            }
        }
    }

    #[Test]
    #[DataProvider('descriptiveLines')]
    public function descriptive_line_has_no_model_needles(int $line): void
    {
        $req = self::REQUIREMENTS[$line];

        $this->assertSame([], $this->fuzzy->needles($req), 'igły: '.implode(', ', $this->fuzzy->needles($req)));
        $this->assertSame([], $this->fuzzy->catalogModelNeedles($req));
        $this->assertFalse($this->fuzzy->hasNamedModel($req));
        $this->assertFalse($this->fuzzy->usesModelAnchoredCatalogSearch($req));
        $this->assertSame([], $this->fuzzy->strongSkuNeedles($req));
    }

    #[Test]
    #[DataProvider('wrongPicks')]
    public function descriptive_line_does_not_fuzzy_match_wrong_pick(int $line, string $sku, string $name, string $manufacturer): void
    {
        $req = self::REQUIREMENTS[$line];
        $card = $this->card($sku, $name, $manufacturer);

        $this->assertFalse($this->fuzzy->matches($req, $card));
        $this->assertSame(0, $this->fuzzy->strongSkuScore($req, $card));
        $this->assertNotContains('fuzzy_model', $this->reasonCodes($req, $card));
    }

    /** Poz. 8: 9310+ dostawało 94% „Model z SIWZ (literówka)” z igły „ffp1” — klasa ochrony to nie kod modelu. */
    #[Test]
    public function ffp1_line_gets_no_fuzzy_model_and_no_brand_points_for_valveless_mask(): void
    {
        $req = self::REQUIREMENTS[8];
        $mask = $this->card('9310+', '3M™ Aura™ półmaska filtrująca, FFP1, bez zaworu, 9310+', '3M');

        $this->assertLessThan(80, $this->fuzzy->score($req, $mask));
        $codes = $this->reasonCodes($req, $mask);
        $this->assertNotContains('fuzzy_model', $codes);
        // „półmaska”, „filtrująca”, „zawór”, „FFP1” w nazwie karty to rodzaj wyrobu i klasa, nie marka
        $this->assertNotContains('brand', $codes);
    }

    /** Poz. 5: AB178 dostawało „Marka / model 50” za „typu” i „szelki” w nazwie szelek asekuracyjnych. */
    #[Test]
    public function generic_nouns_in_card_name_are_not_brand_evidence(): void
    {
        $harness = $this->card('AB178', 'Szelki bezpieczeństwa typu kamizelka 3M™ Protecta® FIRST, kolor niebieski, rozmiar uniwersalny, AB17510CE', '3M');
        $this->assertNotContains('brand', $this->reasonCodes(self::REQUIREMENTS[5], $harness));

        $pants = $this->card('3112', 'Spodnie do pasa z szelkami Extreme', 'AJ GROUP');
        $this->assertNotContains('brand', $this->reasonCodes(self::REQUIREMENTS[4], $pants));

        $mask = $this->card('9310+', '3M™ Aura™ półmaska filtrująca, FFP1, bez zaworu, 9310+', '3M');
        $this->assertNotContains('brand', $this->reasonCodes(self::REQUIREMENTS[13], $mask));

        $visitor = $this->card('71347-00004M', '3M™ Visitor Okulary ochronne nakładkowe, przezroczyste soczewki, 71448-00001, 20 szt./opakowanie', '3M');
        $this->assertNotContains('brand', $this->reasonCodes(self::REQUIREMENTS[6], $visitor));

        // kontrola: producent i nazwa własna z SIWZ nadal punktują jako marka
        $secura = $this->card('S56T0SM0', 'Półmaska SECURA 3000 (nagłowie jednoczęściowe)', 'SECURA');
        $this->assertContains('brand', $this->reasonCodes('Półmaska SECURA 3000 z nagłowiem jednoczęściowym', $secura));
    }

    /**
     * Skróty norm/klas/materiałów (FDA, ESD, SRC, PVC, TPR, NBR, NDS, SRA, AQL, III), lata i poprawki
     * norm (EN 420:2003+A1:2009, EN 388:2016), numer rozporządzenia ((UE) 2016/425) i stężenie
     * (5000 ppm) nie są kodem produktu — inaczej właściwa karta bez tej liczby w SKU stawała się
     * „zamiennikiem”, a SKU zaczynające się od roku trafiało jako kod.
     */
    #[Test]
    #[DataProvider('descriptiveLines')]
    public function descriptive_line_has_no_code_candidates(int $line): void
    {
        $codes = $this->codeCandidates(self::REQUIREMENTS[$line]);

        $this->assertSame([], $codes, 'kody: '.implode(', ', $codes));
        $this->assertNull($this->strongSkuPick(self::REQUIREMENTS[$line], new Collection([])), 'bez kodów pusta pula nie jest dobierana po LIKE');
    }

    #[Test]
    public function real_codes_in_siwz_are_still_code_candidates(): void
    {
        $this->assertContains('6503', $this->codeCandidates('Półmaska 3M 6503 z zaworem'));
        $this->assertContains('rnitz', $this->codeCandidates('Rękawice robocze RNITZ-M ze ściągaczem'));
        $this->assertContains('hy51', $this->codeCandidates('Zestaw higieniczny do nauszników 3M OPTIME I HY51'));
        $this->assertSame(['edge'], $this->codeCandidates('Rękawice EDGE 48-140 ESD, 1000 szt., EN 388:2016+A1:2018, (WE) 1907/2006'));
    }

    /** Poz. 7 („EN 388:2016”): SKU „2016-BLK” dostawało 70 pkt za kod „2016” — rok normy to nie SKU. */
    #[Test]
    public function norm_year_is_not_a_sku_match(): void
    {
        $req = self::REQUIREMENTS[7];
        $card = $this->card('2016-BLK', 'Rękawice 2016-BLK', 'X');

        $this->assertSame(0, $this->skuMatchScore($req, $card));
        $this->assertFalse($this->hasStrongSku($req, $card));
        $this->assertNotContains('sku', $this->reasonCodes($req, $card));
    }

    /**
     * „Zamiennik — inna marka/model niż w SIWZ” tylko gdy SIWZ nazywa model, a karta go nie honoruje
     * (także inny model tej samej marki), albo wskazuje innego producenta. Opis bez marki i modelu
     * (poz. 14 „5000 ppm”) nie ma czego zastępować — właściwa karta nie dostaje tej etykiety.
     */
    #[Test]
    public function brand_substitute_needs_named_model_or_manufacturer_in_siwz(): void
    {
        $absorber = $this->card('S565A202', 'Pochłaniacz gazów i par A2, bagnetowy', 'SECURA');
        $this->assertFalse($this->qualifiesAsBrandSubstitute(self::REQUIREMENTS[14], $absorber));
        foreach (self::WRONG_PICKS as $line => $cards) {
            foreach ($cards as [$sku, $name, $manufacturer]) {
                $this->assertFalse(
                    $this->qualifiesAsBrandSubstitute(self::REQUIREMENTS[$line], $this->card($sku, $name, $manufacturer)),
                    "poz. {$line} vs {$sku}: opis bez marki i modelu — zła karta to nie „zamiennik”, tylko brak trafienia"
                );
            }
        }

        $perspecta = 'OKULARY OCHRONNE MSA PERSPECTA 010';
        $this->assertTrue($this->qualifiesAsBrandSubstitute($perspecta, $this->card('10045516', 'Okulary PERSPECTA 9000 (12szt), bezbarwne', 'MSA')));
        $this->assertFalse($this->qualifiesAsBrandSubstitute($perspecta, $this->card('10045641', 'Okulary PERSPECTA 010 (12szt), bezbarwne', 'MSA')));

        $cerva = 'BUTY gumowe DAMSKIE antyelektrostatyczne rozm. 35-41 TRONCHETTO OB. SRA prod.CERVA · EN ISO 20347';
        $this->assertTrue($this->qualifiesAsBrandSubstitute($cerva, $this->card('ESD-SUB-1', 'Kalosze damskie gumowe ESD', 'KCL')));
    }

    #[Test]
    public function manufacturer_from_siwz_needs_prod_dot_or_producent_before_a_name(): void
    {
        // kody z SIWZ idą przez matchManufacturer (dopasowanie po podciągu) — katalog musi mieć producentów
        foreach (['3M', 'MSA', 'Ansell', 'ATG', 'ARTRA', 'CERVA', 'Canis', 'MAPA', 'CEDERROTH', 'AJ GROUP', 'uvex', 'KCL', 'REJS', 'JSP', 'Portwest'] as $i => $manufacturer) {
            Product::query()->create([
                'sku' => 'MFG-'.$i,
                'name' => 'Karta '.$manufacturer,
                'manufacturer' => $manufacturer,
                'catalog_price_net' => 10,
                'purchase_price' => 8,
                'stock' => 1,
            ]);
        }

        // poz. 12: „produkcja metodą konfekcjonowania” dawało „(wymagano: ukcja)”
        $this->assertNull($this->matcher->requestedManufacturerFromSiwz(self::REQUIREMENTS[12]));
        $this->assertNull($this->matcher->requestedManufacturerFromSiwz('Produkt spełnia normy EN 149'));
        $this->assertNull($this->matcher->requestedManufacturerFromSiwz('produkcji krajowej, prod. 2024'));
        foreach (self::REQUIREMENTS as $line => $req) {
            $this->assertNull($this->matcher->requestedManufacturerFromSiwz($req), "poz. {$line}");
        }

        $this->assertSame('CERVA', $this->matcher->requestedManufacturerFromSiwz(
            'BUTY gumowe DAMSKIE antyelektrostatyczne rozm. 35-41 TRONCHETTO OB. SRA prod.CERVA · EN ISO 20347'
        ));
        $this->assertSame('CERVA', $this->matcher->requestedManufacturerFromSiwz('Buty gumowe. Producent: CERVA'));
        $this->assertSame('3M', $this->matcher->requestedManufacturerFromSiwz('Półmaska prod. 3M z zaworem'));
    }

    /** Wynik fuzzy jest „mocnym SKU” tylko z igły z cyfrą, modelu z myślnikiem albo linii po marce. */
    #[Test]
    public function strong_sku_pick_needs_digit_hyphen_model_or_brand_line_needle(): void
    {
        $ffp1 = new Collection([
            $this->card('9310+', '3M™ Aura™ półmaska filtrująca, FFP1, bez zaworu, 9310+', '3M', 3.0),
            $this->card('9312+', '3M™ Aura™ półmaska filtrująca, FFP1, z zaworem, 9312+ (LANG B)', '3M', 5.0),
            $this->card('9914', '3M™ Półmaska filtrująca 9914, specjalistyczna, z zaworem, FFP1 (pyły i pary organiczne)', '3M', 305.0),
        ]);
        $this->assertNull($this->strongSkuPick(self::REQUIREMENTS[8], $ffp1), 'FFP1 nie jest kodem modelu — pozycja idzie do oceny');
        $this->assertFalse($this->hasStrongSku(self::REQUIREMENTS[8], $ffp1->first()));

        // goły wyraz (TRONCHETTO) zostaje igłą wyszukiwania, ale sam nie rozstrzyga pozycji jak SKU
        $cervaReq = 'BUTY gumowe DAMSKIE antyelektrostatyczne rozm. 35-41 TRONCHETTO OB. SRA prod.CERVA · EN ISO 20347';
        $tronchetto = $this->card('0202001060', 'TRONCHETTO OB SRA buty gumowe damskie', 'CERVA', 40.0);
        $this->assertTrue($this->fuzzy->matches($cervaReq, $tronchetto));
        $this->assertNull($this->strongSkuPick($cervaReq, new Collection([$tronchetto])));
        $this->assertFalse($this->hasStrongSku($cervaReq, $tronchetto));

        // z cyfrą: HY51 ≠ HY52
        $hy51 = $this->card('HY51', '3M Zestaw higieniczny do nauszników Optime I', '3M', 30.0);
        $pick = $this->strongSkuPick('Zestaw higieniczny do nauszników 3M OPTIME I HY51', new Collection([
            $this->card('HY52', '3M Zestaw higieniczny do nauszników PELTOR Optime II', '3M', 20.0),
            $hy51,
        ]));
        $this->assertSame('HY51', $pick['product']->sku ?? null);
        $this->assertTrue($this->hasStrongSku('Zestaw higieniczny do nauszników 3M OPTIME I HY51', $hy51));

        // model z myślnikiem: URG-A ≠ URG-B
        $pick = $this->strongSkuPick('Półmaska URG-A z filtrami', new Collection([
            $this->card('URG-B', 'Półmaska URG-B', 'Urgent', 45.0),
            $this->card('URG-A', 'Półmaska URG-A', 'Urgent', 50.0),
        ]));
        $this->assertSame('URG-A', $pick['product']->sku ?? null);

        // linia po znanej marce: uvex phynomic
        $pick = $this->strongSkuPick('Rękawice uvex phynomic lite', new Collection([
            $this->card('60592', 'uvex unilite thermo', 'uvex', 8.0),
            $this->card('60040', 'uvex phynomic lite', 'uvex', 9.0),
        ]));
        $this->assertSame('60040', $pick['product']->sku ?? null);
    }

    /**
     * Dwie karty tego samego modelu (dwa cenniki): przy remisie fuzzy wygrywa karta z większą liczbą
     * dowodów, nie tańsza. Kod w SIWZ sklejony i z literówką (TEPMICE700), żeby goła nazwa karty
     * nie dobiła do 99 samym pokryciem tokenów — wtedy remis rozstrzygałaby wyłącznie cena.
     */
    #[Test]
    public function strong_sku_tie_prefers_more_evidence_before_price(): void
    {
        $req = 'Rękawice MAPA TEPMICE700 · EN 388 EN 511';
        $bare = $this->card('34700018', 'TEMP-ICE 700', 'X', 10.0);
        $full = $this->card('34700019', 'TEMP-ICE 700 rękawice zimowe', 'X', 12.0);

        $this->assertSame(
            $this->fuzzy->strongSkuScore($req, $bare),
            $this->fuzzy->strongSkuScore($req, $full),
            'fixture: obie karty muszą remisować na fuzzy'
        );
        $this->assertGreaterThan(
            $this->matcher->explainMatch($req, $bare)['score'],
            $this->matcher->explainMatch($req, $full)['score'],
            'fixture: pełna nazwa musi mieć więcej dowodów'
        );

        $this->assertSame('34700019', $this->strongSkuPick($req, new Collection([$bare, $full]))['product']->sku ?? null);
        $this->assertSame('34700019', $this->strongSkuPick($req, new Collection([$full, $bare]))['product']->sku ?? null);
    }

    /** @return list<string> */
    private function reasonCodes(string $requirement, Product $product): array
    {
        return array_column($this->matcher->explainMatch($requirement, $product)['reasons'], 'code');
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array{product: Product, score: int, source: string}|null
     */
    private function strongSkuPick(string $requirement, Collection $products): ?array
    {
        $method = new \ReflectionMethod(ProductMatchService::class, 'strongSkuPick');

        return $method->invoke($this->matcher, $requirement, $products);
    }

    private function hasStrongSku(string $requirement, Product $product): bool
    {
        $method = new \ReflectionMethod(ProductMatchService::class, 'hasStrongSkuInRequirement');

        return $method->invoke($this->matcher, $requirement, $product);
    }

    private function qualifiesAsBrandSubstitute(string $requirement, Product $product): bool
    {
        $method = new \ReflectionMethod(ProductMatchService::class, 'qualifiesAsBrandSubstitute');

        return $method->invoke($this->matcher, $requirement, $product);
    }

    /** @return list<string> */
    private function codeCandidates(string $requirement): array
    {
        $method = new \ReflectionMethod(ProductMatchService::class, 'codeCandidates');

        return $method->invoke($this->matcher, $requirement);
    }

    private function skuMatchScore(string $requirement, Product $product): int
    {
        $normalize = new \ReflectionMethod(ProductMatchService::class, 'normalize');
        $method = new \ReflectionMethod(ProductMatchService::class, 'skuMatchScore');

        return $method->invoke(
            $this->matcher,
            $normalize->invoke($this->matcher, $requirement),
            $this->codeCandidates($requirement),
            $product
        );
    }

    private function card(string $sku, string $name, string $manufacturer, float $purchase = 1.0): Product
    {
        $p = new Product;
        $p->forceFill([
            'id' => random_int(1, 999999),
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'purchase_price' => $purchase,
            'catalog_price_net' => $purchase,
        ]);

        return $p;
    }
}
