<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

/**
 * Wymiary z wymagania (długość, szerokość, grubość…) porównane z kartą po przeliczeniu jednostek.
 *
 * Tolerancja: „ok. 475 mm” ±5%, sama liczba ±2%; min./max. i zakres bez tolerancji. Wymiary
 * „120 × 75 cm” porównujemy bez kolejności. Pewny werdykt daje tylko wartość z nazwą wymiaru
 * i jednostką — liczba bez jednostki („DŁUGOŚĆ 300 / 11.8”) albo wartość w nazwie karty bez
 * nazwy wymiaru („HyFlex 11202 SIZE 19''/47,5 cm”) to najwyżej „do sprawdzenia”.
 */
final class DimensionChecker implements ParameterChecker
{
    private const TOLERANCE_PCT = ['approx' => 5, 'exact' => 2];

    private const NOTE_UNITLESS = 'Karta podaje liczbę bez jednostki — nie wiadomo, czy to mm, cm czy cale.';

    private const NOTE_NAME = 'W nazwie karty wartość bez nazwy wymiaru — nie wiadomo, którego wymiaru dotyczy.';

    private const NOTE_VARIANTS = 'Karta wymienia rozmiary bez wymaganego — sprawdź dostępne warianty.';

    private const NOTE_COUNT = 'Karta podaje inną liczbę wymiarów niż wymaganie.';

    private const NOTE_PARTIAL = 'Karta podaje granicę albo zakres, który tylko częściowo mieści się w wymaganiu.';

    private const NOTE_APPROX = 'Karta podaje wartość przybliżoną („ok.”, ±5%), która tylko częściowo mieści się w wymaganiu.';

    private const BUCKET_MAIN = 'main';

    private const BUCKET_NAME = 'name';

    private const BUCKET_VARIANT = 'variant';

    /**
     * @param  float|null  $minimumTolerancePct  null — okno „Weryfikacja karty” (porównanie dokładne, TOLERANCE_PCT);
     *                                           liczba — tryb sprzeczności z forContradictions()
     */
    public function __construct(
        private readonly DimensionParser $parser = new DimensionParser,
        private readonly ?float $minimumTolerancePct = null,
    ) {}

    /**
     * Tryb do limitu oceny w wyszukiwarce: „fail” tylko przy wprost sprzecznym wymiarze. Wymiar bez „min./max.” (także
     * „ok.”) to minimum — decyzja właściciela 25.09.2026: dłuższy rękaw czy większy fartuch nie przeczy wymaganiu.
     * „max.” to maksimum, zakres — oba końce; każda granica z tolerancją $tolerancePct, więc karta krótsza o 3% nie spada.
     * Wiersze i znaleziska są te same co w oknie, zmienia się tylko przedział akceptowany przez wymaganie.
     */
    public static function forContradictions(float $tolerancePct = 5.0): self
    {
        return new self(new DimensionParser, $tolerancePct);
    }

    public function group(): string
    {
        return 'dimensions';
    }

    public function check(string $requirement, array $cardSources): array
    {
        $card = array_map(fn (CardSource $source): array => [$source, $this->parser->parse($source->text)], $cardSources);

        $rows = [];
        $seen = [];
        foreach ($this->parser->parse($requirement) as $req) {
            $param = self::requirementParam($req);
            if ($param === null) {
                continue;
            }
            $qualifier = $param === DimensionParser::DIMENSIONS ? null : $req['qualifier'];
            $key = $param.($qualifier !== null ? '_'.$qualifier : '');
            $signature = $key.'|'.$req['op'].'|'.implode(',', $req['values_mm']);
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $rows[] = $this->row($requirement, $req, $param, $qualifier, $key, $card);
        }

        return $rows;
    }

    /**
     * Wymaganie bez jednostki niczego nie mówi o wymiarze; para liczb z jednostką to wymiary
     * nawet bez słowa „wymiary” („fartuch 120 × 75 cm”).
     *
     * @param  array<string, mixed>  $req
     */
    private static function requirementParam(array $req): ?string
    {
        if ($req['unitless']) {
            return null;
        }
        if ($req['kind'] === DimensionParser::KIND_DIMS) {
            return DimensionParser::DIMENSIONS;
        }

        return isset(DimensionParser::LABELS[$req['label']]) && $req['label'] !== DimensionParser::DIMENSIONS ? $req['label'] : null;
    }

