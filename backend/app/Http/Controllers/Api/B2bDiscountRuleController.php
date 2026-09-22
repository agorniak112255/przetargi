<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\B2bDiscountRule;
use App\Models\ProductSourcePrice;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDiscountRuleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Rabaty konta B2B. Dla witryn z samą ceną katalogową (protekt.pl) reguły dają cenę zakupu; dla łączników
 * z cennikiem bazowym (UVEX, B2bStandardDiscountSite) to rabat standardowy, od którego zależy wykrycie ceny
 * specjalnej. Cała lista zapisywana jednym żądaniem — kolejność reguł jest ich znaczeniem, więc zapis
 * pojedynczego wiersza wymagałby i tak przenumerowania reszty.
 */
class B2bDiscountRuleController extends Controller
{
    /** Tyle slotów naraz przy przeliczaniu rabatu standardowego — UVEX ma kilka tysięcy kart. */
    private const RECOMPUTE_CHUNK = 500;

    public function __construct(private readonly B2bConnectorRegistry $connectors) {}

    public function index(B2bAccount $b2bAccount): JsonResponse
    {
        return response()->json($this->payload($b2bAccount));
    }

    public function update(Request $request, B2bAccount $b2bAccount): JsonResponse
    {
        $data = $request->validate([
            'rules' => ['present', 'array', 'max:500'],
            'rules.*.name' => ['required', 'string', 'max:150'],
            'rules.*.match_field' => ['required', 'string', Rule::in(B2bDiscountRule::FIELDS)],
            'rules.*.match_type' => ['required', 'string', Rule::in(B2bDiscountRule::TYPES)],
            // Wzorzec pusty tylko dla reguły „wszystko” — pusty wzorzec przy innych typach nic nie łapie.
            'rules.*.pattern' => ['nullable', 'string', 'max:255'],
            'rules.*.discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $rules = array_values($data['rules']);
        foreach ($rules as $index => $rule) {
            $pattern = trim((string) ($rule['pattern'] ?? ''));
            if ($pattern === '' && $rule['match_type'] !== B2bDiscountRule::TYPE_ANY) {
                return response()->json([
                    'message' => 'Reguła „'.$rule['name'].'” nie ma wzorca. Pusty wzorzec nie pasuje do niczego — '
                        .'wpisz wzorzec albo wybierz dopasowanie „wszystko”.',
                    'errors' => ['rules.'.$index.'.pattern' => ['Podaj wzorzec.']],
                ], 422);
            }
        }

        $standard = $this->connectors->usesStandardDiscounts($this->connectors->keyForAccount($b2bAccount));

        $recomputed = DB::transaction(function () use ($b2bAccount, $rules, $standard): ?int {
            // Liczniki trafień z ostatniego przebiegu przenosimy na reguły o niezmienionym dopasowaniu:
            // zmiana nazwy albo kolejności nie może kasować informacji, ile kart reguła łapie.
            $counters = [];
            foreach ($b2bAccount->discountRules as $existing) {
                $counters[self::matcherKey($existing->match_field, $existing->match_type, (string) $existing->pattern)] = [
                    'last_matched_count' => $existing->last_matched_count,
                    'last_matched_at' => $existing->last_matched_at,
                ];
            }

            $b2bAccount->discountRules()->delete();
            foreach ($rules as $position => $rule) {
                $pattern = trim((string) ($rule['pattern'] ?? ''));
                $created = $b2bAccount->discountRules()->create([
                    'position' => $position,
                    'name' => trim((string) $rule['name']),
                    'match_field' => $rule['match_field'],
                    'match_type' => $rule['match_type'],
                    'pattern' => $pattern,
                    'discount_percent' => round((float) $rule['discount_percent'], 2),
                ]);
                $carried = $counters[self::matcherKey($rule['match_field'], $rule['match_type'], $pattern)] ?? null;
                if ($carried !== null) {
                    $created->forceFill($carried)->saveQuietly();
                }
            }

            // W tej samej transakcji: nowe reguły i przeliczone rabaty standardowe widać razem albo wcale —
            // inaczej karta pokazywałaby cenę specjalną ocenioną starą stawką przy nowej liście reguł.
            return $standard ? $this->recomputeStandardDiscounts($b2bAccount) : null;
        });

        $payload = $this->payload($b2bAccount->fresh());
        if ($recomputed !== null) {
            $payload['recomputed'] = $recomputed;
        }

        return response()->json($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(B2bAccount $account): array
    {
        $mode = $this->connectors->discountRulesMode($this->connectors->keyForAccount($account));
        $payload = [
            'rules' => $account->discountRules->map(fn (B2bDiscountRule $rule): array => $this->view($rule))->values(),
            'fields' => B2bDiscountRule::FIELDS,
            'types' => B2bDiscountRule::TYPES,
            'mode' => $mode,
        ];
        if ($mode === B2bConnectorRegistry::DISCOUNT_RULES_STANDARD) {
            // Nazwy kategorii (arkuszy cennika) dosłownie z ostatniej synchronizacji — reguła „równa się”
            // musi mieć wzorzec co do znaku, a literówka w nazwie arkusza cicho zostawia karty bez oceny.
            $payload['categories'] = ProductSourcePrice::query()
                ->where('source_key', ProductSourcePrice::b2bKey((int) $account->id))
                ->whereNotNull('base_price_category')
                ->groupBy('base_price_category')
                ->orderBy('base_price_category')
                ->selectRaw('base_price_category as name, COUNT(DISTINCT product_id) as product_count')
                ->get()
                ->map(static fn (ProductSourcePrice $row): array => [
                    'name' => (string) $row->getAttribute('name'),
                    'product_count' => (int) $row->getAttribute('product_count'),
                ])
                ->values()
                ->all();
        }

        return $payload;
    }

    /**
     * Rabat standardowy slotów konta od nowa z zapisanych reguł — bez czekania na synchronizację. Wejście jak
     * w synchronizacji: kod karty, kategoria wiersza cennika bazowego, nazwa karty. Brak pasującej reguły = null
     * (brak oceny ceny), nigdy 0%. Liczników trafień nie zapisujemy: opisują ostatni przebieg synchronizacji,
     * a nie ten zapis. Ceny zakupu i ceny karty nie ruszamy — zmienia się tylko ocena ceny specjalnej.
     *
     * @return int liczba slotów, którym zmienił się rabat standardowy
     */
    private function recomputeStandardDiscounts(B2bAccount $account): int
    {
        $resolver = new B2bDiscountRuleResolver((int) $account->id);
        $changed = 0;

        ProductSourcePrice::query()
            ->with('product:id,sku,name')
            ->where('source_key', ProductSourcePrice::b2bKey((int) $account->id))
            ->whereNotNull('base_price_category')
            ->chunkById(self::RECOMPUTE_CHUNK, function ($slots) use ($resolver, &$changed): void {
                foreach ($slots as $slot) {
                    /** @var ProductSourcePrice $slot */
                    $product = $slot->product;
                    $match = $product !== null
                        ? $resolver->resolve((string) $product->sku, (string) $slot->base_price_category, (string) $product->name)
                        : null;
                    $slot->standard_discount_percent = $match?->discountPercent;
                    if ($slot->isDirty('standard_discount_percent')) {
                        $slot->save();
                        $changed++;
                    }
                }
            });

        return $changed;
    }

    /** Tożsamość dopasowania reguły — po niej przenosimy licznik trafień przy zapisie listy. */
    private static function matcherKey(string $field, string $type, string $pattern): string
    {
        return $field.'|'.$type.'|'.mb_strtolower($pattern);
    }

    /**
     * @return array<string, mixed>
     */
    private function view(B2bDiscountRule $rule): array
    {
        return [
            'id' => $rule->id,
            'position' => $rule->position,
            'name' => $rule->name,
            'match_field' => $rule->match_field,
            'match_type' => $rule->match_type,
            'pattern' => $rule->pattern,
            'discount_percent' => (float) $rule->discount_percent,
            'last_matched_count' => $rule->last_matched_count,
            'last_matched_at' => $rule->last_matched_at?->toIso8601String(),
        ];
    }
}
