<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bDiscountRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rabaty konta B2B dla witryn z samą ceną katalogową. Reguły sprawdzane po kolei, pierwsza
 * pasująca wygrywa; brak dopasowania to null, nigdy 0% — karta bez reguły ma zostać pominięta
 * z powodem, bo cena katalogowa zapisana jako cena zakupu zawyżyłaby każdą wycenę.
 *
 * Reguły wczytujemy raz na przebieg: przy 6 tys. kart zapytanie na kartę byłoby marnotrawstwem,
 * a zmiana konfiguracji w trakcie przebiegu i tak dałaby cennik liczony dwiema stawkami.
 */
final class B2bDiscountRuleResolver
{
    /** @var Collection<int, B2bDiscountRule>|null */
    private ?Collection $rules = null;

    /** @var array<int, int> ruleId => liczba trafień w tym przebiegu */
    private array $hits = [];

    private int $misses = 0;

    public function __construct(private readonly int $accountId) {}

    public function resolve(string $catalogNo, ?string $category, string $name): ?B2bDiscountMatch
    {
        foreach ($this->rules() as $rule) {
            if (! $rule->matches($catalogNo, $category, $name)) {
                continue;
            }

            $this->hits[$rule->id] = ($this->hits[$rule->id] ?? 0) + 1;

            return new B2bDiscountMatch(
                ruleId: $rule->id,
                ruleName: (string) $rule->name,
                discountPercent: (float) $rule->discount_percent,
                assortmentGroupId: $rule->assortment_group_id,
            );
        }

        $this->misses++;

        return null;
    }

    public function hasRules(): bool
    {
        return $this->rules()->isNotEmpty();
    }

    public function matchedCount(): int
    {
        return array_sum($this->hits);
    }

    public function missedCount(): int
    {
        return $this->misses;
    }

    /** Zapisuje liczniki trafień przy regułach — panel pokazuje, które reguły nic nie łapią. */
    public function flushCounters(): void
    {
        $now = Carbon::now();
        foreach ($this->rules() as $rule) {
            $rule->forceFill([
                'last_matched_count' => $this->hits[$rule->id] ?? 0,
                'last_matched_at' => $now,
            ])->saveQuietly();
        }
    }

    /**
     * @return Collection<int, B2bDiscountRule>
     */
    private function rules(): Collection
    {
        return $this->rules ??= B2bDiscountRule::query()
            ->where('b2b_account_id', $this->accountId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }
}
