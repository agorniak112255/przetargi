<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Polskie odpowiedniki stałych słowników sklepu SIR Safety System (prośba użytkownika 06.10.2026: „przetłumacz kategorie
 * i tabelkę cech SIR”). Sklep nie ma polskiej wersji — działy, grupy towarowe, kolory i jednostki są po angielsku,
 * a nazwy poziomów norm po włosku. To skończone listy (zebrane z 1079 kart konta #31), więc tłumaczy je słownik,
 * nie model: wynik jest powtarzalny i do sprawdzenia.
 *
 * Wartość spoza słownika zostaje dosłownie (nowa grupa w sklepie pojawi się po angielsku, a nie zgadnięta). Nazwy
 * serii i marek (SYMBOL, HARDWEAR, PETZL, MSA…) zostają bez zmian, tłumaczy się tylko rodzaj wyrobu.
 */
final class SirShopTranslations
{
    private const DEPARTMENTS = [
        'CLOTHING' => 'Odzież',
        'EAR PROTECTION' => 'Ochrona słuchu',
        'FALL ARREST DEVICES' => 'Sprzęt chroniący przed upadkiem z wysokości',
        'FOOTWEAR' => 'Obuwie',
        'GLOVES' => 'Rękawice',
        // „Ochrona głowy”, nie „Hełmy i czapki przeciwuderzeniowe”: dział jest w każdej kategorii działu, a „czapki” dawało
        // akcesoriom hełmów typ „czapka przeciwuderzeniowa” (pomiar 06.10.2026, 9 kart); rodzaj mówi grupa
        'HELMETS AND BUMP CAPS' => 'Ochrona głowy',
        'MEDICAL-HEALTH EQUIPMENT' => 'Sprzęt medyczny i pierwszej pomocy',
        'RESPIRATORY PROTECTION' => 'Ochrona dróg oddechowych',
        // literówka sklepu („SHIELS”). Bez słowa „okulary”: kategoria jest dowodem rodzaju wyrobu, a „Okulary i osłony
        // twarzy” dawało osłonom twarzy i częściom przyłbic typ „okulary” (pomiar na produkcji 06.10.2026, 22 karty)
        'SPECTACLES AND FACE SHIELS' => 'Ochrona oczu i twarzy',
        'SPECTACLES AND FACE SHIELDS' => 'Ochrona oczu i twarzy',
        'VARIOUS' => 'Różne',
    ];

    /** Grupy towarowe sklepu (MATKL) — literówki sklepu („ALIMINIZED”, „RAIWEAR”, „VYNIL”) jako klucze dosłownie. */
    private const GROUPS = [
        // bez „czapek”: napotnik do hełmu dostawał typ „czapka przeciwuderzeniowa”
        'ACCESSORIES HELMETS AND BUMP CAPS' => 'Akcesoria do hełmów',
        'ALIMINIZED CLOTHING' => 'Odzież aluminizowana',
        'BUMP CAPS' => 'Czapki przeciwuderzeniowe',
        'CARBOFLAME CLOTHING' => 'Odzież CARBOFLAME',
        'CHAINSAW PROTECTIVE CLOTHING' => 'Odzież chroniąca przed przecięciem piłą łańcuchową',
        'CHEMICAL PROTECTION CLOTHING' => 'Odzież chroniąca przed chemikaliami',
        'CHEMICAL PROTECTION DISPOSABLE CLOTHING' => 'Odzież jednorazowa chroniąca przed chemikaliami',
        'CHEMICAL PROTECTION GLOVES NEOPRENE' => 'Rękawice chroniące przed chemikaliami – neopren',
        'CHEMICAL PROTECTION GLOVES NITRILE' => 'Rękawice chroniące przed chemikaliami – nitryl',
        'CHEMICAL PROTECTION GLOVES PVC' => 'Rękawice chroniące przed chemikaliami – PVC',
        'CHEMICAL PROTECTION GLOVES RUBBER' => 'Rękawice chroniące przed chemikaliami – guma',
        'CLASS 1 HIGH VISIBILITY CLOTHING' => 'Odzież ostrzegawcza klasy 1',
        // w SIR to torby, plecak, wózek, nóż, pasek, skarpety — nie odzież (zob. STANDALONE_GROUPS)
        'CLOTHING ACCESSORIES' => 'Akcesoria różne',
        'CLOTHING BEANIES/CAPS/NECKWORMER' => 'Czapki zimowe, czapki z daszkiem i kominy',
        'CLOTHING BUFF ITEMS' => 'Wyroby BUFF',
        'CLOTHING COLD RESISTANT BODYWARMERS' => 'Kamizelki ocieplane',
        'CLOTHING ESD' => 'Odzież ESD',
        'CLOTHING EVOLUTION SERIES' => 'Odzież – seria EVOLUTION',
        'CLOTHING FUSION SERIES' => 'Odzież – seria FUSION',
        'CLOTHING FUSTIAN SERIES' => 'Odzież – seria FUSTIAN',
        'CLOTHING GEMINI/FIGHTER SERIES' => 'Odzież – seria GEMINI/FIGHTER',
        'CLOTHING HARDWEAR SERIES' => 'Odzież – seria HARDWEAR',
        'CLOTHING HARRISON SERIES' => 'Odzież – seria HARRISON',
        'CLOTHING KOMBAT AND LIBERTY SERIES' => 'Odzież – seria KOMBAT i LIBERTY',
        'CLOTHING MULTINORM KNITWEAR AND SHIRTS' => 'Odzież wielonormowa – dzianina i koszule',
        'CLOTHING MULTIPURPOSE COLD RESISTANT JACKETS' => 'Kurtki ocieplane wielofunkcyjne',
        'CLOTHING PROTECTIVE APRONS' => 'Fartuchy ochronne',
        'CLOTHING RAINWEAR' => 'Odzież przeciwdeszczowa',
        'CLOTHING SHIRTS' => 'Koszule',
        'CLOTHING SOFSHELL' => 'Odzież softshell',
        'CLOTHING SPORT LOOK SERIES' => 'Odzież – seria SPORT LOOK',
        'CLOTHING SUMMER SHIRTS' => 'Koszulki letnie',
        'CLOTHING SYMBOL SERIES' => 'Odzież – seria SYMBOL',
        'CLOTHING UNDERWEAR' => 'Bielizna',
        'CLOTHING WINTER KNITWEAR' => 'Dzianina zimowa',
        'COLD PROTECTION GLOVES' => 'Rękawice chroniące przed zimnem',
        'CUT PROTECTION GLOVES' => 'Rękawice chroniące przed przecięciem',
        'DISPOSABLE CLOTHING' => 'Odzież jednorazowa',
        'DISPOSABLE LATEX GLOVES' => 'Rękawice jednorazowe lateksowe',
        'DISPOSABLE NITRILE GLOVES' => 'Rękawice jednorazowe nitrylowe',
        'DISPOSABLE VYNIL GLOVES' => 'Rękawice jednorazowe winylowe',
        // bez „ochronników”: pusty dozownik wkładek dostawał typ „nauszniki”
        'EAR PROTECTION ACCESSORIES' => 'Akcesoria do ochrony słuchu',
        'EAR PROTECTION EAR MUFFS' => 'Nauszniki przeciwhałasowe',
        'EAR PROTECTION EAR PLUGS' => 'Wkładki przeciwhałasowe',
        'EAR PROTECTION OUT OF LIST' => 'Ochrona słuchu – poza katalogiem',
        'EAR PROTECTION REUSABLE EARPLUS' => 'Wkładki przeciwhałasowe wielokrotnego użytku',
        'FACE SHIELDS' => 'Osłony twarzy',
        'FALL ARREST DEVICES ACCESSORIES' => 'Akcesoria do sprzętu chroniącego przed upadkiem',
        'FALL ARREST DEVICES CARABINERS' => 'Zatrzaśniki (karabinki)',
        'FALL ARREST DEVICES CODES OUT OF LIST' => 'Sprzęt chroniący przed upadkiem – poza katalogiem',
        'FALL ARREST DEVICES ENERGY ABSORBERS' => 'Amortyzatory bezpieczeństwa',
        'FALL ARREST DEVICES FALL PROTECTION KITS' => 'Zestawy chroniące przed upadkiem',
        'FALL ARREST DEVICES HARNESSES' => 'Szelki bezpieczeństwa',
        'FALL ARREST DEVICES MOBILE ANCHORING DEVICES' => 'Przenośne urządzenia kotwiczące',
        'FALL ARREST DEVICES ON ROPE' => 'Urządzenia samozaciskowe na linie',
        'FALL ARREST DEVICES PETZL' => 'Sprzęt chroniący przed upadkiem PETZL',
        'FALL ARREST DEVICES RETRACTABLE DEVICES' => 'Urządzenia samohamowne',
        'FALL ARREST DEVICES WORK POSITIONING SYSTEMS' => 'Systemy ustalające pozycję przy pracy',
        'FLAME RETARDANT CLOTHING' => 'Odzież trudnopalna',
        'FOOTWEAR - SIR FUTURE SERIES POWERED BY RESPONDER TECHNOLOGY' => 'Obuwie – seria SIR FUTURE (technologia RESPONDER)',
        'FOOTWEAR ACCESSORIES' => 'Akcesoria do obuwia',
        'FOOTWEAR AIRBLOCK SERIES' => 'Obuwie – seria AIRBLOCK',
        'FOOTWEAR ALL TERRAIN SERIES' => 'Obuwie – seria ALL TERRAIN',
        'FOOTWEAR FOOTBEDS' => 'Wkładki do obuwia',
        'FOOTWEAR MAXIMUM SERIES' => 'Obuwie – seria MAXIMUM',
        'FOOTWEAR NEW FOBIA SERIES' => 'Obuwie – seria NEW FOBIA',
        'FOOTWEAR NEW METAL TOP SERIES' => 'Obuwie – seria NEW METAL TOP',
        'FOOTWEAR NEW OVERCAP SERIES' => 'Obuwie – seria NEW OVERCAP',
        'FOOTWEAR NEW ULTRA LIGHT SERIES' => 'Obuwie – seria NEW ULTRA LIGHT',
        'FOOTWEAR NITRAL SERIES' => 'Obuwie – seria NITRAL',
        'FOOTWEAR NITRILE BOOTS' => 'Buty nitrylowe',
        'FOOTWEAR OVERCAP SERIES' => 'Obuwie – seria OVERCAP',
        'FOOTWEAR POLYURETHANE BOOTS' => 'Buty poliuretanowe',
        'FOOTWEAR PVC BOOTS' => 'Buty PVC',
        'FOOTWEAR SIR FUTURE JOGGER SERIES' => 'Obuwie – seria SIR FUTURE JOGGER',
        'FOOTWEAR SIR399 SERIES' => 'Obuwie – seria SIR399',
        'FOOTWEAR SPECIAL PROTECTION' => 'Obuwie do zastosowań specjalnych',
        'FOOTWEAR TOTAL PLANE SERIES' => 'Obuwie – seria TOTAL PLANE',
        'FOOTWEAR URANIA BSF SERIES' => 'Obuwie – seria URANIA BSF',
        'FOOTWEAR URBAN EXPLORER SERIES' => 'Obuwie – seria URBAN EXPLORER',
        'GLOVES - OUT OF LIST' => 'Rękawice – poza katalogiem',
        'HEAT PROTECTION GLOVES' => 'Rękawice chroniące przed gorącem',
        'HIGH VISIBILITY CLOTHING RAIWEAR' => 'Odzież ostrzegawcza przeciwdeszczowa',
        'HIGH VISIBILITY CLOTHING SUMMER' => 'Odzież ostrzegawcza letnia',
        'HIGH VISIBILITY CLOTHING SUMMER KNITWEAR' => 'Odzież ostrzegawcza – dzianina letnia',
        'HIGH VISIBILITY CLOTHING WEATHER RESISTANT' => 'Odzież ostrzegawcza chroniąca przed złą pogodą',
        'HIGH VISIBILITY CLOTHING WINTER' => 'Odzież ostrzegawcza zimowa',
        'HIGH VISIBILITY CLOTHING WINTER KNITWEAR' => 'Odzież ostrzegawcza – dzianina zimowa',
        'HOSPITAL CLOTHING' => 'Odzież medyczna',
        'LEATHER WELDING PROTECTION CLOTHING' => 'Odzież spawalnicza skórzana',
        'LOW TEMPERATURES CLOTHING' => 'Odzież do niskich temperatur',
        'MECHANICAL PROTECTION GLOVES COATED TEXTILE' => 'Rękawice chroniące przed zagrożeniami mechanicznymi – powlekane',
        'MECHANICAL PROTECTION GLOVES LEATHER' => 'Rękawice chroniące przed zagrożeniami mechanicznymi – skórzane',
        'MECHANICAL PROTECTION GLOVES NBR' => 'Rękawice chroniące przed zagrożeniami mechanicznymi – NBR',
        'MECHANICAL PROTECTION GLOVES TEXTILE' => 'Rękawice chroniące przed zagrożeniami mechanicznymi – tekstylne',
        'MEDICAL-HEALTH EQUIPMENT CODES OUT OF LIST' => 'Sprzęt medyczny – poza katalogiem',
        'MEDICAL-HEALTH EQUIPMENT EMERGENCY SHOWERS AND EYEWASH' => 'Prysznice ratunkowe i płuczki do oczu',
        'MEDICAL-HEALTH EQUIPMENT FIRST AID' => 'Pierwsza pomoc',
        'MICROLINES CLOTHING' => 'Odzież MICROLINES',
        'MSA HELMETS' => 'Hełmy MSA',
        'NEW METAL SPLASH CLOTHING' => 'Odzież NEW METAL SPLASH',
        'OCCUPATIONAL FOOTWEAR' => 'Obuwie zawodowe',
        // „panoramic mask” w katalogu SIR to gogle (maschera panoramica)
        'PANORAMIC MASKS' => 'Gogle ochronne',
        'POLYTECH CLOTHING' => 'Odzież POLYTECH',
        'POLYTECH PLUS CLOTHING' => 'Odzież POLYTECH PLUS',
        'PROFESSIONAL CLOTHING' => 'Odzież robocza',
        'RESPIRATORY PROTECTION DISPOSABLE MASKS FFP2' => 'Półmaski filtrujące FFP2',
        'RESPIRATORY PROTECTION DISPOSABLE MASKS FFP3' => 'Półmaski filtrujące FFP3',
        'RESPIRATORY PROTECTION ESCAPE SYSTEMS' => 'Sprzęt ucieczkowy',
        // ucięte w sklepie („…FOR RESPIRATO”)
        'RESPIRATORY PROTECTION FILTERS AND ACCESSORIES FOR RESPIRATO' => 'Filtry i akcesoria do sprzętu ochrony dróg oddechowych',
        'RESPIRATORY PROTECTION FULL FACE MASKS' => 'Maski pełnotwarzowe',
        'RESPIRATORY PROTECTION HALF FACE MASKS DRAEGER' => 'Półmaski DRAEGER',
        'RESPIRATORY PROTECTION HALF FACE MASKS SIR' => 'Półmaski SIR',
        'RESPIRATORY PROTECTION NO SAFETY' => 'Ochrona dróg oddechowych – wyroby niebędące ŚOI',
        'RESPIRATORY PROTECTION POWERED RESPIRATORS' => 'Oczyszczające urządzenia z wymuszonym przepływem powietrza',
        'SAFETY HELMETS' => 'Hełmy ochronne',
        'SPECIAL PROTECTION GLOVES' => 'Rękawice do zastosowań specjalnych',
        'SPECTACLES ACCESSORIES AND PANORAMIC MASKS' => 'Akcesoria do okularów i gogli',
        'SPECTACLES AND FACE SHIELDS CODES OUT OF LIST' => 'Ochrona oczu i twarzy – poza katalogiem',
        'SPECTACLES CLEAR LENS' => 'Okulary z soczewką bezbarwną',
        'SPECTACLES DARK LENS' => 'Okulary z soczewką przyciemnianą',
        'SUPERTECH CLOTHING' => 'Odzież SUPERTECH',
        'VARIOUS' => 'Różne',
        'VINYL/NITRILE DISPOSABLE GLOVES' => 'Rękawice jednorazowe winylowo-nitrylowe',
    ];

    /**
     * Pojedyncze nazwy kolorów; kolor dwubarwny („BLUE/HI-VIS YELLOW”) tłumaczy się częściami. „HI-VIS” i „HV” to
     * kolor fluorescencyjny odzieży ostrzegawczej; „ROYAL” to kobaltowy (royal blue). Kod koloru w nawiasie zostaje.
     */
    private const COLOURS = [
        'ALUMINIUM' => 'aluminiowy',
        'ANTHRACITE' => 'antracytowy',
        'AQUAMARINE' => 'akwamaryna',
        'ARMY GREEN' => 'wojskowa zieleń',
        'BLACK' => 'czarny',
        'BLUE' => 'niebieski',
        'BOTTLE GREEN' => 'butelkowa zieleń',
        'BROWN' => 'brązowy',
        'BURGUNDY' => 'bordowy',
        'CAMOUFLAGE' => 'moro',
        'CLEAR' => 'bezbarwny',
        'CORNFLOWER BLUE' => 'chabrowy',
        'CYAN' => 'cyjan',
        'DARK BLUE' => 'ciemnoniebieski',
        'DARK GREEN' => 'ciemnozielony',
        'DARK GREY' => 'ciemnoszary',
        'DARK OLIVE GREEN' => 'ciemnooliwkowy',
        'EMERALD GREEN' => 'szmaragdowy',
        'FUCHSIA' => 'fuksja',
        'GREEN' => 'zielony',
        'GREY' => 'szary',
        'HI-VIS ORANGE' => 'pomarańczowy fluorescencyjny',
        'HI-VIS RED' => 'czerwony fluorescencyjny',
        'HI-VIS YELLOW' => 'żółty fluorescencyjny',
        // sklep skleja „HI-VISYELLOW”
        'HI-VISYELLOW' => 'żółty fluorescencyjny',
        'HV ORANGE' => 'pomarańczowy fluorescencyjny',
        'HV YELLOW' => 'żółty fluorescencyjny',
        'INDIGO' => 'indygo',
        'JEANS' => 'jeansowy',
        'KHAKI' => 'khaki',
        'LIGHT BLUE' => 'jasnoniebieski',
        'LIGHT BROWN' => 'jasnobrązowy',
        'LIGHT GREEN' => 'jasnozielony',
        'LIGHT GREY' => 'jasnoszary',
        'LILAC' => 'liliowy',
        'LIME GREEN' => 'limonkowy',
        'LOBSTER' => 'homarowy (czerwony)',
        'MANGO YELLOW' => 'żółty mango',
        'MELANGE BLACK' => 'czarny melanż',
        'MELANGE BLUE' => 'niebieski melanż',
        'MELANGE GREEN' => 'zielony melanż',
        'MELANGE GREY' => 'szary melanż',
        'MELANGE ORANGE' => 'pomarańczowy melanż',
        'MELANGE RED' => 'czerwony melanż',
        'MELANGE YELLOW' => 'żółty melanż',
        'MOUSEY GREY' => 'mysi szary',
        'NAVY' => 'granatowy',
        'NEON YELLOW' => 'żółty neonowy',
        'NO COLOUR' => 'bez koloru',
        'OCHRE' => 'ochra',
        'ORANGE' => 'pomarańczowy',
        'PEARL' => 'perłowy',
        'PETROLEUM BLUE' => 'niebieski petrol',
        'PINK' => 'różowy',
        'RED' => 'czerwony',
        'ROYAL' => 'kobaltowy',
        'SILVER' => 'srebrny',
        'SLATE GREY' => 'grafitowy',
        'TRICOLOUR' => 'trójkolorowy',
        'TURTLEDOVE' => 'gołębi (szarobeżowy)',
        'TWO-TONE' => 'dwukolorowy',
        'VARIOUS COLOURS' => 'różne kolory',
        'WHITE' => 'biały',
        'YELLOW' => 'żółty',
    ];

    private const UNITS = [
        'PIECE' => 'szt.',
        'PAIR' => 'para',
    ];

    /** Kategoria ŚOI wg rozporządzenia (UE) 2016/425; „NO” = wyrób niebędący ŚOI. */
    private const PPE_CATEGORIES = [
        'I' => 'I',
        'II' => 'II',
        'III' => 'III',
        'NO' => 'nie dotyczy (wyrób niebędący ŚOI)',
    ];

    private const COUNTRIES = [
        'AL' => 'Albania', 'BA' => 'Bośnia i Hercegowina', 'BD' => 'Bangladesz', 'CN' => 'Chiny', 'CZ' => 'Czechy',
        'DE' => 'Niemcy', 'ES' => 'Hiszpania', 'FR' => 'Francja', 'GB' => 'Wielka Brytania', 'HK' => 'Hongkong',
        'ID' => 'Indonezja', 'IN' => 'Indie', 'IT' => 'Włochy', 'LK' => 'Sri Lanka', 'MM' => 'Mjanma', 'MY' => 'Malezja',
        'PK' => 'Pakistan', 'PL' => 'Polska', 'RO' => 'Rumunia', 'SE' => 'Szwecja', 'TH' => 'Tajlandia', 'TR' => 'Turcja',
        'TW' => 'Tajwan', 'US' => 'Stany Zjednoczone', 'VN' => 'Wietnam',
    ];

    /**
     * Nazwy poziomów norm z PerformanceList (sklep podaje je po włosku). Skróty z norm (AP, APC, ATPV, EBT, ELIM, Rct,
     * WP, Icler) zostają; „(Tessuto)” = tkanina.
     */
    private const LEVEL_LABELS = [
        'Acetato d\'etile' => 'Octan etylu',
        'Acetone' => 'Aceton',
        'Acetonitrile' => 'Acetonitryl',
        'Acido acetico 99%' => 'Kwas octowy 99%',
        'Acido fluoridrico 40%' => 'Kwas fluorowodorowy 40%',
        'Acido nitrico 65%' => 'Kwas azotowy 65%',
        'Acido solforico 96%' => 'Kwas siarkowy 96%',
        'Acido, Olio, Ozono' => 'Kwas, olej, ozon',
        'ATPV (Tessuto)' => 'ATPV (tkanina)',
        'Calore convettivo' => 'Ciepło konwekcyjne',
        'Calore da contatto' => 'Ciepło kontaktowe',
        'Calore radiante' => 'Ciepło promieniowania',
        'Campo di utilizzo' => 'Zakres zastosowania',
        'Classe' => 'Klasa',
        'Classe ottica' => 'Klasa optyczna',
        'Comportamento alla Fiamma' => 'Rozprzestrzenianie płomienia',
        'Design' => 'Wzór',
        'Dimensione' => 'Rozmiar',
        'EBT (Tessuto)' => 'EBT (tkanina)',
        'ELIM (Tessuto)' => 'ELIM (tkanina)',
        'Formaldeide 37%' => 'Formaldehyd 37%',
        'Idrossido di ammonio 25%' => 'Wodorotlenek amonu 25%',
        'Idrossido di sodio 40%' => 'Wodorotlenek sodu 40%',
        'Livello di impatto' => 'Poziom odporności na uderzenie',
        'Metalli fusi e solidi incandescenti' => 'Stopione metale i gorące ciała stałe',
        'Metanolo' => 'Metanol',
        'n-eptano' => 'n-heptan',
        'Perossido di idrogeno 30%' => 'Nadtlenek wodoru 30%',
        'Requisiti opzionali' => 'Wymagania dodatkowe',
        'Resistente a temperature molto basse' => 'Odporność na bardzo niskie temperatury',
        'Resistenza al vapore acqueo' => 'Opór pary wodnej',
        'Resistenza all\'impatto' => 'Wytrzymałość mechaniczna',
        'Resistenza alla penetrazione dell\'acqua' => 'Wodoszczelność',
        'Spruzzi di Alluminio fuso' => 'Rozprysk stopionego aluminium',
        'Spruzzi di Ferro fuso' => 'Rozprysk stopionego żelaza',
        'Tipo' => 'Typ',
        'Tipo di filtro' => 'Typ filtra',
        'Valori' => 'Wartości',
    ];

    /**
     * Grupy, których kategoria nie dostaje działu: „CLOTHING ACCESSORIES” w SIR to torby, wózek, nóż i skarpety
     * — „Odzież > …” w kategorii-dowodzie nadawało im rodzinę „odzież” (pomiar na produkcji 06.10.2026: 40 kart,
     * wcześniej bez rodziny).
     */
    private const STANDALONE_GROUPS = ['CLOTHING ACCESSORIES'];

    /**
     * Ścieżka kategorii „Dział > Grupa” po polsku; grupa równa działowi raz, grupa z STANDALONE_GROUPS bez działu.
     */
    public static function categoryPath(string $department, string $group): ?string
    {
        $group = trim((string) preg_replace('/\s+/u', ' ', $group));
        if ($group !== '' && in_array(self::key($group), self::STANDALONE_GROUPS, true)) {
            return self::group($group);
        }
        $parts = [];
        foreach ([self::department($department), $group !== '' ? self::group($group) : ''] as $part) {
            $part = trim($part);
            if ($part !== '' && ! in_array($part, $parts, true)) {
                $parts[] = $part;
            }
        }

        return $parts !== [] ? implode(' > ', $parts) : null;
    }

    public static function department(string $value): string
    {
        return self::DEPARTMENTS[self::key($value)] ?? $value;
    }

    public static function group(string $value): string
    {
        return self::GROUPS[self::key($value)] ?? $value;
    }

    /** „BLUE/HI-VIS YELLOW (P7)” → „niebieski/żółty fluorescencyjny (P7)”; część spoza słownika zostaje. */
    public static function colour(string $value): string
    {
        if (preg_match('/^(.*?)(\s*\([^)]*\))?$/u', trim($value), $m) !== 1 || trim($m[1]) === '') {
            return $value;
        }
        $whole = self::COLOURS[self::key($m[1])] ?? null;
        $name = $whole ?? implode('/', array_map(
            static fn (string $part): string => self::COLOURS[self::key($part)] ?? trim($part),
            explode('/', $m[1]),
        ));

        return $name.($m[2] ?? '');
    }

    public static function unit(string $value): string
    {
        return self::UNITS[self::key($value)] ?? $value;
    }

    public static function ppeCategory(string $value): string
    {
        return self::PPE_CATEGORIES[self::key($value)] ?? $value;
    }

    public static function country(string $value): string
    {
        return self::COUNTRIES[self::key($value)] ?? $value;
    }

    public static function levelLabel(string $value): string
    {
        return self::LEVEL_LABELS[trim((string) preg_replace('/\s+/u', ' ', $value))] ?? $value;
    }

    /** Klucz słownika: wielkie litery, pojedyncze odstępy (sklep miewa „CLOTHING  ACCESSORIES”). */
    private static function key(string $value): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }
}
