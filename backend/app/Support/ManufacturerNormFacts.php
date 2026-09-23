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
    /**
     * `source.connector` par odczytanych przy wzbogacaniu z ramki norm na karcie wyrobu w witrynie producenta
     * (MAPA: „EN 388 → 1121X”). Pary łącznika B2B producenta (ATG) mają pierwszeństwo — tych wzbogacanie nie
     * nadpisuje (replaceableFromWebPage).
     */
    public const WEB_PAGE_CONNECTOR = 'strona-producenta';

    /** Tyle norm ile na kartę — tyle samo, ile dopuszcza kolumna products.norms. */
    private const MAX_NORMS = 8;

    private const MAX_ROWS = 20;

    private const MAX_LABEL = 120;

    /**
     * Poziomy bywają podane słownie w całości (CXS: „odporność na przetarcie - 2, odporność na przecięcie - 1, …”, ok. 100
     * znaków) — przy 60 znakach para zostawała ucięta w połowie, czyli przestawała być dosłowna.
     */
    private const MAX_VALUE = 240;

    /**
     * Zawartość kolumny z par odczytanych przez łącznik producenta; null, gdy karta nie podała ani jednej pary.
     *
     * @param  list<array{label: string, value: string|null}>  $facts  pary dosłownie ze źródła
     * @param  array<string, mixed>  $provenance  dodatkowe pola `source` (norms:from-manufacturer-pages: kind, final_url,
     *                                            reader, identity — czym bramka potwierdziła stronę, block — dosłowny
     *                                            fragment strony z parami, block_sha256)
     */
    public static function build(
        array $facts,
        string $connector,
        string $brand,
        string $url,
        ?CarbonImmutable $syncedAt = null,
        array $provenance = [],
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
                ...$provenance,
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
     * @return array{en388?: string, normy: list<string>, rows?: list<array{label: string, value: string}>}
     */
    public static function context(mixed $column): array
    {
        if (! is_array($column)) {
            return ['normy' => []];
        }

        // `rows` dla resolveAgainstRows — tym samym rozstrzygnięciem liczy się lista norm przy zapisie i w normalizatorze
        $out = ['normy' => self::norms($column), 'rows' => self::rows($column)];
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

    /**
     * Czy pary ze strony producenta mogą zastąpić zapisaną kolumnę: pusta albo sama pochodzi ze strony. Pary sprawdzone
     * bramką tożsamości (`source.identity` — dokładny kod albo EAN na stronie, norms:from-manufacturer-pages) zastępuje
     * tylko odczyt, który też przeszedł bramkę: wzbogacanie opisu kodu wyrobu na stronie nie wymaga, więc ponowne
     * wzbogacenie nie może po cichu podmienić sprawdzonych norm odczytem z innej strony.
     */
    public static function replaceableFromWebPage(mixed $column, bool $incomingVerified = false): bool
    {
        if (self::rows($column) === []) {
            return true;
        }
        if (! is_array($column) || ($column['source']['connector'] ?? null) !== self::WEB_PAGE_CONNECTOR) {
            return false;
        }

        return $incomingVerified || ! self::verified($column);
    }

    /** Czy pary ze strony producenta przeszły bramkę tożsamości (dokładny kod albo EAN wyrobu na stronie). */
    public static function verified(mixed $column): bool
    {
        return is_array($column) && is_array($column['source']['identity'] ?? null);
    }

    /**
     * Pozycje listy norm z innego źródła (opis ze sklepów), które przeczą producentowi: ta sama norma — albo jej
     * część, „EN 374” wobec „EN 374-1” — przy której producent podał oznaczenie. Butoflex 650: producent „EN 388:
     * 1121X”, sklep „EN 388 (1.1.2.2)” (stary zapis) i „EN 374 (A.B.C.I.K.L)”. Normy, przy której producent nie
     * podał oznaczenia, nie ruszamy — wtedy poziom ze sklepu jest jedynym, jaki mamy, i dedupe go zostawi.
     *
     * @param  list<string>  $norms
     * @param  mixed  $column  zawartość products.manufacturer_norms
     * @return list<string> normy producenta na początku, potem pozycje, które mu nie przeczą
     */
    public static function preferOver(array $norms, mixed $column): array
    {
        return self::resolveAgainstRows($norms, self::rows($column));
    }

    /**
     * Jedno rozstrzygnięcie listy norm wobec par producenta — dla listy zapisywanej przy wzbogacaniu (preferOver)
     * i dla normy_en liczonych przez BhpAttributeNormalizer (dopasowanie, karta, Presta). Dwie osobne reguły dawały
     * dwa wyniki: normalizator zostawiał „EN 374 (A.B.C.I.K.L)” obok „EN ISO 374-1 …” producenta, a wyrzucał
     * „EN 388 4121X”, gdy producent podał samo „EN 388” bez poziomu (recenzja planu norm, 23.09.2026).
     *
     * - Na listę idą pary producenta dosłownie („EN 374-1 Type A ABCILMNOS”) — normy_en zostawia przy EN 374-1 samo
     *   oznaczenie, bo liter nie umie odczytać jako poziomu.
     * - Zapis innego źródła odpada tylko przy normie, przy której producent podał oznaczenie: ta sama norma albo jej
     *   część („EN 374” wobec „EN 374-1”), „EN ISO 374-1” i „EN 374-1” to jedna norma. Para bez wartości („EN 374-5”)
     *   niczego nie wypiera — poziom ze sklepu jest wtedy jedynym, jaki mamy.
     * - Dwa podane i różne wydania (producent „EN 388:2016 …”, sklep „EN 388:2003 4542”) to dwie wartości wyrobu —
     *   zapis innego wydania zostaje. Rok tylko po jednej stronie rozstrzyga producent (jak #112: MAPA „EN 388”
     *   wobec sklepowego „EN 388 (1.1.2.2)”).
     *
     * @param  list<string>  $norms
     * @param  list<array{label: string, value: string}>  $rows  wynik rows()
     * @return list<string> normy producenta na początku, potem pozycje, które mu nie przeczą
     */
    public static function resolveAgainstRows(array $norms, array $rows): array
    {
        $own = [];
        $covered = [];
        foreach ($rows as $row) {
            $family = self::conflictFamily($row['label']);
            if ($family === '') {
                continue;
            }
            $own[] = $row['value'] !== '' ? $row['label'].' '.$row['value'] : $row['label'];
            if ($row['value'] !== '') {
                $covered[] = [$family, self::statedEdition($row['label'])];
            }
        }
        if ($own === []) {
            return $norms;
        }

        $kept = [];
        foreach ($norms as $norm) {
            $family = self::conflictFamily($norm);
            $edition = self::statedEdition($norm);
            $contradicts = $family !== '' && array_filter(
                $covered,
                static fn (array $own): bool => ($own[0] === $family || str_starts_with($own[0], $family.'-'))
                    && ($own[1] === null || $edition === null || $own[1] === $edition)
            ) !== [];
            if (! $contradicts) {
                $kept[] = $norm;
            }
        }

        return NormCode::dedupe([...$own, ...$kept]);
    }

    /**
     * Rok wydania podany wprost przy oznaczeniu normy: „EN 388:2016 + A1:2018 …” → 2016, „EN 388 (2003) 4542” → 2003.
     * Bez roku — null (wydania nie zgadujemy z formatu kodu).
     */
    public static function statedEdition(string $norm): ?string
    {
        $pattern = '/^\s*(?:(?:PN|DIN|BS|NF)[\s\-]+)?(?:EN|ISO|IEC)(?:[\s\-]*(?:ISO|IEC))*[\s\-]*\d{2,6}(?:-\d{1,3})*'
            .'(?:\s?:\s?|\s*\(\s*)((?:19|20)\d{2})(?!\d)/iu';

        return preg_match($pattern, $norm, $m) === 1 ? $m[1] : null;
    }

    /** Rodzina normy do wykrycia sprzeczności — „EN ISO 374-1” i „EN 374-1” to ta sama norma w dwóch zapisach. */
    private static function conflictFamily(string $raw): string
    {
        return str_replace(' ISO ', ' ', NormCode::leadFamily($raw));
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
            // Zapis słowny („odporność na przetarcie 2, odporność na przecięcie 1…”, Canis/CXS) to nie kod: `en388`
            // trafia do dopasowania i porównań jako kod karty, a zdanie w tym polu nigdy by nie trafiło w wymaganie
            // („4331B”) i udawałoby poziom podany kodem. Para zostaje dosłownie w `rows`.
            if ($code === null || $code->worded) {
                continue;
            }

            return self::compactSpacedCode($value, $code);
        }

        return null;
    }

    /**
     * Kod rozstrzelony spacjami albo kropkami („2 1 2 1 X”, „3.1.2.1.X”) — zwarty („2121X”); każda inna wartość
     * bez zmian. PO CO: witryna Delta Plus podaje przy „EN 388” kod ze spacjami, a ProductMatchService szuka
     * `en388` w wymaganiu przetargu bez spacji („4331B”) — rozstrzelony kod nigdy by nie trafił, a porównanie
     * dokładne w ProductCrossRefService rozdzieliłoby ten sam poziom na dwa. Zwieramy tylko, gdy wartość to w całości
     * sam kod (dosłowny zapis kodu z En388Code, czyli z jednolitym separatorem) — kod z dopiskiem zostaje dosłownie.
     * Dosłowny zapis z karty i tak zostaje w `rows`.
     */
    private static function compactSpacedCode(string $value, En388Code $code): string
    {
        if ($code->text !== $value) {
            return $value;
        }

        return preg_replace('/[\h.]/u', '', $value) ?? $value;
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
