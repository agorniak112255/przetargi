<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

use App\Support\BhpAttributeNormalizer;
use App\Support\PpeAssortment;

/**
 * Poziomy i klasy z wymagania (EN 388, EN 407, kategoria ŚOI, klasy obuwia, FFP, SNR…) porównane z kartą.
 *
 * Każde pole karty ocenia się osobno i dostaje własny werdykt — nazwa „S1 P” i specyfikacja „Klasa ochrony: S1”
 * dają ok i fail, więc wiersz jest „do sprawdzenia”, a nie ✓. Brak wartości na karcie to „brak”, nigdy „nie spełnia”;
 * „X” w kodzie na karcie to „nie badano”, więc też brak. Pole wymieniające kilka klas („FFP1, FFP2 i FFP3”,
 * „kat. I–III”) to warianty, nie klasa produktu — „do sprawdzenia”. Wiersz powstaje tylko dla parametru podanego
 * w wymaganiu.
 */
final class LevelChecker implements ParameterChecker
{
    private const IMPACT_RANK = ['F' => 1, 'B' => 2, 'A' => 3];

    /** Pozycja kodu z „X” w wymaganiu — przetarg jej nie wymaga, więc nie wpływa na status. */
    private const SKIP = 'skip';

    /**
     * Klasa obuwia tylko wielkimi literami — „o2” czy „sb” w zwykłym tekście to nie klasa. L/S za S1P, S3, S5, S7
     * to typ wkładki antyprzebiciowej z EN ISO 20345:2022 („S1PL”, „S3S”).
     */
    private const FOOTWEAR = '/(?<![\p{L}\d])(S1\h?P[LS]?|S[357][LS]?|S[B1-7]|O[B1-7])(?![\p{L}\d])/u';

    private const FFP = '/(?<![\p{L}\d])FFP\s*-?([123])(?!\d)/iu';

    private const SNR = '/(?<![\p{L}\d])SNR(?![\p{L}\d])[^0-9]{0,12}(\d{2,3})(?!\d)(?:\s*dB)?/iu';

    /** Dłuższy fragment z kilkoma klasami pokazujemy jako pierwsze trafienie, a klasy wymieniamy w notce. */
    private const VARIANTS_SPAN_MAX = 80;

    public function __construct(
        private readonly BhpAttributeNormalizer $attributes = new BhpAttributeNormalizer,
        private readonly PpeAssortment $assortment = new PpeAssortment,
    ) {}

    public function group(): string
    {
        return 'levels';
    }

    public function check(string $requirement, array $cardSources): array
    {
        return array_values(array_filter([
            $this->en388($requirement, $cardSources),
            $this->cutLevel($requirement, $cardSources),
            $this->en407($requirement, $cardSources),
            $this->ppeCategory($requirement, $cardSources),
            $this->footwearClass($requirement, $cardSources),
            $this->ffp($requirement, $cardSources),
            $this->snr($requirement, $cardSources),
            $this->impactClass($requirement, $cardSources),
        ]));
    }

