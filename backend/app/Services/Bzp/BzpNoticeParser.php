<?php

declare(strict_types=1);

namespace App\Services\Bzp;

use App\Models\ProcurementNotice;
use App\Support\CompanyName;
use App\Support\NoticeNumber;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Odczyt ogłoszenia z API Biuletynu Zamówień Publicznych (element listy z /mo-board/api/v1/notice) do postaci
 * zapisu `procurement_notices` — kolumny wprost z pól API, części zamówienia z pełnej treści ogłoszenia (htmlBody).
 *
 * Zasady:
 * - pola treści rozpoznawane po treści etykiety („Liczba otrzymanych ofert lub wniosków:”), nie po numeracji
 *   punktów („6.1.)”), która różni się między rodzajami ogłoszeń; tekst po usunięciu znaczników i zwinięciu spacji;
 * - brak pola albo wartość nieczytelna = null, nigdy zgadywanie (kwota „1.234” bez groszy jest niejednoznaczna → null);
 * - części: nagłówki „Część N” i „SEKCJA … (dla części N)”; ogłoszenie bez nich = jedna część nr 1;
 * - wynik części z treści („…zakończyła się unieważnieniem”), a gdy w treści go nie ma — z pola procedureResult
 *   API (wartości rozdzielone średnikiem, pozycja = numer części; sprawdzone na ogłoszeniu tylko dla części 6 z 6:
 *   „;;;;;uniewaznienie”);
 * - numer ogłoszenia o zamówieniu w ogłoszeniu o wyniku: „Numer ogłoszenia: 2026/BZP …” w sekcji II inny niż
 *   numer samego ogłoszenia.
 *
 * Zmiana sposobu odczytu = podniesienie VERSION; `bzp:fetch --reparse` odczyta zapisane ogłoszenia od nowa.
 */
final class BzpNoticeParser
{
    public const VERSION = 1;

    /** wynik części: zawarto umowę / unieważniona / jeszcze nierozstrzygnięta */
    public const RESULT_AWARDED = 'awarded';

    public const RESULT_CANCELLED = 'cancelled';

    public const RESULT_UNRESOLVED = 'unresolved';

    private const NAME_MAX = 300;

    private const DESCRIPTION_MAX = 2000;

    private const CONTINUATION_MAX_LINES = 60;

    /**
     * Etykiety pól w treści ogłoszenia (początek wiersza po numeracji punktu). Kolejność: dłuższe przed krótszymi.
     */
    private const LABELS = [
        'procedure_value' => 'Wartość zamówienia stanowiącego przedmiot tego postępowania[^:]*:',
        'order_value' => 'Wartość zamówienia\s*:',
        'lot_value' => 'Wartość części\s*:',
        'total_value' => 'Łączna wartość poszczególnych części zamówienia\s*:',
        'notice_number' => 'Numer ogłoszenia\s*:',
        'short_description' => 'Krótki opis przedmiotu zamówienia\s*:?',
        'cpv_main' => 'Główny kod CPV\s*:',
        'cpv_additional' => 'Dodatkowy kod CPV\s*:',
        'result_text' => 'Postępowanie zakończyło się zawarciem umowy albo unieważnieniem postępowania\s*:',
        'offers_count' => 'Liczba otrzymanych ofert lub wniosków\s*:',
        'lowest_price' => 'Cena lub koszt oferty z najniższą ceną lub kosztem\s*:',
        'highest_price' => 'Cena lub koszt oferty z najwyższą ceną lub kosztem\s*:',
        'winner_price' => 'Cena lub koszt oferty wykonawcy, któremu udzielono zamówienia\s*:',
        'contractor_name' => 'Nazwa \(firma\) wykonawcy[^:]*:',
        'national_id' => 'Krajowy Numer Identyfikacyjny\s*:',
        'city' => 'Miejscowość\s*:',
        'procedure_url' => 'Adres strony internetowej prowadzonego postępowania\s*:?',
        'submission_deadline' => 'Termin składania ofert\s*:',
    ];

