# Dopasowanie do przetargów — co się działo i czym to zastępujemy

Stan na 21.09.2026 (noc). Wszystkie liczby poniżej są zmierzone: 29 zapisanych raportów `tenders:eval`
(1065 ocen pozycji), eksperymenty na zamrożonym wejściu i dwa pomiary na żywo po zmianach.
Recenzja planu: drugi agent (Plan) — jego poprawki są uwzględnione.

## 1. Co się naprawdę działo

Dotąd szukaliśmy winy w ocenach modelu i dopisywaliśmy wyjątki. Pomiar pokazał, że model dostawał
**za każdym razem inne i niepełne dane**, więc oceniał co innego — a my łataliśmy skutki.

| # | Przyczyna | Dowód |
|---|-----------|-------|
| 1 | **To samo wymaganie było za każdym razem „rozumiane” od nowa** i model inaczej nazywał produkt i warunki. Od warunków zależy, które karty zostaną znalezione i które 24 trafią do oceny. | Poz. 15: z 54 różnych kart tylko 4 wspólne dla 5 przebiegów; właściwa karta docierała do modelu w 3–4 przebiegach z 5. Poz. 04: w 1 z 5. Pula (80 kart) też pływała: 27 wspólnych ze 141. |
| 2 | **Model widział 1/3 karty**: opis przycięty do 360 znaków, 8 wierszy specyfikacji, 4 cechy — a miał potwierdzić 8–15 warunków. | Wszystkie 28 kart wzorcowych przycięte; średnio model widział 34% opisu. Mediana opisu w katalogu: 1225 znaków. |
| 3 | **„Model pominął właściwą kartę” znaczyło zwykle, że jej nie dostał.** | 74% nietrafionych pozycji (202 z 272): karty wzorcowej nie ma w odpowiedzi modelu. Remisy to 7%, limit dostawcy 6%. |
| 4 | **Kod zamienia jedną wątpliwość modelu w twarde 50** (poniżej progu zapisu 65) i pozycja zostaje pusta — wbrew zasadzie „zawsze zaproponuj najlepszą kartę i powiedz, czego nie spełnia”. | 2320 z 2330 ocen ≤50 to sufit dopisany przez kod, nie ocena modelu. Rękaw HyFlex: model 95 → kod 50. |
| 5 | **Braki w danych kart** zamieniają się w „brak dowodu”. | Wczoraj naprawione trzy wady: zgubione normy z ramki producenta (15 kart AJ GROUP), opis z cudzej strony wariantu (101/001 ← 101/001/A), nieczytane karty PDF producenta. |
| 6 | **Zestaw testowy jest częściowo błędny**, więc mierzył poprawne zachowanie jako błąd. | Poz. 01: karta „wzorcowa” ma ścieranie 1 (wymagane 2), kat. II (wymagana III), szytą konstrukcję (wymagana bezszwowa) — ocena 50 jest tu poprawna. |
| 7 | **Pomiar nie zapisywał, co model dostał** (warunków, listy 24 kart, surowej oceny) — przyczyn nie było widać, więc zgadywaliśmy. | Raporty mają tylko wynik końcowy. |

Co **nie** jest przyczyną:
- Kod decyzji (progi, wybór karty) jest zdrowy: dzisiejszy kod na odpowiedziach modelu z 14.09 daje 42/45 i 44/45.
- Remisy: w 277 remisach z kartą wzorcową system wybiera dobrze w 259 (93%). Rozstrzyganie remisów „liczbą
  potwierdzonych warunków” naprawia 2 przypadki, a psuje 6 — tej drogi **nie** idziemy (to powtórka cofniętej zmiany z 14.09).

## 2. Co już zmienione (trzy zmiany u źródła, nie łatki)

| Zmiana | Skutek zmierzony |
|--------|------------------|
| **Pełna karta dla modelu**: cały opis (do 3000 znaków = 95% katalogu), cała specyfikacja, wszystkie cechy. | Na zamrożonym wejściu 5 razy ta sama odpowiedź (przedtem skakała 50/95). Na żywo 33/45, 0 zakazanych, puste 7% zamiast 10%, czas bez zmian. |
| **Wymaganie rozumiane raz i zapisywane** (tabela `requirement_understandings`; to także ślad do audytu: jak system zrozumiał wymaganie). | Stabilnych pozycji 13 z 15 (było 11); krok „zrozum” 0,9 s zamiast 13–68 s na przebieg. |
| Wcześniej tego dnia: normy z ramki producenta, reguła wariantu po ukośniku, karty PDF producenta, słowa modelu przy niskiej ocenie, dłuższa przerwa przy limicie dostawcy. | Opisane w commitach. |

Po tych zmianach 11 z 15 pozycji testowych jest trafnych **w każdym przebiegu**. Cztery pozostałe są stale nietrafione
i każda ma konkretną przyczynę poza modelem:

- **01 rękaw** — karta przeczy wymaganiu (patrz wiersz 6 wyżej). Do decyzji: etap 3.
- **04 fartuch** — lokalna karta 202 bez norm; na serwerze normy już są.
- **07 rękawice olejowe** — właściwa karta 44-304 jest znajdowana, ale wycina ją bramka słowna „dowód żargonu” przed modelem; przechodzi bliźniacza 44-305.
- **09 gogle** — karta 3M 2890 wycinana bramką klasy uderzenia (karta podaje FT, wymaganie 120 m/s). Czeka na decyzję merytoryczną.

## 3. Czym zastępujemy resztę