    /**
     * Poziomy, dla których pola samej karty podają różne wartości — niezależnie od wymagania. Tylko poziomy
     * (kategoria, EN 388, EN 407, klasa obuwia, FFP, SNR): cechy tak/nie dały w pomiarze na 21 613 kartach same
     * fałszywe alarmy. Pole wymieniające kilka wartości („kat. I–III”, „FFP1, FFP2 i FFP3”) to warianty, nie
     * sprzeczność; kody przeczą sobie tylko na tej samej pozycji — „1241” i „1241B” albo „X1XXXX” i „ciepło
     * kontaktowe: poziom 1” to zgodne zapisy. Klucze jak wiersze check(), żeby sprzeczność dała się połączyć z wierszem.
     *
     * @param  list<CardSource>  $cardSources
     * @return list<CardConflict>
     */
    public function cardConflicts(array $cardSources): array
    {
        $footwear = static fn (string $literal): string => preg_replace('/\s+/u', '', $literal) ?? $literal;
        $labels = ['ppe_category' => 'Kategoria ŚOI', 'en388' => 'EN 388', 'en407' => 'EN 407', 'footwear_class' => 'Klasa obuwia', 'ffp' => 'Klasa FFP', 'snr' => 'Tłumienie SNR'];
        // [pole, zapis, wartość, pozycje kodu]
        $hits = array_fill_keys(array_keys($labels), []);
        $seen = [];
        foreach ($cardSources as $source) {
            foreach (PpeCategory::allIn($source->text) as $category) {
                if (! $category->isList() && ! $this->seen($seen, $source, "ppe_category\0".$category->text)) {
                    $hits['ppe_category'][] = [$source, $category->text, $category->roman(), []];
                }
            }
            $values = [
                'footwear_class' => $this->valuesIn($source->text, self::FOOTWEAR, $footwear),
                // te same odczyty co ffp() i snr(): bez klasy/SNR wg normalizatora trafienie wzorca się nie liczy
                'ffp' => $this->attributes->ffpClass($source->text) === null ? null
                    : $this->valuesIn($source->text, self::FFP, static fn (string $digit): string => 'FFP'.$digit),
                'snr' => $this->attributes->snrRating($source->text) === null ? null
                    : $this->valuesIn($source->text, self::SNR, static fn (string $n): ?string => (int) $n >= 15 && (int) $n <= 45 ? (string) (int) $n : null),
            ];
            foreach ($values as $key => $have) {
                // kilka wartości w jednym polu to warianty
                if ($have !== null && count($have['values']) === 1 && ! $this->seen($seen, $source, $key."\0".$have['text'])) {
                    $hits[$key][] = [$source, $have['text'], $have['values'][0], []];
                }
            }
            foreach (['en388' => En388Code::allIn($source->text), 'en407' => En407Code::allIn($source->text)] as $key => $found) {
                foreach ($found as $code) {
                    if (! $this->seen($seen, $source, $key."\0".$code->text)) {
                        $hits[$key][] = [$source, $code->text, $code->canonical(), $code->levels];
                    }
                }
            }
        }

        $out = [];
        foreach ($hits as $key => $keyHits) {
            $conflict = $key === 'en388' || $key === 'en407'
                ? $this->codesConflict(array_column($keyHits, 3))
                : count(array_unique(array_column($keyHits, 2))) > 1;
            if (! $conflict) {
                continue;
            }
            $grouped = [];
            foreach ($keyHits as [$source, $text, $value]) {
                $grouped[$value][] = CheckRow::finding($source, $text, Status::Unclear);
            }
            $values = [];
            foreach ($grouped as $value => $findings) {
                $values[] = ['value' => (string) $value, 'findings' => $findings];
            }
            $out[] = new CardConflict($key, $key === 'footwear_class' ? $this->footwearConflictLabel(array_keys($grouped)) : $labels[$key], $values);
        }

        return $out;
    }

    /**
     * „S1P” i „S1PL” różnią się tylko literą typu wkładki (EN ISO 20345:2022) — to raczej inny zapis tej samej
     * klasy niż inna klasa, więc etykieta to mówi.
     *
     * @param  list<string|int>  $classes
     */
    private function footwearConflictLabel(array $classes): string
    {
        $bases = array_unique(array_map(
            static fn (string|int $class): string => preg_replace('/^(S1P|S[357])[LS]$/', '$1', (string) $class) ?? (string) $class,
            $classes,
        ));

        return count($bases) === 1 ? 'Klasa obuwia (różne zapisy)' : 'Klasa obuwia';
    }

    /**
     * @param  list<CardSource>  $sources
     */
    private function en388(string $requirement, array $sources): ?CheckRow
    {
        $required = En388Code::first($requirement);
        if ($required === null) {
            return null;
        }
        $codes = [];
        foreach ($sources as $source) {
            foreach (En388Code::allIn($source->text) as $code) {
                $codes[] = [$source, $code->text, $code->levels, $code->canonical()];
            }
        }

        return $this->codeRow('en388', 'EN 388', 'cut_level', $requirement, $this->requiredCodeText($required->text, $required->canonical(), $required->worded), $required->text, $required->canonical(), En388Code::POSITIONS, $required->levels, $codes);
    }

