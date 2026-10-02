<?php

declare(strict_types=1);

namespace App\Services\Substitutes;

use App\Models\Product;
use App\Support\BhpAttributeNormalizer;
use App\Support\PpeAssortment;
use App\Support\RequirementCheck\CardSource;
use App\Support\RequirementCheck\CardSources;
use App\Support\RequirementCheck\LevelChecker;
use App\Support\RequirementCheck\Status;

/**
 * Czy karta kandydata jest zamiennikiem karty głównej — i dowody dlaczego.
 *
 * Zasada: każdy parametr ochronny, który karta główna podaje wprost, karta kandydata też podaje wprost i spełnia
 * (sprawdza LevelChecker, z cytatem z karty kandydata). Brak w karcie kandydata = odrzucenie, nie domysł. Do tego pasmo
 * podobieństwa: „spełnia” to nie to samo co „jest zamiennikiem” — rękawica 4X44F nie zastępuje 4131X, a wkładki z SNR
 * o 8 dB wyższym to inna klasa ochronnika. Cechy rodzaju (powłoka, zakres powłoki, zawór, kształt, sznurek…) muszą być
 * równe; cechy dodatkowe karty głównej (ESD, D, FO, „bez metalu”…) zamiennik musi mieć potwierdzone mocnym źródłem.
 */
final class SubstituteMatcher
{
    /** Wersja reguł — zmiana bramek lub pasm = nowa wersja (trafia do evidence.rules). */
    public const RULES_VERSION = 'substitutes-v3';

    private const LEVEL_LABELS = [
        'en388' => 'EN 388',
        'en407' => 'EN 407',
        'ppe_category' => 'Kategoria ŚOI',
        'footwear_class' => 'Klasa obuwia',
        'ffp' => 'Klasa FFP',
        'snr' => 'Tłumienie SNR',
    ];

    /** Normy bez poziomów ochrony (wymagania ogólne, metody badań) — ich brak na karcie kandydata nie odrzuca. */
    private const NORMS_NOT_REQUIRED = ['21420', '13997', '20344', '13287', '12568', '458'];

    /** Klasa obuwia o jeden stopień wyżej, którą przyjmujemy jako „premium” (ten sam charakter obuwia). */
    private const FOOTWEAR_STEP_UP = [
        'SB' => 'S1', 'S1' => 'S1P', 'S2' => 'S3', 'S3' => 'S7', 'S6' => 'S7', 'S4' => 'S5',
        'OB' => 'O1', 'O1' => 'O1P', 'O2' => 'O3', 'O3' => 'O7', 'O6' => 'O7', 'O4' => 'O5',
    ];

    /** Największa dopuszczalna nadwyżka SNR zamiennika — nadmierne tłumienie to wada (EN 458), nie premia. */
    public const SNR_MAX_ABOVE = 4;

    /** Cechy rodzaju porównywane wartością, nie tak/nie (osobne reguły w flagsMismatch). */
    private const VALUE_FLAGS = ['coating', 'coverage', 'valve', 'shape', 'plug_kind', 'mount', 'main_toe', 'main_plate', 'strong_toe', 'strong_plate'];

    public const ARTICLE_TYPE_LABELS = [
        'cut' => 'rękawice powlekane antyprzecięciowe',
        'coated' => 'rękawice powlekane',
        PpeAssortment::TYPE_POLBUT => 'półbuty',
        PpeAssortment::TYPE_TRZEWIK => 'trzewiki',
        PpeAssortment::TYPE_SANDAL => 'sandały',
        PpeAssortment::TYPE_KALOSZ => 'kalosze',
        PpeAssortment::TYPE_SZTYBLET => 'sztyblety',
        'ffp' => 'półmaska filtrująca',
        'earmuff' => 'nauszniki',
        'earplug' => 'wkładki przeciwhałasowe',
    ];