    /** pola wykonawcy (tylko w sekcji VII ogłoszenia o wyniku) */
    private const CONTRACTOR_FIELDS = ['contractor_name' => 'name', 'national_id' => 'national_id_raw', 'city' => 'city'];

    /** pola, których wartość ciągnie się w kolejnych wierszach także wtedy, gdy zaczyna się w wierszu etykiety */
    private const MULTILINE = ['short_description'];

    /** wartości procedureResult z API */
    private const API_RESULTS = [
        'zawarcieumowy' => self::RESULT_AWARDED,
        'uniewaznienie' => self::RESULT_CANCELLED,
        'nierozstrzygnieto' => self::RESULT_UNRESOLVED,
    ];

    /**
     * @param  array<string, mixed>  $item  element odpowiedzi API Biuletynu
     * @return array<string, mixed> kolumny procurement_notices (bez fetched_at)
     *
     * @throws InvalidArgumentException ogłoszenie bez numeru albo daty publikacji — nie da się go zapisać
     */
    public function parse(array $item): array
    {
        $number = NoticeNumber::parse(self::str($item['noticeNumber'] ?? null));
        if ($number === null || $number['source'] !== NoticeNumber::SOURCE_BZP) {
            throw new InvalidArgumentException('Ogłoszenie bez poprawnego numeru Biuletynu: '.json_encode($item['noticeNumber'] ?? null));
        }
        $publishedAt = self::moment($item['publicationDate'] ?? null);
        if ($publishedAt === null) {
            throw new InvalidArgumentException('Ogłoszenie '.$number['normalized'].' bez daty publikacji.');
        }
        $bzpNumber = NoticeNumber::parse(self::str($item['bzpNumber'] ?? null))['bzp_number'] ?? $number['bzp_number'];

        $html = self::str($item['htmlBody'] ?? null);
        $procedureResult = self::str($item['procedureResult'] ?? null);
        $procedureResults = self::apiResults($procedureResult);
        $apiContractors = self::apiContractors($item['contractors'] ?? null);
        // pozycje procedureResult i contractors odpowiadają numerom części tylko przy tej samej liczbie pozycji
        $apiAligned = $procedureResult !== null && count($apiContractors) === substr_count($procedureResult, ';') + 1;

        $content = $html !== null ? $this->readContent($html) : ['general' => [], 'lots' => []];
        $warnings = [];
        $lots = $this->lots($content, $procedureResults, $apiAligned ? $apiContractors : [], $warnings);
        $general = $content['general'];

        $preceding = null;
        foreach ($general['notice_number'] ?? [] as $value) {
            $parsed = NoticeNumber::parse($value);
            if ($parsed !== null && $parsed['bzp_number'] !== null && $parsed['bzp_number'] !== $bzpNumber) {
                $preceding = $parsed['bzp_number'];
                break;
            }
        }

        $url = null;
        if (isset($general['procedure_url']) && preg_match('~https?://\S+~u', $general['procedure_url'], $m) === 1) {
            $url = mb_substr(rtrim($m[0], '.,;'), 0, 500);
        }

        return [
            'source' => ProcurementNotice::SOURCE_BZP,
            'notice_type' => mb_substr((string) self::str($item['noticeType'] ?? null), 0, 40),
            'notice_number' => $number['normalized'],
            'bzp_number' => $bzpNumber,
            'ocds_id' => self::limit(self::str($item['tenderId'] ?? null), 100),
            'preceding_bzp_number' => $preceding,
            'object_id' => self::limit(self::str($item['objectId'] ?? null), 64),
            'published_at' => $publishedAt,
            'submitting_offers_at' => self::moment($item['submittingOffersDate'] ?? null),
            'order_object' => (string) (self::str($item['orderObject'] ?? null) ?? ''),
            'cpv_codes' => self::cpvList(self::str($item['cpvCode'] ?? null)),
            'organization_name' => mb_substr((string) (self::str($item['organizationName'] ?? null) ?? ''), 0, 500),
            'organization_city' => self::limit(self::str($item['organizationCity'] ?? null), 200),
            'organization_province' => self::limit(self::str($item['organizationProvince'] ?? null), 10),
            'organization_nip' => CompanyName::nip(self::str($item['organizationNationalId'] ?? null)),
            // w całości (kolumna text): przy wielu częściach obcięcie zepsułoby ponowny odczyt (--reparse)
            'procedure_result' => $procedureResult,
            'contractors' => $apiContractors === [] ? null : $apiContractors,
            'parsed' => [
                'version' => self::VERSION,
                'has_lots' => $content['lots'] !== [],
                'preceding_notice_number' => $preceding,
                'procedure_url' => $url,
                // termin z treści, w czasie polskim „na zegarze” (API podaje tę samą chwilę w UTC)
                'submission_deadline_local' => isset($general['submission_deadline'])
                    && preg_match('/^(\d{4}-\d{2}-\d{2})(?:\s+(\d{1,2}:\d{2}))?/', $general['submission_deadline'], $d) === 1
                    ? trim($d[1].' '.($d[2] ?? '')) : null,
                'total_value' => self::amount($general['total_value'] ?? null),
                'lots' => $lots,
                'warnings' => $warnings,
            ],
            'parser_version' => self::VERSION,
            'html_body' => $html,
        ];
    }

