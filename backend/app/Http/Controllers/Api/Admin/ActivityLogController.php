<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);

        $query = ActivityLog::query()
            ->with(['user:id,name,email'])
            ->orderByDesc('id');

        if (! empty($validated['user_id'])) {
            $query->where('user_id', (int) $validated['user_id']);
        }
        if (! empty($validated['action'])) {
            $query->where('action', $validated['action']);
        }
        if (! empty($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }
        if (! empty($validated['q'])) {
            $q = $validated['q'];
            $query->where(function ($builder) use ($q): void {
                $builder
                    ->where('action', 'like', '%'.$q.'%')
                    ->orWhere('ip_address', 'like', '%'.$q.'%')
                    ->orWhere('meta->label', 'like', '%'.$q.'%')
                    ->orWhere('meta->user_name', 'like', '%'.$q.'%')
                    ->orWhere('meta->user_email', 'like', '%'.$q.'%')
                    ->orWhereHas('user', function ($userQuery) use ($q): void {
                        $userQuery
                            ->where('name', 'like', '%'.$q.'%')
                            ->orWhere('email', 'like', '%'.$q.'%');
                    });
            });
        }

        $paginator = $query->paginate($perPage);

        $actions = ActivityLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->values();

        return response()->json([
            'data' => collect($paginator->items())->map(static function (ActivityLog $log): array {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'label' => is_array($log->meta) ? ($log->meta['label'] ?? null) : null,
                    'user' => $log->user !== null
                        ? [
                            'id' => $log->user->id,
                            'name' => $log->user->name,
                            'email' => $log->user->email,
                        ]
                        : [
                            'id' => null,
                            'name' => is_array($log->meta) ? ($log->meta['user_name'] ?? 'Usunięty użytkownik') : 'System',
                            'email' => is_array($log->meta) ? ($log->meta['user_email'] ?? null) : null,
                        ],
                    'subject_type' => $log->subject_type,
                    'subject_id' => $log->subject_id,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'meta' => $log->meta,
                    'created_at' => $log->created_at?->toIso8601String(),
                ];
            })->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'retention_days' => ActivityLog::RETENTION_DAYS,
                'actions' => $actions,
            ],
        ]);
    }
}
