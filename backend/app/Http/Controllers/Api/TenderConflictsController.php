<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Support\RequirementCheck\RequirementCheck;
use Illuminate\Http\JsonResponse;

/**
 * Oznaczenie „! Sprzeczności N” na liście pozycji przetargu: to samo porównanie regułami co okno
 * „Weryfikacja karty”, policzone dla wybranego produktu każdej pozycji. Bez modelu AI i bez cache —
 * dwa zapytania (pozycje, karty) niezależnie od liczby pozycji.
 */
class TenderConflictsController extends Controller
{
    /** Ten sam próg co walidacja `query` w ProductRequirementCheckController. */
    private const MIN_REQUIREMENT_LENGTH = 3;

    public function __construct(
        private readonly RequirementCheck $check,
    ) {}

    public function __invoke(Tender $tender): JsonResponse
    {
        $items = TenderItem::query()
            ->select(['id', 'tender_id', 'main_product_id', 'requirement', 'custom_name', 'match_source'])
            // kolumny, z których CardSources::fromProduct buduje fragmenty karty
            ->with('mainProduct:id,name,norms,description,enrichment_payload,updated_at')
            ->where('tender_id', $tender->id)
            ->whereNotNull('main_product_id')
            ->orderBy('line_no')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($items as $item) {
            // Oferta wpisana ręcznie lub podpowiedź spoza katalogu — oferowany jest wtedy produkt
            // z `custom_name`, nie karta z katalogu, więc porównanie z kartą byłoby o czymś innym.
            if ($item->hasCustomOffer() || $item->mainProduct === null) {
                continue;
            }
            $requirement = trim((string) $item->requirement);
            if (mb_strlen($requirement) < self::MIN_REQUIREMENT_LENGTH) {
                continue;
            }

            $conflicts = $this->check->compare($requirement, $item->mainProduct)['conflicts'];
            $out[(string) $item->id] = [
                'product_id' => (int) $item->mainProduct->id,
                'count' => (int) $conflicts['count'],
                'requirement' => array_values($conflicts['requirement']),
                'card_fields' => array_values(array_column($conflicts['card_fields'], 'key')),
            ];
        }

        // pusta mapa ma być obiektem JSON `{}`, nie tablicą `[]`
        return response()->json(['items' => $out === [] ? (object) [] : $out]);
    }
}
