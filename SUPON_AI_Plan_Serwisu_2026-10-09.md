# Moduł „Serwis” — aplikacja dla serwisantów (plan, 09.10.2026)

Prośba działu usług (spotkanie 09.10.2026): organizacja wyjazdów na przeglądy sprzętu przeciwpożarowego
(gaśnice, hydranty, węże, …). Serwisant wykonuje przegląd, na tablecie z Androidem szybko wprowadza wynik,
klient podpisuje protokół, kierownik zatwierdza i wysyła do ERP XL jako ZS, z którego biuro robi fakturę.

Status: po recenzji agentem Plan (09.10.2026) — sekcja 10 ZASTĘPUJE sekcje 1–6 tam, gdzie się różnią.
Szczegóły procesu działu usług poznamy na kolejnych spotkaniach (sekcja 9).

## 1. Co już mamy (sprawdzone w kodzie 09.10.2026)

| Element | Gdzie | Co daje |
|---|---|---|
| Klienci z XL | tabela `clients` (erp:clients, noc 03:20) | xl_gid, NIP, e-maile, osoby kontaktowe (JSON), opiekun. Adres i telefony z XL czekają na GRANT kolumn (login `przetargi_odczyt` ich nie czyta). |
| Terminy przeglądów | moduł Przeglądy (`inspection_due`, `inspection_positions`) | kiedy u klienta wypada przegląd, z faktur/WZ XL; pozycje XL z interwałem; `recipient_xl_gid` i `location` z dokumentu. |
| Usługi XL | `erp_services` (Twr_Typ 4, ~399 aktywnych, kody UPR…) | lista usług do protokołu. |
| Towary XL | `erp_items` + `erp_item_links` → karty `products` | towary (gaśnice, części) z kodem XL; karty mają opisy, zdjęcia, wersje opisów. |
| Oferta przeglądu | moduł Oferty (`offers.kind = inspection`) | oferta zaczepna do klienta. |
| Webserwis XL | `Webserwis/` (WCF, IIS, cdn_api 2025.1) | `AddOrder` tworzy ZS (Typ 6): nagłówek Akronim/Opis/DokumentObcy/DataWystawienia/Magazyn/adresy, pozycje Towar|Indeks/Ilosc/CenaOferowana/Cecha/Opis + atrybuty; `GetContractorAddresses`. Backend aplikacji jeszcze go NIE woła. |
| Dostęp z sieci | `roles.network_access` (local / local_code / any) | serwisant poza biurem potrzebuje trybu innego niż „local”. |
| Role i uprawnienia | `roles`, wzorzec `modul.view/.manage` | nowe uprawnienia serwisu. |

Ograniczenia webserwisu (z kodu `ComarchXLAPI.cs`), ważne dla etapu 6:
- `AddOrder` NIE zwraca numeru ani GID utworzonego ZS — tylko tekst „Szczegóły importu …”.
- Brak ochrony przed duplikatem po `DokumentObcy` — ponowienie po przekroczeniu czasu (90 s) może zrobić drugi ZS.
- Pozycja, której nie da się dodać, nie przerywa dokumentu — ZS powstaje bez niej, błąd w `Description`.
- Seria ZS i magazyn z `Configuration.xml` per firma (`XLSeriaZS`, `XLMagazyn`) — serwis może potrzebować własnej serii.
- Zamknięcie `TrybZamkniecia = 0` (zapis); bufor = 2 — do decyzji, czy ZS z serwisu idzie do bufora.

## 2. Pojęcia

- **Obiekt** — miejsce u klienta (adres, budynek). Klient XL ma 1..N obiektów (np. szkoła z 3 budynkami, gmina z 20 lokalizacjami).
- **Strefa** — opcjonalny podział obiektu: budynek / piętro / pomieszczenie / opis dojścia („klucz u portiera”).
- **Urządzenie** — konkretny egzemplarz w obiekcie: gaśnica, hydrant, wąż, zawór, koc, oświetlenie awaryjne, drzwi pożarowe…
  Ma typ, numer fabryczny/inwentarzowy, producenta, model, datę produkcji, miejsce (strefa + opis), kod QR naklejki,
  daty: ostatni przegląd, ostatni remont / legalizacja / próba ciśnieniowa, następny termin.
