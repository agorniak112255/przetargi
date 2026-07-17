# SUPON AI

# SPECYFIKACJA PRODUKTU

## TOM 01 -- WIZJA, ARCHITEKTURA I MODUŁY

Wersja 0.2

------------------------------------------------------------------------

# 1. OPIS PRODUKTU

SUPON AI jest platformą do przygotowywania ofert i przetargów BHP z
wykorzystaniem AI. System analizuje dokumenty klienta, wyszukuje
produkty z cenników producentów, proponuje zamienniki, oblicza ceny i
generuje gotową ofertę.

------------------------------------------------------------------------

# 2. CELE BIZNESOWE

-   Skrócenie przygotowania oferty z wielu godzin do kilkunastu minut.
-   Jedna wspólna baza produktów i cenników.
-   Wspólna praca wielu handlowców.
-   Pełna historia zmian.
-   Centralna baza zamienników.
-   AI wspierające handlowca.

------------------------------------------------------------------------

# 3. ROLE

## Dyrektor (Tomek)

-   KPI
-   rentowność
-   akceptacja ofert
-   raporty
-   podgląd wszystkich projektów

## Kierownik (Krzysiek)

-   przydział zadań
-   kontrola jakości
-   akceptacja zamienników
-   komentarze

## Przetargi / Marketing (Wojtek)

-   tworzenie projektu
-   import dokumentów
-   analiza SIWZ
-   kontakt z klientem

## Handlowiec (Arek)

-   dobór produktów
-   wycena
-   komentarze
-   kontakt z producentami

## Handlowiec (Justyna)

-   identyczne uprawnienia

## IT (Artur)

-   AI
-   RAG
-   import cenników
-   użytkownicy
-   integracje
-   logi

------------------------------------------------------------------------

# 4. GŁÓWNE MENU

1 Dashboard 2 Przetargi 3 Import dokumentów 4 Produkty 5 Zamienniki 6
Cenniki producentów 7 Klienci 8 Raporty 9 AI Copilot 10 Administracja

------------------------------------------------------------------------

# 5. DASHBOARD

Widgety: - aktywne przetargi - wartość ofert - marża - ostatnie
importy - status AI - zadania użytkownika - cenniki wymagające
aktualizacji - liczba produktów - liczba zamienników

Panel "AI Pipeline":

Mail → Import → OCR → Analiza SIWZ → Rozpoznanie pozycji → Dopasowanie
produktów → Dobór zamienników → Kalkulacja → Akceptacja → Generowanie
Excel → Generowanie PDF

Każdy etap posiada: - status - czas wykonania - log - możliwość
ponownego uruchomienia

------------------------------------------------------------------------

# 6. MODUŁ PRZETARGI

Widoki: - Lista - Kanban - Kalendarz - Archiwum

Kolumny: - Numer - Klient - Termin - Wartość - Ilość pozycji - Status -
AI % - Opiekun - Ostatnia aktywność

Po wejściu:

Zakładki: - Dokumenty - Pozycje - Produkty - Zamienniki - AI -
Historia - Komentarze - Oferta - Logi

------------------------------------------------------------------------

# 7. IMPORT

Obsługiwane pliki: - PDF - XLS - XLSX - DOCX - ZIP

Źródła: - dysk - udział sieciowy - OneDrive - SharePoint - FTP - e-mail

Każdy import zapisuje: - autora - datę - czas - wersję - liczbę
rekordów - błędy

------------------------------------------------------------------------

# 8. PRODUKTY

Każdy produkt zawiera:

-   producent
-   kod producenta
-   EAN
-   nazwa
-   opis
-   zdjęcia
-   normy
-   certyfikaty
-   rozmiary
-   kolory
-   ceny
-   rabaty
-   historię cen
-   dostępność
-   magazyn
-   dokumenty
-   zamienniki
-   historię użycia
-   komentarze

------------------------------------------------------------------------

# 9. ZAMIENNIKI

Typ: - preferowany - tańszy - premium - awaryjny

AI zapisuje: - zgodność parametrów - zgodność norm - zgodność
certyfikatów - powód wyboru - ocenę %

------------------------------------------------------------------------

# 10. KOLEJNY TOM

Tom 02 będzie zawierał pełną specyfikację Dashboardu wraz z opisem
wszystkich widgetów, przycisków, ekranów, filtrów i zachowania systemu.
