<?php

declare(strict_types=1);

use App\Support\CatalogSlangDictionary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kopia słownika żargonu w `ai_settings.catalog_slang` była zapisywana z `jargon=true`
 * dla każdego wpisu (stare `CatalogSlangDictionary::normalize()` ignorowało flagę),
 * więc sanitizer kroków wycinał cechy techniczne (S5, antyprzebiciowe) jak żargon.
 * Odtwarzamy flagę z domyślnych wpisów (`config/catalog_slang.php`): wpis domyślny
 * bez zmian admina zostaje podmieniony na aktualny domyślny, wpis domyślny zmieniony
 * przez admina dostaje tylko flagę z domyślnych, własny wpis admina zostaje żargonem.
 * Wpis „półmaska → półmaska wielorazowa” (usunięty z domyślnych) znika też z kopii.
 */
return new class extends Migration
{
    /** @var list<array{category: string, terms: list<string>, phrases: list<string>}> */
    private const REMOVED_DEFAULTS = [
        ['category' => 'oddech', 'terms' => ['półmaska'], 'phrases' => ['półmaska wielorazowa']],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ai_settings') || ! Schema::hasColumn('ai_settings', 'catalog_slang')) {
            return;
        }
        $defaults = CatalogSlangDictionary::defaults();
        foreach ($this->rows() as $row) {
            $this->store((int) $row->id, $this->restore($this->decode($row->catalog_slang), $defaults));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_settings') || ! Schema::hasColumn('ai_settings', 'catalog_slang')) {
            return;
        }
        foreach ($this->rows() as $row) {
            $entries = $this->decode($row->catalog_slang);
            $hasRemoved = false;
            foreach ($entries as &$entry) {
                $entry['jargon'] = true;
                foreach (self::REMOVED_DEFAULTS as $removed) {
                    if ($this->sameEntry($entry, $removed)) {
                        $hasRemoved = true;
                    }
                }
            }
            unset($entry);
            if (! $hasRemoved) {
                foreach (self::REMOVED_DEFAULTS as $removed) {
                    $entries[] = $removed + ['note' => '', 'jargon' => true, 'keywords' => [], 'tags' => []];
                }
            }
            $this->store((int) $row->id, CatalogSlangDictionary::normalize($entries));
        }
    }

    /**
     * @return iterable<object{id: int|string, catalog_slang: mixed}>
     */
    private function rows(): iterable
    {
        return DB::table('ai_settings')
            ->select(['id', 'catalog_slang'])
            ->whereNotNull('catalog_slang')
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter($raw, 'is_array'));
        }
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function store(int $id, array $entries): void
    {
        DB::table('ai_settings')->where('id', $id)->update([
            'catalog_slang' => json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  list<array<string, mixed>>  $defaults
     * @return list<array<string, mixed>>
     */
    private function restore(array $entries, array $defaults): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if ($this->isRemovedDefault($entry)) {
                continue;
            }
            $default = $this->matchingDefault($entry, $defaults);
            if ($default === null) {
                $entry['jargon'] = true;
                $out[] = $entry;

                continue;
            }
            if ($this->isUnmodifiedDefault($entry, $default)) {
                $out[] = $default;

                continue;
            }
            $entry['jargon'] = (bool) $default['jargon'];
            $out[] = $entry;
        }

        return CatalogSlangDictionary::normalize($out);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isRemovedDefault(array $entry): bool
    {
        foreach (self::REMOVED_DEFAULTS as $removed) {
            if ($this->sameEntry($entry, $removed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $other
     */
    private function sameEntry(array $entry, array $other): bool
    {
        return ($entry['category'] ?? null) === ($other['category'] ?? null)
            && $this->foldList($entry['terms'] ?? null) === $this->foldList($other['terms'] ?? null)
            && $this->foldList($entry['phrases'] ?? null) === $this->foldList($other['phrases'] ?? null);
    }

    /**
     * Wpis z kopii odpowiada domyślnemu, gdy ma tę samą kategorię i dzieli z nim
     * termin albo ma identyczne frazy (terminy domyślne mogły zostać rozszerzone).
     *
     * @param  array<string, mixed>  $entry
     * @param  list<array<string, mixed>>  $defaults
     * @return array<string, mixed>|null
     */
    private function matchingDefault(array $entry, array $defaults): ?array
    {
        $terms = $this->foldList($entry['terms'] ?? null);
        $phrases = $this->foldList($entry['phrases'] ?? null);
        foreach ($defaults as $default) {
            if (($default['category'] ?? null) !== ($entry['category'] ?? null)) {
                continue;
            }
            $defaultTerms = $this->foldList($default['terms'] ?? null);
            if (array_intersect($terms, $defaultTerms) !== [] || ($phrases !== [] && $phrases === $this->foldList($default['phrases'] ?? null))) {
                return $default;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $default
     */
    private function isUnmodifiedDefault(array $entry, array $default): bool
    {
        $terms = $this->foldList($entry['terms'] ?? null);
        $defaultTerms = $this->foldList($default['terms'] ?? null);

        return array_diff($terms, $defaultTerms) === []
            && $this->foldList($entry['phrases'] ?? null) === $this->foldList($default['phrases'] ?? null)
            && trim((string) ($entry['note'] ?? '')) === trim((string) ($default['note'] ?? ''))
            && $this->foldList($entry['keywords'] ?? null) === $this->foldList($default['keywords'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function foldList(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = mb_strtolower(trim($item));
            }
        }

        return array_values(array_unique($out));
    }
};