    /**
     * @param  array<string, mixed>  $req
     * @param  list<array{0: CardSource, 1: list<array<string, mixed>>}>  $card
     */
    private function row(string $requirement, array $req, string $param, ?string $qualifier, string $key, array $card): CheckRow
    {
        $main = [];
        $name = [];
        foreach ($card as [$source, $measures]) {
            $variants = [];
            foreach ($measures as $measure) {
                $hit = $param === DimensionParser::DIMENSIONS
                    ? $this->dimensionsFinding($req, $measure, $source)
                    : $this->singleFinding($req, $param, $qualifier, $measure, $source);
                if ($hit === null) {
                    continue;
                }
                match ($hit[0]) {
                    self::BUCKET_NAME => $name[] = $hit[1],
                    self::BUCKET_VARIANT => $variants[] = $hit[1],
                    default => $main[] = $hit[1],
                };
            }
            // Z listy wariantów liczy się ten, który pasuje; pozostałe to inne rozmiary tego samego wyrobu.
            $matching = array_values(array_filter($variants, static fn (array $f): bool => $f['verdict'] === Status::Ok->value));
            array_push($main, ...($matching !== [] ? $matching : $variants));
        }

        // Wartość z nazwy bez nazwy wymiaru pokazujemy tylko, gdy nic innego nie potwierdza wymiaru —
        // inaczej „19''/47,5 cm” w nazwie zrobiłoby „do sprawdzenia” z pewnego „Długość: 47,5 cm”.
        $confirmed = array_filter($main, static fn (array $f): bool => $f['verdict'] === Status::Ok->value) !== [];
        $findings = self::unique($confirmed ? $main : [...$name, ...$main]);

        $notes = array_values(array_unique(array_filter(array_map(static fn (array $f): ?string => $f['note'] ?? null, $findings))));

        return new CheckRow(
            key: $key,
            label: self::label($param, $qualifier),
            required: self::required($requirement, $req, $param),
            card: $findings,
            status: Status::fromCardVerdicts(array_map(static fn (array $f): Status => Status::from($f['verdict']), $findings)),
            note: $notes === [] ? null : implode(' ', $notes),
        );
    }

    /**
     * @param  array<string, mixed>  $req
     * @param  array<string, mixed>  $m
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function singleFinding(array $req, string $param, ?string $qualifier, array $m, CardSource $source): ?array
    {
        if ($m['label'] === null || $m['label'] === DimensionParser::SIZES) {
            if ($m['label'] === null && ! $m['unitless'] && $m['kind'] !== DimensionParser::KIND_DIMS && $source->source === CardSource::NAME) {
                return [self::BUCKET_NAME, self::finding($source, $m, Status::Unclear, self::NOTE_NAME)];
            }

            return null;
        }
        if ($m['label'] !== $param || ($m['kind'] === DimensionParser::KIND_DIMS && ! $m['unitless'])) {
            return null;
        }
        if ($m['qualifier'] !== $qualifier) {
            // Wymaganie „długość 300 mm” wobec karty z samą „długością mankietu” — nie ta sama miara.
            if ($qualifier !== null) {
                return null;
            }
            $cardLabel = self::label($param, $m['qualifier']);

            return [self::BUCKET_MAIN, self::finding($source, $m, Status::Unclear, "Karta podaje: {$cardLabel}; wymaganie nie mówi, której miary dotyczy.")];
        }
        if ($m['unitless']) {
            return [self::BUCKET_MAIN, self::finding($source, $m, Status::Unclear, self::NOTE_UNITLESS)];
        }
        if ($m['kind'] === DimensionParser::KIND_DIMS) {
            return null;
        }

        [$lo, $hi] = self::cardInterval($m);
        $verdict = self::verdict($lo, $hi, ...$this->acceptance($req['op'], $req['values_mm']));
        if ($m['listed']) {
            return self::variant($source, $m, $verdict);
        }

        return [self::BUCKET_MAIN, self::finding($source, $m, $verdict, $verdict === Status::Unclear ? self::partialNote($m) : null)];
    }

    /**
     * Brak wymaganej wartości na liście wariantów to nie „nie spełnia”: wyrób bywa w innych wariantach.
     *
     * @param  array<string, mixed>  $m
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function variant(CardSource $source, array $m, Status $verdict): array
    {
        return [self::BUCKET_VARIANT, $verdict === Status::Ok
            ? self::finding($source, $m, Status::Ok)
            : self::finding($source, $m, Status::Unclear, self::NOTE_VARIANTS)];
    }

    /**
     * @param  array<string, mixed>  $m
     */
    private static function partialNote(array $m): string
    {
        return $m['op'] === 'approx' ? self::NOTE_APPROX : self::NOTE_PARTIAL;
    }

