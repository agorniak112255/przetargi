<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BrandDictionaryEntry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Słownik producentów i marek z administracji. Trzyma wyłącznie nadpisania — producenci z katalogu
 * (products.manufacturer) liczeni są na bieżąco w CatalogManufacturerContext, więc import cennika
 * nie wymaga synchronizacji.
 *
 * Trzy rodzaje wpisu:
 * - marka należąca do producenta („Peltor” → 3M): wyłuskiwana z zapytania i tłumaczona na producenta,
 *   bo karty nazywają producenta, a nie podmarkę („3M Optime III H540A” to Peltor);
 * - producent: pozwala wyłączyć wyłuskiwanie nazwy z tekstu („BHP” — 3 karty producenta, a słowo
 *   stoi w nazwach 2273 kart; „odzież BHP” zawężałoby pulę do tych trzech);
 * - wykluczenie: słowo, które nigdy nie jest marką („kask” — dziś wpada do zbioru marek z kluczy
 *   konfiguracji domen).
 *
 * Pusty słownik nie zmienia niczego: zbiór marek zostaje dokładnie taki jak z konfiguracji.
 *
 * Instancja jest jedna na proces (singleton). Worker kolejki żyje godzinami, więc co RECHECK_SECONDS
 * sprawdza w pamięci podręcznej aplikacji numer wersji podbijany przy każdym zapisie w panelu.
 * Zmienna statyczna, jak dotąd w ProductModelFuzzy, pokazywałaby słownik z chwili startu workera.
 */
final class BrandDictionary
{
    private const VERSION_KEY = 'brand_dictionary.version';

    private const RECHECK_SECONDS = 30;

    /** @var array{detect: array<string, true>, suppress: array<string, true>, producers: array<string, string>}|null */
    private ?array $data = null;

    private string $version = '';

    private float $checkedAt = 0.0;

    /**
     * Klucz słowa: małe litery, polskie znaki zdjęte, tylko [a-z0-9]. Ta sama postać co compact()
     * w ProductModelFuzzy i CatalogManufacturerContext — inaczej „3M” ze słownika nie spotkałoby „3m”
     * z zapytania.
     */
    public static function key(string $text): string
    {
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

        return preg_replace('/[^a-z0-9]/', '', strtr(mb_strtolower($text), $map)) ?? '';
    }

    /** Po zapisie w panelu: nowa wersja dla innych procesów, a ten przeładuje słownik od razu. */
    public static function forget(): void
    {
        try {
            Cache::forever(self::VERSION_KEY, uniqid('', true));
        } catch (Throwable) {
            // bez pamięci podręcznej inne procesy zobaczą zmianę dopiero po restarcie — ten zobaczy od razu
        }
        try {
            app(self::class)->reset();
        } catch (Throwable) {
            // poza aplikacją (czysty test jednostkowy) nie ma czego czyścić
        }
    }

    public function reset(): void
    {
        $this->data = null;
        $this->checkedAt = 0.0;
    }

    /**
     * Klucze marek i producentów, które wyłuskujemy z tekstu zapytania.
     *
     * @return array<string, true>
     */
    public function detectable(): array
    {
        return $this->data()['detect'];
    }

    /**
     * Klucze, których nigdy nie traktujemy jako marki w zapytaniu: wykluczenia oraz producenci i marki
     * z wyłączonym wyłuskiwaniem.
     *
     * @return array<string, true>
     */
    public function suppressed(): array
    {
        return $this->data()['suppress'];
    }

    /** Producent (kanoniczna wartość products.manufacturer), do którego należy marka o tym kluczu. */
    public function producerFor(string $key): ?string
    {
        return $this->data()['producers'][$key] ?? null;
    }

    /**
     * @return array{detect: array<string, true>, suppress: array<string, true>, producers: array<string, string>}
     */
    private function data(): array
    {
        $now = microtime(true);
        if ($this->data !== null && $now - $this->checkedAt < self::RECHECK_SECONDS) {
            return $this->data;
        }
        $version = $this->currentVersion();
        $this->checkedAt = $now;
        if ($this->data !== null && $version === $this->version) {
            return $this->data;
        }
        $this->version = $version;
        $this->data = $this->load();

        return $this->data;
    }

    private function currentVersion(): string
    {
        try {
            return (string) Cache::get(self::VERSION_KEY, '0');
        } catch (Throwable) {
            return '0';
        }
    }

    /**
     * @return array{detect: array<string, true>, suppress: array<string, true>, producers: array<string, string>}
     */
    private function load(): array
    {
        $out = ['detect' => [], 'suppress' => [], 'producers' => []];
        try {
            if (! Schema::hasTable('brand_dictionary_entries')) {
                return $out;
            }
            $entries = BrandDictionaryEntry::query()->get();
        } catch (Throwable) {
            // baza bez migracji albo niedostępna — słownik pusty, zbiór marek jak z konfiguracji
            return $out;
        }

        foreach ($entries as $entry) {
            $key = (string) $entry->term_key;
            if ($key === '') {
                continue;
            }
            // Marka tłumaczy się na producenta także wtedy, gdy nie wyłuskujemy jej z zapytania —
            // producenta zgadniętego przez model też trzeba umieć przypisać.
            if ($entry->kind === BrandDictionaryEntry::KIND_BRAND && trim((string) $entry->manufacturer) !== '') {
                $out['producers'][$key] = trim((string) $entry->manufacturer);
            }
            if ($entry->kind === BrandDictionaryEntry::KIND_EXCLUSION || ! $entry->detect_in_query) {
                $out['suppress'][$key] = true;

                continue;
            }
            $out['detect'][$key] = true;
        }

        return $out;
    }
}