    /**
     * Litera ISO 13997 podana wprost, gdy wymaganie nie ma kodu EN 388 z pozycjami (ten porównuje wiersz en388).
     * Bez domyślnego „B” z PpeAssortment::requiredCutLevel — to byłoby dopisanie wymagania.
     *
     * @param  list<CardSource>  $sources
     */
    private function cutLevel(string $requirement, array $sources): ?CheckRow
    {
        if (En388Code::first($requirement) !== null) {
            return null;
        }
        $required = $this->cutLevels($requirement);
        if ($required === []) {
            return null;
        }
        $letters = array_column($required, 0);
        $min = min($letters);
        $requiredText = $required[(int) array_search($min, $letters, true)][1];

        $findings = [];
        $seen = [];
        foreach ($sources as $source) {
            foreach ($this->cutLevels($source->text) as [$letter, $text]) {
                if ($this->seen($seen, $source, $text)) {
                    continue;
                }
                $findings[] = CheckRow::finding($source, $text, strcmp($letter, $min) >= 0 ? Status::Ok : Status::Fail, ['value' => $letter]);
            }
        }

        return $this->valueRow('cut_level', 'Poziom cięcia ISO 13997', 'cut_level', $requirement, $requiredText, $min, $findings);
    }

    /**
     * Litery ISO 13997 z dosłownym zapisem: z kodu EN 388 (En388Code — pomija rok i cyfry dalej w tekście) oraz
     * z zapisów słownych PpeAssortment::cutLevelsIn. Literę, której cutLevelsIn nie potwierdza zapisem słownym, wziął
     * jego odczyt kodu — ten w „EN 388:2015 D” czyta rok jako pozycje, więc ją pomijamy.
     *
     * @return list<array{0: string, 1: string}> [litera, dosłowny zapis]
     */
    private function cutLevels(string $text): array
    {
        $out = [];
        foreach (En388Code::allIn($text) as $code) {
            $letter = $code->levels['iso'];
            if (! $code->worded && $letter !== null && $letter !== 'X') {
                $out[] = [$letter, $code->text];
            }
        }
        foreach ($this->assortment->cutLevelsIn($text) as $letter) {
            $literal = $this->locateWordedCutLevel($text, $letter);
            if ($literal !== null) {
                $out[] = [$letter, $literal];
            }
        }

        return $out;
    }

    /**
     * @param  list<CardSource>  $sources
     */
    private function en407(string $requirement, array $sources): ?CheckRow
    {
        $required = En407Code::first($requirement);
        $codes = [];
        foreach ($sources as $source) {
            foreach (En407Code::allIn($source->text) as $code) {
                $codes[] = [$source, $code->text, $code->levels, $code->canonical()];
            }
        }

        if ($required === null) {
            // „EN 407 poziom 1 (do 100°C)” — nie wiadomo, której pozycji dotyczy, więc karty nie oceniamy
            $vague = En407Code::unspecifiedLevel($requirement);
            if ($vague === null) {
                return null;
            }
            $findings = [];
            $seen = [];
            foreach ($codes as [$source, $text, , $canonical]) {
                if (! $this->seen($seen, $source, $text)) {
                    $findings[] = CheckRow::finding($source, $text, Status::Unclear, ['code' => $canonical]);
                }
            }

            return new CheckRow(
                'en407',
                'EN 407',
                // °C stoi zwykle tuż za poziomem: „EN 407 poziom 1 (do 100°C)”
                ['text' => $vague, 'quote' => CheckRow::quote($requirement, $vague)] + $this->celsius(mb_substr($requirement, (int) mb_strpos($requirement, $vague), mb_strlen($vague) + 40)),
                $findings,
                Status::Unclear,
                'Wymaganie podaje poziom EN 407 bez nazwy parametru (np. ciepło kontaktowe) — porównaj ręcznie.',
            );
        }

        $row = $this->codeRow('en407', 'EN 407', null, $requirement, $this->requiredCodeText($required->text, $required->canonical(), $required->worded), $required->text, $required->canonical(), En407Code::POSITIONS, $required->levels, $codes);

        return new CheckRow($row->key, $row->label, $row->required + $this->celsius($required->context), $row->card, $row->status, $row->note, $row->positions, $row->gate);
    }

