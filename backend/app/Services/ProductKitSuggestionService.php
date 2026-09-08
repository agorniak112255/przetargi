<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Support\PpeAssortment;
use Illuminate\Support\Collection;

/**
 * Propozycje zestawu: najpierw wąska pula z katalogu, potem model wybiera
 * to, co realnie dokłada się do zamówienia (filtr, mocowanie, para odzieżowa).
 */
final class ProductKitSuggestionService
{
    public const PROMPT_VERSION = 'kit-2026-09-08b';

    private const CANDIDATE_LIMIT = 40;

    private const MAX_PICKS = 12;

    public function __construct(
        private readonly OpenAiCompatibleClient $llm,
        private readonly PpeAssortment $assortment,
    ) {}

    /**
     * @return array{
     *     family: string|null,
     *     family_label: string,
     *     article_type: string|null,
     *     prompt_version: string,
     *     suggestions: list<array<string, mixed>>
     * }
     */
    public function suggest(Product $product): array
    {
        $family = $this->assortment->productFamily($product);
        $articleType = $this->assortment->articleTypePreferIdentity(
            trim($product->sku.' '.$product->name),
            $this->productText($product),
            $family
        );
        $candidates = $this->collectCandidates($product, $family, $articleType);
        if ($candidates->isEmpty()) {
            return $this->emptyResult($family, $articleType);
        }

        $raw = $this->llm->chatJson(
            $this->messages($product, $family, $articleType, $candidates),
            0.1,
            1200,
            null,
            AiTask::KitSuggest
        );
        $byId = $candidates->keyBy(static fn (Product $row): int => (int) $row->id);
        $picks = [];
        $seen = [];
        foreach ($this->rawPicks($raw) as $pick) {
            $id = (int) ($pick['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id]) || ! $byId->has($id)) {
                continue;
            }
            $seen[$id] = true;
            $related = $byId->get($id);
            if (! $related instanceof Product) {
                continue;
            }
            $picks[] = $this->card(
                $related,
                $this->sanitizeLabel((string) ($pick['role'] ?? 'akcesorium')),
                $this->sanitizeLabel((string) ($pick['reason'] ?? ''))
            );
            if (count($picks) >= self::MAX_PICKS) {
                break;
            }
        }

        return [
            'family' => $family,
            'family_label' => $this->familyLabel($family),
            'article_type' => $articleType,
            'prompt_version' => self::PROMPT_VERSION,
            'suggestions' => $picks,
        ];
    }

    /**
     * Pula dla testów promptu — bez wywołania modelu.
     *
     * @return Collection<int, Product>
     */
    public function collectCandidates(Product $product, ?string $family, ?string $articleType): Collection
    {
        $exclude = $product->accessories()
            ->whereNotNull('related_product_id')
            ->pluck('related_product_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $exclude[] = (int) $product->id;

        $needles = $this->needles($product, $family, $articleType);
        if ($needles === []) {
            return collect();
        }

        $query = Product::query()
            ->with([
                'prestaExport',
                'images' => static fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id'),
            ])
            ->whereNotIn('id', array_values(array_unique($exclude)))
            ->where(function ($builder) use ($needles): void {
                foreach ($needles as $needle) {
                    $esc = '%'.addcslashes($needle, '%_\\').'%';
                    $builder->orWhere('name', 'like', $esc)
                        ->orWhere('sku', 'like', $esc);
                }
            });

        $lineTokens = $this->lineTokens($product);
        if ($lineTokens !== []) {
            $parts = [];
            $bindings = [];
            foreach ($lineTokens as $token) {
                $parts[] = '(CASE WHEN name LIKE ? OR sku LIKE ? THEN 1 ELSE 0 END)';
                $like = '%'.addcslashes($token, '%_\\').'%';
                $bindings[] = $like;
                $bindings[] = $like;
            }
            $query->orderByRaw('('.implode(' + ', $parts).') DESC', $bindings);
        }
        $manufacturer = trim((string) $product->manufacturer);
        if ($manufacturer !== '') {
            $query->orderByRaw(
                'CASE WHEN manufacturer = ? THEN 0 ELSE 1 END',
                [$manufacturer]
            );
        }
        $query->orderBy('name');

        return $query->limit(80)
            ->get()
            ->filter(fn (Product $row): bool => $this->isCompanionCandidate($product, $row, $family, $articleType))
            ->values()
            ->take(self::CANDIDATE_LIMIT);
    }

    /**
     * @param  Collection<int, Product>  $candidates
     * @return list<array{role: string, content: string}>
     */
    public function messages(Product $product, ?string $family, ?string $articleType, Collection $candidates): array
    {
        return [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt($product, $family, $articleType, $candidates)],
        ];
    }

    public function card(Product $product, string $role = '', string $reason = ''): array
    {
        $image = $product->relationLoaded('images')
            ? $product->images->first()
            : $product->images()->orderByDesc('is_primary')->orderBy('sort_order')->first();

        return [
            'id' => (int) $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'manufacturer' => $product->manufacturer,
            'short_description' => $this->shortDescription($product),
            'image_url' => $image?->url(),
            'in_presta' => $this->inPresta($product),
            'presta_id' => $this->prestaId($product),
            'presta_url' => $this->prestaUrl($product),
            'role' => $role,
            'reason' => $reason,
        ];
    }

    public function inPresta(?Product $product, ?int $prestaRelatedId = null): bool
    {
        return $this->prestaId($product, $prestaRelatedId) > 0;
    }

    public function prestaId(?Product $product, ?int $prestaRelatedId = null): ?int
    {
        $match = $product?->prestaExport;
        if ($match && (int) $match->presta_id > 0) {
            return (int) $match->presta_id;
        }
        $fallback = (int) ($prestaRelatedId ?? 0);

        return $fallback > 0 ? $fallback : null;
    }

    public function prestaUrl(?Product $product): ?string
    {
        $url = trim((string) ($product?->prestaExport?->presta_url ?? ''));

        return $url !== '' ? $url : null;
    }

    public function shortDescription(Product $product): string
    {
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($product->description ?? ''))) ?? '');
        $maker = trim((string) $product->manufacturer);
        if ($plain === '') {
            return $maker;
        }
        if (mb_strlen($plain) > 180) {
            $plain = mb_substr($plain, 0, 177).'…';
        }

        return $maker !== '' ? $maker.' — '.$plain : $plain;
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
Jesteś doradcą BHP w hurtowni SUPON. Składasz ZESTAW do produktu głównego — nie szukasz zamiennika.

