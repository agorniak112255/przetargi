<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;

/**
 * Który plik z portalu zdjęć Ansella (AnsellAssetBankClient, podkategoria „Product images - static”) jest packshotem
 * tej karty cennika. Same reguły, bez sieci — testowalne na tytułach z portalu (06.10.2026):
 * „EDGE 48-501 Black Product EMEA - Front”, „HyFlex 11-840 Gray and Black Product- Back”, „Ringers R840 UCard Global”,
 * „AlphaTec 4000-162 Green Product - Back”, „RIG CL00 Black Product EMEA/APAC”, „ActivArmr RIGS Bi-Color Class 2 16 – Front”.
 * Zdjęcia w użyciu („Application”, „Warehouse”, „E-Commerce” — to rękawice przy silniku), z rekwizytem („Prop”),
 * zestawy („Amazon Image Stack”, „Group Image”), detale i pliki tylko dla Ameryk/Azji nie są packshotem dla nas.
 */
final class AnsellAssetBankImagePicker
{
    /** Granice słów bez \b — „Front_1”: podkreślnik to w regexie znak słowa. */
    private const NOT_PACKSHOT = '/(?<![a-z])(?:application|prop|amazon|stack|detail|warehouse|e-?commerce|assembly|group|kit|lab|laboratory|environment|test|banner|lifestyle|in use|super hero|hero|ankle|zip|zip flap|chin strap|wrist cuffs?|waist|waist wraps?|feature)(?![a-z])/iu';

    private const PACKSHOT_VIEW = '/(?<![a-z])(?:product|front|back|u-?card|ucard|side|primary|top|palm|pair)(?![a-z])/iu';

    /**
     * Seria i krój kombinezonu w tytule (po mb_strtolower), z nazwą linii pomiędzy albo bez: „2000 comfort-129”,
     * „2000 ts plus_156”, „4000 apron with sleeves-215”, „4000 - 130”, „2000 129 static white product”.
     */
    private const SUIT_REF = '/(?<![\d-])(\d{4})((?:[\s_]+[a-z][a-z:]*)*?)\s*[-_ ]\s*(\d{3})(?!\d)/u';

    /** Kolory kombinezonów AlphaTec z kodu cennika („4000-GR”, „OR15S-…”). */
    private const SUIT_COLOURS = [
        'GR' => 'green', 'OR' => 'orange', 'WH' => 'white', 'YE' => 'yellow', 'YL' => 'yellow', 'BL' => 'blue',
        'NV' => 'blue', 'RD' => 'red', 'BE' => 'beige', 'TN' => 'beige', 'GY' => 'grey', 'BK' => 'black',
    ];

    /** Kolory rękawic RIGS z kodu („RIG0011B120”). */
    private const RIGS_COLOURS = ['B' => 'black', 'Y' => 'yellow', 'R' => 'red'];

    public function __construct(private readonly ProductSearchIdentity $identity) {}

    /**
     * Czego szukać w portalu dla karty: frazy do pola „tytuł zawiera” (po kolei, do pierwszego trafienia) i reguła
     * dopasowania tytułu. Null — karta bez modelu, którego portal by używał (KleenGuard, akcesoria).
     *
     * @return array{kind: string, needles: list<string>, label: string}|null
     */
    public function target(Product $product): ?array
    {
        $glove = $this->identity->ansellGloveModel($product);
        if ($glove !== null) {
            return ['kind' => 'glove', 'needles' => [$glove], 'label' => $glove];
        }
        $name = (string) $product->name;
        if (preg_match('/\bringers\s+r?-?(\d{3})/iu', $name, $m) === 1) {
            // portal: „Ringers 065 palm”, „Ringers 267 primary”, „Ringers R840 UCard Global” — samo „Ringers” oddaje tylko
            // pierwszą stronę (60) wyników, a goły numer ginie wśród „87-665”, „92-665”
            return ['kind' => 'ringers', 'needles' => ['Ringers '.$m[1], 'Ringers R'.$m[1]], 'label' => 'R'.$m[1]];
        }
        $rigs = $this->rigs($product);
        if ($rigs !== null) {
            return ['kind' => 'rigs', 'needles' => ['RIG'], 'label' => 'RIG CL'.$rigs['class'].' '.$rigs['length'].'in '.$rigs['colour']];
        }
        $bits = $this->identity->ansellCatalogBits($product);
        if ($bits['series'] !== null && $bits['model'] !== null && preg_match('/^\d{3}$/', (string) $bits['model']) === 1) {
            $series = (string) $bits['series'];
            $model = (string) $bits['model'];

            $needles = [$series.'-'.$model, $series.' - '.$model];
            if ($this->isCfrCard($product)) {
                $needles[] = 'CFR-'.$model;
            }

            return ['kind' => 'suit', 'needles' => $needles, 'label' => $series.'-'.$model];
        }

        return null;
    }

