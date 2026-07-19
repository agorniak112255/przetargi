<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $scopeOwn = ! $request->user()->can('tenders.view_all');

        $byStatus = Tender::query()
            ->when($scopeOwn, fn ($q) => $q->where('owner_id', $request->user()->id))
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(offer_value_net),0) as offer_value_net'), DB::raw('AVG(margin_percent) as avg_margin'))
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->map(static fn ($row) => [
                'status' => $row->status,
                'count' => (int) $row->count,
                'offer_value_net' => round((float) $row->offer_value_net, 2),
                'avg_margin' => $row->avg_margin !== null ? round((float) $row->avg_margin, 1) : null,
            ]);

        $byOwner = Tender::query()
            ->when($scopeOwn, fn ($q) => $q->where('owner_id', $request->user()->id))
            ->join('users', 'users.id', '=', 'tenders.owner_id')
            ->select(
                'tenders.owner_id',
                'users.name as owner_name',
                DB::raw('COUNT(*) as count'),
                DB::raw('COALESCE(SUM(tenders.offer_value_net),0) as offer_value_net'),
                DB::raw('AVG(tenders.margin_percent) as avg_margin')
            )
            ->groupBy('tenders.owner_id', 'users.name')
            ->orderByDesc('offer_value_net')
            ->get()
            ->map(static fn ($row) => [
                'owner_id' => (int) $row->owner_id,
                'owner_name' => $row->owner_name,
                'count' => (int) $row->count,
                'offer_value_net' => round((float) $row->offer_value_net, 2),
                'avg_margin' => $row->avg_margin !== null ? round((float) $row->avg_margin, 1) : null,
            ]);

        return response()->json([
            'by_status' => $byStatus,
            'by_owner' => $byOwner,
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $scopeOwn = ! $request->user()->can('tenders.view_all');

        $rows = Tender::query()
            ->when($scopeOwn, fn ($q) => $q->where('owner_id', $request->user()->id))
            ->with(['client:id,name', 'owner:id,name'])
            ->orderByDesc('last_activity_at')
            ->get();

        return response()->streamDownload(static function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['number', 'title', 'client', 'owner', 'status', 'offer_value_net', 'margin_percent', 'deadline', 'ai_percent'], ';');
            foreach ($rows as $t) {
                fputcsv($out, [
                    $t->number,
                    $t->title,
                    $t->client?->name,
                    $t->owner?->name,
                    $t->status,
                    $t->offer_value_net,
                    $t->margin_percent,
                    $t->deadline?->format('Y-m-d'),
                    $t->ai_percent,
                ], ';');
            }
            fclose($out);
        }, 'raport-przetargi.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