Zestaw = to, co klient dokłada do tego samego zamówienia, żeby produkt dało się używać albo uzupełnić ochronę:
- części zużywalne i zamienne (filtry, pochłaniacze, prefiltry, wkładki, szyby, zestawy higieniczne)
- mocowania, adaptery, uchwyty do TEGO systemu / producenta
- uzupełnienie ochrony (okulary do maski, nauszniki albo przyłbica do hełmu, nakolanniki do spodni)
- para odzieżowa tej samej linii (bluza↔spodnie / ogrodniczki), nigdy druga bluza ani druga para spodni

NIE wybieraj:
- drugiego egzemplarza tego samego typu (druga maska, drugi hełm, drugie okulary, druga bluza)
- zamiennika „podobny produkt innego producenta”
- asortymentu z innej rodziny bez typowego związku (buty do okularów, szelki do rękawic)
- samego koloru albo rozmiaru jako osobnej pozycji
- środków czyszczących, padów, gąbek, mopów i narzędzi porządkowych
- kilku kart tego samego modelu (ten sam kod / ta sama nazwa pod innym id) — zostaw jedną, najlepszą

Wybieraj TYLKO spośród podanych kandydatów (pole id). Kompatybilność bierz z nazwy i SKU produktu głównego (system, złącze, linia) — pole producent bywa mylne. Filtr 3M nie pasuje do półmaski SECURA i odwrotnie. Jeśli nic nie pasuje — pusta lista.

Zwróć wyłącznie JSON:
{"picks":[{"id":123,"role":"filtr","reason":"Pochłaniacz A2 do tej półmaski"}]}