- **Typ urządzenia** — szablon: jakie pola i pomiary (np. hydrant: ciśnienie statyczne, dynamiczne, wydajność; wąż: próba
  ciśnieniowa co 5 lat), jakie czynności (przegląd, remont, legalizacja UDT, wymiana), jakie usługi/towary XL domyślnie.
- **Zlecenie** — wyjazd do jednego obiektu w jednym terminie, przypisany serwisant(ci), lista urządzeń do przeglądu.
- **Protokół** — wynik zlecenia: stan każdego urządzenia, usterki, zużyte towary i usługi XL, zdjęcia, uwagi, podpis klienta.
  Po podpisie niezmienny (korekta = nowa wersja z powodem, stara zostaje).
- **Kartoteka serwisowa** — opisy usług i towarów XL dla serwisu (zdjęcia, instrukcje, normy), jak opisy kart z cenników.

## 3. Cały proces (także etapy, których nie omówiono)

1. **Baza**: obiekty i urządzenia klienta (pierwszy wyjazd = inwentaryzacja na tablecie; import z Excela/papierów).
2. **Termin**: moduł Przeglądy + rejestr urządzeń podpowiadają, u kogo i co wypada → lista „do zaplanowania”.
3. **Umówienie**: kontakt z klientem (osoba kontaktowa obiektu), propozycja terminu, mail z potwierdzeniem.
4. **Planowanie**: kalendarz serwisantów, przypisanie zleceń, kolejność wyjazdów w dniu, mapa (później).
5. **Przygotowanie**: serwisant widzi listę urządzeń, uwagi z poprzedniego razu, co zabrać (części, gaśnice na wymianę).
6. **Wykonanie (tablet, offline)**: start wizyty → skan QR / lista urządzeń → przy każdym jeden dotyk „Sprawne” albo
   „Usterka/Wymiana/Do kasacji/Brak dostępu” + pomiary wymagane przez typ + zdjęcie usterki → nowe urządzenia → zużyte
   towary/usługi (podpowiadane z typu i czynności) → podsumowanie.
7. **Podpis**: klient widzi podsumowanie na tablecie, wpisuje imię i nazwisko, podpisuje palcem; serwisant też podpisuje.
   PDF protokołu idzie mailem do klienta (adres osoby kontaktowej obiektu albo wpisany na miejscu).
8. **Kontrola kierownika**: lista „podpisane, do sprawdzenia”; podgląd, poprawa cen/ilości (nie zmienia podpisanego
   protokołu — zmienia tylko dane do ZS, z historią), zatwierdzenie.
9. **ZS do XL**: wysyłka przez webserwis `AddOrder`; zapis odpowiedzi; status „w XL” z numerem ZS (odczyt z XL po
   `DokumentObcy` = numer protokołu); ochrona przed duplikatem.
10. **Faktura**: biuro w XL z ZS; aplikacja widzi fakturę nocnym odczytem (moduł Przeglądy już czyta faktury/WZ) →
    zlecenie „zafakturowane”, następny termin liczy się sam.
11. **Po wizycie**: usterki i urządzenia do wymiany → oferta (moduł Oferty, kind inspection) / zlecenie naprawy;
    przypomnienie przed następnym terminem.
12. **Raporty**: zlecenia w miesiącu, przychód z serwisu, czas wizyt, usterki wg typu, terminy przekroczone.

## 4. Decyzje techniczne (proponowane)

