<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CatalogSlangDictionary;
use App\Support\PpeAssortment;
use App\Support\ProductFeatureMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Znaczniki wymagania do okna „Weryfikacja karty”: nagłówek wyrobu, rzeczownik rodzaju
 * i normy EN. Same reguły i regexy — bez modelu AI, więc wynik jest powtarzalny.
 */
class ProductRequirementTermsController extends Controller
{
    public function __construct(
        private readonly PpeAssortment $assortment,
        private readonly ProductFeatureMatch $features,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'min:3', 'max:5000'],
        ]);

        $query = (string) $data['query'];
        $head = CatalogSlangDictionary::requirementHead($query);
        // Rodzinę czytamy z pełnego tekstu (normy i dalsze zdania też ją wskazują),
        // a brzmienie rzeczownika — tylko z nagłówka.
        $family = $this->assortment->family($query);

        return response()->json([
            'head' => $head,
            'noun' => $family === null ? null : $this->assortment->familyNoun($head, $family),
            'norms' => $this->norms($query),
        ]);
    }

    /**
     * Frontend sumuje trafienia podciągów, więc jedno wystąpienie normy w opisie może
     * pasować do najwyżej jednej igły: „EN ISO 20345” trafia tylko w „ISO 20345”, a „EN 20345”
     * tylko w „EN 20345”. Dlatego żadna igła nie zawiera innej — „EN ISO n” jako osobna igła
     * liczyłaby to samo wystąpienie drugi raz razem z „ISO n”.
     *
     * @return list<array{label: string, needles: list<string>}>
     */
    private function norms(string $query): array
    {
        $normalized = $this->features->normalize($query);

        return array_map(fn (string $number): array => [
            'label' => $this->hasIso($normalized, $number) ? 'EN ISO '.$number : 'EN '.$number,
            'needles' => ['EN '.$number, 'EN'.$number, 'ISO '.$number, 'ISO'.$number],
        ], $this->features->norms($query));
    }

    /** Ten sam zapis co w ProductFeatureMatch::norms(), ale z wymaganym „ISO” przed numerem. */
    private function hasIso(string $normalized, string $number): bool
    {
        return preg_match('/\ben\s*iso\s*'.preg_quote($number, '/').'(?!\d)/u', $normalized) === 1;
    }
}
