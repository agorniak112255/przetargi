<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Warunek, przy którym obowiązuje cena konta, dosłownie ze sklepu (Delta Plus: „Cena jednostkowa za pełny karton
 * tego samego rozmiaru i koloru”). cartonQty = ilość w kartonie, dla której obowiązuje cena; null przy przypisie =
 * rozmiary karty mają różne kartony. note null = sklep podał, że warunku nie ma — zapis czyści poprzedni.
 */
final readonly class B2bPriceCondition
{
    public function __construct(
        public ?string $note,
        public ?float $cartonQty = null,
    ) {}

    /**
     * @return array{price_note: string|null, price_carton_qty: float|null}
     */
    public function slotValues(): array
    {
        $note = $this->note !== null ? trim($this->note) : '';

        return [
            'price_note' => $note !== '' ? mb_substr($note, 0, 255) : null,
            'price_carton_qty' => $note !== '' ? $this->cartonQty : null,
        ];
    }
}
