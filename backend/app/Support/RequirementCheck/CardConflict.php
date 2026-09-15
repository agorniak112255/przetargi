<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

/**
 * Parametr, dla którego pola tej samej karty podają różne wartości — np. ARMEN: nazwa „S1 P”,
 * a specyfikacja „Klasa ochrony: S1”. Każda wartość ma znaleziska z polem źródłowym i cytatem,
 * żeby handlowiec widział, gdzie karta sobie przeczy, a nie tylko że przeczy.
 */
final readonly class CardConflict
{
    /**
     * @param  list<array{value: string, findings: list<array<string, mixed>>}>  $values  co najmniej dwie różne wartości
     * @param  string|null  $row  klucz wiersza porównania w `groups`, gdy przetarg pyta o ten parametr
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $values,
        public ?string $row = null,
    ) {}

    /**
     * @return array{key: string, label: string, row: ?string, values: list<array{value: string, findings: list<array<string, mixed>>}>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'row' => $this->row,
            'values' => $this->values,
        ];
    }
}
