# Cenniki z plików: bałagan i słabe opisy — plan naprawy (04.10.2026)

Analiza: dane z produkcji (tylko odczyt), raport kodu importu, ocena 40 losowych opisów ze sprawdzeniem źródeł,
recenzja planu przez agenta Plan.

## Stan

| Cennik | Kart | Opisy sprzed 16.09 | Opis z innych stron niż producent | Ocena próbki | Ocena pracowników |
|---|---|---|---|---|---|
| MAPA | 147 | 0 | 1 | 4/5 | OK |
| AJ GROUP | 163 | 0 | 10 | 4/5 | OK |
| CEDERROTH | 84 | 13 | 4 | 3,5/5 | OK |
| SECURA | 42 | 0 | 13 | 3/5 | w trakcie |
| Coba | 869 | 604 (+157 bez opisu) | 43 | 3/5 | — |
| Ansell | 683 | 599 | 228 (21 z supon.rzeszow.pl) | 2/5 | w trakcie |
| Canis | usunięty 04.10; 118 kart-sierot do usunięcia w panelu; wraca jako B2B (CXS) | | | 2/5 | do poprawy |

## Skąd bałagan (mieszanie)

1. Kliknięcie producenta w Cennikach pokazuje karty **tej marki**, a nie **tego cennika**
   (PriceLists.tsx:2237 → ProductController.php:120). Karty Canis z marką Ansell pokazywały się „w Ansellu”.
2. Cennik pamięta tylko karty z **ostatniego** importu (`price_lists.product_ids`). Z tej listy korzystają: usuwanie
   cennika (dlatego zostało 118 sierot Canis), „Pobierz opisy”, liczniki i `backfill-bhp-attributes --price-list`.
3. Wiersz cennika producenta może przejąć kartę tej samej marki z innego pliku i nadpisać jej SKU. Po usunięciu Canis
   to mało realne — odłożone.
4. Automatyczne łączenie rozmiarów po imporcie bierze wszystkie karty marki, także z innego cennika.

## Skąd słabe opisy

1. **Wiek** — Ansell i Coba: ~90% opisów sprzed wszystkich poprawek jakości (16.09, 22.09, 01.10). Nikt ich nie
   odświeżył; 627 kart Ansella ma status „błąd” tylko dlatego, że 22.09 zatrzymano pobieranie (opis został).
2. **„Bez lateksu” → lateks** — atrybuty czytane z całego opisu bez przeczeń (BhpAttributeNormalizer). Ansell:
   127 kart z materiałem „lateks” i 56 z rodziną „lateks”, choć opis mówi „bez lateksu”. Rodzina materiału
   **wyklucza zamienniki** (ProductCrossRefService.php:269) — rękawica PU „bez lateksu” paruje się z lateksem.
3. **„Certyfikat producenta”** — doklejany do każdego PDF uznanego za certyfikat, także deklaracji zgodności
   (ProductEnrichmentService.php:1325). Do tego polecenie dla modelu każe wpisywać do certyfikatów „kat. PPE, CE”
   (EnrichmentDescriptionTemplates.php:204). Ansell 456 kart. Idzie na kartę, do sklepu (Presta) i do wyszukiwania.
4. **Strona innego wyrobu** — Ansell 9192 opisany z AlphaTec 2000 zamiast 2500; Coba 11105 z „Premier Track”
   zamiast „Premier Rib” (opis z 27.09, po poprawkach); CEDERROTH 26566 i SECURA 179 sklejone z kilku wyrobów.
5. **Słowniki** — PVC w odzieży → rodzina „guma”; kaptur → kurtka; arkusz EPDM → kalosz; rozmiar „m” z „1 m”,
   „4” z „4,5 mm”; śmieci w normach („badanie”, A2 przy EN 388, zgubiona cyfra poziomu).

## Pułapki przy ponownym pobieraniu (wykryte w recenzji)

- Z wymuszeniem (force) zdjęcia i PDF-y są kasowane po znalezieniu strony, ale **przed** napisaniem opisu
  (ProductEnrichmentService.php:867). Błąd modelu → stary opis bez zdjęć.
- Bez wymuszenia karta „błąd” dostaje **stary opis z pamięci** (cache SKU) i status „gotowe” — nic się nie poprawia.
- Z wymuszeniem przy limicie partii (domyślnie 5) każde kolejne „Pobierz opisy” bierze **te same** pierwsze karty.

## Kolejność napraw

### A. Bez modelu, szybko (naprawia Ansella od razu)
1. Przeczenia materiałów: odfiltrować „bez / nie zawiera / -free / bezlateksowy” z połączonej listy materiałów
   i z tekstu dla rodziny materiału. Testy: 8718, 8698, 8864, 11202000. Potem istniejące polecenia:
   `products:backfill-bhp-attributes --manufacturer=Ansell --force` (podgląd → `--apply`),
   `products:rebuild-search-index`, `products:reindex-embeddings`.
2. Certyfikaty: etykieta z nazwy dokumentu i adresu (deklaracja ≠ certyfikat); „CE / kat. PPE” poza certyfikatami
   (prompt + filtr); polecenie przeetykietowania zapisanych kart (podgląd → `--apply`).

### B. Porządek cenników (równolegle, niskie ryzyko)
3. Link z Cenników po cenniku (filtr `price_list` = slot pliku), liczniki i „Pobierz opisy” po slotach.
4. Usuwanie cennika: karty po slotach + `product_ids`, okno z podglądem skutków; chronione tylko karty użyte
   w przetargach (zostaje karta, znika cena z pliku).

### C. Przed ponownym generowaniem opisów
5. Kasowanie zdjęć/PDF przy force przenieść za udany opis.
6. `products:queue-enrichment --price-list= --force --enriched-before=2026-09-16`, żeby partie szły dalej.
7. PVC w odzieży → rodzina pusta (nie „guma”); rozmiar nie z długości („1 m”, „4,5 mm”).

### D. Ponowne opisy (użytkownik, partiami)
8. Ansell: próbka 40 kart → porównanie z dzisiejszą oceną → reszta partiami. Potem Coba (+157 bez opisu), SECURA,
   13 starych CEDERROTH.

### E. Odłożone / do pomiaru
- Bramka „strona innego wyrobu” (2000 vs 2500, Track vs Rib, strony kolekcji): najpierw zmierzyć na pełnej próbce —
  największe ryzyko regresji w całym module.
- Ochrona karty przed przejęciem przez inny plik tej samej marki (pkt 3 bałaganu).
- „Zatrzymaj wszystko” → status „gotowe”: nie robić (przykryłoby karty „ręcznie” i bez zdjęć); ponowne opisy i tak
  ustawią status.
