<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Warunek zamawiania pozycji u dostawcy, dosłownie ze sklepu (UVEX: pole ilości koszyka min/step). Jak w HTML krok
 * liczy się od minimum: dozwolone ilości to min, min+step, min+2·step… step null przy podanym min = bez kroku.
 * varies = rozmiary jednej karty mają różne warunki: min i step null (warunku nie przypisujemy karcie), zapis czyści
 * poprzedni, a widoki mówią „zależy od rozmiaru”.
 */
final readonly class B2bOrderQuantity
{
    public function __construct(
        public ?float $min,
        public ?float $step,
        public ?string $unit = null,
        public bool $varies = false,
    ) {}

    /** Zamówienie ograniczone: minimum powyżej 1 albo krok inny niż pełna sztuka. */
    public function restricts(): bool
    {
        return self::restricting($this->min, $this->step);
    }

    public static function restricting(?float $min, ?float $step): bool
    {
        return ($min !== null && $min > 1) || ($step !== null && $step > 1);
    }

    /** Atrybut min/step pola ilości: liczba (także „10.0000”); „any”, pusty albo nieliczbowy = null. */
    public static function attribute(string $value): ?float
    {
        $value = trim($value);
        if (preg_match('/^\d+(?:[.,]\d+)?$/', $value) !== 1) {
            return null;
        }
        $number = (float) str_replace(',', '.', $value);

        return $number > 0 ? $number : null;
    }

    /** Ilość do pokazania: „10”, „2,5” — bez zer po przecinku ze źródła („10.0000”). */
    public static function format(float $qty): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.'));
    }

    /**
     * @return array{order_min_qty: float|null, order_step_qty: float|null, order_unit: string|null, order_varies: bool}
     */
    public function slotValues(): array
    {
        return [
            'order_min_qty' => $this->varies ? null : $this->min,
            'order_step_qty' => $this->varies ? null : $this->step,
            'order_unit' => $this->unit !== null && trim($this->unit) !== '' ? mb_substr(trim($this->unit), 0, 20) : null,
            'order_varies' => $this->varies,
        ];
    }
}