    private const FLAG_LABELS = [
        'needle' => 'ochrona przed igłami',
        'impact' => 'ochrona przed uderzeniami',
        'welding' => 'spawalnicze',
        'winter' => 'ocieplane',
        'cuff' => 'długi mankiet',
        'metatarsal' => 'ochrona śródstopia',
        'carbon' => 'węgiel aktywny / spawalnicza',
        'food' => 'spożywcza / wykrywalna',
        'corded' => 'ze sznurkiem',
        'detectable' => 'wykrywalne',
        'banded' => 'na pałąku',
        'helmet' => 'nahełmowe',
        'electronic' => 'elektroniczne',
        'dispenser' => 'wkład do dozownika',
        'calf' => 'cholewka do połowy łydki',
        'hygiene' => 'higieniczne / łatwe do mycia',
        'slip_on' => 'wsuwane',
        'perforated' => 'perforowane',
        'semi_insert' => 'półwkładki (nie wnikają do kanału)',
        'folding' => 'składane',
        'junior' => 'dziecięce',
    ];

    private const COVERAGE_LABELS = ['full' => 'pełna powłoka', 'three_quarter' => 'powłoka ¾', 'palm' => 'powłoka na dłoni'];

    private const C_LABELS = ['esd' => 'antystatyczne / ESD', 'food' => 'kontakt z żywnością', 'nr' => 'NR', 'r' => 'R', 'dolomite' => 'D (dolomit)'];

    public const NOT_CHECKED = [
        PpeAssortment::FAMILY_GLOVES => ['rozmiarówka', 'grubość i gramatura dzianiny', 'chwyt na mokro i w oleju', 'kolor', 'dopasowanie i komfort'],
        PpeAssortment::FAMILY_FOOTWEAR => ['rozmiarówka i tęgość', 'materiał cholewki', 'kolor', 'waga'],
        PpeAssortment::FAMILY_RESPIRATORY => ['rozmiar', 'współpraca z okularami', 'pakowanie'],
        PpeAssortment::FAMILY_HEARING => ['rozmiar wkładek / nacisk pałąka', 'współpraca z kaskiem i okularami', 'kolor', 'pakowanie'],
    ];

    public function __construct(
        private readonly SubstituteProfiler $profiler = new SubstituteProfiler,
        private readonly LevelChecker $levels = new LevelChecker,
        private readonly BhpAttributeNormalizer $attributes = new BhpAttributeNormalizer,
    ) {}

    /**
     * Szybkie sito bez LevelChecker (na profilach): null — para idzie dalej, tekst — powód odrzucenia.
     */
    public function prefilter(SubstituteProfile $main, SubstituteProfile $cand): ?string
    {
        if ($main->family !== $cand->family) {
            return 'inna grupa wyrobów';
        }
        if ($main->brandKey === '' || $cand->brandKey === '' || $main->brandKey === $cand->brandKey) {
            return 'ten sam producent';
        }
        if ($cand->articleType !== $main->articleType) {
            return 'inny rodzaj wyrobu';
        }
        foreach ($this->requiredNorms($main) as $key => $norm) {
            if (! $this->hasNorm($cand, $key)) {
                return "brak normy {$norm['label']}";
            }
        }

        return $this->flagsMismatch($main, $cand);
    }

