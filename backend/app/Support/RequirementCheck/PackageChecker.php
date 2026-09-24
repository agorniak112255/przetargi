<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

/**
 * Pojemność i „sztuka czy zestaw” — dla wymagań podających pojemność (płukanki, kremy, mydła, spraye).
 *
 * Uwagi 24.09 do przetargu 1, poz. 11: SIWZ chce jednej butelki 500 ml, a zamienniki to Pocket 235 ml, 2-pack 2x500 ml,
 * szafka, walizka 5x500 ml i stacja. Pomiar na produkcji (4 wymagania z pojemnością, pula 132 kart): 43 karty odpadają,
 * żadna propozycja ani karta wzorcowa. Wiersz „Pojemność” rozstrzyga nazwa, gdy podaje jedną pojemność — opis wymienia
 * często inne wersje („dostępna też 235 ml”). Wiersz „Sztuka, nie zestaw” powstaje tylko wtedy, gdy nazwa karty mówi
 * o zestawie, a wymaganie nie — brak takiego słowa to nie dowód, że karta jest pojedynczą sztuką.
 */
final class PackageChecker implements ParameterChecker
{
    /** Liczba sztuk („2x”, „5 ×”), liczba, jednostka. „L” wielkie tylko poza rozmiarem („rozm. 9 L”). */
    private const CAPACITY = '/(?<![\p{L}\d.,])(?:(\d{1,3})\s*[x×]\s*)?(\d+(?:[.,]\d+)?)\s*(ml|ML|l|L|litr\p{L}*)(?![\p{L}\d])/u';

    /** Zestaw zamiast sztuki. „kit” tylko jako słowo — nie „kitel”. */
    private const SET = '/(?<![\p{L}])(zestaw|komplet|walizk|szafk|stacj|station|cabinet|kit(?!\p{L})|pakiet|\d+\s*-?\s*pack)\p{L}*/iu';

    /** Wymaganie samo mówi o kilku sztukach w opakowaniu: „2 x butelka 500 ml”. */
    private const REQUIRED_MULTI = '/(?<![\p{L}\d])\d{1,3}\s*[x×]\s*(?:butel|flakon|opakow|szt|tub|kanist)\p{L}*/iu';

    private const MIN = '/(?:min\.?|minimum|co\s+najmniej|nie\s+mniej\s+ni\p{L}*)\s*[:\-–]?\s*$/iu';

    /** Rozmiar przed liczbą: „rozm. 9 L” to nie 9 litrów. */
    private const SIZE_BEFORE = '/(?:rozm\p{L}*|size)\.?\s*[:\-]?\s*$/iu';

    /** Dopuszczalna różnica przy „ok. 500 ml” i zapisie 0,5 l — 5%. */
    private const TOLERANCE = 0.05;

    public function group(): string
    {
        return 'package';
    }

    public function check(string $requirement, array $cardSources): array
    {
        $required = self::capacities($requirement);
        if ($required === []) {
            return [];
        }

        return array_values(array_filter([
            $this->capacityRow($requirement, $required, $cardSources),
            $this->setRow($requirement, $required, $cardSources),
        ]));
    }

