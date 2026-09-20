<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\RequirementCheck\En388Code;
use Carbon\CarbonImmutable;

/**
 * Normy odczytane z karty wyrobu u JEGO producenta — kolumna products.manufacturer_norms.
 *
 * PO CO: poziomy EN 388 na kartach rękawic pochodziły dotąd z opisu wzbogacanego ze sklepów, bo w hierarchii
 * źródeł opisu sklep stoi nad witryną producenta (ProductEnrichmentService::descriptionSourceScore). Sprawdzenie
 * 54 kart ATG z 20.09.2026: 25 kart miało kod EN 388 inny niż karta producenta — 12 przekręcony (34-274 jako
 * „4121A” zamiast „3121A”), 5 nieczytelny („3”, „4-1-3-1-A”), 8 nie miało go wcale. Kod EN 388 decyduje
 * o dopasowaniu do wymagania przetargu, więc musi pochodzić od autora wyrobu, a nie z cudzej karty w sklepie.
 *
 * Kolumna trzyma DWIE warstwy:
 * - `rows` — pary dosłownie z karty producenta (etykieta normy i wartość), do cytowania przy weryfikacji
 *   wymagania; to jest „wprost w źródle”, razem z adresem strony i datą odczytu w `source`,
 * - `en388` i `normy_en` — odczyt z tych par na potrzeby dopasowania (BhpAttributeNormalizer).
 *
 * Czego tu nie ma, tego nie było w źródle: brak wartości = brak klucza, nigdy pusty string ani „-”.
 * Kodu, którego En388Code nie potwierdzi, nie zapisujemy wcale — wtedy karta zostaje bez poziomów producenta,
 * a nie z wartością, której nie umiemy odczytać.
 *
 * @phpstan-type NormFactsColumn array{
 *     source: array{connector: string, brand: string, url: string, synced_at: string},
 *     rows: list<array{label: string, value?: string}>,
 *     en388?: string,
 *     normy_en?: list<string>
 * }
 */
final class ManufacturerNormFacts
{
    /** Tyle norm ile na kartę — tyle samo, ile dopuszcza kolumna products.norms. */
    private const MAX_NORMS = 8;

    private const MAX_ROWS = 20;

    private const MAX_LABEL = 120;

    private const MAX_VALUE = 60;

    /**
     * Zawartość kolumny z par odczytanych przez łącznik producenta; null, gdy karta nie podała ani jednej pary.
     *
     * @param  list<array{label: string, value: string|null}>  $facts  pary dosłownie ze źródła
     */
    public static function build(
        array $facts,
        string $connector,
        string $brand,
        string $url,
        ?CarbonImmutable $syncedAt = null,
    ): ?array {
        $rows = [];
        foreach ($facts as $fact) {
            $label = trim($fact['label']);
            if ($label === '' || count($rows) >= self::MAX_ROWS) {
                continue;
            }
            $value = trim((string) ($fact['value'] ?? ''));
            $row = ['label' => mb_substr($label, 0, self::MAX_LABEL)];
            if ($value !== '') {
                $row['value'] = mb_substr($value, 0, self::MAX_VALUE);
            }
            $rows[] = $row;
        }

        if ($rows === []) {
            return null;
        }

        $column = [
            'source' => [
                'connector' => $connector,
                'brand' => $brand,
                'url' => mb_substr($url, 0, 2000),
                'synced_at' => ($syncedAt ?? CarbonImmutable::now())->toIso8601String(),
            ],
            'rows' => $rows,
        ];

        $en388 = self::readEn388($rows);
        if ($en388 !== null) {
            $column['en388'] = $en388;
        }
        $norms = self::readNorms($rows);
        if ($norms !== []) {
            $column['normy_en'] = $norms;
        }

        return $column;
    }

    /**
     * Slot dla BhpAttributeNormalizer: to, co producent podał wprost, w formie gotowej do scalenia.
     *
     * @param  mixed  $column  zawartość products.manufacturer_norms
     * @return array{en388?: string, normy: list<string>}
     */
    public static function context(mixed $column): array
    {
        if (! is_array($column)) {
            return ['normy' => []];
        }

        $out = ['normy' => self::norms($column)];
        $en388 = $column['en388'] ?? null;
        if (is_string($en388) && trim($en388) !== '') {
            $out['en388'] = trim($en388);
        }

        return $out;
    }

