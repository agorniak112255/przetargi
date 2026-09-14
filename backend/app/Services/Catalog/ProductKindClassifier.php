<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Product;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Support\PpeAssortment;
use Illuminate\Support\Collection;

/**
 * Rodzaj produktu rozpoznany modelem — narzędzie do szukania luk w regułach rodziny PPE, nie źródło `ppe_family`.
 * Zapisana rodzina działa jak twarda bramka (PpeAssortment::compatibleProduct, ProductMatchService::narrowSkuPool),
 * więc zła rodzina z wiedzy modelu ukryłaby kartę przed dopasowaniem przetargu. Przetarg 1 poz. 1: rękaw HyFlex 11-202
 * bez rodziny wypadał z puli; przebudowa indeksu 14.09 pokazała rękawy termokurczliwe 3M dopisane do rękawic.
 *
 * Nic nie zapisuje. Podstawa „dane” tylko wtedy, gdy cytat z odpowiedzi występuje w tekście karty, który model dostał.
 */
final class ProductKindClassifier
{
    public const BATCH = 40;

    public const PPE_YES = 'tak';

    public const PPE_NO = 'nie';

    public const PPE_UNKNOWN = 'nieznane';

    public const BASIS_DATA = 'dane';

    public const BASIS_MODEL = 'wiedza_modelu';

    private const FAMILIES = [
        PpeAssortment::FAMILY_GLOVES,
        PpeAssortment::FAMILY_FOOTWEAR,
        PpeAssortment::FAMILY_APPAREL,
        PpeAssortment::FAMILY_HEAD,
        PpeAssortment::FAMILY_FACE,
        PpeAssortment::FAMILY_EYES,
        PpeAssortment::FAMILY_HEARING,
        PpeAssortment::FAMILY_RESPIRATORY,
        PpeAssortment::FAMILY_FALL,
        PpeAssortment::FAMILY_KNEE,
    ];

    private const DESCRIPTION_CHARS = 600;

    private const NORMS_CHARS = 200;

    private const MAX_TOKENS = 4000;

    private const CONCURRENCY = 4;

    public function __construct(
        private readonly OpenAiCompatibleClient $llm,
        private readonly PpeAssortment $assortment,
    ) {}