    /**
     * @param  list<CardSource>  $sources
     */
    private function ppeCategory(string $requirement, array $sources): ?CheckRow
    {
        $required = PpeCategory::first($requirement);
        if ($required === null) {
            return null;
        }
        $findings = [];
        $seen = [];
        $variants = [];
        $higher = null;
        foreach ($sources as $source) {
            foreach (PpeCategory::allIn($source->text) as $category) {
                if ($this->seen($seen, $source, $category->text)) {
                    continue;
                }
                // „Kategoria: I/II/III”, „(kat. I–III)” — nie wiadomo, która dotyczy produktu
                if ($category->isList()) {
                    $findings[] = CheckRow::finding($source, $category->text, Status::Unclear, ['value' => $category->romans()]);
                    $variants[] = "{$category->text} ({$source->source})";

                    continue;
                }
                $findings[] = CheckRow::finding($source, $category->text, $category->level >= $required->level ? Status::Ok : Status::Fail, ['value' => $category->roman()]);
                if ($category->level > $required->level) {
                    $higher ??= $category->roman();
                }
            }
        }
        $row = $this->valueRow('ppe_category', 'Kategoria ŚOI', null, $requirement, $required->text, $required->roman(), $findings);
        if ($row->status === Status::Ok && $higher !== null) {
            return new CheckRow($row->key, $row->label, $row->required, $row->card, $row->status, "Karta: kategoria {$higher}, wyższa niż wymagana {$required->roman()}.", null, null);
        }

        return $this->withNotes($row, $this->variantsNote('kategorii', $variants));
    }

    /**
     * @param  list<CardSource>  $sources
     */
    private function footwearClass(string $requirement, array $sources): ?CheckRow
    {
        $class = static fn (string $literal): string => preg_replace('/\s+/u', '', $literal) ?? $literal;
        $required = $this->valuesIn($requirement, self::FOOTWEAR, $class);
        if ($required === null) {
            return null;
        }
        $need = $required['values'][0];
        $findings = [];
        $seen = [];
        $variants = [];
        $notes = [];
        foreach ($sources as $source) {
            $have = $this->valuesIn($source->text, self::FOOTWEAR, $class);
            if ($have === null || $this->seen($seen, $source, $have['text'])) {
                continue;
            }
            if (count($have['values']) > 1) {
                $findings[] = CheckRow::finding($source, $have['text'], Status::Unclear, ['value' => implode('/', $have['values'])]);
                $variants[] = "{$have['label']} ({$source->source})";

                continue;
            }
            $verdict = $this->footwearVerdict($need, $have['values'][0]);
            $findings[] = CheckRow::finding($source, $have['text'], $verdict, ['value' => $have['values'][0]]);
            if ($verdict === Status::Unclear) {
                $notes[] = "Wymaganie {$need} podaje typ wkładki antyprzebiciowej (L/S), karta: {$have['values'][0]} ({$source->source}).";
            }
        }
        $row = $this->valueRow('footwear_class', 'Klasa obuwia', 'footwear', $requirement, $required['first'], $need, $findings);

        return $this->withNotes($row, [...$notes, ...$this->variantsNote('klas obuwia', $variants)]);
    }

    /**
     * S3 spełnia S1P, S1 nie spełnia S1P — reguła bramki obuwia. Litera L/S (EN ISO 20345:2022) to typ wkładki
     * antyprzebiciowej: gdy wymaganie ją podaje, a karta nie albo podaje inną, klasa może pasować, ale wkładka —
     * nie wiadomo.
     */
    private function footwearVerdict(string $required, string $have): Status
    {
        $insert = static fn (string $class): ?string => preg_match('/^(?:S1P|S[357])([LS])$/', $class, $m) === 1 ? $m[1] : null;
        $needInsert = $insert($required);
        if ($needInsert === null) {
            return $this->attributes->footwearClassMeets($required, $have) ? Status::Ok : Status::Fail;
        }
        $haveInsert = $insert($have);
        $haveBase = $haveInsert === null ? $have : substr($have, 0, -1);
        if (! $this->attributes->footwearClassMeets(substr($required, 0, -1), $haveBase)) {
            return Status::Fail;
        }

        return $haveInsert === $needInsert ? Status::Ok : Status::Unclear;
    }