### 4.1 Aplikacja na tablet: PWA (ta sama aplikacja React), bez osobnej natywnej
- Osobny widok „Serwis” w obecnym frontendzie (React 19 + Vite), układ pod tablet (duże przyciski, jedna kolumna).
- Instalowana na Androidzie jako aplikacja (manifest + service worker), działa **offline**: dane zleceń dnia
  pobierane rano do IndexedDB, wynik zapisywany lokalnie, wysyłany gdy wróci sieć (kolejka z identyfikatorem
  urządzenia i numerem operacji — wysyłka idempotentna).
- Aparat: `<input capture>` / getUserMedia; skan QR: BarcodeDetector (Chrome Android) z zapasem w bibliotece JS.
- Podpis: canvas (wektor punktów + PNG), z datą, godziną, imieniem i nazwiskiem podpisującego, opcjonalnie GPS.
- Kompromis: PWA nie wymaga sklepu Google ani osobnego kodu; minus — mniejsza kontrola nad urządzeniem (MDM).
  Jeśli okaże się potrzebny APK (np. drukarka Bluetooth do naklejek), opakowanie Capacitorem tego samego kodu.

### 4.2 Logowanie i bezpieczeństwo tabletu
- Rola „Serwisant” z trybem sieci pozwalającym na pracę w terenie; token urządzenia (tablet) z możliwością
  zdalnego odebrania; krótszy czas sesji, PIN do odblokowania aplikacji offline.
- Dane offline tylko na zlecenia przypisane serwisantowi (minimum danych osobowych — RODO).

### 4.3 Dane (nowe tabele, nazwy robocze)
`service_sites` (obiekt; client_xl_gid, adres, opis dojazdu, godziny, GPS), `service_site_contacts` (osoby obiektu;
własne, niezależne od XL), `service_zones`, `service_device_types` (szablon pól/pomiarów/czynności/domyślnych pozycji XL),
`service_devices` (egzemplarze, qr_code unikalny), `service_device_events` (historia: przegląd/remont/legalizacja/
wymiana/kasacja — źródło terminów), `service_orders` (zlecenie), `service_order_devices`, `service_protocols`
(numer PR-RRRR-NNNN, wersja, status, hash treści, PDF), `service_protocol_lines` (pozycje do ZS: erp_item/erp_service,
ilość, cena, źródło: z typu / ręcznie), `service_protocol_signatures`, `service_attachments` (zdjęcia),
`service_erp_exports` (każda próba wysyłki: żądanie, odpowiedź, status, numer ZS).
Słowniki XL (klient, towar, usługa) zawsze przez xl_gid + kopia nazwy z chwili zapisu (protokół = dokument).

### 4.4 Kartoteka serwisowa (punkt 2 prośby)
Wykorzystać istniejące karty `products` + edytor opisu/zdjęć (wersje opisów) i powiązania `erp_item_links`;
usługi XL (`erp_services`) potrzebują własnej karty opisu (dziś nie mają). Do sprawdzenia w recenzji: czy karta
`products` nadaje się na usługę, czy osobna `service_catalog_entries` z tym samym edytorem.

### 4.5 Wysyłka ZS (punkt 3 prośby)
- Backend PHP woła webserwis SOAP (`AddOrder`) z kolejki (job), nie z żądania przeglądarki.
- `DokumentObcy` = numer protokołu. Przed wysyłką i po błędzie/limicie czasu: odczyt XL (READ ONLY) czy ZS z tym
  `DokumentObcy` już istnieje → wtedy tylko podpięcie numeru, bez drugiej wysyłki.
- Odpowiedź z błędami pozycji = status „w XL z brakami” + lista do poprawy ręcznie w XL; nie ponawiamy całości.
- Zmiana w webserwisie (mała, wsteczna zgodność): zwracać GID/numer ZS; opcjonalny `ZamSeria` / tryb bufora
  w nagłówku. Webserwis służy też innej platformie — zmiany tylko dodające pola.
- Ceny: login aplikacji nie czyta `CDN.TwrCeny` → domyślnie bez ceny (XL nada cenę z cennika klienta) albo cena
  z umowy/ręcznie od kierownika — decyzja działu (sekcja 9).