Zasada: **żadnych nowych wyjątków w instrukcji dla modelu i żadnych nowych reguł „na przypadek”.**
Każdy etap ma pomiar przed wdrożeniem i kryterium, po którym z niego rezygnujemy.

### Etap 1 — przyrząd pomiarowy i naprawa zestawu testowego
- Raport `tenders:eval` i ślad wyszukiwania zapisują: warunki z kroku „zrozum”, listę kart wysłanych do modelu,
  surową ocenę modelu i listę braków sprzed sufitu.
- Przegląd 15 pozycji testowych: czy karta wzorcowa naprawdę spełnia wymaganie (01, 07, 09 na pewno do poprawy).
- Bez tego każda kolejna zmiana jest oceniana krzywą miarą.

### Etap 2 — model ogląda wszystko, co znaleziono
- Dziś: z ok. 80 znalezionych kart do modelu idą 24, wybierane po liczbie trafionych słów (bogatszy opis wygrywa).
- Zamiast tego: cała pula oceniana w 2–3 równoległych paczkach. Koszt: 2–3× więcej zapytań rankingu, czas podobny.
- Kryterium: karta wzorcowa jest oceniana przez model w ≥95% przebiegów (dziś 13 z 15 pozycji). Jeśli nie — rezygnujemy.

### Etap 3 — zawsze propozycja z listą braków (wymaga Twojej decyzji)
- Dziś: brak dowodu jednego „kluczowego” warunku → 50 → pozycja pusta.
- Zamiast tego: próg decyduje tylko, czy karta jest wpisywana **automatycznie**, czy jako **„propozycja do sprawdzenia”**
  z wypisanymi brakami. Pozycja jest pusta tylko wtedy, gdy w katalogu nie ma wyrobu tego rodzaju.
- To zmiana zasady biznesowej, nie techniki — dlatego decyzja należy do Ciebie.

### Etap 4 — bramki słowne przed modelem
- Dziś 12 bramek wycina karty, zanim model je zobaczy; część opiera się na obecności słowa w opisie.
  Zmierzone: 6 z 73 kart wzorcowych jest tak wycinanych (żargon 2, hełm 2, rodzaj 2, antystatyka 1, klasa uderzenia 1).
- Twardo zostaje tylko „to inny rodzaj wyrobu”. Reszta staje się informacją dla modelu albo ostrzeżeniem przy pozycji —
  bramka po bramce, każda z pomiarem.

### Etap 5 — ocena warunek po warunku (próba, nie wdrożenie)
- Model zamiast jednej liczby zwraca dla każdego warunku: spełnia / przeczy / brak danych, z cytatem z karty.
  Kod sprawdza, czy cytat naprawdę stoi na karcie, i sam liczy wynik. Istniejący moduł „Weryfikacja karty”
  (wymiary, poziomy EN 388/407, kategoria ŚOI) **weryfikuje** model — nie układa rankingu (pokrycie to dziś ok. 18% warunków).
- Próba na 4 pozycjach × 5 powtórzeń. Kontynuujemy, gdy mniej niż 5% komórek „karta × warunek” zmienia status;
  przy 15% i więcej rezygnujemy — to byłaby ta sama niestabilność w drobniejszej siatce.

### Etap 6 — sprzątanie łatek
Po każdym etapie usuwamy to, co przestało być potrzebne, z pomiarem przed i po. Kandydaci: sufit 50 za brak dowodu,
skala 50/70–89/90–99 w instrukcji, wycinki `constraint_evidence` i `description_norms` (obchodziły przycięcie karty),
wybór 24 kart po trafionych słowach, tabela napięć i reguła A2B2E2K2 w instrukcji, `missingWeldingFilterEvidence`,
`missingRequiredFootwearTypeEvidence`, `showsAllRequiredNorms`, `EVIDENCE_VETO_GAP`, `MODEL_SCORE_TIE_MARGIN`.
Zostają: synonimy rodzaju wyrobu, reguły marki, literówki, żargon, wymaganie podwójne, ścieżka mocnego kodu,
bramka karty bez opisu, obsługa niedostępnego modelu.

### Tor równoległy — dane kart
Audyt „źródło podaje normę, karta jej nie ma” dla całego katalogu (wczoraj zrobiony ręcznie dla AJ GROUP: 15 kart).

## 4. Ograniczenia i ryzyka
- Zapisane zrozumienie jest trwałe: gdy model raz źle zrozumie wymaganie, kolejne przebiegi tego nie zmienią.
  Tak stało się 21.09 z „Rękawice Ultrane” (zapisane jako samo „rękawice”). Zabezpieczenie: nazwa serii z wymagania,
  której zrozumienie nie zawiera, wraca do fraz wyszukiwania przy każdym odczycie (słowo w nazwach 1–60 kart i w ponad
  połowie kart, które je wymieniają). Wyjścia awaryjne: zmiana treści wymagania albo podniesienie `UNDERSTAND_PROMPT_VERSION`.
  Docelowo: podgląd i edycja warunków przy pozycji.
- Pełna karta zwiększa liczbę tokenów rankingu mniej więcej 2–3×; czasu przebiegu to nie wydłużyło.
- Zestaw testowy to 15 pozycji jednego przetargu — każdy etap trzeba dodatkowo sprawdzić na prawdziwych zapytaniach z maili.
- Pomiar na żywo zależy od limitu zapytań u dostawcy (4 z 45 pozycji bez odpowiedzi w ostatnim przebiegu lokalnym).
