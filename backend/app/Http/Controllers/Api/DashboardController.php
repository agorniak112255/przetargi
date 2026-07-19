<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSubstitute;
use App\Models\Tender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $scopeOwn = ! $user->can('tenders.view_all');

        $myTenders = Tender::query()
            ->when($scopeOwn, fn ($q) => $q->where('owner_id', $user->id))
            ->whereNotIn('status', ['archived'])
            ->count();

        $offerValue = (float) Tender::query()
            ->when($scopeOwn, fn ($q) => $q->where('owner_id', $user->id))
            ->whereNotIn('status', ['archived'])
            ->sum('offer_value_net');

        $avgMargin = (float) Tender::query()
            ->when($scopeOwn, fn ($q) => $q->where('owner_id', $user->id))
            ->whereNotNull('margin_percent')
            ->avg('margin_percent');

        return response()->json([
            'my_tenders' => $myTenders,
            'offer_value_net' => round($offerValue, 2),
            'avg_margin_percent' => round($avgMargin, 1),
            'products_count' => Product::query()->count(),
            'substitutes_pending' => ProductSubstitute::query()
                ->where('approval_status', 'oczekuje')
                ->count(),
            'recent_tenders' => Tender::query()
                ->when($scopeOwn, fn ($q) => $q->where('owner_id', $user->id))
                ->with(['client:id,name', 'owner:id,name'])
                ->latest('last_activity_at')
                ->limit(5)
                ->get(),
        ]);
    }
}
