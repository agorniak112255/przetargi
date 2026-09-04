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

W description (6–12 zdań) ujmij: przeznaczenie, materiał wkładki i powłoki, wykończenie (gładkie / piankowe / nitryl), mankiet, normy z poziomami, typowe prace.
W specs i attributes koniecznie: kod/SKU, materiał wkładki, powłoka, poziomy EN 388 (np. 4544C → attributes.poziomy_en388), EN 374 / EN 407 / EN 511 / EN ISO 21420 jeśli są w źródłach, długość, rozmiary 6–12 (albo 7–11).
rozmiar: tylko numery rękawic ze źródeł; nigdy numery butów (36–48) ani 1–5XL.
Nie zmyślaj poziomów EN 388 spoza źródeł.
TXT,
            'obuwie' => <<<'TXT'
To obuwie ochronne / robocze. Zbierz pełną kartę katalogową — nie opisuj rękawic ani odzieży.

W description ujmij: przeznaczenie, cholewka, podnosek, wkładka, podeszwa, właściwości (antystatyczność, SRC, wodoodporność, izolacja), klasa (S1–S5 / O1–O5 / S1P), zastosowania.
W specs i attributes: kod/SKU, materiał cholewki, podnosek (kompozyt / stal), wkładka, podeszwa, EN ISO 20345 / 20347, klasa_ochrony (np. S3), rozmiary EU ze źródeł.
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
Opis: 1) przeznaczenie 2) budowa/materiały 3) właściwości użytkowe 4) normy/certyfikaty 5) zastosowania — min. 6–12 zdań.
rozmiar: obuwie tylko EU 36–50; rękawice 6–12; odzież S–XXL; nigdy 1–5XL przy butach; brak w źródłach → null.
Jeśli nazwa to PPE (obuwie, rękawice, odzież…), a tekst dotyczy odczynnika / CAS — description="" i confidence=0.
TXT,
        ];
    }

    public static function jsonContract(): string
    {
        return <<<'SYS'
Zwróć WYŁĄCZNIE JSON — bez pola thought/reasoning/thinking. Zacznij od {"description":
{
  "description": "pełny opis PL: 1) przeznaczenie 2) budowa/materiały 3) właściwości użytkowe 4) normy/certyfikaty 5) zastosowania — min. 6–12 zdań",
  "features": ["cechy i korzyści — min. 5 pozycji, gdy źródła na to pozwalają"],
  "specs": ["parametr: wartość (nr art./SKU, typ, materiał wkładki, powłoka, opakowanie, rozmiary…)"],
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
    "rozmiar": "obuwie: tylko EU 36-48 ze źródeł; rękawice: 7-11; odzież: S-XXL; nigdy 1-5XL przy butach; brak w źródłach → null",
    "poziomy_en388": "np. 4544C albo null"
  },
  "image_urls": ["https://… tylko realny URL zdjęcia produktu"],
  "document_urls": ["https://… tylko realny URL PDF karty/certyfikatu"],
  "source_urls": ["https://… karty produktu"],
  "confidence": 0.0
}
JĘZYK: cały tekst wyjściowy po polsku, także gdy źródła są francuskie, niemieckie, czeskie czy angielskie.
Bez zdań w języku oryginału i bez etykiet typu „Produit”, „Matériaux”, „Usage” — tłumacz je na polskie odpowiedniki.
WYPEŁNIJ tablice features/specs/norms/materials/use_cases oraz attributes, gdy fakty są w tekście — nie zostawiaj ich pustych „dla skrótu”.
Nie powtarzaj tych samych zdań w description, features i specs — description zostaje pełny (6–12 zdań).
attributes: używaj wyłącznie wartości ze źródeł; brak danych → null / [].
Nie zmyślaj URL ani kodów EN spoza źródeł. Brak opisu → description="" i confidence=0.
Nie przepisuj nazwy z cennika jako dowodu — opisuj wyłącznie podane strony.
Pomiń reklamy, nieruchomości, leasing, biura, inwestycje i inny tekst niezwiązany z tym produktem BHP.
Jeśli źródła opisują substancję chemiczną / CAS, a nazwa produktu to PPE (obuwie, rękawice, odzież…) — description="" i confidence=0.
SYS;
    }
}