    /**
     * @param  array<string, mixed>  $req
     * @param  array<string, mixed>  $m
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function dimensionsFinding(array $req, array $m, CardSource $source): ?array
    {
        if ($m['kind'] !== DimensionParser::KIND_DIMS) {
            return null;
        }
        $fromName = $source->source === CardSource::NAME && $m['label'] === null;
        if ($m['unitless']) {
            if ($m['label'] === DimensionParser::DIMENSIONS) {
                return [self::BUCKET_MAIN, self::finding($source, $m, Status::Unclear, self::NOTE_UNITLESS)];
            }
            // „Fartuch wodoochronny 120/75” — liczby jak w wymaganiu, ale bez jednostki. Inne pary
            // bez jednostki w nazwie to zwykle kod modelu („6200/07”), więc ich nie pokazujemy.
            if (($fromName || $m['label'] === DimensionParser::SIZES) && self::sameNumbers($req, $m['numbers'])) {
                return [$fromName ? self::BUCKET_NAME : self::BUCKET_MAIN, self::finding($source, $m, Status::Unclear, self::NOTE_UNITLESS)];
            }

            return null;
        }
        if ($m['label'] === null) {
            return $fromName ? [self::BUCKET_NAME, self::finding($source, $m, Status::Unclear, self::NOTE_NAME)] : null;
        }

        if (count($m['values_mm']) !== count($req['values_mm'])) {
            return [self::BUCKET_MAIN, self::finding($source, $m, Status::Unclear, self::NOTE_COUNT)];
        }
        $required = self::sortedDesc($req['values_mm']);
        $verdicts = [];
        foreach (self::sortedDesc($m['values_mm']) as $i => $value) {
            [$lo, $hi] = $m['op'] === 'approx' ? self::approxInterval($value) : [$value, $value];
            $verdicts[] = self::verdict($lo, $hi, ...$this->acceptance($req['op'], [$required[$i]]));
        }
        $verdict = Status::worst($verdicts);

        if ($m['label'] === DimensionParser::SIZES || $m['listed']) {
            return self::variant($source, $m, $verdict);
        }

        return [self::BUCKET_MAIN, self::finding($source, $m, $verdict, $verdict === Status::Unclear ? self::partialNote($m) : null)];
    }

    /**
     * Przedział akceptowany przez wymaganie, w mm.
     *
     * @param  list<float>  $values
     * @return array{0: float, 1: float}
     */
    private function acceptance(string $op, array $values): array
    {
        $value = $values[0];
        if ($this->minimumTolerancePct !== null) {
            $low = 1 - $this->minimumTolerancePct / 100;
            $high = 1 + $this->minimumTolerancePct / 100;

            return match ($op) {
                'max' => [0.0, $value * $high],
                'range' => [$values[0] * $low, $values[1] * $high],
                default => [$value * $low, INF],
            };
        }

        return match ($op) {
            'approx', 'exact' => [$value * (1 - self::TOLERANCE_PCT[$op] / 100), $value * (1 + self::TOLERANCE_PCT[$op] / 100)],
            'min' => [$value, INF],
            'max' => [0.0, $value],
            'range' => [$values[0], $values[1]],
        };
    }

    /**
     * Wartość karty jako przedział: „max. 30 cm” to [0, 300], „45–50 cm” to [450, 500],
     * „ok. 30 cm” to [285, 315] — ta sama tolerancja co „ok.” w wymaganiu.
     *
     * @param  array<string, mixed>  $m
     * @return array{0: float, 1: float}
     */
    private static function cardInterval(array $m): array
    {
        $values = $m['values_mm'];

        return match (true) {
            $m['kind'] === DimensionParser::KIND_RANGE => [$values[0], $values[1]],
            $m['op'] === 'min' => [$values[0], INF],
            $m['op'] === 'max' => [0.0, $values[0]],
            $m['op'] === 'approx' => self::approxInterval($values[0]),
            default => [$values[0], $values[0]],
        };
    }

