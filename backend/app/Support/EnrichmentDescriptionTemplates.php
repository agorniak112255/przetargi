<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Szablony promptu opisu produktu — jedna instrukcja na rodzinę BHP.
 */
final class EnrichmentDescriptionTemplates
{
    public const FALLBACK = 'inne';

    public const MAX_INSTRUCTIONS_LEN = 12000;

    public const MIN_INSTRUCTIONS_LEN = 20;

    /** @var array<string, string> */
    public const LABELS = [
        'rekawice' => 'Rękawice',
        'obuwie' => 'Obuwie',
        'odziez' => 'Odzież',
        'ochrona_glowy' => 'Ochrona głowy',
        'ochrona_twarzy' => 'Ochrona twarzy',
        'ochrona_oczu' => 'Ochrona oczu',
        'ochrona_sluchu' => 'Ochrona słuchu',
        'drogi_oddechowe' => 'Drogi oddechowe',
        'asekuracja' => 'Asekuracja / wysokość',
        'ochrona_kolan' => 'Ochrona kolan',
        'inne' => 'Domyślny (inne / nierozpoznane)',
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return BhpAttributeNormalizer::KATEGORIE;
    }

    public static function isValidKey(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }

    public static function defaultInstructions(string $key): string
    {
        return self::defaults()[$key] ?? self::defaults()[self::FALLBACK];
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'rekawice' => <<<'TXT'
To rękawice ochronne. Zbierz pełną kartę katalogową tej pary — nie opisuj obuwia, odzieży ani kasków.

W description (2–4 akapity) ujmij: przeznaczenie, materiał wkładki i powłoki, wykończenie (gładkie / piankowe / nitryl), mankiet, normy z poziomami, typowe prace.
W specs i attributes koniecznie: kod/SKU, materiał wkładki, powłoka, poziomy EN 388 (np. 4544C → attributes.poziomy_en388), EN 374 / EN 407 / EN 511 / EN ISO 21420 jeśli są w źródłach, długość, rozmiary 6–12 (albo 7–11).
rozmiar: tylko numery rękawic ze źródeł; nigdy numery butów (36–48) ani 1–5XL.
Nie zmyślaj poziomów EN 388 spoza źródeł.
TXT,
            'obuwie' => <<<'TXT'
To obuwie ochronne / robocze (EN ISO 20345 — z podnoskiem, EN ISO 20347 — zawodowe bez podnoska). Zbierz pełną kartę katalogową — nie opisuj rękawic ani odzieży.

STAŁY ZESTAW CECH DOBORU — szukaj w źródłach punktów 1–10 i w specs wypisz te, które źródła podają, w tej kolejności, każdy w osobnym wierszu „parametr: wartość”:
1. Typ wyrobu: półbut / trzewik / sztyblet / sandał / kalosz.
2. Wysokość cholewki (niska / za kostkę / wysoka; cm, jeśli podano).
3. Klasa ochrony z normą w pełnym zapisie, np. „EN ISO 20345:2022 S3L”. Sufiks L/S przy klasie to typ wkładki antyprzebiciowej z wydania 2022 — przepisz go dokładnie ze źródła, nigdy nie dopisuj go sam.
4. Oznaczenia dodatkowe: SRC, FO, HRO, CI, HI, WR, ESD — wypisz te, które są w źródle.
5. Typ zapięcia: sznurowane / rzepy / BOA lub inny system pokrętła / wsuwane / zamek błyskawiczny.
6. Podnosek: stalowy / kompozytowy / aluminiowy / brak.
7. Wkładka antyprzebiciowa: stalowa / tekstylna / brak.
8. Materiał wierzchu i materiał podszewki.
9. Materiał i typ podeszwy.
10. Zakres rozmiarów EU, waga, przeznaczenie.
Poza tą listą w specs: kod/SKU producenta. W attributes: kod_producenta, material, normy_en, klasa_ochrony (np. S3), rozmiar.

BRAK INFORMACJI = POMIŃ: cechy, której źródła nie podają, nie wypisuj wcale — ani w specs, ani w description. W miejsce wartości nie wpisuj zdań o braku danych.

ZAKAZ ZGADYWANIA: żadnej z tych cech nie wolno wymyślić. Nie wpisuj wartości „typowej dla klasy”, „standardowej”, przeniesionej z innego modelu tej serii ani wyprowadzonej z samej nazwy produktu. Przykład: na stronie producenta ARTRA typu zapięcia nie ma w żadnym polu tekstowym — ani w bloku parametrów, ani w karcie produktu PDF — widać je wyłącznie na zdjęciu. Wtedy typ zapięcia pomiń.

W description (1–4 akapity) opisz prozą te z cech, które źródła podają: budowa (cholewka, zapięcie, podnosek, wkładka antyprzebiciowa, podeszwa), właściwości (antystatyczność, SRC, wodoodporność, izolacja) tylko ze słowa albo oznaczenia w źródle, klasa i norma w zapisie ze źródła, przeznaczenie i zastosowania tylko wtedy, gdy źródła je podają.
rozmiar: wyłącznie numery EU 36–50 ze źródeł; nigdy 1–5XL ani 6–12 z rękawic.
TXT,
            'odziez' => <<<'TXT'
To odzież ochronna / robocza. Zbierz pełną kartę — kurtka, spodnie, kombinezon, bluza, kamizelka itd.

W description ujmij: fason (kurtka / spodnie / kombinezon…), materiał, gramatura jeśli podana, taśmy odblaskowe, wodoodporność, EN ISO 20471 / 11612 / 11611 / 343 / 14116 jeśli są, przeznaczenie.
W specs: skład, gramatura, kolory, rozmiary odzieżowe (XS–5XL / 46–64), klasa widoczności.
rozmiar: S–5XL albo numery konfekcyjne; nigdy numery butów ani 6–12 z rękawic.
Nie myl kamizelki ostrzegawczej z osłoną twarzy.
TXT,
            'ochrona_glowy' => <<<'TXT'
To ochrona głowy (kask, hełm, czapka, kominiarka, wkładka do kasku). Nie opisuj gogli, nauszników ani masek, chyba że są zintegrowanym osprzętem tego modelu.

W description: typ (hełm przemysłowy / kask / czapka), skorupa, więźba, daszek, otwory wentylacyjne, EN 397 / EN 12492 / EN 812 jeśli są, zakres temperatur, przeznaczenie.
W specs: materiał skorupy, regulacja, sloty na nauszniki/osłonę, masa, kolory, rozmiary obwodu głowy.
TXT,
            'ochrona_twarzy' => <<<'TXT'
To osłona twarzy / przyłbica / siatka na twarz. Nie opisuj gogli same w sobie ani kasku, chyba że to zintegrowany zestaw.

W description: typ osłony, materiał szyby/siatki, montaż (uchwyt kasku / nagłowie), EN 166 / EN 1731 jeśli są, odporność mechaniczna, zastosowania (szlifierka, koszenie, spawanie).
W specs: klasa optyczna, oznaczenia EN 166, wymiary szyby, kompatybilność z kaskiem.
TXT,
            'ochrona_oczu' => <<<'TXT'
To okulary lub gogle ochronne. Nie opisuj przyłbicy na całą twarz ani maski oddechowej.

W description: typ (okulary / gogle), soczewka, oprawka, powłoki (anti-fog, rysoodporna), EN 166, oznaczenia (FT, 1, 3, 4, 5, 9), zastosowania.
W specs: klasa optyczna, filtr, wentylacja gogli, materiał, rozmiar/regulacja.
TXT,
            'ochrona_sluchu' => <<<'TXT'
To ochronniki słuchu (nauszniki, wkładki, stopery). Nie opisuj kasku ani gogli, chyba że to nauszniki do hełmu.

W description: typ, SNR / HML jeśli podane, EN 352-1/2/3, montaż (pałąk / hełm / wkładki), przeznaczenie.
W specs: SNR, masa, tłumienie, kompatybilność z kaskiem, rozmiar wkładek.
Nie myl „pianek” zatyczek z rękawicami powlekanymi.
TXT,
            'drogi_oddechowe' => <<<'TXT'
To ochrona dróg oddechowych (półmaska, maska, FFP, pochłaniacz, filtr). Nie opisuj gogli ani chemikaliów/CAS.

W description: typ (FFP1/2/3, półmaska, pełna twarz, pochłaniacz), klasa, zawór, EN 149 / EN 140 / EN 143 / EN 14387, zastosowanie (pył, gazy).
W specs: klasa_ochrony (FFP2, P3, A2P3…), liczba filtrów, rozmiar części twarzowej, masa.
Jeśli źródło to karta substancji chemicznej, a produkt to maska/PPE — description="" i confidence=0.
TXT,
            'asekuracja' => <<<'TXT'
To sprzęt asekuracyjny / praca na wysokości (szelki, lonża, amortyzator, urządzenie samohamowne, linka).

W description: typ urządzenia, punkty kotwiczenia, EN 361 / 358 / 354 / 355 / 360 / 362, materiał taśm, zastosowanie (dach, maszt, ewakuacja).
W specs: masa użytkownika, długość lonży, liczba punktów A, łączniki, certyfikaty.
Nie opisuj odzieży roboczej ani obuwia.
TXT,
            'ochrona_kolan' => <<<'TXT'
To nakolanniki / ochrona kolan. Nie opisuj spodni, chyba że nakolannik jest wkładany do kieszeni tych spodni i źródło tak podaje.

W description: typ, materiał wkładki, EN 14404, typ (1/2) i poziom, przeznaczenie (brukarstwo, glazura).
W specs: wymiary, mocowanie, klasa, kompatybilność z kieszeniami odzieży.
TXT,
            'inne' => <<<'TXT'
Zbierz PEŁNĄ specyfikację jak na karcie katalogowej BHP/PPE.
Opis: 1) przeznaczenie 2) budowa/materiały 3) właściwości użytkowe 4) normy/certyfikaty 5) zastosowania — 2–4 akapity.
rozmiar: obuwie tylko EU 36–50; rękawice 6–12; odzież pełny zakres ze źródła (bywa S–5XL, S–6XL); nigdy rozmiaru odzieży przy butach; brak w źródłach → null.
Jeśli nazwa to PPE (obuwie, rękawice, odzież…), a tekst dotyczy odczynnika / CAS — description="" i confidence=0.
TXT,
        ];
    }

    /**
     * Zasady „tylko źródła” — do 22.09.2026 wyłącznie przy opisie z PDF B2B, od tego dnia w poleceniu każdego
     * wzbogacania. Audyt ręcznych cenników znalazł ~288 opisów z wiedzą ogólną (200 J / 15 kN, objaśnienia EN 388
     * i kategorii III, „co oznacza”), bo prompt kazał ekspertowi „wyłuszczyć” ochronę, a szablon obuwia wypisywać
     * „brak danych w źródle”. Wariant: strony zbiorcze (COBAswitch klasa 0/2/4, SPIRO P1/P2/P3) dawały karcie
     * jednego wariantu wartości wszystkich.
     */
    public static function sourcesOnlyRules(string $heading = 'TYLKO ŹRÓDŁA — mają pierwszeństwo przed instrukcją rodziny:'): string
    {
        return $heading."\n".<<<'TXT'
- Pisz wyłącznie fakty podane w tych źródłach. Nie uzupełniaj niczego ogólną wiedzą o wyrobach tego typu.
- Nie objaśniaj wymagań klasy ani normy: bez energii uderzenia (np. 200 J), sił zgniatania (np. 15 kN), opisów
  badań, podłoży i środków badawczych, bez zdań o tym, co oznacza klasa, poziom albo oznaczenie. Oznaczenie
  klasy i normy podaj tak, jak stoi w źródle.
- Nie dopisuj przeznaczenia, zastosowań, branż ani warunków pracy, których źródła nie podają.
- Nie dopisuj właściwości (wodoodporność, izolacja od zimna lub ciepła, odporność na oleje, chemikalia, przebicie,
  antystatyczność, elektroizolacja, praca w wysokich temperaturach), których źródła nie podają słowem albo
  oznaczeniem w zapisie klasy lub normy.
- Gdy źródło obejmuje kilka wariantów (klasa, grubość, rozmiar, kolor), podaj wartości wyłącznie wariantu z nazwy
  karty; jeśli nie da się przypisać — pomiń.
- Brak informacji = pomiń. Nie pisz „brak danych w źródle”, „źródła nie podają…” ani podobnych zdań — ani
  w description, ani w specs, features, use_cases czy materials. Pozycję listy bez wartości pomiń w całości.
- Opis może być krótki, jeśli źródła są ubogie — to lepsze niż zdanie spoza źródeł.
TXT;
    }

    public static function writingRules(): string
    {
        return <<<'TXT'
PISANIE OPISU — te zasady mają pierwszeństwo przed instrukcją rodziny:
Przeczytaj fakty ze stron i NAPISZ z nich nowy, czytelny tekst po polsku. Lista cech w instrukcji rodziny mówi, czego szukać w źródłach — nie, co dopisać.
Nie tłumacz karty produktu 1 do 1 i nie wklejaj jej jako opisu.
Nie przepisuj UI sklepu: cenników rozmiarów (EU 35 - 309 zł), etykiet „Wariant”, tabeli długości stopy, zwrotów, gwarancji sklepu ani CTA wysyłki.
description = 1–4 krótkie akapity (budowa, ochrona, normy, zastosowanie — o ile źródła je podają), oddzielone pustą linią.
Bez HTML, CSS, markdown i bez tabeli parametrów / list SKU w description.
specs = wyłącznie krótkie „parametr: wartość”. Nie powtarzaj zdań z description.
features = krótkie korzyści, nie te same zdania co description.
TXT."\n\n".self::sourcesOnlyRules();
    }

    public static function jsonContract(): string
    {
        return <<<'SYS'
Zwróć WYŁĄCZNIE JSON — bez pola thought/reasoning/thinking. Zacznij od {"description":
{
  "description": "opis PL napisany wyłącznie z faktów źródeł: 1–4 krótkie akapity — budowa, ochrona, normy, zastosowanie, o ile źródła je podają. Nie zrzut karty ani cennika.",
  "features": ["krótkie korzyści — nie zdania z description"],
  "specs": ["parametr: wartość (nr art./SKU, typ, materiał, powłoka, opakowanie, rozmiary)"],
  "norms": ["EN … z poziomami, jeśli podane w źródłach", "EN ISO …"],
  "certificates": ["certyfikaty, kat. PPE, CE"],
  "materials": ["materiały / powłoki"],
  "use_cases": ["zastosowania / branże / warunki pracy"],
  "attributes": {
    "kategoria_bhp": "rekawice|obuwie|odziez|ochrona_glowy|ochrona_twarzy|ochrona_oczu|ochrona_sluchu|drogi_oddechowe|asekuracja|ochrona_kolan|inne",
    "kod_producenta": "SKU / nr katalogowy producenta",
    "material": "główny materiał (np. nitryl)",
    "materialy": ["lista materiałów"],
    "normy_en": ["EN 388", "EN ISO 20345"],
    "klasa_ochrony": "S3 / kat. II / …",
    "rozmiar": "pełny zakres ze źródła, bez ucinania (odzież bywa S-5XL, S-6XL); obuwie: tylko EU ze źródeł; rękawice: numery 6-12 albo litery S-XXL; nigdy rozmiaru odzieży przy butach; brak w źródłach → null",
    "poziomy_en388": "np. 4544C albo null"
  },
  "image_urls": ["https://… tylko realny URL zdjęcia produktu"],
  "document_urls": ["https://… tylko realny URL PDF karty/certyfikatu"],
  "source_urls": ["https://… karty produktu"],
  "confidence": 0.8
}
JĘZYK: cały tekst wyjściowy po polsku, także gdy źródła są francuskie, niemieckie, czeskie, hiszpańskie, chińskie czy angielskie.
Bez zdań w języku oryginału i bez etykiet typu „Produit”, „Matériaux”, „Usage” — tłumacz je na polskie odpowiedniki.
WYPEŁNIJ tablice features/specs/norms/materials/use_cases oraz attributes, gdy fakty są w tekście — nie zostawiaj ich pustych „dla skrótu”.
Nie powtarzaj tych samych zdań w description, features i specs.
attributes: używaj wyłącznie wartości ze źródeł; brak danych → null / [].
Nie zmyślaj URL ani kodów EN spoza źródeł. Brak opisu → description="" i confidence=0.
confidence = pewność 0–1, że źródła opisują TEN produkt; przy niepustym description podaj wartość większą od 0 (opis z confidence 0 jest odrzucany).
Nie przepisuj nazwy z cennika jako dowodu — opisuj wyłącznie podane strony.
Pomiń reklamy, nieruchomości, leasing, biura, inwestycje i inny tekst niezwiązany z tym produktem BHP.
Jeśli źródła opisują substancję chemiczną / CAS, a nazwa produktu to PPE (obuwie, rękawice, odzież…) — description="" i confidence=0.
SYS;
    }
}
