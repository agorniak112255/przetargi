# SUPON AI -- Product Requirements Document (PRD) v0.1

## Cel projektu

Stworzenie platformy do obsługi przetargów BHP wspomaganej AI. System ma
automatycznie analizować dokumenty przetargowe, dopasowywać produkty z
cenników producentów, proponować zamienniki, wyliczać ceny i generować
gotowe oferty.

------------------------------------------------------------------------

# Role

  Osoba      Rola
  ---------- -----------------------
  Tomek      Dyrektor
  Krzysiek   Kierownik
  Wojtek     Przetargi / Marketing
  Arek       Handlowiec
  Justyna    Handlowiec
  Artur      IT / Administrator

------------------------------------------------------------------------

# Moduły

1.  Dashboard
2.  Przetargi
3.  Import dokumentów
4.  Analiza SIWZ
5.  Produkty
6.  Zamienniki
7.  Cenniki producentów
8.  AI Copilot
9.  Historia ofert
10. Raporty
11. Administracja

------------------------------------------------------------------------

# Workflow

Mail ↓ Import PDF/XLS/DOCX/ZIP ↓ OCR ↓ Analiza AI ↓ Budowa projektu ↓
Dopasowanie produktów ↓ Dobór zamienników ↓ Kalkulacja cen ↓ Akceptacja
handlowca ↓ Akceptacja kierownika ↓ Akceptacja dyrektora ↓ Generowanie
Excel/PDF ↓ Archiwizacja

------------------------------------------------------------------------

# Dashboard

Widok zarządu: - liczba aktywnych przetargów - wartość ofert -
rentowność - postęp AI - zadania zespołu - ostatnie importy

Widok handlowca: - moje przetargi - zadania - komentarze - oferty do
akceptacji

------------------------------------------------------------------------

# Moduł Przetargi

Lista: - klient - termin - status - liczba pozycji - postęp AI -
odpowiedzialny

Widok projektu: - arkusz pozycji - komentarze - AI - historia -
dokumenty

------------------------------------------------------------------------

# Import

Obsługiwane pliki: - XLSX - XLS - PDF - DOCX - ZIP

Proces: 1. OCR 2. Rozpoznanie tabel 3. Identyfikacja producenta 4.
Aktualizacja bazy 5. Embeddingi

------------------------------------------------------------------------

# Produkty

Każdy produkt posiada:

-   producent
-   kod
-   EAN
-   nazwa
-   opis
-   zdjęcia
-   normy
-   certyfikaty
-   rozmiary
-   kolor
-   cena katalogowa
-   rabat
-   cena zakupu
-   sugerowana cena sprzedaży
-   historia cen
-   dostępność
-   stan magazynu
-   powiązane oferty
-   zamienniki

------------------------------------------------------------------------

# Zamienniki

Typy: - preferowany - tańszy - premium - awaryjny

Każdy posiada uzasadnienie AI.

------------------------------------------------------------------------

# Cenniki producentów

Dla każdego: - historia wersji - liczba produktów - zmiany cen - nowe
produkty - usunięte produkty

Import automatyczny z: - udziału sieciowego - SharePoint - OneDrive -
FTP - API

------------------------------------------------------------------------

# AI Copilot

Funkcje: - analiza SIWZ - analiza PDF - dobór produktów - dobór
zamienników - wykrywanie braków - analiza marży - uzasadnienia decyzji

------------------------------------------------------------------------

# Architektura

Frontend: - React - Tailwind - shadcn/ui

Backend: - Laravel lub FastAPI

AI: - vLLM - Qwen / GLM - OCR

Baza: - PostgreSQL - pgvector lub Qdrant

------------------------------------------------------------------------

# Roadmap

Etap 1 - UX - prototyp

Etap 2 - frontend

Etap 3 - backend

Etap 4 - AI

Etap 5 - produkcja

------------------------------------------------------------------------

To jest dokument startowy. Każdy rozdział będzie rozwijany do pełnej
specyfikacji.
