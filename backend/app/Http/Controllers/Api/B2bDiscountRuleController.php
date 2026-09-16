<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\B2bDiscountRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Rabaty konta B2B dla witryn, które podają tylko cenę katalogową (protekt.pl). Cała lista zapisywana
 * jednym żądaniem — kolejność reguł jest ich znaczeniem, więc zapis pojedynczego wiersza wymagałby
 * i tak przenumerowania reszty.
 */
class B2bDiscountRuleController extends Controller
{
    public function index(B2bAccount $b2bAccount): JsonResponse
    {
        return response()->json([
            'rules' => $b2bAccount->discountRules->map(fn (B2bDiscountRule $rule): array => $this->view($rule))->values(),
            'fields' => B2bDiscountRule::FIELDS,
            'types' => B2bDiscountRule::TYPES,
        ]);
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

        DB::transaction(function () use ($b2bAccount, $rules): void {
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
        });

        return $this->index($b2bAccount->fresh());
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
