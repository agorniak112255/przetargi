<?php

declare(strict_types=1);

namespace App\Services\Enrichment\Sources;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductSourcePin;
use App\Models\ProductSourcePrice;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\PartsTable\PartsTables;
use Illuminate\Container\Attributes\Scoped;

/**
 * Jedna strona źródłowa karty (10.10.2026, uogólnione przypięcie Coby). Kolejność:
 *  1. adres wskazany przez człowieka (Product::trustedShopUrl) — brak przypięcia, stara ścieżka z adresem ręcznym;
 *  2. tabela części producenta (PartsTables, Coba) — PartsTablePin;
 *  3. mapa importera cennika (product_source_pins z url) — tylko gdy cennik karty (slot „file”) przyjmowany jest
 *     nowym sposobem (PriceList::usesIntake) i wiersz mapy należy do tego cennika — MappedSourcePin.
 * Karta cennika map_only bez przypięcia i bez adresu człowieka jest zablokowana (blockedReason): bez opisu z internetu,
 * do „Do przeglądu”. Cenniki dawnym sposobem (source_policy null) — wiersze mapy są ignorowane, przebieg jak dotąd.
 * Niczego nie zapisuje. Cenniki trzymane raz na przebieg (#[Scoped]: kolejne zadanie kolejki czyta od nowa).
 */
#[Scoped]
final class SourcePins
{
    /** Brak wiersza mapy (albo wiersz innego cennika — karta przeszła do innego cennika po mapowaniu). */
    public const REASON_NO_MAP = 'karta nie ma jeszcze mapy importera';

    /** Wiersz mapy bez adresu i bez powodu. */
    public const REASON_UNRESOLVED = 'importer nie przypiął strony';

    /** Strona z mapy odrzucona przez handlowca w przeglądzie („to cudza strona”). */
    public const REASON_REJECTED = 'strona z mapy importera odrzucona w przeglądzie';

    /** @var array<int, PriceList|null> id cennika => cennik (null = nie istnieje) */
    private array $lists = [];

    public function pinFor(Product $product): ?SourcePin
    {
        return $this->resolve($product)['pin'];
    }

    /** Powód blokady karty map_only bez przypięcia; null = karta przypięta, z adresem człowieka albo dawnym sposobem. */
    public function blockedReason(Product $product): ?string
    {
        return $this->resolve($product)['reason'];
    }

    /**
     * Przypięcie albo powód blokady w jednym przejściu (tabela części liczona raz).
     *
     * @return array{pin: ?SourcePin, reason: ?string}
     */
    public function resolve(Product $product): array
    {
        if ($product->trustedShopUrl() !== null) {
            return ['pin' => null, 'reason' => null];
        }
        $parts = app(PartsTables::class)->pinFor($product)?->pin;
        if ($parts !== null) {
            return ['pin' => $parts, 'reason' => null];
        }

        return $this->mapped($product);
    }

    /** @return array{pin: ?MappedSourcePin, reason: ?string} */
    private function mapped(Product $product): array
    {
        $list = $this->intakeListOf($product);
        if ($list === null) {
            return ['pin' => null, 'reason' => null];
        }
        $row = ProductSourcePin::query()->where('product_id', $product->id)->first();
        if ($row === null || (int) $row->price_list_id !== (int) $list->id) {
            return ['pin' => null, 'reason' => self::REASON_NO_MAP];
        }
        if (! $row->isResolved()) {
            $reason = trim((string) $row->unresolved_reason);

            return ['pin' => null, 'reason' => $reason !== '' ? $reason : self::REASON_UNRESOLVED];
        }
        $pin = MappedSourcePin::fromRow($row);
        $key = DescriptionVersionStore::sourceUrlKey($pin->url());
        foreach (app(DescriptionVersionStore::class)->rejectedUrls($product) as $rejected) {
            if (DescriptionVersionStore::sourceUrlKey($rejected) === $key) {
                return ['pin' => null, 'reason' => self::REASON_REJECTED.': '.$pin->url()];
            }
        }

        return ['pin' => $pin, 'reason' => null];
    }

    /** Cennik karty ze slotu „file”, gdy przyjmowany jest nowym sposobem (source_policy); inaczej null. */
    private function intakeListOf(Product $product): ?PriceList
    {
        if ($product->id === null) {
            return null;
        }
        $listId = ProductSourcePrice::query()
            ->where('product_id', $product->id)
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->value('price_list_id');
        if ($listId === null) {
            return null;
        }
        $listId = (int) $listId;
        if (! array_key_exists($listId, $this->lists)) {
            $this->lists[$listId] = PriceList::query()->find($listId);
        }
        $list = $this->lists[$listId];

        return $list !== null && $list->usesIntake() ? $list : null;
    }

    public function flush(): void
    {
        $this->lists = [];
    }
}
