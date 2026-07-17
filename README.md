# SUPON AI — Przetargi

Solid stack: **Laravel 12 API** (`backend/`) + **React + TypeScript + Vite + Tailwind** (`frontend/`).

## Start lokalny

```bash
# API (SQLite out of the box)
cd backend
php artisan serve

# Frontend (drugi terminal)
cd frontend
npm run dev
```

- Frontend: http://localhost:5173  
- API: http://127.0.0.1:8000/api  

**Login demo:** `arek@supon.local` / `password`

## Co jest zrobione

- Auth (Sanctum token)
- Dashboard, przetargi (+ szczegóły: pozycje / zamienniki / oferta / import / workflow)
- **Nowy przetarg** — formularz na liście przetargów
- **Edycja oferty** — produkt główny, ilość, cena (status `draft` / `wycena`)
- **Import XLSX** — zakładka Import (przykład: `samples/import_pozycje_demo.xlsx`)
- **Dopasowanie AI** — scoring tekstu SIWZ ↔ produkty (normy, słowa kluczowe)
- **Eksport** — Excel + PDF oferty
- **Workflow** — szkic → wycena → kierownik → dyrektor → zatwierdzona (+ historia)
- Akceptacja zamienników (kierownik / dyrektor)
- Produkty, klienci, pomoc
- Seeder: `arek@supon.local` / `password` (także krzysiek@, tomek@, …)

## Dokumentacja produktowa

Pliki `SUPON_AI_*.md` / `.docx` oraz mock `SUPON_AI_Koncepcja_Handel.html` w katalogu głównym.