    /**
     * Porównanie FFP liczone tutaj, bo BhpAttributeNormalizer::ffpClassMeets przy braku klasy zwraca true.
     *
     * @param  list<CardSource>  $sources
     */
    private function ffp(string $requirement, array $sources): ?CheckRow
    {
        $required = $this->attributes->ffpClass($requirement);
        $requiredText = $this->locate($requirement, '/(?<![\p{L}\d])FFP\s*-?[123](?!\d)/iu');
        if ($required === null || $requiredText === null) {
            return null;
        }
        $findings = [];
        $seen = [];
        $variants = [];
        foreach ($sources as $source) {
            $have = $this->attributes->ffpClass($source->text) === null
                ? null
                : $this->valuesIn($source->text, self::FFP, static fn (string $digit): string => 'FFP'.$digit);
            if ($have === null || $this->seen($seen, $source, $have['text'])) {
                continue;
            }
            if (count($have['values']) > 1) {
                $findings[] = CheckRow::finding($source, $have['text'], Status::Unclear, ['value' => implode('/', $have['values'])]);
                $variants[] = "{$have['label']} ({$source->source})";

                continue;
            }
            $verdict = (int) substr($have['values'][0], 3) >= (int) substr($required, 3) ? Status::Ok : Status::Fail;
            $findings[] = CheckRow::finding($source, $have['text'], $verdict, ['value' => $have['values'][0]]);
        }
        $row = $this->valueRow('ffp', 'Klasa FFP', 'ffp', $requirement, $requiredText, $required, $findings);

        return $this->withNotes($row, $this->variantsNote('klas FFP', $variants));
    }

    /**
     * @param  list<CardSource>  $sources
     */
    private function snr(string $requirement, array $sources): ?CheckRow
    {
        $required = $this->attributes->requiredSnr($requirement);
        if ($required === null) {
            return null;
        }
        $requiredText = $this->locate($requirement, '/(?:SNR|tłumien\p{L}*)[^0-9]{0,32}'.$required.'(?:\s*dB)?/iu') ?? "SNR {$required} dB";
        $findings = [];
        $seen = [];
        $variants = [];
        foreach ($sources as $source) {
            // zakres jak BhpAttributeNormalizer::snrRating — liczba spoza 15–45 dB to nie SNR
            $have = $this->attributes->snrRating($source->text) === null
                ? null
                : $this->valuesIn($source->text, self::SNR, static fn (string $n): ?string => (int) $n >= 15 && (int) $n <= 45 ? (string) (int) $n : null);
            if ($have === null || $this->seen($seen, $source, $have['text'])) {
                continue;
            }
            if (count($have['values']) > 1) {
                $findings[] = CheckRow::finding($source, $have['text'], Status::Unclear, ['value' => implode('/', $have['values'])]);
                $variants[] = "{$have['label']} ({$source->source})";

                continue;
            }
            $value = (int) $have['values'][0];
            $findings[] = CheckRow::finding($source, $have['text'], $value >= $required ? Status::Ok : Status::Fail, ['value' => $value]);
        }
        $row = $this->valueRow('snr', 'Tłumienie SNR', 'snr', $requirement, $requiredText, $required, $findings);

        return $this->withNotes($row, $this->variantsNote('wartości SNR', $variants));
    }

    /**
     * @param  list<CardSource>  $sources
     */
    private function impactClass(string $requirement, array $sources): ?CheckRow
    {
        $required = $this->assortment->requiredImpactClass($requirement);
        if ($required === null) {
            return null;
        }
        $requiredText = $this->locateImpactClass($requirement, $required) ?? "klasa {$required}";
        $findings = [];
        $seen = [];
        foreach ($sources as $source) {
            $highest = $this->assortment->impactClass($source->text);
            if ($highest === null) {
                continue;
            }
            // najniższa klasa w tym samym polu — oprawka BT z soczewką FT to nie pewne B
            $lowest = $this->assortment->requiredImpactClass($source->text) ?? $highest;
            $text = $this->locateImpactClass($source->text, $highest) ?? "klasa {$highest}";
            if ($this->seen($seen, $source, $text)) {
                continue;
            }
            $verdict = match (true) {
                self::IMPACT_RANK[$lowest] >= self::IMPACT_RANK[$required] => Status::Ok,
                self::IMPACT_RANK[$highest] < self::IMPACT_RANK[$required] => Status::Fail,
                default => Status::Unclear,
            };
            $findings[] = CheckRow::finding($source, $text, $verdict, ['value' => $highest]);
        }

        return $this->valueRow('impact_class', 'Klasa uderzenia EN 166', 'impact', $requirement, $requiredText, $required, $findings);
    }