## 5. Ekrany (proste słowa)

Web (biuro): Serwis → Do zaplanowania · Kalendarz · Zlecenia · Protokoły do sprawdzenia · Obiekty i urządzenia ·
Typy urządzeń · Kartoteka · Raporty.
Tablet: Moje zlecenia (dziś / jutro) → Zlecenie (adres, kontakt, nawigacja, uwagi) → Urządzenia (lista + skan QR)
→ Urządzenie (jeden ekran, duże przyciski) → Towary i usługi → Podsumowanie → Podpis klienta → Wysłane.

## 6. Etapy

0. Warsztat z działem usług + sondy XL READ ONLY (usługi serwisowe, serie ZS, magazyn, cenniki, jak dziś wygląda
   protokół papierowy, ile serwisantów/tabletów, gdzie nie ma zasięgu). Wynik: zatwierdzone typy urządzeń i wzór protokołu.
1. Obiekty, strefy, urządzenia, typy urządzeń (web) + import z Excela.
2. Kartoteka serwisowa (opisy i zdjęcia usług/towarów XL).
3. Zlecenia i kalendarz (web), zasilane z Przeglądów i rejestru urządzeń.
4. Tablet: wykonanie zlecenia offline, zdjęcia, QR, podpis, PDF i mail do klienta. Pilotaż: 1 serwisant, 2 tygodnie.
5. Kontrola kierownika + wysyłka ZS (najpierw na firmie/serii testowej albo do bufora) + rozszerzenie webserwisu.
6. Domknięcie: faktura widoczna w aplikacji, usterki → oferty, przypomnienia, raporty, naklejki QR.

## 7. Ryzyka
- Offline i synchronizacja (dwa urządzenia edytują ten sam obiekt) — zlecenie ma jednego „właściciela” na czas wizyty.
- Duplikaty ZS przy limicie czasu webserwisu — ochrona przez odczyt po `DokumentObcy`.
- Jakość danych wejściowych (adresy obiektów, numery gaśnic) — pierwsza wizyta to inwentaryzacja.
- Wymogi prawne protokołu (normy PN-EN 3, PN-EN 671-3, rozporządzenie MSWiA o ochronie ppoż.) — wzór od działu usług,
  aplikacja nie wymyśla wymagań ani interwałów.
- Podpis odręczny na tablecie = forma dokumentowa (nie kwalifikowany) — wystarcza dla protokołu, do potwierdzenia przez dział.

## 8. Czego aplikacja nie robi
- Nie wystawia faktur (robi to XL z ZS).
- Nie zmienia danych kontrahenta w XL (obiekty i osoby obiektu żyją w aplikacji).
- Nie zgaduje terminów prawnych — interwał z typu urządzenia, zatwierdzony przez dział usług.

## 9. Pytania do działu usług (zmieniają implementację)
1. Jakie typy urządzeń i czynności, jakie pomiary, wzór obecnego protokołu (papier/Excel)?
2. Ilu serwisantów, czy jeżdżą parami, czy jeden protokół na obiekt czy na dzień?
3. Ceny w ZS: z cennika XL, z umowy z klientem, ryczałt za obiekt, dojazd jako pozycja?
4. Czy ZS ma iść do bufora (biuro jeszcze sprawdza w XL), czy od razu zatwierdzony? Osobna seria ZS dla serwisu?
5. Kto umawia termin z klientem i jak (telefon, mail, SMS)?
6. Czy klient zawsze podpisuje na miejscu? Co gdy nie ma osoby uprawnionej (podpis później zdalnie przez link)?
7. Czy serwisant zabiera towar z magazynu (MM na samochód) — czy rozliczać stan „magazynu samochodowego”?
8. Naklejki na gaśnice: dziś papierowe? Czy chcą kodów QR?
9. Tablety: jaki model, Android w wersji ≥ 10, internet w tablecie (SIM)?

