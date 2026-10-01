<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Druga cena konta: niższa cena przy zakupie pełnego kartonu (decyzja właściciela 01.10.2026, BIG: „ab 96 Paar 0,92 €”
 * obok „ab 12 Paar 1,15 €”). Cena konta (B2bRemotePrice::$net) zostaje ceną od minimum zamówienia. net null = sklep
 * podał, że takiej ceny nie ma (zapis czyści poprzednią); qty null = ilość w kartonie nieznana.
 */
final readonly class B2bCartonPrice
{
    public function __construct(
        public ?float $net,
        public ?float $qty = null,
    ) {}

    /**
     * @return array{carton_price_net: float|null, carton_qty: float|null}
     */
    public function slotValues(): array
    {
        $net = $this->net !== null && $this->net > 0 ? round($this->net, 2) : null;

        return [
            'carton_price_net' => $net,
            'carton_qty' => $net !== null ? $this->qty : null,
        ];
    }
}