    /**
     * Wiersz kodu z pozycjami (EN 388, EN 407). Werdykt pola = najgorsza pozycja; `positions` z pierwszego pola
     * o najgorszym werdykcie, żeby tabela pokazała, dlaczego wiersz nie jest ✓.
     *
     * @param  array<string, string>  $labels
     * @param  array<string, string|null>  $requiredLevels
     * @param  list<array{0: CardSource, 1: string, 2: array<string, string|null>, 3: string}>  $codes
     */
    private function codeRow(string $key, string $label, ?string $gate, string $requirement, string $requiredText, string $requiredLiteral, string $requiredCode, array $labels, array $requiredLevels, array $codes): CheckRow
    {
        $findings = [];
        $verdicts = [];
        $positionsPerFinding = [];
        $levelsPerFinding = [];
        $seen = [];
        foreach ($codes as [$source, $text, $levels, $canonical]) {
            if ($this->seen($seen, $source, $text)) {
                continue;
            }
            $positions = $this->positions($labels, $requiredLevels, $levels);
            $statuses = [];
            foreach ($positions as $position) {
                if ($position['status'] !== self::SKIP) {
                    $statuses[] = Status::from($position['status']);
                }
            }
            $verdict = Status::worst($statuses);
            $findings[] = CheckRow::finding($source, $text, $verdict, ['code' => $canonical]);
            $verdicts[] = $verdict;
            $positionsPerFinding[] = $positions;
            $levelsPerFinding[$canonical] = $levels;
        }

        $status = Status::fromCardVerdicts($verdicts);
        $note = null;
        if ($findings === []) {
            $shown = $this->positions($labels, $requiredLevels, []);
        } else {
            $shown = $positionsPerFinding[(int) array_search(Status::worst($verdicts), $verdicts, true)];
            $parts = [];
            foreach ($shown as $position) {
                if ($position['status'] === Status::Fail->value) {
                    $parts[] = "{$position['name']} {$position['card']} < {$position['required']}";
                } elseif ($position['status'] === Status::Missing->value) {
                    $parts[] = $position['card'] === 'X' ? "{$position['name']}: X na karcie (nie badano)" : "{$position['name']}: karta nie podaje";
                }
            }
            if ($this->codesConflict(array_values($levelsPerFinding))) {
                $parts[] = 'pola karty podają różne kody: '.implode(', ', array_keys($levelsPerFinding));
            }
            $note = $parts === [] ? null : implode('; ', $parts);
        }

        return new CheckRow(
            $key,
            $label,
            ['text' => $requiredText, 'quote' => CheckRow::quote($requirement, $requiredLiteral), 'code' => $requiredCode],
            $findings,
            $status,
            $note,
            $shown,
            $gate,
        );
    }

    /**
     * Zapis słowny wymagania pokazujemy jako kod tylko wtedy, gdy podaje wszystkie pozycje — „-1----” nic nie mówi,
     * lepiej dosłowne „ciepło kontaktowe poziom 1”.
     */
    private function requiredCodeText(string $literal, string $canonical, bool $worded): string
    {
        return $worded && ! str_contains($canonical, '-') ? $canonical : $literal;
    }