    /**
     * Element odpowiedzi API odtworzony z zapisanego ogłoszenia — do ponownego odczytu nową wersją parsera.
     * Bez pełnej treści (html_body wyczyszczony) części nie da się odczytać → null.
     *
     * @return array<string, mixed>|null
     */
    public static function toApiItem(ProcurementNotice $notice): ?array
    {
        $html = $notice->getRawOriginal('html_body');
        if (! is_string($html) || $html === '') {
            return null;
        }
        $cpv = [];
        foreach (is_array($notice->cpv_codes) ? $notice->cpv_codes : [] as $row) {
            if (is_array($row) && isset($row['code'])) {
                $cpv[] = $row['code'].' ('.($row['name'] ?? '').')';
            }
        }
        $contractors = null;
        if (is_array($notice->contractors)) {
            $contractors = array_map(static fn (mixed $c): array => [
                'contractorName' => is_array($c) ? ($c['name'] ?? null) : null,
                'contractorCity' => is_array($c) ? ($c['city'] ?? null) : null,
                'contractorProvince' => is_array($c) ? ($c['province'] ?? null) : null,
                'contractorCountry' => is_array($c) ? ($c['country'] ?? null) : null,
                'contractorNationalId' => is_array($c) ? ($c['national_id_raw'] ?? null) : null,
            ], $notice->contractors);
        }

        return [
            'noticeType' => $notice->notice_type,
            'noticeNumber' => $notice->notice_number,
            'bzpNumber' => $notice->bzp_number,
            'publicationDate' => $notice->published_at?->toIso8601String(),
            'orderObject' => $notice->order_object,
            'cpvCode' => implode(',', $cpv),
            'submittingOffersDate' => $notice->submitting_offers_at?->toIso8601String(),
            'procedureResult' => $notice->procedure_result,
            'organizationName' => $notice->organization_name,
            'organizationCity' => $notice->organization_city,
            'organizationProvince' => $notice->organization_province,
            'organizationNationalId' => $notice->organization_nip,
            'tenderId' => $notice->ocds_id,
            'contractors' => $contractors,
            'objectId' => $notice->object_id,
            'htmlBody' => $html,
        ];
    }