    /**
     * Pełne porównanie pary. Zwraca dowody (params jak w evidence) albo powód odrzucenia.
     *
     * @return array{ok: true, verdict: string, params: list<array<string, mixed>>, extra_in_sub: list<string>, score: int, reason: string}|array{ok: false, reason: string}
     */
    public function compare(Product $main, SubstituteProfile $mainProfile, Product $cand, SubstituteProfile $candProfile): array
    {
        $pre = $this->prefilter($mainProfile, $candProfile);
        if ($pre !== null) {
            return ['ok' => false, 'reason' => $pre];
        }
        $issue = $this->profiler->cardIssue($cand, $candProfile);
        if ($issue !== null) {
            return ['ok' => false, 'reason' => "karta zamiennika: {$issue}"];
        }
        if ($this->sameModel($main, $cand)) {
            return ['ok' => false, 'reason' => 'ten sam model (inna karta tego wyrobu)'];
        }
        $sources = CardSources::fromProduct($cand);

        $params = [$this->articleTypeParam($mainProfile, $candProfile)];
        foreach ($mainProfile->levels as $key => $level) {
            $row = $this->profiler->checkFragment($key, $level['fragment'], $sources);
            if ($row === null || $row->status !== Status::Ok) {
                $status = $row?->status->value ?? 'brak';

                return ['ok' => false, 'reason' => self::LEVEL_LABELS[$key].": {$level['value']} — karta zamiennika: {$status}"];
            }
            $finding = $this->profiler->firstOkFinding($row);
            $candValue = (string) ($finding['code'] ?? $finding['value'] ?? '');
            // SNR z częścią dziesiętną z profilu kandydata (LevelChecker czyta całe decybele)
            if ($key === 'snr' && isset($candProfile->levels['snr'])) {
                $candValue = $candProfile->levels['snr']['value'];
            }
            $relation = $this->relation($key, $level['value'], $candValue);
            if ($relation === null) {
                return ['ok' => false, 'reason' => self::LEVEL_LABELS[$key].": {$candValue} zamiast {$level['value']} — poza pasmem podobieństwa"];
            }
            // SNR pokazujemy jako wartość z częścią dziesiętną — dosłowny zapis znaleziska bywa ucięty („SNR: 27” z „27,5 dB”)
            $snrText = static fn (string $v): string => 'SNR '.str_replace('.', ',', $v).' dB';
            $params[] = [
                'key' => $key,
                'label' => self::LEVEL_LABELS[$key],
                'main' => ['value' => $level['value'], 'text' => $key === 'snr' ? $snrText($level['value']) : $level['text'], 'source' => $level['source'], 'quote' => $level['quote'], 'inferred' => false],
                'sub' => ['value' => $candValue, 'text' => $key === 'snr' ? $snrText($candValue) : (string) ($finding['text'] ?? $candValue), 'source' => (string) ($finding['source'] ?? CardSource::DESCRIPTION), 'quote' => $finding['quote'] ?? null, 'inferred' => false],
                'relation' => $relation,
                'note' => $row->note,
            ];
        }
        foreach ($this->flagParams($mainProfile, $candProfile) as $param) {
            $params[] = $param;
        }
        [$normsParam, $extra] = $this->normsParam($mainProfile, $candProfile);
        if ($normsParam !== null) {
            $params[] = $normsParam;
        }

        $relations = array_column($params, 'relation');
        $higher = array_values(array_filter($params, static fn (array $p): bool => $p['relation'] === 'higher'));
        $verdict = $higher !== [] ? 'premium' : 'preferowany';
        $equal = count(array_keys($relations, 'equal', true));
        $meets = count(array_keys($relations, 'meets', true));
        $score = 10 * $equal + 6 * $meets + 3 * count($higher)
            + count(array_intersect_key($candProfile->norms, $mainProfile->norms));

        return [
            'ok' => true,
            'verdict' => $verdict,
            'params' => $params,
            'extra_in_sub' => $extra,
            'score' => $score,
            'reason' => $this->reasonText($mainProfile, $cand, $params, $higher),
        ];
    }

    /**
     * Relacja wartości zamiennika do wartości karty głównej (LevelChecker potwierdził już „spełnia”): equal / higher /
     * meets, albo null — poza pasmem podobieństwa.
     */
    public function relation(string $key, string $main, string $cand): ?string
    {
        if ($cand === '') {
            return null;
        }
        if (mb_strtoupper($cand) === mb_strtoupper($main)) {
            return 'equal';
        }
        // kody z różną liczbą pozycji („X1XXX” i „X1XXXX”, „4X43C” i „4X43CP”) — równe na pozycjach podanych w obu
        if (($key === 'en388' || $key === 'en407') && $this->samePositions($main, $cand)) {
            return 'equal';
        }

        return match ($key) {
            'en388' => $this->en388Relation($main, $cand),
            // EN 407: tylko ten sam kod — X1XXXX i 42324X to inne rękawice (zwykła i hutnicza)
            'en407' => null,
            'ppe_category' => $this->romanDiff($main, $cand) === 1 ? 'higher' : null,
            'footwear_class' => $this->footwearRelation($main, $cand),
            'ffp' => ((int) substr($cand, 3)) - ((int) substr($main, 3)) === 1 ? 'higher' : null,
            'snr' => ((float) $cand - (float) $main) >= 0 && ((float) $cand - (float) $main) <= self::SNR_MAX_ABOVE
                ? ((float) $cand === (float) $main ? 'equal' : 'meets') : null,
            default => null,
        };
    }

