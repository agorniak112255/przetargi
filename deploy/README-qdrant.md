# Qdrant (RAG / embeddingi)

## Lokalnie (Windows, bez Dockera) — zalecane na tym PC

```powershell
.\deploy\start-qdrant.ps1 -Download   # pierwsze uruchomienie
.\deploy\start-qdrant.ps1             # kolejne
```

API: `http://127.0.0.1:6333`

> Docker Desktop też można zainstalować, ale wymaga WSL2 + wirtualizacji w BIOS.

## Docker (VPS / gdy masz Docker)

```bash
docker compose -f deploy/docker-compose.qdrant.yml up -d
```

## Konfiguracja aplikacji

1. UI → **Ustawienia AI** → sekcja **Wyszukiwanie wektorowe**
2. Włącz, ustaw `qdrant_url` (`http://127.0.0.1:6333`), model embeddings (np. `text-embedding-3-small`)
3. Gdy chat = MiniMax bez embeddings: osobny `embedding_base_url` + klucz
4. **Test Qdrant / embeddings**
5. Pełny reindex:

```bash
cd backend
php artisan products:reindex-embeddings
# albo synchronicznie:
php artisan products:reindex-embeddings --sync --force
```

Indeks aktualizuje się też po enrichmentcie i imporcie cennika (kolejka).

## Wektory kart, których nie ma w bazie

```bash
php artisan products:prune-orphan-vectors          # podgląd: liczba, producenci, przykłady
php artisan products:prune-orphan-vectors --apply  # kasowanie porcjami (--chunk, domyślnie 500)
```

Punkt bez karty nie wraca w wynikach, ale zajmuje miejsce w puli wektorowej. Zostaje po kartach scalonych
przed 25.09.2026 i wtedy, gdy Qdrant nie odpowie przy usuwaniu karty (błąd tylko w logu).
