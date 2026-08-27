<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;

/**
 * Facety zamienników z karty produktu (twarde fakty, nie marketing).
 * Zaznaczone = AND: kandydat musi spełniać każdy warunek.
 */
final class ProductCrossRefFilters
{
    public const GROUP_SPEC = 'spec';

    public const GROUP_FEATURE = 'feature';

    public const GROUP_MATERIAL = 'material';

    public const GROUP_NORM = 'norm';

    public const GROUP_CERT = 'certificate';

    public const GROUP_USE = 'use';

    /** @var array<string, string> */
    private const GROUP_LABELS = [
        self::GROUP_SPEC => 'Specyfikacja',
        self::GROUP_FEATURE => 'Cechy',
        self::GROUP_MATERIAL => 'Materiały',
        self::GROUP_NORM => 'Normy',
        self::GROUP_CERT => 'Certyfikaty',
        self::GROUP_USE => 'Zastosowanie',
    ];

    /** @var array<string, string> */
    private const TYPE_LABELS = [
        'ffp' => 'półmaska filtrująca (FFP)',
        'fullface' => 'maska pełnotwarzowa',
        'reusable_half' => 'półmaska wielorazowa',
        'filter' => 'pochłaniacz / filtr',
        'kalosz' => 'kalosz',
        'trzewik' => 'trzewik',
        'polbut' => 'półbut',
        'sandal' => 'sandał',
        'disposable' => 'rękawice jednorazowe',
        'welding' => 'rękawice spawalnicze',
        'cut' => 'rękawice antyprzecięciowe',
        'chemical' => 'rękawice chemiczne',
        'leather' => 'rękawice skórzane',
        'coated' => 'rękawice powlekane',
        'goggles' => 'gogle',
        'glasses' => 'okulary',
        'earmuff' => 'nauszniki',
        'earplug' => 'wkładki przeciwhałasowe',
        'helmet' => 'hełm / kask',
        'cap' => 'czapka',
        'balaclava' => 'kominiarka',
        'welding_helmet' => 'przyłbica',
        'shield' => 'osłona twarzy',
        'harness' => 'szelki',
        'lanyard' => 'linka / lonża',
        'kneepad' => 'nakolanniki',
    ];

    /** @var array<string, string> */
    private const PURPOSE_LABELS = [
        'welding' => 'spawanie',
        'agriculture' => 'rolnictwo',
        'food' => 'spożywka',
        'chemical' => 'chemia',
        'hivis' => 'odblask / hi-vis',
        'electric' => 'elektryka',
    ];

    /** @var array<string, array{group: string, rank: int}> */
    private const CLASS_RANKS = [
        'OB' => ['group' => 'footwear', 'rank' => 1],
        'SB' => ['group' => 'footwear', 'rank' => 2],
        'S1' => ['group' => 'footwear', 'rank' => 3],
        'S1P' => ['group' => 'footwear', 'rank' => 4],
        'S2' => ['group' => 'footwear', 'rank' => 5],
        'S3' => ['group' => 'footwear', 'rank' => 6],
        'S4' => ['group' => 'footwear', 'rank' => 7],
        'S5' => ['group' => 'footwear', 'rank' => 8],
        'S7' => ['group' => 'footwear', 'rank' => 9],
        'FFP1' => ['group' => 'ffp', 'rank' => 1],
        'FFP2' => ['group' => 'ffp', 'rank' => 2],
        'FFP3' => ['group' => 'ffp', 'rank' => 3],
        'KAT1' => ['group' => 'ppe_kat', 'rank' => 1],
        'KAT2' => ['group' => 'ppe_kat', 'rank' => 2],
        'KAT3' => ['group' => 'ppe_kat', 'rank' => 3],
    ];

    /**
     * @param  array<string, mixed>  $attrs
     * @return list<array{id: string, label: string, items: list<array{id: string, label: string, default: bool}>}>
     */
    public function groupsFor(Product $product, array $attrs): array
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $hay = $this->haystack($product, $attrs);
        $buckets = [
            self::GROUP_SPEC => [],
            self::GROUP_FEATURE => [],
            self::GROUP_MATERIAL => [],
            self::GROUP_NORM => [],
            self::GROUP_CERT => [],
            self::GROUP_USE => [],
        ];