    private function samePositions(string $main, string $cand): bool
    {
        $m = str_split(mb_strtoupper($main));
        $c = str_split(mb_strtoupper($cand));
        for ($i = 0, $n = min(count($m), count($c)); $i < $n; $i++) {
            if ($m[$i] !== $c[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * EN 388: każda pozycja cyfrowa najwyżej o jeden poziom wyżej, litera ISO 13997 równa albo o jedną wyżej (pozycje
     * „X” po którejkolwiek stronie pomijamy — „spełnia” sprawdził już LevelChecker).
     */
    private function en388Relation(string $main, string $cand): ?string
    {
        $m = str_split(mb_strtoupper($main));
        $c = str_split(mb_strtoupper($cand));
        for ($i = 0; $i <= 4; $i++) {
            $mv = $m[$i] ?? null;
            $cv = $c[$i] ?? null;
            if ($mv === null || $cv === null || $mv === 'X' || $cv === 'X') {
                continue;
            }
            if (ord($cv) - ord($mv) > 1) {
                return null;
            }
        }

        return 'higher';
    }

    private function footwearRelation(string $main, string $cand): ?string
    {
        $mb = $this->attributes->footwearClassBase($main);
        $cb = $this->attributes->footwearClassBase($cand);
        if ($mb === $cb) {
            // ten sam stopień, inny zapis (S1P i S1PL) — spełnia
            return 'meets';
        }

        return (self::FOOTWEAR_STEP_UP[$mb] ?? null) === $cb ? 'higher' : null;
    }

    private function romanDiff(string $a, string $b): int
    {
        $n = ['I' => 1, 'II' => 2, 'III' => 3];

        return ($n[$b] ?? 0) - ($n[$a] ?? 0);
    }

    /**
     * Normy EN karty głównej z poziomami ochrony, które zamiennik musi podać.
     *
     * @return array<string, array{label: string, source: ?string}>
     */
    public function requiredNorms(SubstituteProfile $main): array
    {
        return array_filter(
            $main->norms,
            fn (string $key): bool => str_starts_with($key, 'en') && ! in_array($this->profiler->normBase($key), self::NORMS_NOT_REQUIRED, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** Norma z częścią („en352-2”) — ta sama część; bez części („en352”) — dowolna część tej normy. */
    private function hasNorm(SubstituteProfile $cand, string $key): bool
    {
        if (isset($cand->norms[$key])) {
            return true;
        }
        if (str_contains($key, '-')) {
            return false;
        }
        foreach (array_keys($cand->norms) as $have) {
            if (str_starts_with($have, 'en') && $this->profiler->normBase($have) === $this->profiler->normBase($key)) {
                return true;
            }
        }

        return false;
    }

    private function flagsMismatch(SubstituteProfile $main, SubstituteProfile $cand): ?string
    {
        $mk = $main->flags['k'];
        $ck = $cand->flags['k'];
        // cechy rodzaju tak/nie — równe po obu stronach
        foreach ($mk as $name => $value) {
            if (in_array($name, self::VALUE_FLAGS, true) || ! is_bool($value)) {
                continue;
            }
            if ($value !== ($ck[$name] ?? false)) {
                $label = self::FLAG_LABELS[$name] ?? $name;

                return "cecha „{$label}” tylko w jednej karcie";
            }
        }
        foreach (['coating' => 'inna powłoka', 'coverage' => 'inny zakres powłoki', 'valve' => 'inny lub nieznany zawór'] as $name => $reason) {
            if (array_key_exists($name, $mk) && ($mk[$name] === null || ($ck[$name] ?? null) !== $mk[$name])) {
                return $reason;
            }
        }
        foreach (['shape' => 'inny lub nieznany kształt półmaski', 'plug_kind' => 'inny rodzaj wkładek', 'mount' => 'inny sposób noszenia nauszników'] as $name => $reason) {
            if (($mk[$name] ?? null) !== null && ($ck[$name] ?? null) !== $mk[$name]) {
                return $reason;
            }
        }
        // cechy dodatkowe karty głównej — u zamiennika potwierdzone mocnym źródłem
        foreach ($main->flags['c'] as $name => $evidence) {
            if ($name === 'fo_2011' || $name === 'metal_free' || ! $evidence['weak']) {
                continue;
            }
            if (! ($cand->flags['c'][$name]['strong'] ?? false)) {
                return 'brak potwierdzenia: '.(self::C_LABELS[$name] ?? $name);
            }
        }
        if ($main->family === PpeAssortment::FAMILY_FOOTWEAR) {
            return $this->footwearMismatch($main, $cand);
        }

        return null;
    }

    private function footwearMismatch(SubstituteProfile $main, SubstituteProfile $cand): ?string
    {
        // „Metal free” całej karty głównej: zamiennik musi wprost mówić o całym bucie bez metalu — sam podnosek
        // bez metalu to za mało (audyt 5: „100% wolny od metalu podnosek” przy „sandały Metal free”)
        if (($main->flags['c']['metal_free']['weak'] ?? false) && ! ($cand->flags['c']['metal_free']['strong'] ?? false)) {
            return 'brak potwierdzenia: cały but bez metalu';
        }
        foreach ($this->footwearParts($main) as $part => $label) {
            if (($main->flags['k']['main_'.$part] ?? null) === 'nonmetal' && ($cand->flags['k']['strong_'.$part] ?? null) !== 'nonmetal') {
                return "brak potwierdzenia: {$label} bez metalu";
            }
        }
        $have = $this->effectiveMarkings($cand);
        foreach (array_intersect($main->markings, SubstituteProfiler::FOOTWEAR_MARKINGS_REQUIRED) as $mark) {
            if (! in_array($mark, $have, true)) {
                return "brak oznaczenia {$mark}";
            }
        }
        $fullSlip = ['SRC', 'SR'];
        if (array_intersect($main->markings, $fullSlip) !== [] && array_intersect($have, $fullSlip) === []) {
            return 'brak antypoślizgowości SRC/SR';
        }
        if (array_intersect($main->markings, ['SRA', 'SRB']) !== [] && array_intersect($have, ['SRA', 'SRB', 'SRC', 'SR']) === []) {
            return 'brak oznaczenia antypoślizgowości';
        }
        if (in_array('FO', $main->markings, true) && ! $this->foImpliedByClass($main) && ! in_array('FO', $have, true) && ! $this->foImpliedByClass($cand)) {
            return 'brak oznaczenia FO';
        }

        return null;
    }

    /**
     * Elementy ochronne buta do porównania materiału: podnosek zawsze (klasy O nie mają go — wtedy karta go nie opisze),
     * wkładka antyprzebiciowa tylko przy klasach z odpornością na przebicie (S1P, S3, S5, S7, O1P, O3, O5, O7).
     *
     * @return array<string, string>
     */
    private function footwearParts(SubstituteProfile $main): array
    {
        $class = $this->attributes->footwearClassBase((string) ($main->levels['footwear_class']['value'] ?? ''));
        $parts = ['toe' => 'podnosek'];
        if (in_array($class, ['S1P', 'S3', 'S5', 'S7', 'O1P', 'O3', 'O5', 'O7'], true)) {
            $parts['plate'] = 'wkładka antyprzebiciowa';
        }

        return $parts;
    }

    /** FO jest częścią klasy S1–S3 w wydaniu 2011 — tylko gdy karta podaje rok 2011 wprost. */
    private function foImpliedByClass(SubstituteProfile $profile): bool
    {
        $class = $profile->levels['footwear_class']['value'] ?? null;

        return $class !== null
            && in_array($this->attributes->footwearClassBase($class), ['S1', 'S1P', 'S2', 'S3'], true)
            && ($profile->flags['c']['fo_2011']['weak'] ?? false);
    }

    /**
     * Oznaczenia zamiennika z mocnych pól, z WR zawartym w klasie S6/S7 (O6/O7) z wydania 2022.
     *
     * @return list<string>
     */
    private function effectiveMarkings(SubstituteProfile $profile): array
    {
        $marks = $profile->strongMarkings;
        $class = $profile->levels['footwear_class']['value'] ?? null;
        if ($class !== null && in_array($this->attributes->footwearClassBase($class), ['S6', 'S7', 'O6', 'O7'], true)) {
            $marks[] = 'WR';
        }

        return array_values(array_unique($marks));
    }

    /**
     * Ten sam model pod inną kartą (dystrybutor, karta z innym kodem): oznaczenie modelu z nazwy karty głównej
     * (słowo z cyfrą, nie klasa i nie norma) w nazwie kandydata.
     */
    public function sameModel(Product $main, Product $cand): bool
    {
        // w obie strony i po członach kodu: „Ear muffs Peltor H510A-401-GU” (karta dystrybutora) i „Optime H510A”
        return array_intersect($this->modelTokens((string) $main->name), $this->modelTokens((string) $cand->name)) !== [];
    }

    /**
     * @return list<string>
     */
    private function modelTokens(string $name): array
    {
        $out = [];
        foreach (explode(' ', $this->tokensText($name)) as $token) {
            if (mb_strlen($token) < 3 || preg_match('/\d/', $token) !== 1) {
                continue;
            }
            // klasy, normy, poziomy i rozmiary to nie model
            if (preg_match('/^(s[1-7bp]+[ls]?|o[1-7bp]+[ls]?|ffp\d|en\d+|\d{3,5}|[0-5x]{4}[a-fx]?|\d+(cm|mm|db|szt|par)|\d{2}-\d{2})$/', $token) === 1
                && preg_match('/^\d{4,5}$/', $token) !== 1) {
                continue;
            }
            $out[] = $token;
            // człony kodu z literą i cyfrą („h510a” z „h510a-401-gu”)
            foreach (str_contains($token, '-') ? explode('-', $token) : [] as $part) {
                if (mb_strlen($part) >= 4 && preg_match('/\d/', $part) === 1 && preg_match('/\p{L}/u', $part) === 1) {
                    $out[] = $part;
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function tokensText(string $name): string
    {
        $t = mb_strtolower($name);
        $t = preg_replace('/[^\p{L}\d+\-]+/u', ' ', $t) ?? $t;

        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /**
     * @return array<string, mixed>
     */
    private function articleTypeParam(SubstituteProfile $main, SubstituteProfile $cand): array
    {
        $label = self::ARTICLE_TYPE_LABELS[$main->articleType] ?? (string) $main->articleType;

        return [
            'key' => 'article_type',
            'label' => 'Rodzaj wyrobu',
            'main' => ['value' => $main->articleType, 'text' => $label, 'source' => $main->articleTypeFromName ? CardSource::NAME : 'derived', 'quote' => null, 'inferred' => true],
            'sub' => ['value' => $cand->articleType, 'text' => $label, 'source' => $cand->articleTypeFromName ? CardSource::NAME : 'derived', 'quote' => null, 'inferred' => true],
            'relation' => 'equal',
            'note' => null,
        ];
    }

    /**
     * Cechy odczytane regułą (wniosek automatu): pokazujemy te, które coś mówią o karcie głównej.
     *
     * @return list<array<string, mixed>>
     */
    private function flagParams(SubstituteProfile $main, SubstituteProfile $cand): array
    {
        $param = static fn (string $key, string $label, string $mainText, string $subText, string $relation = 'equal'): array => [
            'key' => $key,
            'label' => $label,
            'main' => ['value' => $mainText, 'text' => $mainText, 'source' => 'derived', 'quote' => null, 'inferred' => true],
            'sub' => ['value' => $subText, 'text' => $subText, 'source' => 'derived', 'quote' => null, 'inferred' => true],
            'relation' => $relation,
            'note' => null,
        ];
        $mk = $main->flags['k'];
        $ck = $cand->flags['k'];
        $out = [];
        if (isset($mk['coating'])) {
            $out[] = $param('coating', 'Powłoka', str_replace(',', ', ', (string) $mk['coating']), str_replace(',', ', ', (string) $ck['coating']));
        }
        if (isset($mk['coverage'])) {
            $out[] = $param('coverage', 'Zakres powłoki', self::COVERAGE_LABELS[$mk['coverage']] ?? (string) $mk['coverage'], self::COVERAGE_LABELS[$ck['coverage']] ?? (string) $ck['coverage']);
        }
        if (isset($mk['valve'])) {
            $out[] = $param('valve', 'Zawór wydechowy', (string) $mk['valve'], (string) $ck['valve']);
        }
        if (isset($mk['shape'])) {
            $out[] = $param('shape', 'Kształt', (string) $mk['shape'], (string) $ck['shape']);
        }
        if (isset($mk['mount'])) {
            $out[] = $param('mount', 'Sposób noszenia', (string) $mk['mount'], (string) $ck['mount']);
        }
        if (isset($mk['plug_kind'])) {
            $out[] = $param('plug_kind', 'Wkładki', (string) $mk['plug_kind'], (string) $ck['plug_kind']);
        }
        foreach ($main->family === PpeAssortment::FAMILY_FOOTWEAR ? $this->footwearParts($main) : [] as $part => $label) {
            $label = mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1);
            $m = $mk['main_'.$part] ?? null;
            if ($m !== null) {
                $c = $ck['strong_'.$part] ?? $ck['main_'.$part] ?? null;
                $text = static fn (?string $v): string => match ($v) {
                    'metal' => 'metalowa / stalowa',
                    'nonmetal' => 'bez metalu',
                    default => 'karta nie podaje',
                };
                $out[] = $param($part, $label, $text($m), $text($c), $m === $c ? 'equal' : 'meets');
            }
        }
        if ($main->markings !== []) {
            $out[] = [
                'key' => 'markings',
                'label' => 'Oznaczenia',
                'main' => ['value' => implode(', ', $main->markings), 'text' => implode(', ', $main->markings), 'source' => 'derived', 'quote' => null, 'inferred' => false],
                'sub' => ['value' => implode(', ', $cand->strongMarkings), 'text' => implode(', ', $cand->strongMarkings), 'source' => 'derived', 'quote' => null, 'inferred' => false],
                'relation' => array_diff($cand->strongMarkings, $main->markings) === [] ? 'equal' : 'meets',
                'note' => null,
            ];
        }
        $features = [];
        foreach ($mk as $name => $value) {
            if ($value === true && isset(self::FLAG_LABELS[$name])) {
                $features[] = self::FLAG_LABELS[$name];
            }
        }
        foreach ($main->flags['c'] as $name => $evidence) {
            if ($evidence['weak'] && isset(self::C_LABELS[$name])) {
                $features[] = self::C_LABELS[$name];
            }
        }
        if ($features !== []) {
            $out[] = $param('features', 'Cechy', implode(', ', $features), implode(', ', $features));
        }

        return $out;
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: list<string>}
     */
    private function normsParam(SubstituteProfile $main, SubstituteProfile $cand): array
    {
        $extra = array_values(array_map(
            static fn (array $n): string => $n['label'],
            array_diff_key($cand->norms, $main->norms),
        ));
        if ($main->norms === []) {
            return [null, $extra];
        }
        $mainLabels = array_column($main->norms, 'label');
        $subLabels = array_column(array_intersect_key($cand->norms, $main->norms), 'label');
        $missingOptional = array_diff_key($main->norms, $cand->norms);

        return [[
            'key' => 'norms',
            'label' => 'Normy',
            'main' => ['value' => implode(', ', $mainLabels), 'text' => implode(', ', $mainLabels), 'source' => CardSource::NORMS, 'quote' => null, 'inferred' => false],
            'sub' => ['value' => implode(', ', $subLabels), 'text' => implode(', ', $subLabels), 'source' => CardSource::NORMS, 'quote' => null, 'inferred' => false],
            'relation' => $missingOptional === [] ? 'equal' : 'meets',
            'note' => $missingOptional === [] ? null
                : 'Karta zamiennika nie wymienia: '.implode(', ', array_column($missingOptional, 'label')).' (wymagania ogólne, metoda badania albo inna część normy — bez poziomu ochrony).',
        ], $extra];
    }

    /**
     * @param  list<array<string, mixed>>  $params
     * @param  list<array<string, mixed>>  $higher
     */
    private function reasonText(SubstituteProfile $main, Product $cand, array $params, array $higher): string
    {
        $type = self::ARTICLE_TYPE_LABELS[$main->articleType] ?? (string) $main->articleType;
        $checked = array_values(array_filter($params, static fn (array $p): bool => $p['key'] !== 'article_type'));
        $text = ucfirst($type).' '.trim((string) $cand->manufacturer).': spełnia '.count($checked).' z '.count($checked)
            .' sprawdzonych parametrów karty głównej';
        if ($higher !== []) {
            $text .= '; wyżej: '.implode(', ', array_map(
                static fn (array $p): string => "{$p['label']} {$p['sub']['value']} zamiast {$p['main']['value']}",
                $higher,
            ));
        }

        return $text.'.';
    }
}
