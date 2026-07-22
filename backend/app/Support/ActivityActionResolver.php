<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Client;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductSubstitute;
use App\Models\Role;
use App\Models\Tender;
use App\Models\TenderComment;
use App\Models\TenderCondition;
use App\Models\TenderDocument;
use App\Models\TenderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class ActivityActionResolver
{
    /**
     * @return array{action: string, label: string, subject: ?Model, meta: array<string, mixed>}
     */
    public function resolve(Request $request): array
    {
        $method = strtoupper($request->method());
        $path = $this->normalizedPath($request);
        $route = $request->route();
        $params = is_array($route?->parameters()) ? $route->parameters() : [];

        $subject = $this->resolveSubject($params);
        [$action, $label] = $this->mapAction($method, $path, $params);

        return [
            'action' => $action,
            'label' => $label,
            'subject' => $subject,
            'meta' => [
                'label' => $label,
                'method' => $method,
                'path' => $path,
                'route_params' => $this->scalarParams($params),
            ],
        ];
    }

    private function normalizedPath(Request $request): string
    {
        $path = trim($request->path(), '/');
        if (str_starts_with($path, 'api/')) {
            $path = substr($path, 4);
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{0: string, 1: string}
     */
    private function mapAction(string $method, string $path, array $params): array
    {
        $rules = [
            ['POST', '#^logout$#', 'logout', 'Wylogowanie'],
            ['POST', '#^tenders$#', 'tender.created', 'Utworzono przetarg'],
            ['PATCH', '#^tenders/\d+$#', 'tender.updated', 'Zaktualizowano przetarg'],
            ['POST', '#^tenders/\d+/transition$#', 'tender.status_changed', 'Zmiana statusu przetargu'],
            ['POST', '#^tenders/\d+/comments$#', 'tender.comment_added', 'Dodano komentarz'],
            ['DELETE', '#^tenders/\d+/comments/\d+$#', 'tender.comment_deleted', 'Usunięto komentarz'],
            ['POST', '#^tenders/\d+/import$#', 'tender.imported', 'Import pozycji przetargu'],
            ['POST', '#^tenders/\d+/documents/analyze$#', 'tender.document_analyzed', 'Analiza dokumentu SIWZ'],
            ['POST', '#^tenders/\d+/documents/commit$#', 'tender.document_committed', 'Zatwierdzenie dokumentu SIWZ'],
            ['POST', '#^tenders/\d+/documents/\d+/reanalyze$#', 'tender.document_reanalyzed', 'Ponowna analiza dokumentu'],
            ['DELETE', '#^tenders/\d+/documents/\d+$#', 'tender.document_deleted', 'Usunięto dokument'],
            ['POST', '#^tenders/\d+/conditions$#', 'tender.condition_created', 'Dodano warunek przetargu'],
            ['PATCH', '#^tenders/\d+/conditions/\d+$#', 'tender.condition_updated', 'Zaktualizowano warunek'],
            ['DELETE', '#^tenders/\d+/conditions/\d+$#', 'tender.condition_deleted', 'Usunięto warunek'],
            ['POST', '#^tenders/\d+/match$#', 'tender.match_run', 'Uruchomiono dopasowanie produktów'],
            ['POST', '#^tenders/\d+/items/\d+/match$#', 'tender.item_matched', 'Dopasowano pozycję'],
            ['PATCH', '#^tenders/\d+/items/\d+$#', 'tender.item_updated', 'Zaktualizowano pozycję'],
            ['POST', '#^tenders/\d+/items/bulk$#', 'tender.items_bulk_updated', 'Masowa aktualizacja pozycji'],
            ['POST', '#^tenders/\d+/items/apply-cheaper-substitutes$#', 'tender.cheaper_substitutes_applied', 'Zastosowano tańsze zamienniki'],
            ['GET', '#^tenders/\d+/export/(excel|pdf|docx)$#', 'tender.exported', 'Eksport oferty'],
            ['POST', '#^clients$#', 'client.created', 'Utworzono klienta'],
            ['PATCH', '#^clients/\d+$#', 'client.updated', 'Zaktualizowano klienta'],
            ['POST', '#^substitutes$#', 'substitute.created', 'Utworzono zamiennik'],
            ['PATCH', '#^substitutes/\d+$#', 'substitute.updated', 'Zaktualizowano zamiennik'],
            ['DELETE', '#^substitutes/\d+$#', 'substitute.deleted', 'Usunięto zamiennik'],
            ['PATCH', '#^substitutes/\d+/approve$#', 'substitute.approved', 'Decyzja o zamienniku'],
            ['PATCH', '#^price-lists/\d+$#', 'price_list.updated', 'Zaktualizowano cennik'],
            ['DELETE', '#^price-lists/\d+$#', 'price_list.deleted', 'Usunięto cennik'],
            ['POST', '#^price-lists/analyze$#', 'price_list.analyzed', 'Analiza pliku cennika'],
            ['POST', '#^price-lists/import$#', 'price_list.imported', 'Import cennika'],
            ['POST', '#^price-lists/\d+/enrich$#', 'price_list.enriched', 'Wzbogacanie produktów z cennika'],
            ['POST', '#^products/enrich$#', 'product.enriched', 'Wzbogacanie produktów'],
            ['POST', '#^products/\d+/enrich$#', 'product.enriched', 'Wzbogacanie produktu'],
            ['POST', '#^products/catalog-health/queue$#', 'product.catalog_health_queued', 'Kolejka health katalogu'],
            ['POST', '#^products/catalog-health/backfill-attributes$#', 'product.attributes_backfilled', 'Uzupełnianie atrybutów BHP'],
            ['POST', '#^product-enrichment-batches/\d+/cancel$#', 'product.enrichment_cancelled', 'Anulowano wzbogacanie'],
            ['POST', '#^products/ai-search$#', 'product.ai_search', 'Wyszukiwanie AI produktów'],
            ['PUT', '#^ai-settings$#', 'ai_settings.updated', 'Zmiana ustawień AI'],
            ['POST', '#^ai-settings/test$#', 'ai_settings.tested', 'Test połączenia AI'],
            ['POST', '#^ai-settings/test-vector$#', 'ai_settings.vector_tested', 'Test wyszukiwania wektorowego'],
            ['POST', '#^admin/users$#', 'user.created', 'Utworzono użytkownika'],
            ['PATCH', '#^admin/users/\d+$#', 'user.updated', 'Zaktualizowano użytkownika'],
            ['DELETE', '#^admin/users/\d+$#', 'user.deleted', 'Usunięto użytkownika'],
            ['POST', '#^admin/roles$#', 'role.created', 'Utworzono rolę'],
            ['PUT', '#^admin/roles/[^/]+$#', 'role.updated', 'Zaktualizowano rolę'],
            ['DELETE', '#^admin/roles/[^/]+$#', 'role.deleted', 'Usunięto rolę'],
        ];

        foreach ($rules as [$ruleMethod, $pattern, $action, $label]) {
            if ($ruleMethod === $method && preg_match($pattern, $path) === 1) {
                if ($action === 'tender.exported' && isset($params['tender'])) {
                    $format = basename($path);

                    return [$action, 'Eksport oferty ('.$format.')'];
                }

                return [$action, $label];
            }
        }

        $resource = explode('/', $path)[0] ?: 'system';

        return [
            strtolower($method).'.'.str_replace('/', '.', $path),
            sprintf('%s %s', $method, $resource),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function resolveSubject(array $params): ?Model
    {
        $priority = [
            'tender' => Tender::class,
            'item' => TenderItem::class,
            'comment' => TenderComment::class,
            'condition' => TenderCondition::class,
            'document' => TenderDocument::class,
            'client' => Client::class,
            'productSubstitute' => ProductSubstitute::class,
            'priceList' => PriceList::class,
            'product' => Product::class,
            'user' => User::class,
            'role' => Role::class,
        ];

        foreach ($priority as $key => $class) {
            $value = $params[$key] ?? null;
            if ($value instanceof Model) {
                return $value;
            }
            if (is_numeric($value)) {
                /** @var class-string<Model> $class */
                $model = $class::query()->find((int) $value);
                if ($model !== null) {
                    return $model;
                }
            }
            if (is_string($value) && $key === 'role') {
                $model = Role::query()->where('name', $value)->first();
                if ($model !== null) {
                    return $model;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, int|string>
     */
    private function scalarParams(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            if ($value instanceof Model) {
                $out[(string) $key] = $value->getKey();
                continue;
            }
            if (is_scalar($value)) {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }
}