        $type = is_string($attrs['typ_wyrobu'] ?? null) ? $attrs['typ_wyrobu'] : null;
        if ($type !== null && $type !== '') {
            $buckets[self::GROUP_SPEC][] = $this->item(
                self::GROUP_SPEC,
                'typ',
                $type,
                'Typ: '.$this->typeLabel($type),
                true
            );
        }

        $klasa = is_string($attrs['klasa_ochrony'] ?? null) ? trim($attrs['klasa_ochrony']) : '';
        if ($klasa !== '') {
            $buckets[self::GROUP_SPEC][] = $this->item(
                self::GROUP_SPEC,
                'klasa',
                $this->classKey($klasa) ?? $klasa,
                'Klasa: '.$klasa,
                true
            );
        }

        $marks = $this->markingSet($attrs);
        $klassBlob = mb_strtoupper(trim(
            (string) ($attrs['klasa_ochrony'] ?? '').' '.(string) ($product->description ?? '')
        ));
        if (preg_match('/FFP\s*[123].*\bD\b/u', $klassBlob) === 1) {
            $marks[] = 'D';
        }
        foreach (array_values(array_unique($marks)) as $mark) {
            $buckets[self::GROUP_SPEC][] = $this->item(
                self::GROUP_SPEC,
                'mark',
                $mark,
                'Oznaczenie: '.$mark,
                false
            );
        }

        $valve = $this->valveState($hay);
        if ($valve === 1) {
            $buckets[self::GROUP_SPEC][] = $this->item(self::GROUP_SPEC, 'zawor', '1', 'Zawór wydechowy', false);
        } elseif ($valve === 0) {
            $buckets[self::GROUP_SPEC][] = $this->item(self::GROUP_SPEC, 'zawor', '0', 'Bez zaworu', false);
        }

        $forma = $this->formLabel($hay);
        if ($forma !== null) {
            $buckets[self::GROUP_SPEC][] = $this->item(
                self::GROUP_SPEC,
                'forma',
                $forma,
                'Konstrukcja: '.($forma === 'skladana' ? 'składana' : 'muszlowa'),
                false
            );
        }

        $en388 = is_string($attrs['poziomy_en388'] ?? null) ? trim($attrs['poziomy_en388']) : '';
        if ($en388 !== '') {
            $buckets[self::GROUP_SPEC][] = $this->item(self::GROUP_SPEC, 'en388', $en388, 'EN 388: '.$en388, false);
        }

        $cel = is_string($attrs['przeznaczenie'] ?? null) ? $attrs['przeznaczenie'] : null;
        if ($cel !== null && $cel !== '') {
            $buckets[self::GROUP_SPEC][] = $this->item(
                self::GROUP_SPEC,
                'cel',
                $cel,
                'Przeznaczenie: '.(self::PURPOSE_LABELS[$cel] ?? $cel),
                false
            );
        }

        foreach ($this->featureTokens($this->stringList($payload['features'] ?? null)) as $feature) {
            $slug = $this->slug($feature);
            if ($slug === '') {
                continue;
            }
            $buckets[self::GROUP_FEATURE][] = $this->item(self::GROUP_FEATURE, 'v', $slug, $feature, false);
        }

        $materials = array_values(array_unique(array_merge(
            $this->stringList($attrs['materialy'] ?? null),
            $this->stringList($payload['materials'] ?? null),
        )));
        foreach ($this->shortFacts($materials, 40, 10) as $mat) {
            if ($this->isJunkLine($mat)) {
                continue;
            }
            $slug = $this->slug($mat);
            if ($slug === '') {
                continue;
            }
            $buckets[self::GROUP_MATERIAL][] = $this->item(self::GROUP_MATERIAL, 'v', $slug, $mat, false);
        }

        $seenNorm = [];
        foreach ($this->stringList($attrs['normy_en'] ?? null) as $norm) {
            $core = $this->normCore($norm);
            if ($core === null || isset($seenNorm[$core])) {
                continue;
            }
            $seenNorm[$core] = true;
            $buckets[self::GROUP_NORM][] = $this->item(self::GROUP_NORM, 'en', substr($core, 2), $this->normLabel($norm), true);
        }

        foreach ($this->shortFacts($this->stringList($payload['certificates'] ?? null), 40, 8) as $cert) {
            if (str_contains(mb_strtolower($cert), 'http')) {
                continue;
            }
            $slug = $this->slug($cert);
            if ($slug === '') {
                continue;
            }
            $buckets[self::GROUP_CERT][] = $this->item(self::GROUP_CERT, 'v', $slug, $cert, false);
        }

