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
        $seeAll = $user->can('tenders.view_all');

        $scoped = static function ($query) use ($user, $seeAll) {
            return $seeAll ? $query : $query->accessibleBy($user);
        };

        $myTenders = $scoped(Tender::query())
            ->whereNotIn('status', ['archiwum'])
            ->count();

        $offerValue = (float) $scoped(Tender::query())
            ->whereNotIn('status', ['archiwum'])
            ->sum('offer_value_net');

        $avgMargin = (float) $scoped(Tender::query())
            ->whereNotNull('margin_percent')
            ->avg('margin_percent');

        $approvalStatuses = [];
        if ($user->can('tenders.transition.akceptacja_dyrektor')) {
            $approvalStatuses[] = 'akceptacja_km';
        }
        if ($user->can('tenders.transition.zatwierdzona')) {
            $approvalStatuses[] = 'akceptacja_dyrektor';
        }

        $pendingApproval = $approvalStatuses === []
            ? 0
            : $scoped(Tender::query())
                ->whereIn('status', $approvalStatuses)
                ->count();

        $deadlineSoon = $scoped(Tender::query())
            ->whereNotNull('deadline')
            ->whereDate('deadline', '<=', now()->addDays(7))
            ->whereDate('deadline', '>=', now()->toDateString())
            ->whereNotIn('status', ['archiwum', 'exported', 'odrzucony'])
            ->count();

        return response()->json([
            'my_tenders' => $myTenders,
            'offer_value_net' => round($offerValue, 2),
            'avg_margin_percent' => round($avgMargin, 1),
            'products_count' => Product::query()->count(),
            'substitutes_pending' => ProductSubstitute::query()
                ->where('approval_status', 'oczekuje')
                ->count(),
            'pending_my_approval' => $pendingApproval,
            'deadline_soon' => $deadlineSoon,
            'recent_tenders' => $scoped(Tender::query())
                ->with(['client:id,name', 'owner:id,name'])
                ->latest('last_activity_at')
                ->limit(5)
                ->get(),
        ]);
    }
}