    /**
     * Wynik po id karty; karta bez odpowiedzi modelu nie ma wpisu.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, array{family: ?string, type: ?string, ppe: string, basis: string, evidence: ?string, evidence_confirmed: bool, evidence_matches_rule: bool}>
     */
    public function classify(Collection $products): array
    {
        $cards = $products
            ->filter(static fn (mixed $product): bool => $product instanceof Product)
            ->keyBy(static fn (Product $product): int => (int) $product->id);
        if ($cards->isEmpty()) {
            return [];
        }

        $chunks = $cards->chunk(self::BATCH)->values();
        $raws = $this->llm->chatJsonMany(
            $chunks->map(fn (Collection $chunk): array => $this->messages($chunk))->all(),
            self::MAX_TOKENS,
            AiTask::Enrichment,
            self::CONCURRENCY,
        );

        $out = [];
        foreach ($chunks as $i => $chunk) {
            $items = is_array($raws[$i]['items'] ?? null) ? $raws[$i]['items'] : [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $id = (int) ($item['id'] ?? 0);
                $product = $chunk->get($id);
                if (! $product instanceof Product || isset($out[$id])) {
                    continue;
                }
                $out[$id] = $this->validated($item, $product);
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int, Product>  $chunk
     * @return list<array{role: string, content: string}>
     */
    private function messages(Collection $chunk): array
    {
        $cards = $chunk->map(fn (Product $product): array => array_filter(
            ['id' => (int) $product->id] + $this->cardFields($product),
            static fn (mixed $value): bool => $value !== '',
        ))->values()->all();

        return [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => "Karty:\n".json_encode($cards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'SYS'
Rozpoznajesz rodzaj produktu z katalogu hurtowni BHP. Dla każdej karty ustal:
- ppe: "tak" (środek ochrony indywidualnej albo jego część / wkład / filtr), "nie" (produkt spoza ŚOI), "nieznane" (brak podstaw);
- family: gloves | footwear | apparel | head | face | eyes | hearing | respiratory | fall | knee | null;
- type: krótki typ po polsku, np. "rękaw ochronny", "rękawice nitrylowe", "filtr do półmaski", "taśma klejąca".
Rodziny: gloves = rękawice oraz rękawy i zarękawki chroniące ręce i przedramiona; footwear = obuwie; apparel = odzież;
head = hełmy i czapki ochronne; face = przyłbice i osłony twarzy; eyes = okulary i gogle; hearing = ochronniki słuchu;
respiratory = półmaski, maski, filtry, pochłaniacze; fall = sprzęt chroniący przed upadkiem; knee = nakolanniki.
basis: "dane", gdy rodzaj wynika z tekstu karty — evidence to wtedy DOSŁOWNY fragment (2–8 słów) skopiowany z nazwy,
kategorii, norm albo opisu tej karty. "wiedza_modelu", gdy rozpoznajesz produkt tylko po kodzie lub nazwie modelu
z własnej wiedzy — evidence = null.
Produkty przemysłowe (taśmy, kleje, ścierniwa, osprzęt kablowy, sorbenty, chemia) → ppe "nie", family null.
Nie zgaduj: brak podstaw → ppe "nieznane", family null.
Zwróć WYŁĄCZNIE JSON, po jednym elemencie na każdą kartę:
{"items":[{"id":1,"ppe":"tak","family":"gloves","type":"rękaw ochronny","basis":"dane","evidence":"rękaw ochronny"}]}
SYS;
    }

    /** @return array{sku: string, nazwa: string, producent: string, kategoria: string, normy: string, opis: string} */
    private function cardFields(Product $product): array
    {
        return [
            'sku' => (string) $product->sku,
            'nazwa' => (string) $product->name,
            'producent' => (string) ($product->manufacturer ?? ''),
            'kategoria' => (string) ($product->category ?? ''),
            'normy' => mb_substr(trim((string) ($product->norms ?? '')), 0, self::NORMS_CHARS),
            'opis' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($product->description ?? '')) ?? ''), 0, self::DESCRIPTION_CHARS),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{family: ?string, type: ?string, ppe: string, basis: string, evidence: ?string, evidence_confirmed: bool, evidence_matches_rule: bool}
     */
    private function validated(array $item, Product $product): array
    {
        $ppe = in_array($item['ppe'] ?? null, [self::PPE_YES, self::PPE_NO, self::PPE_UNKNOWN], true)
            ? (string) $item['ppe']
            : self::PPE_UNKNOWN;
        $family = in_array($item['family'] ?? null, self::FAMILIES, true) ? (string) $item['family'] : null;
        if ($ppe !== self::PPE_YES) {
            $family = null;
        }
        $type = is_string($item['type'] ?? null) && trim($item['type']) !== '' ? mb_substr(trim($item['type']), 0, 80) : null;
        $evidence = is_string($item['evidence'] ?? null) && trim($item['evidence']) !== ''
            ? mb_substr(trim($item['evidence']), 0, 200)
            : null;
        $confirmed = $evidence !== null && $this->occursIn($evidence, implode(' ', $this->cardFields($product)));

        return [
            'family' => $family,
            'type' => $type,
            'ppe' => $ppe,
            'basis' => ($item['basis'] ?? null) === self::BASIS_DATA && $confirmed ? self::BASIS_DATA : self::BASIS_MODEL,
            'evidence' => $evidence,
            'evidence_confirmed' => $confirmed,
            // cytat z danych sam wskazuje rodzinę regułą, a karta i tak jej nie ma → problem z kolejnością reguł, nie brak wzorca
            'evidence_matches_rule' => $confirmed && $family !== null && $this->assortment->family($evidence) === $family,
        ];
    }

    private function occursIn(string $needle, string $haystack): bool
    {
        $needle = trim((string) preg_replace('/\s+/u', ' ', $this->assortment->normalize($needle)));
        if (mb_strlen($needle) < 3) {
            return false;
        }

        return str_contains((string) preg_replace('/\s+/u', ' ', $this->assortment->normalize($haystack)), $needle);
    }
}