## 10. Po recenzji (09.10.2026) — zmiany wiążące

Fakty sprawdzone w kodzie przy recenzji:
- Klient serwisu = `erp_customers` (jak w Przeglądach), nie `clients` (tylko klienci z progiem sprzedaży).
  `ErpCustomer` ma `acronym` (wymagany przez `AddOrder`), ulicę, kod, telefon.
- `inspection_due.location` to kod oddziału (WarehouseLocations), `recipient_xl_gid` to karta odbiorcy — żadne
  z nich nie jest „obiektem”. Obiekt to nowa encja.
- Magazyn ZS da się już podać w nagłówku `AddOrder`; usługi idą przez 4 magazyny (01G, 13G, 14U, 11G) → zlecenie
  ma `branch`, z niego magazyn ZS. Stała z konfiguracji jest tylko seria.
- `AddOrder` dodatkowo: nie sprawdza wyniku `XLZamknijDokumentZam` (nieudane zamknięcie = „sukces”), pomija
  `headerAttribute`; po 90 s wywołanie natywne trwa dalej (może utworzyć ZS), a kolejne są odrzucane do końca
  poprzedniego; webserwis bez uwierzytelniania (`basicHttpBinding`, `includeExceptionDetailInFaults=true`).
- Sanctum `expiration = null`; `local_code` = 24 h na jeden adres IP → na LTE nie do użycia; 401 `reason=network`
  wylogowuje front.
- Karta `products` NIE nadaje się na usługi: zapis przelicza `ProductSearchBlob` i reindeks wektorów (usługi
  wpadłyby do wyszukiwarki przetargów), pola pod cenniki/B2B, `erp_item_links` tylko dla `erp_items`.

Zmiany planu:
1. **Protokół ≠ rozliczenie.** `service_protocols` = niezmienny obraz po podpisie (JSON, hash, PDF, ilości bez cen).
   Kierownik edytuje `service_settlements` + pozycje; z rozliczenia powstaje ZS. Jedno rozliczenie może objąć kilka
   protokołów (klienci rozliczani miesięcznie).
2. **Numer offline:** tablet nadaje UUID; numer PR-RRRR-NNNN nadaje serwer przy przyjęciu. `export_key` (stały,
   niezmienny przy wersjach) = `DokumentObcy` w XL.
3. **Obiekt:** `service_sites` z `customer_xl_gid` (nabywca), opcjonalnie `recipient_xl_gid` i adres XL (Adw, typ 864
   z `GetContractorAddresses`), `branch`, własny adres i opis dojazdu. Klient bez karty XL = stan „czeka na kartę XL”,
   blokuje wysyłkę ZS.
4. **Urządzenia na start zbiorczo:** pozycja „GP-6 × 12, budynek A”; pojedyncze sztuki tylko tam, gdzie są pomiary
   (hydranty). Pełny rejestr sztuk, QR, strefy — po pilotażu. Źródło terminów: rejestr, gdy obiekt go ma, w innym razie
   faktury (Przeglądy).
5. **Dochodzi:** `service_order_technicians` (pary), tabela operacji synchronizacji (UUID operacji), liczniki numerów,
   status urządzenia (aktywne/warsztat/skasowane), wersja szablonu typu zapisana w protokole.
6. **Tablet:** osobny punkt wejścia Vite (`serwis.html`, mały pakiet, service worker o zasięgu `/serwis/`), to samo API.
   HTTPS obowiązkowy. `navigator.storage.persist()`, zdjęcia zmniejszane do ~1600 px przed zapisem, aktualizacja nie
   wymuszana przy niepustej kolejce, API przyjmuje wersję payloadu. Synchronizacja przy otwarciu i po `online`.
7. **Logowanie tabletu:** token przypisany do tabletu (nazwa + `expires_at`), zdalne odebranie, tryb sieci pozwalający
   na LTE dla roli Serwisant; wylogowanie nie kasuje niewysłanej kolejki; PIN do odblokowania offline.