    /**
     * Kwota z ogłoszenia: „1365178,85 PLN”, „1 365 178,85 zł”, „1.365.178,85”, „143320.83”. Niejednoznaczny zapis
     * („1.234”, „1,234”) albo więcej niż 2 miejsca po przecinku → null.
     *
     * @return array{amount: string, currency: ?string}|null kwota z kropką i 2 miejscami po niej
     */
    public static function amount(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) preg_replace('/[\s\x{00A0}\x{2007}\x{202F}]+/u', ' ', $value));
        if (preg_match('/^(\d[\d .,]*?)\s*(PLN|EUR|USD|GBP|CHF|zł|zl)?\.?$/iu', $value, $m) !== 1) {
            return null;
        }
        $number = str_replace(' ', '', $m[1]);
        $currency = isset($m[2]) && $m[2] !== '' ? mb_strtoupper($m[2]) : null;
        if ($currency === 'ZŁ' || $currency === 'ZL') {
            $currency = 'PLN';
        }

        $integer = null;
        $fraction = '';
        $hasDot = str_contains($number, '.');
        $hasComma = str_contains($number, ',');
        if (! $hasDot && ! $hasComma) {
            $integer = preg_match('/^\d+$/', $number) === 1 ? $number : null;
        } elseif ($hasDot && $hasComma) {
            $decimal = strrpos($number, ',') > strrpos($number, '.') ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $pattern = '/^(\d{1,3}(?:'.preg_quote($thousands, '/').'\d{3})*)'.preg_quote($decimal, '/').'(\d{1,2})$/';
            if (preg_match($pattern, $number, $p) === 1) {
                $integer = str_replace($thousands, '', $p[1]);
                $fraction = $p[2];
            }
        } else {
            $sep = $hasDot ? '.' : ',';
            if (substr_count($number, $sep) === 1 && preg_match('/^(\d+)'.preg_quote($sep, '/').'(\d{1,2})$/', $number, $p) === 1) {
                $integer = $p[1];
                $fraction = $p[2];
            } elseif (substr_count($number, $sep) > 1 && preg_match('/^\d{1,3}(?:'.preg_quote($sep, '/').'\d{3})+$/', $number) === 1) {
                $integer = str_replace($sep, '', $number);
            }
        }
        if ($integer === null) {
            return null;
        }
        $integer = ltrim($integer, '0');

        return [
            'amount' => ($integer === '' ? '0' : $integer).'.'.str_pad($fraction, 2, '0'),
            'currency' => $currency,
        ];
    }

    /**
     * Tekst ogłoszenia jako wiersze: bloki (nagłówki, akapity, wiersze tabel) → osobne wiersze, bez znaczników,
     * encje zdekodowane, spacje (także twarde) zwinięte.
     *
     * @return list<string>
     */
    public static function lines(string $html): array
    {
        if (! mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
        }
        $html = (string) preg_replace('~<(script|style|header)\b[^>]*>.*?</\1\s*>~isu', ' ', $html);
        $html = (string) preg_replace('~<!--.*?-->~su', ' ', $html);
        $html = (string) preg_replace('~</?(?:h[1-6]|p|div|br|li|ul|ol|tr|table|tbody|thead|section|main|footer|article)\b[^>]*>~iu', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim((string) preg_replace('/[\s\x{00A0}\x{2007}\x{202F}\x{200B}]+/u', ' ', $line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Pola treści: ogólne i per część.
     *
     * @return array{general: array<string, mixed>, lots: array<int, array<string, mixed>>}
     */
    private function readContent(string $html): array
    {
        $lines = self::lines($html);
        $count = count($lines);
        $general = [];
        $lots = [];
        $section = null;
        $lot = null;
        /** nowy wykonawca zaczyna się przy następnym polu wykonawcy (po wierszu „Wykonawca”) */
        $newContractor = false;

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            if (preg_match('/^SEKCJA\s+([IVX]+)\b(.*)$/u', $line, $m) === 1) {
                $section = $m[1];
                $lot = preg_match('/\(dla\s+cz[eę][sś][cć]i\s+(\d{1,3})\)/iu', $m[2], $p) === 1 ? (int) $p[1] : null;
                if ($lot !== null) {
                    $lots[$lot] ??= [];
                }
                $newContractor = true;

                continue;
            }
            if (preg_match('/^Cz[eę][sś][cć]\s+(\d{1,3})$/u', $line, $m) === 1) {
                $lot = (int) $m[1];
                $lots[$lot] ??= [];
                $newContractor = true;

                continue;
            }
            if (preg_match('/^Wykonawca(?:\s+\d+)?$/u', $line) === 1) {
                $newContractor = true;

                continue;
            }
            if (preg_match('/^\d+(?:\.\d+)+\.?\)\s*(.*)$/u', $line, $m) !== 1) {
                continue;
            }
            $body = $m[1];

            foreach (self::LABELS as $field => $label) {
                if (preg_match('/^'.$label.'\s*/iu', $body, $l) !== 1) {
                    continue;
                }
                $value = trim(mb_substr($body, mb_strlen($l[0])));
                $multiline = in_array($field, self::MULTILINE, true) || $field === 'cpv_additional';
                if ($value === '' || $multiline) {
                    $more = [];
                    while ($i + 1 < $count && count($more) < self::CONTINUATION_MAX_LINES && ! self::isBoundary($lines[$i + 1])) {
                        $more[] = $lines[++$i];
                    }
                    $value = trim($value.($value !== '' && $more !== [] ? "\n" : '').implode("\n", $more));
                }
                if ($value === '') {
                    break;
                }

                if (isset(self::CONTRACTOR_FIELDS[$field])) {
                    // wykonawca tylko w sekcji VII; w sekcji I to numer i miejscowość zamawiającego
                    if ($section !== 'VII') {
                        break;
                    }
                    $key = self::CONTRACTOR_FIELDS[$field];
                    if ($lot !== null) {
                        self::addContractorField($lots[$lot], $key, $value, $newContractor);
                    } else {
                        self::addContractorField($general, $key, $value, $newContractor);
                    }
                    $newContractor = false;
                    break;
                }

                if ($field === 'notice_number') {
                    // numer tego ogłoszenia i numer ogłoszenia poprzedzającego — oba w sekcji II
                    if ($section === 'II') {
                        $general['notice_number'][] = $value;
                    }
                    break;
                }

                if ($lot !== null) {
                    $lots[$lot][$field] ??= $value;
                } else {
                    $general[$field] ??= $value;
                }
                break;
            }
        }

        return ['general' => $general, 'lots' => $lots];
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private static function addContractorField(array &$target, string $key, string $value, bool $startNew): void
    {
        $list = $target['contractors'] ?? [];
        $last = array_key_last($list);
        if ($startNew || $last === null || isset($list[$last][$key])) {
            $list[] = [];
            $last = array_key_last($list);
        }
        $list[$last][$key] = $value;
        $target['contractors'] = $list;
    }

    private static function isBoundary(string $line): bool
    {
        return preg_match('/^\d+(?:\.\d+)+\.?\)/u', $line) === 1
            || preg_match('/^SEKCJA\s+[IVX]+\b/u', $line) === 1
            || preg_match('/^Cz[eę][sś][cć]\s+\d{1,3}$/u', $line) === 1
            || preg_match('/^(?:Wykonawca|Kryterium)(?:\s+\d+)?$/u', $line) === 1;
    }

    /**
     * @param  array{general: array<string, mixed>, lots: array<int, array<string, mixed>>}  $content
     * @param  array<int, ?string>  $procedureResults  wynik z API po numerze części
     * @param  list<array<string, ?string>|null>  $apiContractors  wykonawcy z API, pozycja = numer części − 1 (pusta,
     *                                                             gdy pozycje API nie odpowiadają częściom)
     * @param  list<string>  $warnings
     * @return list<array<string, mixed>>
     */
    private function lots(array $content, array $procedureResults, array $apiContractors, array &$warnings): array
    {
        $raw = $content['lots'];
        if ($raw === []) {
            $general = $content['general'];
            $value = $general['procedure_value'] ?? $general['order_value'] ?? $general['lot_value'] ?? null;
            $single = array_intersect_key($general, array_flip([
                'short_description', 'cpv_main', 'cpv_additional', 'result_text', 'offers_count',
                'lowest_price', 'highest_price', 'winner_price', 'contractors',
            ]));
            if ($value !== null) {
                $single['lot_value'] = $value;
            }
            // ogłoszenie bez części, ale z wynikiem w API — też jedna część
            if ($single !== [] || isset($procedureResults[1])) {
                $raw = [1 => $single];
            }
        }
        ksort($raw);

        $lots = [];
        foreach ($raw as $no => $fields) {
            $description = isset($fields['short_description']) ? mb_substr($fields['short_description'], 0, self::DESCRIPTION_MAX) : null;
            $cpvMain = isset($fields['cpv_main']) && preg_match('/(\d{8}-\d)(?:\s*-\s*(.+))?/u', $fields['cpv_main'], $c) === 1 ? $c : null;
            preg_match_all('/\b(\d{8}-\d)\b/u', (string) ($fields['cpv_additional'] ?? ''), $additional);

            $htmlResult = self::resultFromText($fields['result_text'] ?? null);
            $apiResult = $procedureResults[$no] ?? null;
            $result = $htmlResult ?? $apiResult;
            if ($htmlResult !== null && $apiResult !== null && $htmlResult !== $apiResult) {
                $warnings[] = 'Część '.$no.': wynik w treści ogłoszenia („'.$fields['result_text'].'”) różni się od wyniku w danych Biuletynu ('.$apiResult.') — przyjęto treść.';
            }

            $contractors = [];
            foreach ($fields['contractors'] ?? [] as $contractor) {
                $nationalId = $contractor['national_id_raw'] ?? null;
                $contractors[] = [
                    'name' => isset($contractor['name']) ? mb_substr($contractor['name'], 0, 500) : null,
                    'national_id_raw' => $nationalId !== null ? mb_substr($nationalId, 0, 40) : null,
                    'nip' => CompanyName::nip($nationalId),
                    'city' => $contractor['city'] ?? null,
                    'source' => 'html',
                ];
            }
            // wykonawca z pola contractors API (pozycja = numer części): uzupełnia brakującą w treści nazwę
            // (ten sam NIP) albo całego wykonawcę, gdy treść go nie podaje, a część zakończyła się umową
            $api = $apiContractors[$no - 1] ?? null;
            if ($api !== null && ($api['name'] !== null || $api['national_id_raw'] !== null)) {
                $apiNip = CompanyName::nip($api['national_id_raw']);
                $matched = false;
                foreach ($contractors as &$contractor) {
                    if ($contractor['name'] === null && $apiNip !== null && $contractor['nip'] === $apiNip) {
                        $contractor['name'] = $api['name'];
                        $contractor['source'] = 'html+api';
                        $matched = true;
                    }
                }
                unset($contractor);
                if ($contractors === [] && ! $matched && $result === self::RESULT_AWARDED) {
                    $contractors[] = [
                        'name' => $api['name'],
                        'national_id_raw' => $api['national_id_raw'],
                        'nip' => $apiNip,
                        'city' => $api['city'],
                        'source' => 'api',
                    ];
                }
            }

            $lots[] = [
                'lot_no' => (int) $no,
                'name' => self::nameFrom($description),
                'description' => $description,
                'cpv_main' => $cpvMain[1] ?? null,
                'cpv_main_name' => isset($cpvMain[2]) ? trim($cpvMain[2]) : null,
                'cpv_additional' => array_values(array_unique($additional[1])),
                'estimated_value' => self::amount($fields['lot_value'] ?? null),
                'result' => $result,
                'result_text' => $fields['result_text'] ?? null,
                'result_source' => $htmlResult !== null ? 'html' : ($apiResult !== null ? 'api' : null),
                'offers_count' => isset($fields['offers_count']) && preg_match('/^\d{1,5}$/', $fields['offers_count']) === 1
                    ? (int) $fields['offers_count'] : null,
                'lowest_price' => self::amount($fields['lowest_price'] ?? null),
                'highest_price' => self::amount($fields['highest_price'] ?? null),
                'winner_price' => self::amount($fields['winner_price'] ?? null),
                'contractors' => $contractors,
            ];
        }

        return $lots;
    }

    private static function resultFromText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $lower = mb_strtolower($text);

        return match (true) {
            str_contains($lower, 'unieważnieniem') => self::RESULT_CANCELLED,
            str_contains($lower, 'zawarciem umowy') => self::RESULT_AWARDED,
            str_contains($lower, 'nie rozstrzygnięto'), str_contains($lower, 'nierozstrzygnięt') => self::RESULT_UNRESOLVED,
            default => null,
        };
    }

    /**
     * @return array<int, string> wynik części po jej numerze (puste pozycje pominięte)
     */
    private static function apiResults(?string $value): array
    {
        if ($value === null) {
            return [];
        }
        $out = [];
        foreach (explode(';', $value) as $i => $part) {
            $key = strtolower(trim($part));
            if (isset(self::API_RESULTS[$key])) {
                $out[$i + 1] = self::API_RESULTS[$key];
            }
        }

        return $out;
    }

    /**
     * @return list<array{name: ?string, city: ?string, province: ?string, country: ?string, national_id_raw: ?string, nip: ?string}|null>
     */
    private static function apiContractors(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach (array_values($value) as $row) {
            if (! is_array($row)) {
                $out[] = null;

                continue;
            }
            $nationalId = self::limit(self::str($row['contractorNationalId'] ?? null), 40);
            $out[] = [
                'name' => self::limit(self::str($row['contractorName'] ?? null), 500),
                'city' => self::limit(self::str($row['contractorCity'] ?? null), 200),
                'province' => self::limit(self::str($row['contractorProvince'] ?? null), 10),
                'country' => self::limit(self::str($row['contractorCountry'] ?? null), 10),
                'national_id_raw' => $nationalId,
                'nip' => CompanyName::nip($nationalId),
            ];
        }

        return $out;
    }

    /**
     * „35110000-8 (Sprzęt gaśniczy, ratowniczy i bezpieczeństwa),18100000-0 (Odzież …)” → lista kodów z nazwami.
     *
     * @return list<array{code: string, name: ?string}>
     */
    private static function cpvList(?string $value): array
    {
        if ($value === null) {
            return [];
        }
        preg_match_all('/(\d{8}-\d)\s*(?:\((.*?)\))?(?=\s*,\s*\d{8}-\d|\s*$)/u', $value, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $row) {
            $name = isset($row[2]) ? trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $row[2])) : '';
            $out[] = ['code' => $row[1], 'name' => $name !== '' ? $name : null];
        }

        return $out;
    }

    /** Nazwa części = pierwszy wiersz krótkiego opisu (skrócony na granicy słowa). */
    private static function nameFrom(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }
        $first = trim(strtok($description, "\n") ?: '');
        if ($first === '') {
            return null;
        }
        if (mb_strlen($first) <= self::NAME_MAX) {
            return $first;
        }
        $cut = mb_substr($first, 0, self::NAME_MAX);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > self::NAME_MAX / 2 ? mb_substr($cut, 0, $space) : $cut, ' ,;:-').'…';
    }

    private static function moment(mixed $value): ?CarbonImmutable
    {
        $value = self::str($value);
        if ($value === null) {
            return null;
        }
        // API podaje 7 cyfr ułamka sekundy („09:41:43.6123456Z”) — PHP czyta najwyżej 6
        $value = (string) preg_replace('/(\.\d{6})\d+/', '$1', $value);
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private static function str(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function limit(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }
}
