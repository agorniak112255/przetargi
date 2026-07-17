# SUPON AI -- Product Requirements Document

## Część 1 -- Wizja produktu i założenia

# 1. Cel projektu

## Nazwa

**SUPON AI -- Platforma do zarządzania przetargami i ofertami BHP
wspomagana AI**

## Problem

Obecny proces przygotowania ofert jest rozproszony i wymaga ręcznej
analizy PDF, Excela, cenników producentów oraz doboru zamienników.

## Cel biznesowy

Skrócenie przygotowania oferty z kilku godzin lub dni do kilkunastu
minut, przy zachowaniu pełnej kontroli przez handlowca.

# 2. Role

  Osoba      Rola                    Zakres
  ---------- ----------------------- --------------------------------------
  Tomek      Dyrektor                Akceptacja ofert, KPI, rentowność
  Krzysiek   Kierownik               Przydział zadań, kontrola jakości
  Wojtek     Przetargi / Marketing   Import dokumentów, analiza SIWZ
  Arek       Handlowiec              Dobór produktów, wycena
  Justyna    Handlowiec              Dobór produktów, wycena
  Artur      IT / Administrator      AI, integracje, cenniki, użytkownicy

# 3. Moduły

-   Dashboard
-   Przetargi
-   Import dokumentów
-   Analiza SIWZ
-   Produkty
-   Zamienniki
-   Cenniki producentów
-   AI Copilot
-   Raporty
-   Administracja

# 4. Workflow

1.  Odbiór wiadomości e-mail.
2.  Import PDF/XLSX/DOCX/ZIP.
3.  OCR i analiza dokumentów.
4.  Rozpoznanie pozycji przetargowych.
5.  Dopasowanie produktów.
6.  Dobór zamienników.
7.  Kalkulacja cen.
8.  Akceptacja handlowca.
9.  Akceptacja kierownika.
10. Akceptacja dyrektora.
11. Generowanie Excel i PDF.
12. Archiwizacja.

# 5. Produkty

Każdy produkt posiada: - producent - kod producenta - EAN - nazwę -
opis - zdjęcia - normy - certyfikaty - rozmiary - kolor - cenę
katalogową - rabat - cenę zakupu - sugerowaną cenę sprzedaży - historię
cen - stan magazynowy - dokumenty - zamienniki - historię wykorzystania
w ofertach

# 6. Zamienniki

Typy: - Preferowany - Tańszy - Premium - Awaryjny - Sezonowy

Każdy zamiennik posiada: - uzasadnienie AI, - zgodność parametrów, -
zgodność norm, - zgodność certyfikatów, - historię użycia, - autora, -
osobę zatwierdzającą.

# 7. Cenniki producentów

Przechowywane są wszystkie wersje cenników wraz z historią zmian.

Przykładowi producenci: - Delta Plus - Portwest - Cerva - Reis - Uvex -
Ansell - ATG

# 8. Architektura

Frontend: - React - TypeScript - Tailwind - shadcn/ui

Backend: - Laravel lub FastAPI

AI: - vLLM - GLM / Qwen - OCR - Parser PDF - Parser Excel

Baza: - PostgreSQL - pgvector

------------------------------------------------------------------------

To jest Część 1. Kolejne części będą rozwijały każdy moduł do pełnej
specyfikacji.