    /**
     * @param  list<array{ml: float, pack: int, min: bool, text: string}>  $required
     * @param  list<CardSource>  $sources
     */
    private function capacityRow(string $requirement, array $required, array $sources): ?CheckRow
    {
        $values = array_values(array_unique(array_column($required, 'ml')));
        if (count($values) !== 1) {
            return null; // kilka pojemności w wymaganiu — nie wiadomo, której dotyczy
        }
        $want = $values[0];
        $min = in_array(true, array_column($required, 'min'), true);
        $requiredText = $required[0]['text'];

        $findings = [];
        $nameVerdict = null;
        foreach ($sources as $source) {
            $caps = self::capacities($source->text);
            $distinct = array_values(array_unique(array_column($caps, 'ml')));
            if ($distinct === []) {
                continue;
            }
            if (count($distinct) > 1) {
                // kilka pojemności w jednym polu to warianty — nie wiemy, która dotyczy wyrobu
                $findings[] = CheckRow::finding($source, $caps[0]['text'], Status::Unclear, ['ml' => $distinct]);

                continue;
            }
            $ok = $min ? $distinct[0] >= $want * (1 - self::TOLERANCE) : abs($distinct[0] - $want) <= $want * self::TOLERANCE;
            $verdict = $ok ? Status::Ok : Status::Fail;
            $findings[] = CheckRow::finding($source, $caps[0]['text'], $verdict, ['ml' => $distinct[0]]);
            if ($source->source === CardSource::NAME) {
                $nameVerdict = $verdict;
            }
        }
        $status = $nameVerdict ?? Status::fromCardVerdicts(array_map(static fn (array $f): Status => Status::from((string) $f['verdict']), $findings));
        $note = $nameVerdict !== null && count($findings) > 1 ? 'Rozstrzyga nazwa karty; pozostałe pola pokazane, nie oceniane.' : null;

        return new CheckRow(
            'capacity',
            'Pojemność',
            ['text' => ($min ? 'min. ' : '').self::format($want), 'quote' => CheckRow::quote($requirement, $requiredText), 'ml' => $want],
            $findings,
            $status,
            $note,
        );
    }

    /**
     * @param  list<array{ml: float, pack: int, min: bool, text: string}>  $required
     * @param  list<CardSource>  $sources
     */
    private function setRow(string $requirement, array $required, array $sources): ?CheckRow
    {
        if (preg_match(self::SET, $requirement) === 1 || preg_match(self::REQUIRED_MULTI, $requirement) === 1
            || max(array_column($required, 'pack')) > 1) {
            return null;
        }
        foreach ($sources as $source) {
            if ($source->source !== CardSource::NAME) {
                continue;
            }
            $hit = preg_match(self::SET, $source->text, $m) === 1 ? trim($m[0]) : null;
            foreach (self::capacities($source->text) as $cap) {
                if ($hit === null && $cap['pack'] > 1) {
                    $hit = $cap['text'];
                }
            }
            if ($hit === null) {
                return null;
            }

            return new CheckRow(
                'single_item',
                'Sztuka, nie zestaw',
                ['text' => 'jedna sztuka ('.self::format($required[0]['ml']).')', 'quote' => CheckRow::quote($requirement, $required[0]['text'])],
                [CheckRow::finding($source, $hit, Status::Fail)],
                Status::Fail,
                'Nazwa karty wskazuje zestaw lub kilka sztuk, a wymaganie mówi o jednej sztuce.',
            );
        }

        return null;
    }

    /**
     * @return list<array{ml: float, pack: int, min: bool, text: string}>
     */
    public static function capacities(string $text): array
    {
        if (preg_match_all(self::CAPACITY, $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }
        $out = [];
        foreach ($m as $hit) {
            $before = substr($text, max(0, $hit[0][1] - 30), min(30, $hit[0][1]));
            $unit = $hit[3][0];
            if (($unit === 'L' || $unit === 'l') && preg_match(self::SIZE_BEFORE, $before) === 1) {
                continue;
            }
            $number = (float) str_replace(',', '.', $hit[2][0]);
            $ml = mb_strtolower($unit) === 'ml' ? $number : $number * 1000;
            if ($ml <= 0) {
                continue;
            }
            $out[] = [
                'ml' => $ml,
                'pack' => $hit[1][0] !== '' ? (int) $hit[1][0] : 1,
                'min' => preg_match(self::MIN, $before) === 1,
                'text' => trim($hit[0][0]),
            ];
        }

        return $out;
    }

    private static function format(float $ml): string
    {
        return $ml >= 1000 && fmod($ml, 1000.0) === 0.0
            ? ((int) ($ml / 1000)).' l'
            : rtrim(rtrim(number_format($ml, 1, ',', ''), '0'), ',').' ml';
    }
}