        foreach ($this->shortFacts($this->stringList($payload['use_cases'] ?? null), 72, 10) as $use) {
            if ($this->isJunkLine($use)) {
                continue;
            }
            $slug = $this->slug($use);
            if ($slug === '') {
                continue;
            }
            $buckets[self::GROUP_USE][] = $this->item(self::GROUP_USE, 'v', $slug, $use, false);
        }

        $groups = [];
        foreach ($buckets as $groupId => $items) {
            $items = $this->uniqueItems($items);
            if ($items === []) {
                continue;
            }
            $groups[] = [
                'id' => $groupId,
                'label' => self::GROUP_LABELS[$groupId],
                'items' => $items,
            ];
        }

        return $groups;
    }

    /**
     * @param  list<string>  $must
     * @return list<string>
     */
    public function sanitizeMust(array $must): array
    {
        $out = [];
        foreach ($must as $id) {
            if (! is_string($id)) {
                continue;
            }
            $id = trim($id);
            if ($id === '' || ! $this->isValidId($id)) {
                continue;
            }
            $out[] = $id;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $must
     * @param  array<string, mixed>  $candAttrs
     */
    public function matchesAll(array $must, Product $cand, array $candAttrs): bool
    {
        foreach ($this->sanitizeMust($must) as $id) {
            if (! $this->candidateHas($id, $cand, $candAttrs)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $must
     * @param  list<array{id: string, label: string, items: list<array{id: string, label: string, default: bool}>}>  $groups
     * @return list<array{id: string, label: string, group: string, group_label: string}>
     */
    public function resolveApplied(array $must, array $groups): array
    {
        $byId = [];
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $byId[$item['id']] = [
                    'id' => $item['id'],
                    'label' => $item['label'],
                    'group' => $group['id'],
                    'group_label' => $group['label'],
                ];
            }
        }

        $applied = [];
        foreach ($this->sanitizeMust($must) as $id) {
            if (isset($byId[$id])) {
                $applied[] = $byId[$id];

                continue;
            }
            $parts = explode(':', $id, 3);
            $group = $parts[0] ?? 'spec';
            $applied[] = [
                'id' => $id,
                'label' => $id,
                'group' => $group,
                'group_label' => self::GROUP_LABELS[$group] ?? $group,
            ];
        }

        return $applied;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function haystack(Product $product, array $attrs): string
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $parts = [
            (string) ($product->name ?? ''),
            (string) ($product->description ?? ''),
            (string) ($product->category ?? ''),
            (string) ($product->norms ?? ''),
            (string) ($attrs['klasa_ochrony'] ?? ''),
            (string) ($attrs['typ_wyrobu'] ?? ''),
            (string) ($attrs['material'] ?? ''),
            (string) ($attrs['przeznaczenie'] ?? ''),
            (string) ($attrs['poziomy_en388'] ?? ''),
            implode(' ', $this->stringList($attrs['materialy'] ?? null)),
            implode(' ', $this->stringList($attrs['normy_en'] ?? null)),
            implode(' ', $this->markingSet($attrs)),
            implode(' ', $this->stringList($payload['materials'] ?? null)),
            implode(' ', $this->stringList($payload['norms'] ?? null)),
            implode(' ', $this->stringList($payload['features'] ?? null)),
            implode(' ', $this->stringList($payload['specs'] ?? null)),
            implode(' ', $this->stringList($payload['certificates'] ?? null)),
            implode(' ', $this->stringList($payload['use_cases'] ?? null)),
        ];

        return $this->norm(implode("\n", $parts));
    }

    /**
     * @param  array<string, mixed>  $candAttrs
     */
    private function candidateHas(string $id, Product $cand, array $candAttrs): bool
    {
        $parts = explode(':', $id, 3);
        if (count($parts) !== 3) {
            return false;
        }
        [$group, $kind, $value] = $parts;
        $hay = $this->haystack($cand, $candAttrs);

        return match ($group) {
            self::GROUP_SPEC => $this->hasSpec($kind, $value, $candAttrs, $hay),
            self::GROUP_FEATURE => $this->hasSluggedList(
                $this->stringList(($cand->enrichment_payload['features'] ?? null)),
                $value,
                $hay
            ),
            self::GROUP_MATERIAL => $this->hasMaterial($value, $candAttrs, $cand, $hay),
            self::GROUP_NORM => $this->hasNorm($value, $candAttrs),
            self::GROUP_CERT => $this->hasSluggedList(
                $this->stringList(($cand->enrichment_payload['certificates'] ?? null)),
                $value,
                $hay
            ),
            self::GROUP_USE => $this->hasSluggedList(
                $this->stringList(($cand->enrichment_payload['use_cases'] ?? null)),
                $value,
                $hay
            ),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function hasSpec(string $kind, string $value, array $attrs, string $hay): bool
    {
        return match ($kind) {
            'typ' => ($attrs['typ_wyrobu'] ?? null) === $value,
            'klasa' => $this->classMeets($value, (string) ($attrs['klasa_ochrony'] ?? '')),
            'mark' => in_array(mb_strtoupper($value), $this->markingSet($attrs), true)
                || $this->containsWord($hay, $this->norm($value)),
            'zawor' => $this->valveState($hay) === (int) $value,
            'forma' => $this->formLabel($hay) === $value,
            'en388' => mb_strtoupper(preg_replace('/\s+/', '', (string) ($attrs['poziomy_en388'] ?? '')) ?? '')
                === mb_strtoupper(preg_replace('/\s+/', '', $value) ?? ''),
            'cel' => ($attrs['przeznaczenie'] ?? null) === $value,
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function hasMaterial(string $slug, array $attrs, Product $cand, string $hay): bool
    {
        $payload = is_array($cand->enrichment_payload) ? $cand->enrichment_payload : [];
        $items = array_merge(
            $this->stringList($attrs['materialy'] ?? null),
            $this->stringList($payload['materials'] ?? null),
            array_filter([(string) ($attrs['material'] ?? ''), (string) ($attrs['rodzina_materialu'] ?? '')]),
        );

        return $this->hasSluggedList($items, $slug, $hay);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function hasNorm(string $digits, array $attrs): bool
    {
        $want = 'en'.preg_replace('/\D+/', '', $digits);
        if ($want === 'en') {
            return false;
        }
        foreach ($this->stringList($attrs['normy_en'] ?? null) as $norm) {
            if ($this->normCore($norm) === $want) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $items
     */
    private function hasSluggedList(array $items, string $slug, string $hay): bool
    {
        foreach ($items as $item) {
            if ($this->slug($item) === $slug) {
                return true;
            }
        }
        $words = trim(str_replace('-', ' ', $slug));

        return $words !== '' && str_contains($hay, $words);
    }

    private function classMeets(string $need, string $candClass): bool
    {
        $needKey = $this->classRank($need);
        if ($needKey === null) {
            $n = $this->norm($need);
            $c = $this->norm($candClass);

            return $n !== '' && ($n === $c || str_contains($c, $n));
        }
        $candKey = $this->classRank($candClass);
        if ($candKey === null) {
            return $needKey['group'] !== 'footwear';
        }
        if ($needKey['group'] !== $candKey['group']) {
            return false;
        }

        return $candKey['rank'] >= $needKey['rank'];
    }

    /**
     * @return array{group: string, rank: int}|null
     */
    private function classRank(string $class): ?array
    {
        $key = $this->classKey($class);
        if ($key === null) {
            return null;
        }

        return self::CLASS_RANKS[$key] ?? null;
    }

    private function classKey(string $class): ?string
    {
        $c = mb_strtoupper(trim($class));
        if ($c === '') {
            return null;
        }
        if (isset(self::CLASS_RANKS[$c])) {
            return $c;
        }
        if (preg_match('/^S[1-57]P?\b/', $c, $m) === 1) {
            $k = $m[0];

            return isset(self::CLASS_RANKS[$k]) ? $k : null;
        }
        if (preg_match('/FFP[123]/', $c, $m) === 1) {
            return $m[0];
        }
        if (preg_match('/KAT\.?\s*(I{1,3}|[123])/u', $c, $m) === 1) {
            return match ($m[1]) {
                'I', '1' => 'KAT1',
                'II', '2' => 'KAT2',
                default => 'KAT3',
            };
        }

        return null;
    }

    /** 1 = z zaworem, 0 = bez, null = nie wiadomo */
    private function valveState(string $hay): ?int
    {
        if (preg_match('/\bbez\s+zawor/u', $hay) === 1) {
            return 0;
        }
        if (preg_match('/\b(zawor|valve|cool\s*flow)\w*/u', $hay) === 1) {
            return 1;
        }

        return null;
    }

    private function formLabel(string $hay): ?string
    {
        if (preg_match('/\b(skladan|foldable|skladajac)\w*/u', $hay) === 1) {
            return 'skladana';
        }
        if (preg_match('/\b(muszlow|cup[\s-]*shaped|cup\s*shape)\w*/u', $hay) === 1) {
            return 'muszlowa';
        }

        return null;
    }

    private function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? $type;
    }

    private function isValidId(string $id): bool
    {
        return preg_match(
            '/^(spec|feature|material|norm|certificate|use):[a-z0-9_-]+:[A-Za-z0-9._%-]{1,80}$/',
            $id
        ) === 1;
    }

    /**
     * @return array{id: string, label: string, default: bool}
     */
    private function item(string $group, string $kind, string $value, string $label, bool $default): array
    {
        $value = substr($value, 0, 80);

        return [
            'id' => $group.':'.$kind.':'.$value,
            'label' => $label,
            'default' => $default,
        ];
    }

    /**
     * @param  list<array{id: string, label: string, default: bool}>  $items
     * @return list<array{id: string, label: string, default: bool}>
     */
    private function uniqueItems(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            if (isset($seen[$item['id']])) {
                continue;
            }
            $seen[$item['id']] = true;
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Krótkie fakty z cech — bez zdań marketingowych (HRO/SRC już w Specyfikacji).
     *
     * @param  list<string>  $features
     * @return list<string>
     */
    private function featureTokens(array $features): array
    {
        $out = [];
        foreach ($features as $feature) {
            $feature = trim($feature);
            if ($feature === '' || $this->isJunkLine($feature)) {
                continue;
            }
            $words = preg_split('/\s+/u', $feature, -1, PREG_SPLIT_NO_EMPTY);
            if ($words === false || count($words) > 5 || mb_strlen($feature) > 36) {
                continue;
            }
            if (preg_match('/\b(HRO|SRC|SRA|CI|HI|WR)\b/u', $feature) === 1) {
                continue;
            }
            $out[] = $feature;
            if (count($out) >= 5) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private function shortFacts(array $items, int $maxLen, int $limit): array
    {
        $out = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '' || mb_strlen($item) > $maxLen) {
                continue;
            }
            $out[] = $item;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function isJunkLine(string $line): bool
    {
        return preg_match(
            '/^(nr\.?\s*(art|kat|katalog)|sku|ean|gtin|producent|kod(\s|$)|indeks|marka|model|referenc)/iu',
            $line
        ) === 1;
    }

    private function normCore(string $norm): ?string
    {
        $c = preg_replace('/\s+/', '', mb_strtolower($norm)) ?? '';
        if (preg_match('/en(?:iso)?(\d{3,5})/i', $c, $m) === 1) {
            return 'en'.$m[1];
        }

        return null;
    }

    private function normLabel(string $norm): string
    {
        if (preg_match('/EN(?:\s*ISO)?\s*\d{3,5}/iu', $norm, $m) === 1) {
            return strtoupper(preg_replace('/\s+/', ' ', trim($m[0])) ?? $norm);
        }

        return $norm;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return list<string>
     */
    private function markingSet(array $attrs): array
    {
        $raw = is_array($attrs['oznaczenia'] ?? null) ? $attrs['oznaczenia'] : [];
        $out = [];
        foreach ($raw as $tag) {
            $t = mb_strtoupper(trim((string) $tag));
            if ($t !== '' && preg_match('/^[A-Z0-9]{1,8}$/', $t) === 1) {
                $out[] = $t;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private function stringList(mixed $v): array
    {
        if (! is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    private function containsWord(string $hay, string $word): bool
    {
        $word = trim($word);

        return $word !== '' && preg_match('/\b'.preg_quote($word, '/').'\b/u', $hay) === 1;
    }

    private function slug(string $s): string
    {
        $n = preg_replace('/[^a-z0-9]+/', '-', $this->norm($s)) ?? '';

        return substr(trim($n, '-'), 0, 80);
    }

    private function norm(string $v): string
    {
        $t = mb_strtolower(trim($v));
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

        return strtr($t, $map);
    }
}
