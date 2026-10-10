<?php

declare(strict_types=1);

namespace App\Services\PriceLists;

use App\Models\PriceList;
use App\Models\PriceListFile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Pliki cenników na dysku (10.10.2026): `price-list-files/{sha256}.{ext}` na dysku local. Ścieżka zależy od treści,
 * więc ten sam plik w dwóch cennikach leży na dysku raz — usuwamy go dopiero, gdy nie wskazuje go żaden wiersz.
 */
final class PriceListFileStore
{
    public const DISK = 'local';

    public const DIRECTORY = 'price-list-files';

    /**
     * Zapis pliku do cennika. Nowy plik zastępuje poprzednie pliki `new` tego cennika (superseded) — importuje się
     * najnowszy. Ten sam sha256 w tym cenniku: najnowszy albo starszy zaimportowany → istniejący wiersz (created=false,
     * bez zmian); starszy niezaimportowany → wraca jako najnowszy (revive, created=true).
     *
     * @return array{file: PriceListFile, created: bool}
     */
    public function store(PriceList $list, UploadedFile $upload, ?User $user): array
    {
        $realPath = $upload->getRealPath();
        if ($realPath === false || ! is_file($realPath)) {
            throw new RuntimeException('Nie można odczytać przesłanego pliku.');
        }
        $sha = hash_file('sha256', $realPath);
        if ($sha === false) {
            throw new RuntimeException('Nie można policzyć sumy kontrolnej pliku.');
        }

        $existing = $this->find($list, $sha);
        if ($existing !== null) {
            $latestId = (int) PriceListFile::query()->where('price_list_id', $list->id)->max('id');
            // najnowszy plik — nic się nie zmienia; starszy zaimportowany — konflikt (decyduje kontroler)
            if ((int) $existing->id === $latestId || $existing->status === PriceListFile::STATUS_IMPORTED) {
                return ['file' => $existing, 'created' => false];
            }

            return ['file' => $this->revive($list, $existing, $upload, $user), 'created' => true];
        }

        $ext = self::extensionOf($upload->getClientOriginalName());
        $name = $sha.($ext !== '' ? '.'.$ext : '');
        $path = self::DIRECTORY.'/'.$name;
        $disk = Storage::disk(self::DISK);
        // ten sam plik mógł już trafić do innego cennika — treść jest ta sama, nie przepisujemy
        $written = false;
        if (! $disk->exists($path)) {
            if ($disk->putFileAs(self::DIRECTORY, $upload, $name) === false) {
                throw new RuntimeException('Nie udało się zapisać pliku cennika na dysku.');
            }
            $written = true;
        }

        try {
            $file = DB::transaction(function () use ($list, $upload, $user, $sha, $path): PriceListFile {
                PriceListFile::query()
                    ->where('price_list_id', $list->id)
                    ->where('status', PriceListFile::STATUS_NEW)
                    ->update(['status' => PriceListFile::STATUS_SUPERSEDED]);

                return PriceListFile::query()->create([
                    'price_list_id' => $list->id,
                    'sha256' => $sha,
                    'disk' => self::DISK,
                    'path' => $path,
                    'original_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
                    'size' => (int) $upload->getSize(),
                    'mime' => mb_substr((string) ($upload->getClientMimeType() ?: ''), 0, 100) ?: null,
                    'uploaded_by' => $user?->id,
                    'status' => PriceListFile::STATUS_NEW,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // ten sam plik wysłany dwa razy naraz — wygrywa pierwszy zapis
            $existing = $this->find($list, $sha);
            if ($existing === null) {
                throw new RuntimeException('Nie udało się zapisać pliku cennika.');
            }

            return ['file' => $existing, 'created' => false];
        } catch (Throwable $e) {
            // bez wiersza plik na dysku byłby sierotą — usuwamy tylko to, co zapisał ten przebieg
            if ($written) {
                $this->deleteUnreferenced([['disk' => self::DISK, 'path' => $path]]);
            }
            throw $e;
        }

        return ['file' => $file, 'created' => true];
    }

    /**
     * Starszy, niezaimportowany plik wgrany ponownie (np. po pomyłce z nowszym plikiem B) wraca jako najnowszy:
     * w jednej transakcji stary wiersz znika, pozostałe `new` → superseded i powstaje nowy wiersz z tym samym sha
     * i ścieżką (plik na dysku zostaje). Nowy id, bo stan cennika liczy się z najnowszego pliku.
     */
    private function revive(PriceList $list, PriceListFile $existing, UploadedFile $upload, ?User $user): PriceListFile
    {
        // w trakcie importu wiersz pliku nie może zniknąć (runner zapisałby wynik do usuniętego wiersza) — ta sama
        // blokada co import, trzymana do końca przywracania
        $lock = Cache::lock(PriceListIntakeRunner::LOCK_PREFIX.$list->id, 60);
        if (! $lock->get()) {
            throw new IntakeBusy('Import tego cennika trwa — plik można przywrócić po jego zakończeniu.');
        }
        try {
            return $this->reviveLocked($list, $existing, $upload, $user);
        } finally {
            $lock->release();
        }
    }

    private function reviveLocked(PriceList $list, PriceListFile $existing, UploadedFile $upload, ?User $user): PriceListFile
    {
        $diskName = (string) ($existing->disk ?: self::DISK);
        $path = (string) $existing->path;
        $disk = Storage::disk($diskName);
        // plik mógł zniknąć z dysku — treść jest ta sama, odtwarzamy go z uploadu
        if (! $disk->exists($path) && $disk->putFileAs(dirname($path), $upload, basename($path)) === false) {
            throw new RuntimeException('Nie udało się zapisać pliku cennika na dysku.');
        }

        return DB::transaction(function () use ($list, $existing, $upload, $user, $diskName, $path): PriceListFile {
            $existing->delete();
            PriceListFile::query()
                ->where('price_list_id', $list->id)
                ->where('status', PriceListFile::STATUS_NEW)
                ->update(['status' => PriceListFile::STATUS_SUPERSEDED]);

            return PriceListFile::query()->create([
                'price_list_id' => $list->id,
                'sha256' => (string) $existing->sha256,
                'disk' => $diskName,
                'path' => $path,
                'original_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
                'size' => (int) $upload->getSize(),
                'mime' => mb_substr((string) ($upload->getClientMimeType() ?: ''), 0, 100) ?: null,
                'uploaded_by' => $user?->id,
                'status' => PriceListFile::STATUS_NEW,
            ]);
        });
    }

    /** Ścieżka absolutna pliku na dysku — wejście importera (PriceListImporter::read). */
    public function absolutePath(PriceListFile $file): string
    {
        $disk = Storage::disk((string) ($file->disk ?: self::DISK));
        if (! $disk->exists((string) $file->path)) {
            throw new RuntimeException('Brak pliku cennika na dysku: '.$file->path.' ('.$file->original_name.').');
        }

        return $disk->path((string) $file->path);
    }

    /**
     * Pliki cennika (dysk + ścieżka) — zbierane PRZED usunięciem cennika, bo wiersze znikają kaskadą.
     *
     * @return list<array{disk: string, path: string}>
     */
    public function pathsOf(PriceList $list): array
    {
        return PriceListFile::query()
            ->where('price_list_id', $list->id)
            ->get(['disk', 'path'])
            ->map(static fn (PriceListFile $f): array => ['disk' => (string) ($f->disk ?: self::DISK), 'path' => (string) $f->path])
            ->unique(static fn (array $ref): string => $ref['disk']."\n".$ref['path'])
            ->values()
            ->all();
    }

    /**
     * Usuwa z dysku pliki, których nie wskazuje już żaden wiersz price_list_files (po usunięciu cennika).
     *
     * @param  list<array{disk: string, path: string}>  $refs
     * @return int liczba usuniętych plików
     */
    public function deleteUnreferenced(array $refs): int
    {
        $deleted = 0;
        foreach ($refs as $ref) {
            $path = trim($ref['path']);
            if ($path === '' || ! str_starts_with($path, self::DIRECTORY.'/')) {
                continue;
            }
            $stillUsed = PriceListFile::query()
                ->where('disk', $ref['disk'])
                ->where('path', $path)
                ->exists();
            if ($stillUsed) {
                continue;
            }
            $disk = Storage::disk($ref['disk']);
            if ($disk->exists($path) && $disk->delete($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public static function extensionOf(string $originalName): string
    {
        // „plik..pdf” i wielkie litery jak w PriceListImportController::extensionOf
        return preg_match('/\.([a-z0-9]+)$/', mb_strtolower(trim($originalName)), $m) === 1 ? $m[1] : '';
    }

    private function find(PriceList $list, string $sha): ?PriceListFile
    {
        return PriceListFile::query()
            ->where('price_list_id', $list->id)
            ->where('sha256', $sha)
            ->first();
    }
}