    /**
     * Najlepszy packshot z wyników wyszukiwania — albo null, gdy żaden nie pasuje.
     *
     * @param  list<array{id: int, title: string, subcategory: string, regions: list<string>}>  $assets
     * @return array{id: int, title: string, subcategory: string, regions: list<string>}|null
     */
    public function pick(Product $product, array $assets): ?array
    {
        return $this->ranked($product, $assets)[0] ?? null;
    }

    /**
     * Pasujące packshoty od najlepszego — kolejny wchodzi, gdy pierwszy ma prawa użycia, których nie mamy.
     *
     * @param  list<array{id: int, title: string, subcategory: string, regions: list<string>}>  $assets
     * @return list<array{id: int, title: string, subcategory: string, regions: list<string>}>
     */
    public function ranked(Product $product, array $assets): array
    {
        $target = $this->target($product);
        if ($target === null) {
            return [];
        }
        $scored = [];
        foreach ($assets as $asset) {
            $title = (string) $asset['title'];
            if (! $this->isPackshot($title, $target['kind']) || ! $this->regionAllowed($asset['regions'])
                || ! $this->titleMatches($product, $target, $title) || ! $this->sameNamedVariant($product, $title)) {
                continue;
            }
            $scored[] = ['asset' => $asset, 'score' => $this->viewRank($title) * 10 + $this->regionRank($asset['regions'])];
        }
        usort($scored, static fn (array $a, array $b): int => [$a['score'], $a['asset']['id']] <=> [$b['score'], $b['asset']['id']]);

        return array_map(static fn (array $row): array => $row['asset'], $scored);
    }

    /** Prawa użycia z karty pliku: wewnętrzne i dla jednego klienta — nie dla dystrybutora. */
    public static function rightsAllowed(string $rights): bool
    {
        $rights = mb_strtolower(trim($rights));

        return ! str_contains($rights, 'internal') && ! str_contains($rights, 'customer-specific');
    }

    /**
     * RIGS w portalu bywają opisane bez ujęcia („ActivArmr RIGS Class 2- Black”, „RIG Bi-Color Class 4 18 inch Global”)
     * — tam wystarcza brak słów zdjęcia w użyciu, zestawu i detalu.
     */
    public function isPackshot(string $title, string $kind = ''): bool
    {
        if (preg_match(self::NOT_PACKSHOT, $title) === 1) {
            return false;
        }

        return $kind === 'rigs' || preg_match(self::PACKSHOT_VIEW, $title) === 1;
    }

    /**
     * Wariant nazwany w karcie i w tytule musi się zgadzać: „66-300 model 111” to nie „66-300 Model 122” (inny krój
     * kombinezonu), a tytuł bez numeru modelu nie potwierdza żadnego; rękaw „extra wide” to nie „Sleeve Narrow”.
     */
    private function sameNamedVariant(Product $product, string $title): bool
    {
        $name = mb_strtolower((string) $product->name);
        $t = mb_strtolower($title);
        if (preg_match('/(?<![a-z])model\s*(\d{3})/u', $name, $ours) === 1) {
            preg_match_all('/(?<![a-z])model\s*(\d{3})(?:-g\d{2})?((?:\s*(?:and|&|,|\/)\s*(?:model\s*)?\d{3}(?:-g\d{2})?)*)/u', $t, $named, PREG_SET_ORDER);
            $models = [];
            foreach ($named as $hit) {
                preg_match_all('/(?<!\d)(\d{3})(?!\d)/u', $hit[0], $digits);
                $models = array_merge($models, $digits[1]);
            }
            if (! in_array($ours[1], $models, true)) {
                return false;
            }
        }
        // nazwy z cennika bywają ucięte: „THUMBSLOT EXTRA WI”
        $wideWord = '/(?<![a-z])(?:wide|extra\s+wi|x-?wi)(?![a-z])/u';
        $width = static fn (string $s): ?string => match (true) {
            preg_match('/(?<![a-z])narrow(?![a-z])/u', $s) === 1 && preg_match($wideWord, $s) !== 1 => 'narrow',
            preg_match($wideWord, $s) === 1 && preg_match('/(?<![a-z])narrow(?![a-z])/u', $s) !== 1 => 'wide',
            default => null,
        };
        $ourWidth = $width($name);
        $titleWidth = $width($t);

        return $ourWidth === null || $titleWidth === null || $ourWidth === $titleWidth;
    }