8. **Webserwis — zmiany tylko dodające (inna platforma też go używa):** opcjonalne `SprawdzDuplikat=1` (SELECT na
   `CDN.ZamNag` po `ZaN_DokumentObcy` + kontrahent, bez anulowanych → zwraca istniejący ZS z flagą „duplikat”);
   nowe pola odpowiedzi: GID i numer ZS, liczba dodanych pozycji, błędy pozycji (indeks, kod, powód), wynik zamknięcia;
   sprawdzanie `closeResult`; opcjonalne `ZamSeria` i `TrybZamkniecia` (0/2) z listą dozwolonych w `Configuration.xml`;
   uwierzytelnianie wywołań (co najmniej klucz + lista adresów IP). Pilotaż wysyła do bufora.
9. **Backend wysyłki:** job unikalny per rozliczenie, jedna wysyłka naraz, limit czasu > 90 s; po przekroczeniu czasu
   status „nieznany” i sprawdzenie w XL, bez automatycznego ponawiania. Nocny odczyt `ZamNag` śledzi bufor → zatwierdzony
   → FS (wymaga GRANT SELECT na `CDN.ZamNag` dla `przetargi_odczyt`).
10. **Kartoteka serwisowa:** osobna `service_catalog_entries` po (xl_type, xl_gid): opis, normy, lista kontrolna,
    zdjęcia, PDF; dla towarów odnośnik do istniejącej karty produktu. Po MVP.
11. **Sondy w etapie 0:** SELECT na `CDN.ZamNag`, powiązanie ZS→FS, format `CenaUzgodniona` i zachowanie przy pustej
    cenie, `ext-soap` na produkcji (albo koperta SOAP przez klienta HTTP), trasa sieciowa Linux → IIS.

### 10.1 Nowa kolejność etapów
0. Warsztat z działem usług + sondy XL + zmiany webserwisu (pkt 8) przetestowane do bufora.
1. **MVP end-to-end (pilotaż 1 serwisanta, 2 tygodnie):** z wiersza Przeglądów „Utwórz zlecenie” (pozycje UPR
   z ilościami z ostatniego razu, oddział, serwisant, data, adres) → tablet: lista pozycji zbiorczych z korektą ilości,
   dodanie usług/towarów z wyszukiwarki, usterki tekstem, zdjęcia, podpis klienta i serwisanta, szkic zapisany lokalnie
   → serwer: PDF + mail do klienta → kierownik: lista, rozliczenie, „Do XL” (bufor, magazyn oddziału, kontrola
   duplikatu) → faktura z XL wraca do Przeglądów i liczy następny termin.
2. Pełny offline (service worker, kolejka) — zakres według pomiaru braku zasięgu z pilotażu.
3. Obiekty i rejestr urządzeń (pojedyncze sztuki, pomiary hydrantów, historia), import z Excela, kalendarz serwisantów.
4. Kartoteka serwisowa ze zdjęciami.
5. Nieudana wizyta / przełożenie, podpis zdalny linkiem, warsztat (zabranie do remontu/UDT, sprzęt zastępczy),
   kasacja, rozliczenia zbiorcze, naklejki QR, wykaz sprzętu obiektu dla klienta, raporty.

### 10.2 Etapy procesu dopisane po recenzji (do omówienia z działem)
Warsztat i legalizacja UDT, kasacja/utylizacja, sprzedaż z samochodu (stany auta, MM), brak dostępu do części
urządzeń → zlecenie uzupełniające, klient nieobecny / przełożenie, brak podpisu na miejscu, nowy klient bez karty XL,
dojazd jako pozycja, anulowanie/korekta ZS po wysyłce, naklejki z datą, wykaz sprzętu dla ubezpieczyciela i straży,
retencja protokołów, zgubiony tablet, widoczność (serwisant — swoje zlecenia, kierownik — swój oddział).
