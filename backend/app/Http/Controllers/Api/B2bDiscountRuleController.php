<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\B2bDiscountRule;
use App\Models\B2bSyncRun;
use App\Models\ProductSourcePrice;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDiscountRuleResolver;
use App\Services\B2b\B2bStandardDiscountSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

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

    /** Tyle godzin pamiętamy arkusze pobranego cennika bazowego (podpowiedzi i kontrola nazw reguł). */
    private const CATEGORIES_TTL_HOURS = 24;

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
        if ($standard) {
            $unknown = $this->unknownCategoryRule($b2bAccount, $rules);
            if ($unknown !== null) {
                return response()->json($unknown, 422);
            }
        }

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
            $payload['categories'] = $this->knownCategories($account);
            // propozycja od dostawcy dla konta bez reguł — okno wstawia ją do formularza, zapis robi użytkownik
            $connector = $this->connectors->make($account);
            $payload['defaults'] = $connector instanceof B2bStandardDiscountSite
                ? array_map(static fn (array $d): array => [
                    'name' => $d['category'],
                    'match_field' => B2bDiscountRule::FIELD_CATEGORY,
                    'match_type' => B2bDiscountRule::TYPE_EQUALS,
                    'pattern' => $d['category'],
                    'discount_percent' => $d['discount_percent'],
                ], $connector::defaultStandardDiscounts())
                : [];
        }

        return $payload;
    }

    /**
     * Arkusze aktualnego cennika bazowego prosto od dostawcy (logowanie i pobranie pliku, kilka sekund) — okno
     * reguł podpowiada nazwy, zanim pierwsza synchronizacja zapisze kategorie na kartach. Wynik zapamiętany
     * (CATEGORIES_TTL_HOURS) — kontrola nazw przy zapisie reguł korzysta z tej samej listy. W trakcie
     * synchronizacji konta nie logujemy się drugi raz: nowa sesja u dostawcy mogłaby unieważnić sesję przebiegu.
     */
    public function baseCategories(B2bAccount $b2bAccount): JsonResponse
    {
        if (! $this->connectors->usesStandardDiscounts($this->connectors->keyForAccount($b2bAccount))) {
            return response()->json(['message' => 'To konto nie ma cennika bazowego.'], 422);
        }
        $running = B2bSyncRun::query()
            ->where('b2b_account_id', $b2bAccount->id)
            ->where('status', B2bSyncRun::STATUS_RUNNING)
            ->where('updated_at', '>=', now()->subMinutes(B2bSyncRun::STALE_MINUTES))
            ->exists();
        if ($running) {
            return response()->json([
                'message' => 'Trwa synchronizacja konta — arkusze cennika pobiorę po jej zakończeniu.',
                'categories' => $this->knownCategories($b2bAccount),
            ], 409);
        }

        $connector = $this->connectors->make($b2bAccount);
        if (! $connector instanceof B2bStandardDiscountSite) {
            return response()->json(['message' => 'To konto nie ma cennika bazowego.'], 422);
        }
        try {
            $sheets = $connector->basePriceCategories();
        } catch (Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'categories' => $this->knownCategories($b2bAccount),
            ], 502);
        }
        Cache::put(self::categoriesCacheKey($b2bAccount), array_values($sheets), now()->addHours(self::CATEGORIES_TTL_HOURS));

        return response()->json(['categories' => $this->knownCategories($b2bAccount)]);
    }

    /**
     * Kategorie znane dla konta: z kart (ostatnia synchronizacja, z liczbą kart) i z ostatnio pobranego cennika
     * (liczba kart 0, dopóki synchronizacja ich nie zapisze). Nazwy dosłownie — reguła „jest równe” musi mieć
     * pełną nazwę arkusza, a literówka cicho zostawiłaby karty bez oceny.
     *
     * @return list<array{name: string, product_count: int}>
     */
    private function knownCategories(B2bAccount $account): array
    {
        $out = [];
        $rows = ProductSourcePrice::query()
            ->where('source_key', ProductSourcePrice::b2bKey((int) $account->id))
            ->whereNotNull('base_price_category')
            ->groupBy('base_price_category')
            ->selectRaw('base_price_category as name, COUNT(DISTINCT product_id) as product_count')
            ->get();
        foreach ($rows as $row) {
            $name = (string) $row->getAttribute('name');
            $out[mb_strtolower($name)] = ['name' => $name, 'product_count' => (int) $row->getAttribute('product_count')];
        }
        foreach ((array) Cache::get(self::categoriesCacheKey($account), []) as $sheet) {
            $out[mb_strtolower((string) $sheet)] ??= ['name' => (string) $sheet, 'product_count' => 0];
        }
        $list = array_values($out);
        usort($list, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $list;
    }

    /**
     * Reguła po kategorii, która nie trafia w żaden znany arkusz cennika — odrzucenie z nazwą reguły zamiast
     * cichego braku oceny cen. Bez znanych kategorii (cennika jeszcze nie pobrano) nie ma z czym porównać.
     *
     * @param  list<array<string, mixed>>  $rules
     * @return array{message: string, errors: array<string, list<string>>}|null
     */
    private function unknownCategoryRule(B2bAccount $account, array $rules): ?array
    {
        $known = array_map(static fn (array $c): string => mb_strtolower(trim($c['name'])), $this->knownCategories($account));
        if ($known === []) {
            return null;
        }
        foreach ($rules as $index => $rule) {
            if ($rule['match_field'] !== B2bDiscountRule::FIELD_CATEGORY || $rule['match_type'] === B2bDiscountRule::TYPE_ANY) {
                continue;
            }
            $pattern = mb_strtolower(trim((string) ($rule['pattern'] ?? '')));
            $hit = false;
            foreach ($known as $name) {
                $hit = match ($rule['match_type']) {
                    B2bDiscountRule::TYPE_EQUALS => $name === $pattern,
                    B2bDiscountRule::TYPE_CONTAINS => str_contains($name, $pattern),
                    default => str_starts_with($name, $pattern),
                };
                if ($hit) {
                    break;
                }
            }
            if (! $hit) {
                return [
                    'message' => 'Reguła „'.$rule['name'].'”: arkusza „'.trim((string) $rule['pattern']).'” nie ma w cenniku bazowym. '
                        .'Znane arkusze: '.implode(', ', array_map(static fn (array $c): string => $c['name'], $this->knownCategories($account))).'.',
                    'errors' => ['rules.'.$index.'.pattern' => ['Nieznany arkusz cennika.']],
                ];
            }
        }

        return null;
    }

    private static function categoriesCacheKey(B2bAccount $account): string
    {
        return 'b2b:base-categories:'.$account->id;
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