    /** @param  list<string>  $regions */
    private function regionAllowed(array $regions): bool
    {
        if ($regions === []) {
            return true;
        }
        foreach ($regions as $region) {
            if (preg_match('/\b(?:emea|global)\b/i', $region) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $regions */
    private function regionRank(array $regions): int
    {
        $joined = mb_strtolower(implode(' ', $regions));

        return match (true) {
            str_contains($joined, 'emea') => 0,
            str_contains($joined, 'global') => 1,
            default => 2,
        };
    }

    private function viewRank(string $title): int
    {
        $t = mb_strtolower($title);

        return match (true) {
            preg_match('/(?<![a-z])(?:front|primary)(?![a-z])/u', $t) === 1 => 0,
            preg_match('/(?<![a-z])(?:u-?card|ucard|top)(?![a-z])/u', $t) === 1 => 1,
            preg_match('/(?<![a-z])(?:back|palm)(?![a-z])/u', $t) === 1 => 2,
            preg_match('/(?<![a-z])(?:side|pair)(?![a-z])/u', $t) === 1 => 3,
            default => 4,
        };
    }

    /**
     * @param  array{kind: string, needles: list<string>, label: string}  $target
     */
    private function titleMatches(Product $product, array $target, string $title): bool
    {
        $t = mb_strtolower($title);

        return match ($target['kind']) {
            'glove' => $this->gloveTitleMatches($target['label'], $t),
            'ringers' => $this->ringersTitleMatches(substr($target['label'], 1), $t),
            'rigs' => $this->rigsTitleMatches($product, $t),
            'suit' => $this->suitTitleMatches($product, $t),
            default => false,
        };
    }

    private function gloveTitleMatches(string $model, string $title): bool
    {
        [$head, $tail] = explode('-', $model);
        // lista jednej rodziny na jednym zdjęciu: „AlphaTec 85-300/301/302/303/304/305 CSM White Iso Box Product”
        preg_match_all('/(?<!\d)(\d{2})-(\d{3})((?:\s*\/\s*\d{3})*)(?!\d)/u', $title, $all, PREG_SET_ORDER);
        $listed = false;
        foreach ($all as $hit) {
            preg_match_all('/\d{3}/', $hit[3], $more);
            $family = array_map(static fn (string $t): string => $hit[1].'-'.$t, [$hit[2], ...$more[0]]);
            // inny model tej samej numeracji w tytule („11-840 with 11-800”) — zestaw, nie packshot jednego wyrobu
            if (! in_array($model, $family, true)) {
                return false;
            }
            $listed = true;
        }

        return $listed || preg_match('/(?<!\d)'.$head.'-?'.$tail.'(?!\d)/u', $title) === 1;
    }

    private function ringersTitleMatches(string $digits, string $title): bool
    {
        return str_contains($title, 'ringers')
            && preg_match('/(?<![a-z0-9])(?:r-?)?'.$digits.'(?!\d)/u', $title) === 1;
    }

    /**
     * RIG0011B120 → klasa 00, 11 cali, czarna; RIG014YBSC120 → klasa 0, 14 cali, dwukolorowa (żółto-czarna);
     * RIG0011BUL110 → ULW (ultralekka).
     *
     * @return array{class: string, length: string, colour: string, bicolour: bool, ulw: bool}|null
     */
    private function rigs(Product $product): ?array
    {
        $sku = strtoupper(trim((string) $product->sku));
        if (preg_match('/^RIG(00|[0-4])(11|14|16|18)([A-Z]+?)(\d{2,3})$/', $sku, $m) !== 1) {
            return null;
        }
        $rest = $m[3];
        $ulw = str_contains($rest, 'UL');
        $bicolour = str_starts_with($rest, 'YB');
        $colour = $bicolour ? 'bi-color' : (self::RIGS_COLOURS[$rest[0]] ?? '');

        return ['class' => $m[1], 'length' => $m[2], 'colour' => $colour, 'bicolour' => $bicolour, 'ulw' => $ulw];
    }

    private function rigsTitleMatches(Product $product, string $title): bool
    {
        $rigs = $this->rigs($product);
        if ($rigs === null || ! str_contains($title, 'rig')) {
            return false;
        }
        if (preg_match('/\b(?:cl|class)\s?(00|[0-4])\b/u', $title, $class) !== 1 || $class[1] !== $rigs['class']) {
            return false;
        }
        $titleBicolour = str_contains($title, 'bi-color') || str_contains($title, 'bicolor');
        if ($titleBicolour !== $rigs['bicolour'] || str_contains($title, 'ulw') !== $rigs['ulw']) {
            return false;
        }
        if (! $rigs['bicolour'] && $rigs['colour'] !== '') {
            $colours = $this->titleColours($title);
            if ($colours !== [] && ! in_array($rigs['colour'], $colours, true)) {
                return false;
            }
        }
        if (preg_match('/\b(11|14|16|18)\s*(?:"|”|in\b|inch\b|–|-|_)/u', $title, $length) === 1 && $length[1] !== $rigs['length']) {
            return false;
        }

        return true;
    }

    private function suitTitleMatches(Product $product, string $title): bool
    {
        $bits = $this->identity->ansellCatalogBits($product);
        $series = (string) $bits['series'];
        $model = (string) $bits['model'];
        $seriesModel = false;
        preg_match_all(self::SUIT_REF, $title, $refs, PREG_SET_ORDER);
        foreach ($refs as $ref) {
            if ($ref[1] !== $series || $ref[3] !== $model) {
                // dwa kombinezony na jednym zdjęciu („2300 PLUS-205 Yellow Product with AlphaTec 2000 STANDARD-111”)
                return false;
            }
            $titleLine = trim($ref[2], " \t_");
            if ($titleLine !== '' && ! self::sameSuitLine((string) $product->name, $titleLine)) {
                return false;
            }
            $seriesModel = true;
        }
        // „AlphaTec CFR-111” to seria CFR — nie „1500 PLUS FR” ani 2300 z tym samym numerem kroju
        $cfrModel = $this->isCfrCard($product) && preg_match('/(?<![a-z])cfr-'.$model.'(?!\d)/u', $title) === 1;
        if (! $seriesModel && ! $cfrModel) {
            return false;
        }
        $ours = self::SUIT_COLOURS[strtoupper((string) $bits['color'])] ?? null;
        $colours = $this->titleColours($title);

        return $ours === null || $colours === [] || in_array($ours, $colours, true);
    }

    /**
     * Linia kombinezonu z karty („2000-WH STD CVRL HOOD”, „1800-WH TSPLUS”, „2300-WY CMF”) i z tytułu („STANDARD”,
     * „Ts PLUS”, „COMFORT”, „PLUS FR”) musi być ta sama; karta bez linii (seria 4000) przyjmuje każdą.
     */
    private static function sameSuitLine(string $cardName, string $titleLine): bool
    {
        $line = static function (string $text): array {
            $t = (string) preg_replace('/[\s_]+/u', ' ', mb_strtolower($text));

            return [
                match (true) {
                    preg_match('/(?<![a-z])ts ?plus(?![a-z])/u', $t) === 1 => 'tsplus',
                    preg_match('/(?<![a-z])(?:cmf|comfort)(?![a-z])/u', $t) === 1 => 'comfort',
                    preg_match('/(?<![a-z])(?:std|standard)(?![a-z])/u', $t) === 1 => 'standard',
                    preg_match('/(?<![a-z])plus(?![a-z])/u', $t) === 1 => 'plus',
                    default => null,
                },
                preg_match('/(?<![a-z])fr(?![a-z])/u', $t) === 1,
            ];
        };
        [$cardLine, $cardFr] = $line($cardName);
        [$titleLineName, $titleFr] = $line($titleLine);

        return $cardFr === $titleFr && ($cardLine === null || $titleLineName === null || $cardLine === $titleLineName);
    }

    private function isCfrCard(Product $product): bool
    {
        return preg_match('/(?<![a-z])cfr(?![a-z])/iu', (string) $product->name) === 1;
    }

    /** @return list<string> rodziny kolorów nazwane w tytule („Gray and Black” → grey, black) */
    private function titleColours(string $title): array
    {
        $out = [];
        foreach (preg_split('/[^a-z]+/u', $title) ?: [] as $word) {
            $family = $word !== '' ? $this->identity->colorFamily($word) : null;
            if ($family !== null) {
                $out[$family] = true;
            }
        }

        return array_keys($out);
    }
}