    /**
     * Kody sobie przeczą tylko wtedy, gdy ta sama pozycja ma różne wartości. Specyfikacja „Ciepło kontaktowe: poziom 1”
     * i opis „X1XXXX” podają tę samą jedynkę — to nie sprzeczność.
     *
     * @param  list<array<string, string|null>>  $codes
     */
    private function codesConflict(array $codes): bool
    {
        foreach ($codes as $i => $a) {
            foreach (array_slice($codes, $i + 1) as $b) {
                foreach ($a as $key => $value) {
                    if ($value !== null && ($b[$key] ?? null) !== null && $b[$key] !== $value) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Pozycje podane w wymaganiu. Cyfr Coup Test i liter ISO 13997 nie przeliczamy między sobą.
     *
     * @param  array<string, string>  $labels
     * @param  array<string, string|null>  $required
     * @param  array<string, string|null>  $card
     * @return list<array{name: string, required: string, card: ?string, status: string}>
     */
    private function positions(array $labels, array $required, array $card): array
    {
        $out = [];
        foreach ($labels as $key => $name) {
            $need = $required[$key] ?? null;
            if ($need === null) {
                continue;
            }
            $have = $card[$key] ?? null;
            $status = match (true) {
                $need === 'X' => self::SKIP,
                $have === null || $have === 'X' => Status::Missing->value,
                strcmp($have, $need) >= 0 => Status::Ok->value,
                default => Status::Fail->value,
            };
            $out[] = ['name' => $name, 'required' => $need, 'card' => $have, 'status' => $status];
        }

        return $out;
    }

    /**
     * Wiersz jednej wartości (kategoria, klasa, FFP…). Sprzeczne pola karty → notka z wartościami, żeby było widać,
     * co sobie przeczy.
     *
     * @param  list<array<string, mixed>>  $findings
     */
    private function valueRow(string $key, string $label, ?string $gate, string $requirement, string $requiredText, string|int $value, array $findings): CheckRow
    {
        $verdicts = array_map(static fn (array $f): Status => Status::from((string) $f['verdict']), $findings);
        $status = Status::fromCardVerdicts($verdicts);
        $note = null;
        if ($status === Status::Unclear) {
            $values = array_values(array_unique(array_map(static fn (array $f): string => "{$f['text']} ({$f['source']})", $findings)));
            $note = 'Pola karty podają różne wartości: '.implode(', ', $values).'.';
        }

        return new CheckRow(
            $key,
            $label,
            ['text' => $requiredText, 'quote' => CheckRow::quote($requirement, $requiredText), 'value' => $value],
            $findings,
            $status,
            $note,
            null,
            $gate,
        );
    }

    /**
     * Notka zamiast „pola karty podają różne wartości”, gdy wiersz jest niejasny z powodu wariantów albo typu wkładki.
     * Jeśli pola dodatkowo sobie przeczą (ok i fail), notka o sprzeczności zostaje przed nią.
     *
     * @param  list<string>  $notes
     */
    private function withNotes(CheckRow $row, array $notes): CheckRow
    {
        if ($notes === [] || $row->status !== Status::Unclear) {
            return $row;
        }
        $verdicts = array_column($row->card, 'verdict');
        $conflict = in_array(Status::Ok->value, $verdicts, true) && in_array(Status::Fail->value, $verdicts, true);
        $note = ($conflict && $row->note !== null ? $row->note.' ' : '').implode(' ', $notes);

        return new CheckRow($row->key, $row->label, $row->required, $row->card, $row->status, $note, $row->positions, $row->gate);
    }

    /**
     * @param  list<string>  $variants  „zapis (pole)”
     * @return list<string>
     */
    private function variantsNote(string $what, array $variants): array
    {
        return $variants === [] ? [] : ["Karta wymienia kilka {$what}: ".implode(', ', $variants).' — nie wiadomo, która dotyczy produktu.'];
    }

    /**
     * Trafienia wzorca w polu. `values` — różne wartości (grupa 1 po $value; null pomija trafienie) w kolejności
     * w tekście; `first` — dosłowny zapis pierwszego trafienia; `text` — zapis do znaleziska: pierwsze trafienie albo,
     * przy kilku wartościach, fragment od pierwszego do ostatniego („FFP1, FFP2 i FFP3”), jeśli jest krótki;
     * `label` — ten fragment albo zapisy wartości po przecinku.
     *
     * @param  callable(string): ?string  $value
     * @return array{values: list<string>, first: string, text: string, label: string}|null
     */
    private function valuesIn(string $text, string $pattern, callable $value): ?array
    {
        if (preg_match_all($pattern, $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }
        $literals = [];
        $start = null;
        $end = 0;
        foreach ($m as $hit) {
            $v = $value($hit[1][0]);
            if ($v === null) {
                continue;
            }
            $literals['v'.$v] ??= trim($hit[0][0]);
            $start ??= $hit[0][1];
            $end = $hit[0][1] + strlen($hit[0][0]);
        }
        if ($start === null) {
            return null;
        }
        $values = array_map(static fn (string $k): string => substr($k, 1), array_keys($literals));
        $first = (string) reset($literals);
        if (count($values) === 1) {
            return ['values' => $values, 'first' => $first, 'text' => $first, 'label' => $first];
        }
        $span = trim(substr($text, $start, $end - $start));
        if (mb_strlen($span) <= self::VARIANTS_SPAN_MAX && ! str_contains($span, "\n")) {
            return ['values' => $values, 'first' => $first, 'text' => $span, 'label' => $span];
        }

        return ['values' => $values, 'first' => $first, 'text' => $first, 'label' => implode(', ', $literals)];
    }

    /** Dosłowny zapis litery poziomu cięcia z zapisów słownych, które czyta PpeAssortment::cutLevelsIn. */
    private function locateWordedCutLevel(string $text, string $letter): ?string
    {
        return $this->locate($text, [
            '/(?i:iso)\s*139[79]7\s*[:\-–—]?\s*(?:(?i:poziom)\p{L}*\s*)?'.$letter.'(?![\p{L}\d])/u',
            '/(?i:przecię|przecie|przeciec)\p{L}*[^0-9\n]{0,30}?(?i:poziom)\p{L}*\s*[:\-–—]?\s*'.$letter.'(?![\p{L}\d])/u',
            '/(?i:en\s*iso)\s+'.$letter.'(?![\p{L}\d])/u',
        ]);
    }

    /** Dosłowny zapis klasy uderzenia — tymi samymi zapisami, które czyta PpeAssortment (prędkość, energia, oznaczenie). */
    private function locateImpactClass(string $text, string $class): ?string
    {
        $speed = ['F' => '45', 'B' => '120', 'A' => '190'][$class];
        $energy = ['F' => '(?i:nisk|mał|mal)', 'B' => '(?i:średni|sredni)', 'A' => '(?i:wysok|duż|duz)'][$class];

        return $this->locate($text, [
            '/(?<!\d)'.$speed.'\s*m\s*\/?\s*s(?![\p{L}\d])/u',
            '/(?i:uderz|cząst|czast|odprysk)\p{L}*[\p{L}\d\s]{0,40}?'.$energy.'\p{L}*\s+(?i:energi)\p{L}*/u',
            '/'.$energy.'\p{L}*\s+(?i:energi)\p{L}*\s+(?:(?i:kinetyczn)\p{L}*\s+)?(?i:uderz|cząst|czast)\p{L}*/u',
            '/(?<![\p{L}\d])(?:EN\s?166|166)(?::\s?2001)?\s*[:\-–]?\s*(?:[0-9]\s+){0,4}'.$class.'T?(?![\p{L}\d])/u',
            '/(?<![\p{L}\d])'.$class.'T(?![\p{L}\d])/u',
            '/(?i:klas|oznacz)\p{L}*\s*[:\-–]?\s*\(?'.$class.'T?\)?(?![\p{L}\d])/u',
        ]);
    }

    /**
     * @param  string|list<string>  $patterns
     */
    private function locate(string $text, string|array $patterns): ?string
    {
        foreach ((array) $patterns as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                return trim($m[0]);
            }
        }

        return null;
    }

    /**
     * °C z fragmentu wymagania EN 407 — tylko informacyjnie; BhpAttributeNormalizer::celsiusRatings pomija
     * wartości poniżej 80°C i ujemne.
     *
     * @return array{celsius?: list<int>}
     */
    private function celsius(string $fragment): array
    {
        $ratings = $this->attributes->celsiusRatings($fragment);

        return $ratings === [] ? [] : ['celsius' => $ratings];
    }

    /**
     * To samo pole i ten sam zapis liczymy raz — opis 44-304 powtarza blok norm dwa razy.
     *
     * @param  array<string, true>  $seen
     */
    private function seen(array &$seen, CardSource $source, string $text): bool
    {
        $key = $source->source."\0".$text;
        if (isset($seen[$key])) {
            return true;
        }
        $seen[$key] = true;

        return false;
    }
}
