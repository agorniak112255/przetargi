<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tender;
use App\Services\TenderAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTenderAccess
{
    public function __construct(
        private readonly TenderAccessService $access,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tender = $request->route('tender');
        $user = $request->user();

        if ($tender instanceof Tender && $user !== null && ! $this->access->canView($user, $tender)) {
            return response()->json(['message' => 'Brak dostępu do tego przetargu.'], 403);
        }

        return $next($request);
    }
}
