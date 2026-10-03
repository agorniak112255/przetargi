<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemAlert;
use App\Services\System\SystemAlertService;
use App\Services\System\SystemGaps;
use App\Services\System\SystemStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administracja › Stan systemu (GET /admin/system-status, …/gaps/{kind}, POST /admin/system-alerts/{alert}/mute|unmute).
 * Trasy: admin.access + admin.system.view (routes/api.php).
 */
class SystemStatusController extends Controller
{
    /** Rodzaje „Danych do uzupełnienia” (SystemGapKind we frontendzie). */
    public const GAP_KINDS = SystemGaps::KINDS;

    public function __construct(
        private readonly SystemStatusService $status,
        private readonly SystemGaps $gaps,
        private readonly SystemAlertService $alerts,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->status->build());
    }

    public function gaps(Request $request, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, self::GAP_KINDS, true), 404);

        return response()->json(['data' => $this->gaps->rows($kind)]);
    }

    public function mute(Request $request, SystemAlert $alert): JsonResponse
    {
        return response()->json($this->status->presentAlert($this->alerts->mute($alert, $request->user())));
    }

    public function unmute(Request $request, SystemAlert $alert): JsonResponse
    {
        return response()->json($this->status->presentAlert($this->alerts->unmute($alert)));
    }
}
