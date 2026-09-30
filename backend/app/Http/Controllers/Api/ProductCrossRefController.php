<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompareProductsRequest;
use App\Models\Product;
use App\Services\Pricing\SupplierSpecialMask;
use App\Services\ProductCompareService;
use App\Services\ProductCrossRefService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductCrossRefController extends Controller
{
    public function __construct(
        private readonly ProductCrossRefService $crossRef,
        private readonly ProductCompareService $compare,
    ) {}

    public function options(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $result = $this->crossRef->optionsForCode($data['code']);
        $mask = SupplierSpecialMask::forUser($request->user());
        if (is_array($result['seed'] ?? null)) {
            $result['seed'] = $this->maskedCard($result['seed'], $mask);
        }

        return response()->json($result);
    }

    public function crossRef(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:2', 'max:120'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:40'],
            'must' => ['sometimes', 'array', 'max:40'],
            'must.*' => ['string', 'max:120'],
        ]);

        $result = $this->crossRef->findByCode(
            $data['code'],
            (int) ($data['limit'] ?? 12),
            is_array($data['must'] ?? null) ? $data['must'] : []
        );
        // bez uprawnienia cena specjalna B2B karty w cenie standardowej (wybór i kolejność zamienników nie zależą od ceny)
        $mask = SupplierSpecialMask::forUser($request->user());
        $mask->preload(array_map(static fn (array $card): int => (int) $card['product_id'], [
            ...(is_array($result['seed'] ?? null) ? [$result['seed']] : []),
            ...($result['matches'] ?? []),
        ]));
        if (is_array($result['seed'] ?? null)) {
            $result['seed'] = $this->maskedCard($result['seed'], $mask);
        }
        foreach ($result['matches'] ?? [] as $i => $card) {
            $result['matches'][$i] = $this->maskedCard($card, $mask);
        }

        return response()->json($result);
    }

    public function compare(CompareProductsRequest $request): JsonResponse
    {
        $ids = $request->productIds();
        $productsById = Product::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
        $products = array_map(
            static fn (int $id): Product => $productsById->get($id),
            $ids
        );
        $requirement = $request->validated('requirement');
        // porównanie liczy się na kartach w widoku cen widza — wiersz „Cena katalogowa” i jego zgodność też
        $mask = SupplierSpecialMask::forUser($request->user());
        $mask->preload($ids);
        $products = array_map(static fn (Product $product): Product => $mask->maskProduct($product), $products);

        $payload = count($products) === 2
            ? $this->compare->compare($products[0], $products[1], $requirement)
            : $this->compare->compareMany($products, $requirement);

        return response()->json($payload);
    }

    /**
     * Karta wyniku (ProductCrossRefService::productCard) w widoku cen widza — cena katalogowa jako liczba jak w serwisie.
     *
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    private function maskedCard(array $card, SupplierSpecialMask $mask): array
    {
        if (! $mask->hides() || ($card['catalog_price_net'] ?? null) === null) {
            return $card;
        }
        $row = $mask->productRow(['id' => $card['product_id'], 'catalog_price_net' => $card['catalog_price_net']]);
        $card['catalog_price_net'] = (float) $row['catalog_price_net'];

        return $card;
    }
}