    /**
     * @return array{0: float, 1: float}
     */
    private static function approxInterval(float $value): array
    {
        return [$value * (1 - self::TOLERANCE_PCT['approx'] / 100), $value * (1 + self::TOLERANCE_PCT['approx'] / 100)];
    }

    /** Cały przedział karty w wymaganiu → ok; całkiem poza → fail; częściowo → do sprawdzenia. */
    private static function verdict(float $lo, float $hi, float $min, float $max): Status
    {
        $eps = 1e-9;
        if ($lo >= $min * (1 - $eps) && $hi <= $max * (1 + $eps)) {
            return Status::Ok;
        }
        if ($hi < $min * (1 - $eps) || $lo > $max * (1 + $eps)) {
            return Status::Fail;
        }

        return Status::Unclear;
    }

    /**
     * Liczby bez jednostki zgodne z wymaganiem w jego jednostce albo w mm („120/75” wobec „120 × 75 cm”).
     *
     * @param  array<string, mixed>  $req
     * @param  list<float>  $numbers
     */
    private static function sameNumbers(array $req, array $numbers): bool
    {
        if (count($numbers) !== count($req['numbers'])) {
            return false;
        }
        $card = self::sortedDesc($numbers);
        foreach ([$req['numbers'], $req['values_mm']] as $candidate) {
            $expected = self::sortedDesc($candidate);
            $equal = true;
            foreach ($card as $i => $value) {
                $equal = $equal && abs($value - $expected[$i]) <= 0.02 * $expected[$i];
            }
            if ($equal) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private static function finding(CardSource $source, array $m, Status $verdict, ?string $note = null): array
    {
        $extra = [];
        if (! $m['unitless']) {
            $extra = match ($m['kind']) {
                DimensionParser::KIND_RANGE => ['min_mm' => self::mm($m['values_mm'][0]), 'max_mm' => self::mm($m['values_mm'][1])],
                DimensionParser::KIND_DIMS => ['values_mm' => array_map(self::mm(...), $m['values_mm'])],
                default => ['value_mm' => self::mm($m['values_mm'][0])] + ($m['op'] !== 'exact' ? ['op' => $m['op']] : []),
            };
        }
        if ($note !== null) {
            $extra['note'] = $note;
        }

        return CheckRow::finding($source, $m['text'], $verdict, $extra);
    }

    /**
     * @param  array<string, mixed>  $req
     * @return array<string, mixed>
     */
    private static function required(string $requirement, array $req, string $param): array
    {
        $out = [
            'text' => trim(($req['op_text'] ?? '').' '.$req['text']),
            'quote' => CheckRow::quote($requirement, $req['fragment']),
            'op' => $req['op'],
        ];
        if ($req['op'] === 'range') {
            $out += ['min_mm' => self::mm($req['values_mm'][0]), 'max_mm' => self::mm($req['values_mm'][1])];
        } elseif ($param === DimensionParser::DIMENSIONS) {
            $out['values_mm'] = array_map(self::mm(...), $req['values_mm']);
        } else {
            $out['value_mm'] = self::mm($req['values_mm'][0]);
        }
        if (isset(self::TOLERANCE_PCT[$req['op']])) {
            $out['tolerance_pct'] = self::TOLERANCE_PCT[$req['op']];
        }
        if ($req['alias'] !== null) {
            $out['alias'] = $req['alias'];
        }

        return $out;
    }

    private static function label(string $param, ?string $qualifier): string
    {
        $base = DimensionParser::LABELS[$param];

        return $qualifier === null ? $base : $base.' '.DimensionParser::QUALIFIERS[$qualifier][1];
    }

    /**
     * To samo zdanie powtórzone w opisie (sklepy kopiują akapity) to jedno znalezisko.
     *
     * @param  list<array<string, mixed>>  $findings
     * @return list<array<string, mixed>>
     */
    private static function unique(array $findings): array
    {
        $out = [];
        foreach ($findings as $finding) {
            $out[$finding['source'].'|'.$finding['text'].'|'.$finding['verdict']] ??= $finding;
        }

        return array_values($out);
    }

    /**
     * @param  list<float>  $values
     * @return list<float>
     */
    private static function sortedDesc(array $values): array
    {
        rsort($values);

        return $values;
    }

    /** 475.0 → 475, żeby JSON nie pokazywał „475.0”; ułamki do 0,01 mm. */
    private static function mm(float $value): int|float
    {
        $rounded = round($value, 2);

        return floor($rounded) === $rounded ? (int) $rounded : $rounded;
    }
}