    /**
     * @param  mixed  $column  zawartość products.manufacturer_norms
     * @return list<string>
     */
    public static function norms(mixed $column): array
    {
        if (! is_array($column) || ! is_array($column['normy_en'] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($column['normy_en'] as $norm) {
            if (is_string($norm) && trim($norm) !== '') {
                $out[] = trim($norm);
            }
        }

        return $out;
    }

    /**
     * Pary dosłownie z karty producenta — do cytowania przy weryfikacji wymagania.
     *
     * @param  mixed  $column  zawartość products.manufacturer_norms
     * @return list<array{label: string, value: string}> wartość '' = norma bez poziomu (sama zgodność)
     */
    public static function rows(mixed $column): array
    {
        if (! is_array($column) || ! is_array($column['rows'] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($column['rows'] as $row) {
            if (! is_array($row) || ! is_string($row['label'] ?? null)) {
                continue;
            }
            $value = $row['value'] ?? '';
            $out[] = [
                'label' => trim($row['label']),
                'value' => is_string($value) ? trim($value) : '',
            ];
        }

        return $out;
    }

    /**
     * Czy zapisana kolumna niesie te same fakty co świeży odczyt — bez daty odczytu, bo ta należy do par,
     * a nie do przebiegu. Równe fakty = nie ma czego zapisywać (i po co zlecać reindeks wektora).
     *
     * @param  mixed  $stored  zawartość products.manufacturer_norms
     * @param  array<string, mixed>  $fresh  wynik build()
     */
    public static function sameFacts(mixed $stored, array $fresh): bool
    {
        if (! is_array($stored)) {
            return false;
        }

        $comparable = static function (array $column): array {
            unset($column['source']['synced_at']);

            return $column;
        };

        return $comparable($stored) == $comparable($fresh);
    }

    /** Adres karty producenta, z której pochodzą pary; null gdy kolumna pusta. */
    public static function sourceUrl(mixed $column): ?string
    {
        $url = is_array($column) ? ($column['source']['url'] ?? null) : null;

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    /**
     * Kod EN 388 z par — tylko gdy etykieta jest tą normą, a wartość da się odczytać jako jej kod.
     * „3”, „4-1-3-1-A” czy poziom EN 407 wpisany przy EN 388 nie przechodzą: karta zostaje bez poziomów.
     *
     * @param  list<array{label: string, value?: string}>  $rows
     */
    private static function readEn388(array $rows): ?string
    {
        foreach ($rows as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            // Etykieta musi być normą EN 388 (a nie ANSI/ISEA czy EN 407), i to ona wyznacza fragment,
            // w którym En388Code szuka kodu — dlatego sprawdzamy sklejkę etykiety z wartością.
            $code = En388Code::first($row['label'].' '.$value);
            if ($code === null) {
                continue;
            }

            return $value;
        }

        return null;
    }

    /**
     * Lista norm z par: oznaczenie razem z poziomem, gdy sklejka też jest czytelna jako norma
     * („EN 388:2016 + A1:2018 4331B”), inaczej samo oznaczenie („EN ISO 374-1” przy literach KLMNOP,
     * „EN ISO 21420” bez poziomu). ANSI/ISEA normą EN nie jest — zostaje w `rows`, na liście norm go nie ma.
     *
     * @param  list<array{label: string, value?: string}>  $rows
     * @return list<string>
     */
    private static function readNorms(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (count($out) >= self::MAX_NORMS) {
                break;
            }
            $label = trim($row['label']);
            if (! NormCode::looksLikeNorm($label)) {
                continue;
            }
            $value = trim((string) ($row['value'] ?? ''));
            $joined = $value === '' ? $label : $label.' '.$value;
            $out[] = NormCode::looksLikeNorm($joined) ? $joined : $label;
        }

        return NormCode::dedupe($out);
    }
}