role: krótka etykieta po polsku (filtr, mocowanie, okulary, nauszniki, spodnie, linka, etui).
reason: jedno zdanie, dlaczego pasuje do TEGO produktu.
Maks. 12 pozycji, od najbardziej oczywistych.
TXT;
    }

    /**
     * @param  Collection<int, Product>  $candidates
     */
    private function userPrompt(Product $product, ?string $family, ?string $articleType, Collection $candidates): string
    {
        $lines = [
            'Produkt główny:',
            json_encode($this->productCard($product, $family, $articleType), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '',
            'Jakie pozycje typowo dokładamy do tego rodzaju asortymentu:',
            $this->familyGuide($family, $articleType),
            '',
            'Kandydaci z katalogu (wybierz id albo zwróć pustą listę):',
        ];
        foreach ($candidates as $row) {
            $lines[] = json_encode([
                'id' => (int) $row->id,
                'sku' => $row->sku,
                'name' => $row->name,
                'manufacturer' => $row->manufacturer,
                'family' => $row->ppe_family,
                'type' => $this->assortment->articleType((string) $row->name, $row->ppe_family ?: null),
                'description' => mb_substr($this->shortDescription($row), 0, 220),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function productCard(Product $product, ?string $family, ?string $articleType): array
    {
        return [
            'id' => (int) $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'manufacturer' => $product->manufacturer,
            'family' => $family,
            'type' => $articleType,
            'norms' => $product->norms,
            'description' => mb_substr($this->shortDescription($product), 0, 280),
        ];
    }

    private function familyGuide(?string $family, ?string $articleType): string
    {
        $type = $articleType ?? '';

        return match ($family) {
            PpeAssortment::FAMILY_RESPIRATORY => $type === 'ffp'
                ? 'To jednorazowa półmaska FFP. Zwykle nie ma zestawu — nie dokładaj filtrów do masek wielorazowych ani drugiej FFP.'
                : 'Do maski / półmaski wielorazowej: pochłaniacze i filtry pasujące do złącza, prefiltry, pokrywy, zawory, adaptery; ewentualnie okulary albo osłona twarzy. Nie dokładaj drugiej maski ani FFP.',
            PpeAssortment::FAMILY_HEAD => 'Do hełmu / kasku: nauszniki na hełm, przyłbica / osłona twarzy, adapter mocowania, wkładka / pasek potu, lampka. Nie dokładaj drugiego hełmu.',
            PpeAssortment::FAMILY_FACE => 'Do przyłbicy / osłony twarzy: adapter do hełmu, hełm z tym mocowaniem, okulary pod osłonę, nauszniki. Nie dokładaj drugiej przyłbicy.',
            PpeAssortment::FAMILY_EYES => 'Do okularów / gogli: etui, sznurek, wkładki, szyba zapasowa. Nie dokładaj drugiej pary okularów ani gogli jako zamiennika.',
            PpeAssortment::FAMILY_HEARING => 'Do nauszników / stoperów: zestaw higieniczny, wkładki, pałąk, adapter na hełm. Nie dokładaj drugich nauszników.',
            PpeAssortment::FAMILY_APPAREL => $type === 'pants'
                ? 'Do spodni / ogrodniczek: bluza albo kurtka TEJ SAMEJ linii, nakolanniki. Nie dokładaj drugiej pary spodni.'
                : 'Do bluzy / kurtki: spodnie albo ogrodniczki TEJ SAMEJ linii, nakolanniki. Nie dokładaj drugiej bluzy.',
            PpeAssortment::FAMILY_FOOTWEAR => 'Do obuwia: wkładki, skarpety ochronne, sznurówki zapasowe — tylko gdy widać, że pasują. Nie dokładaj drugiej pary butów.',
            PpeAssortment::FAMILY_GLOVES => 'Do rękawic: wkładki wewnętrzne, rękawice podspawalnicze. Zwykle zestaw jest pusty. Nie dokładaj drugiej pary tego samego typu.',
            PpeAssortment::FAMILY_FALL => 'Do szelek: linka / lonża, amortyzator, karabińczyk, punkt kotwiczący. Nie dokładaj drugich szelek.',
            PpeAssortment::FAMILY_KNEE => 'Do nakolanników: spodnie z kieszeniami na nakolanniki. Nie dokładaj drugich nakolanników.',
            default => 'Dobierz tylko oczywiste akcesoria i części do tego produktu. Gdy niepewne — pomiń.',
        };
    }

    /**
     * @return list<string>
     */
    private function needles(Product $product, ?string $family, ?string $articleType): array
    {
        $needles = match ($family) {
            PpeAssortment::FAMILY_RESPIRATORY => [
                'pochłaniacz', 'pochlaniacz', 'filtropochłaniacz', 'filtropochlaniacz',
                'prefiltr', 'filtr', 'adapter', 'zawór', 'zawor', 'okular', 'osłona twarzy', 'oslona twarzy',
            ],
            PpeAssortment::FAMILY_HEAD => [
                'nausznik', 'przyłbic', 'przylbic', 'osłona twarzy', 'oslona twarzy',
                'adapter', 'wkładk', 'wkladk', 'pasek potu', 'lampk',
            ],
            PpeAssortment::FAMILY_FACE => [
                'adapter', 'hełm', 'helm', 'kask', 'okular', 'nausznik', 'mocowan',
            ],
            PpeAssortment::FAMILY_EYES => [
                'etui', 'futerał', 'futeral', 'sznurek', 'wkładk', 'wkladk', 'szyba',
            ],
            PpeAssortment::FAMILY_HEARING => [
                'higien', 'wkładk', 'wkladk', 'pałąk', 'palak', 'adapter', 'hełm', 'helm',
            ],
            PpeAssortment::FAMILY_APPAREL => $articleType === 'pants'
                ? ['bluza', 'kurtka', 'softshell', 'nakolann']
                : ['spodnie', 'ogrodniczk', 'nakolann'],
            PpeAssortment::FAMILY_FOOTWEAR => ['wkładk', 'wkladk', 'skarpeta', 'skarpety', 'sznurów', 'sznurow'],
            PpeAssortment::FAMILY_GLOVES => ['wkładk', 'wkladk', 'podspawal', 'liner'],
            PpeAssortment::FAMILY_FALL => [
                'linka', 'lonża', 'lonza', 'amortyzator', 'karabiń', 'karabin', 'kotwicz',
            ],
            PpeAssortment::FAMILY_KNEE => ['spodnie', 'ogrodniczk', 'kieszeni'],
            default => ['akcesor', 'adapter', 'mocowan', 'filtr', 'wkładk', 'wkladk', 'etui'],
        };

        foreach ($this->lineTokens($product) as $token) {
            $needles[] = $token;
        }

        return array_values(array_unique(array_filter($needles, static fn (string $n): bool => mb_strlen($n) >= 3)));
    }

    /**
     * @return list<string>
     */
    private function lineTokens(Product $product): array
    {
        $text = trim($product->sku.' '.$product->name);
        $tokens = [];
        foreach (preg_split('/[\s,;:\/|+]+/u', $text) ?: [] as $raw) {
            $token = trim($raw, ".-_");
            if (mb_strlen($token) < 4) {
                continue;
            }
            if (preg_match('/\d/', $token) === 1 || preg_match('/^[A-ZĄĆĘŁŃÓŚŹŻ]{4,}$/u', $token) === 1) {
                $tokens[] = $token;
            }
        }

        return array_values(array_unique($tokens));
    }

    private function isCompanionCandidate(Product $main, Product $row, ?string $family, ?string $articleType): bool
    {
        $rowFamily = $this->assortment->productFamily($row);
        $allowed = $this->companionFamilies($family);
        if ($allowed !== null && $rowFamily !== null && ! in_array($rowFamily, $allowed, true)) {
            return false;
        }
        $rowType = $this->assortment->articleTypePreferIdentity(
            trim($row->sku.' '.$row->name),
            (string) $row->name,
            $rowFamily
        );

        if ($this->looksLikeCleaningSupply($row)) {
            return false;
        }
        $accessory = $this->looksLikeAccessoryName($row);
        if (! $accessory && $articleType !== null && $rowType !== null && $articleType === $rowType) {
            return false;
        }
        if (! $accessory && $family === PpeAssortment::FAMILY_RESPIRATORY && in_array($rowType, ['reusable_half', 'fullface', 'ffp'], true)) {
            return false;
        }
        if (! $accessory && $family === PpeAssortment::FAMILY_HEAD && $rowType === 'helmet') {
            return false;
        }
        if (! $accessory && $family === PpeAssortment::FAMILY_EYES && in_array($rowType, ['glasses', 'goggles'], true)) {
            return false;
        }

        return true;
    }

    private function looksLikeCleaningSupply(Product $product): bool
    {
        $t = $this->assortment->normalize($product->sku.' '.$product->name);

        return preg_match('/\b(czyszczac|doodlebug|gabk|mop\b|plyn\s+do|pad\s+czysz)\w*/u', $t) === 1;
    }

    private function looksLikeAccessoryName(Product $product): bool
    {
        $t = $this->assortment->normalize($product->sku.' '.$product->name);

        return preg_match(
            '/\b(etui|futeral|sznurek|adapter|mocowan|pochlaniacz|filtropochlaniacz|prefiltr'
            .'|wkladk|higien|amortyzator|lonza|karabin|nakolann|przylbic|oslona\s+twarz)\w*/u',
            $t
        ) === 1;
    }

    /**
     * @return list<string>|null
     */
    private function companionFamilies(?string $family): ?array
    {
        return match ($family) {
            PpeAssortment::FAMILY_RESPIRATORY => [
                PpeAssortment::FAMILY_RESPIRATORY,
                PpeAssortment::FAMILY_EYES,
                PpeAssortment::FAMILY_FACE,
            ],
            PpeAssortment::FAMILY_HEAD => [
                PpeAssortment::FAMILY_HEAD,
                PpeAssortment::FAMILY_HEARING,
                PpeAssortment::FAMILY_FACE,
                PpeAssortment::FAMILY_EYES,
            ],
            PpeAssortment::FAMILY_FACE => [
                PpeAssortment::FAMILY_FACE,
                PpeAssortment::FAMILY_HEAD,
                PpeAssortment::FAMILY_EYES,
                PpeAssortment::FAMILY_HEARING,
            ],
            PpeAssortment::FAMILY_EYES => [PpeAssortment::FAMILY_EYES],
            PpeAssortment::FAMILY_HEARING => [
                PpeAssortment::FAMILY_HEARING,
                PpeAssortment::FAMILY_HEAD,
            ],
            PpeAssortment::FAMILY_APPAREL => [
                PpeAssortment::FAMILY_APPAREL,
                PpeAssortment::FAMILY_KNEE,
            ],
            PpeAssortment::FAMILY_FOOTWEAR => [PpeAssortment::FAMILY_FOOTWEAR],
            PpeAssortment::FAMILY_GLOVES => [PpeAssortment::FAMILY_GLOVES],
            PpeAssortment::FAMILY_FALL => [PpeAssortment::FAMILY_FALL],
            PpeAssortment::FAMILY_KNEE => [
                PpeAssortment::FAMILY_KNEE,
                PpeAssortment::FAMILY_APPAREL,
            ],
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(?string $family, ?string $articleType): array
    {
        return [
            'family' => $family,
            'family_label' => $this->familyLabel($family),
            'article_type' => $articleType,
            'prompt_version' => self::PROMPT_VERSION,
            'suggestions' => [],
        ];
    }

    private function familyLabel(?string $family): string
    {
        return match ($family) {
            PpeAssortment::FAMILY_RESPIRATORY => 'drogi oddechowe',
            PpeAssortment::FAMILY_HEAD => 'ochrona głowy',
            PpeAssortment::FAMILY_FACE => 'ochrona twarzy',
            PpeAssortment::FAMILY_EYES => 'ochrona oczu',
            PpeAssortment::FAMILY_HEARING => 'ochrona słuchu',
            PpeAssortment::FAMILY_APPAREL => 'odzież',
            PpeAssortment::FAMILY_FOOTWEAR => 'obuwie',
            PpeAssortment::FAMILY_GLOVES => 'rękawice',
            PpeAssortment::FAMILY_FALL => 'asekuracja',
            PpeAssortment::FAMILY_KNEE => 'ochrona kolan',
            default => 'asortyment',
        };
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<array<string, mixed>>
     */
    private function rawPicks(array $raw): array
    {
        $rows = $raw['picks'] ?? $raw['suggestions'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function sanitizeLabel(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_substr($value, 0, 180);
    }

    private function productText(Product $product): string
    {
        return trim(implode(' ', array_filter([
            (string) $product->sku,
            (string) $product->name,
            (string) $product->manufacturer,
            (string) $product->norms,
            (string) $product->description,
        ])));
    }
}
