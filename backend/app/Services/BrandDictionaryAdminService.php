<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BrandDictionaryEntry;
use App\Models\Product;
use App\Support\BrandDictionary;
use App\Support\CatalogManufacturerContext;
use App\Support\ProductModelFuzzy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Panel słownika producentów i marek: lista wpisów, producenci z katalogu z liczbą kart oraz zapis wpisów.
 * Producenci nie są przepisywani do tabeli — liczymy ich na bieżąco z products.manufacturer, a wpis
 * rodzaju „producent” pojawia się dopiero wtedy, gdy ktoś zmienił mu ustawienie.
 */
final class BrandDictionaryAdminService
{
    public const KIND_LABELS = [
        BrandDictionaryEntry::KIND_PRODUCER => 'Producent',
        BrandDictionaryEntry::KIND_BRAND => 'Marka',
        BrandDictionaryEntry::KIND_EXCLUSION => 'Wykluczenie',
    ];

    public const MESSAGE_TERM_TAKEN = 'To słowo już jest w słowniku.';

    public function __construct(
        private readonly CatalogManufacturerContext $catalog,
        private readonly ProductModelFuzzy $fuzzy,
    ) {}

    /**
     * @return array{entries: list<array<string, mixed>>, producers: list<array<string, mixed>>, kinds: array<string, string>}
     */
    public function overview(): array
    {
        $entries = BrandDictionaryEntry::query()->orderBy('term')->orderBy('id')->get();

        $producerEntries = [];
        foreach ($entries as $entry) {
            if ($entry->kind === BrandDictionaryEntry::KIND_PRODUCER) {
                $producerEntries[$entry->term_key] ??= $entry;
            }
        }

        [$exactCounts, $foldedCounts] = $this->cardCounts();

        $producers = [];
        foreach ($this->catalog->catalogManufacturers() as $name) {
            $key = BrandDictionary::key($name);
            $entry = $producerEntries[$key] ?? null;
            $producers[] = [
                'name' => $name,
                'key' => $key,
                // MySQL grupuje bez rozróżniania wielkości liter i może oddać grupę pod inną pisownią niż
                // DISTINCT w katalogu — wtedy szukamy licznika po nazwie sprowadzonej do małych liter
                'cards_count' => $exactCounts[$name] ?? $foldedCounts[mb_strtolower($name)] ?? 0,
                'entry_id' => $entry?->id,
                // Stan rzeczywisty, a nie „brak wpisu = tak”: domyślnie rozpoznawani są tylko producenci ze zbioru
                // z konfiguracji domen (3M, MSA, Ansell…), a BHP, JSP czy ARDON nie. Pytamy tę samą metodę, której
                // używa wyszukiwarka — ekran ma pokazywać to, co dzieje się z zapytaniem, nie domysł.
                'detect_in_query' => $this->fuzzy->catalogBrands($name) !== [],
            ];
        }

        // nazwy porównujemy jak tekst (strcmp) — `<=>` porównałby „10” i „9” jak liczby
        usort($producers, static fn (array $a, array $b): int => $a['cards_count'] <=> $b['cards_count']
            ?: strcmp(mb_strtolower($a['name']), mb_strtolower($b['name']))
            ?: strcmp($a['name'], $b['name']));

        return [
            'entries' => $entries->map(fn (BrandDictionaryEntry $entry): array => $this->present($entry))->values()->all(),
            'producers' => $producers,
            'kinds' => self::KIND_LABELS,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(BrandDictionaryEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'term' => $entry->term,
            'term_key' => $entry->term_key,
            'kind' => $entry->kind,
            'manufacturer' => $entry->manufacturer,
            'detect_in_query' => (bool) $entry->detect_in_query,
            'note' => $entry->note,
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  dane po walidacji
     */
    public function create(array $data): BrandDictionaryEntry
    {
        $entry = new BrandDictionaryEntry;
        // bez jawnej wartości model po zapisie nie znałby domyślnego true z bazy
        $entry->detect_in_query = true;
        $this->fill($entry, $data);
        $this->save($entry);

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $data  dane po walidacji
     */
    public function update(BrandDictionaryEntry $entry, array $data): BrandDictionaryEntry
    {
        $this->fill($entry, $data);
        $this->save($entry);

        return $entry;
    }

    public function termTaken(string $termKey, ?int $ignoreId = null): bool
    {
        return BrandDictionaryEntry::query()
            ->where('term_key', $termKey)
            ->when($ignoreId !== null, static fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    /**
     * Nazwa producenta dokładnie w pisowni katalogu. Marka musi wskazywać wartość products.manufacturer,
     * bo po niej zawężamy pulę kart — „3m” wpisane w panelu nie trafiłoby w karty „3M”.
     */
    public function canonicalManufacturer(?string $manufacturer): ?string
    {
        $key = BrandDictionary::key((string) $manufacturer);
        if ($key === '') {
            return null;
        }
        foreach ($this->catalog->catalogManufacturers() as $name) {
            if (BrandDictionary::key($name) === $key) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Błąd pola producenta dla wpisu o danym rodzaju albo null, gdy wszystko w porządku. Pozostałe rodzaje
     * producenta nie trzymają (model go czyści), więc nie ma czego sprawdzać.
     */
    public function manufacturerError(?string $kind, ?string $manufacturer): ?string
    {
        if ($kind !== BrandDictionaryEntry::KIND_BRAND) {
            return null;
        }
        if (trim((string) $manufacturer) === '') {
            return 'Podaj producenta, do którego należy marka.';
        }

        return $this->canonicalManufacturer($manufacturer) === null ? 'Nie ma takiego producenta w katalogu.' : null;
    }

    /**
     * Wpis rodzaju „producent” musi wskazywać producenta z katalogu. Pomyłka „Peltor” jako producent zamiast
     * marki byłaby groźna: słowo zaczęłoby być rozpoznawane w zapytaniu, a że żadna karta nie ma producenta
     * „Peltor”, wyszukiwarka uznałaby markę za nieobecną w katalogu — to zeruje producenta i model w intencji
     * i pokazuje handlowcowi „Marki PELTOR nie ma w katalogu”, choć wyroby są.
     */
    public function producerTermError(?string $kind, ?string $term): ?string
    {
        if ($kind !== BrandDictionaryEntry::KIND_PRODUCER) {
            return null;
        }

        return $this->canonicalManufacturer($term) === null
            ? 'Nie ma takiego producenta w katalogu. Jeśli to podmarka (np. Peltor), wybierz rodzaj „Marka” i wskaż producenta.'
            : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fill(BrandDictionaryEntry $entry, array $data): void
    {
        foreach (['term', 'kind', 'note'] as $field) {
            if (array_key_exists($field, $data)) {
                $entry->{$field} = $data[$field];
            }
        }
        if (array_key_exists('detect_in_query', $data)) {
            $entry->detect_in_query = (bool) $data['detect_in_query'];
        }
        if (array_key_exists('manufacturer', $data)) {
            $entry->manufacturer = $data['manufacturer'];
        }
        if ($entry->kind === BrandDictionaryEntry::KIND_BRAND) {
            // walidacja przepuściła tylko producenta z katalogu — zapisujemy jego kanoniczną pisownię
            $entry->manufacturer = $this->canonicalManufacturer($entry->manufacturer) ?? $entry->manufacturer;
        }
        if ($entry->kind === BrandDictionaryEntry::KIND_PRODUCER) {
            // producent też w pisowni katalogu — ekran łączy wpis z wierszem producenta po kluczu
            $entry->term = $this->canonicalManufacturer($entry->term) ?? $entry->term;
        }
    }

    private function save(BrandDictionaryEntry $entry): void
    {
        try {
            $entry->save();
        } catch (UniqueConstraintViolationException) {
            // dwa równoczesne zapisy tego samego słowa: walidacja przepuściła oba, indeks zatrzymał drugi
            throw ValidationException::withMessages(['term' => self::MESSAGE_TERM_TAKEN]);
        }
    }

    /**
     * Liczba kart na producenta jednym zapytaniem (GROUP BY), nie osobnym na każdego producenta.
     *
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    private function cardCounts(): array
    {
        $rows = Product::query()
            ->whereNotNull('manufacturer')
            ->where('manufacturer', '!=', '')
            ->groupBy('manufacturer')
            ->selectRaw('manufacturer, COUNT(*) as cards')
            ->toBase()
            ->get();

        $exact = [];
        $folded = [];
        foreach ($rows as $row) {
            $name = trim((string) $row->manufacturer);
            if ($name === '') {
                continue;
            }
            $exact[$name] = ($exact[$name] ?? 0) + (int) $row->cards;
            $lower = mb_strtolower($name);
            $folded[$lower] = ($folded[$lower] ?? 0) + (int) $row->cards;
        }

        return [$exact, $folded];
    }
}
