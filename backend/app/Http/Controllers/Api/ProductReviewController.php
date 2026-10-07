<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductSourceDocument;
use App\Models\User;
use App\Services\Enrichment\SourceDocumentStore;
use App\Services\ProductReviewConflict;
use App\Services\ProductReviewService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Przegląd opisów kart cenników z plików (etap 1, 08.10.2026): lista „Do przeglądu”, historia wersji opisu karty,
 * decyzje (zatwierdź / odrzuć / podaj adres), przywrócenie wersji i tekst zapisanej strony źródła.
 * Reguły decyzji: App\Services\ProductReviewService.
 */
class ProductReviewController extends Controller
{
    public function __construct(private readonly ProductReviewService $reviews) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'price_list_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', Rule::in(Product::REVIEW_REASONS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.ProductReviewService::MAX_PER_PAGE],
        ]);

        return response()->json($this->reviews->list(
            [
                'price_list_id' => isset($data['price_list_id']) ? (int) $data['price_list_id'] : null,
                'reason' => $data['reason'] ?? null,
            ],
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 50),
        ));
    }

    public function versions(Product $product): JsonResponse
    {
        return response()->json($this->reviews->history($product));
    }

    public function review(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(['approve', 'reject', 'url'])],
            // odrzucenie zawsze wskazuje wersję: bez niej drugie kliknięcie odrzuciłoby kolejny opis karty
            'version_id' => ['nullable', 'integer', 'min:1', 'required_if:action,reject'],
            'url' => ['nullable', 'string', 'max:2000', 'required_if:action,url', 'url:http,https'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $user = $this->user($request);
        $versionId = isset($data['version_id']) ? (int) $data['version_id'] : null;
        $note = isset($data['note']) ? (string) $data['note'] : null;

        try {
            $state = match ($data['action']) {
                'approve' => $this->reviews->approve($product, $versionId, $user, $note),
                'reject' => $this->reviews->reject($product, $versionId, $user, $note),
                default => $this->reviews->giveUrl($product, (string) $data['url'], $user),
            };
        } catch (ProductReviewConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (DomainException|RuntimeException $e) {
            // RuntimeException: odmowa kolejki opisów (ProductEnrichmentService::enqueueProduct) — jak przy „Pobierz”
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($state);
    }

    public function restore(Request $request, Product $product, ProductDescriptionVersion $descriptionVersion): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $this->reviews->restore($product, $descriptionVersion, $this->user($request), isset($data['note']) ? (string) $data['note'] : null);
        } catch (ProductReviewConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($result);
    }

    /** Tekst strony źródła zapisany przy pobieraniu opisu (SourceDocumentStore) — do sprawdzenia cytatów dowodów. */
    public function sourceText(Product $product, ProductSourceDocument $document, SourceDocumentStore $store): Response
    {
        abort_if((int) $document->product_id !== (int) $product->id, 404);
        $text = $store->get((string) $document->sha256);
        abort_if($text === null, 404, 'Tekst tej strony nie jest już przechowywany.');

        return response($text, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
