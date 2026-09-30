<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Adresy wypisane z mailingu — pomijane we wszystkich kampaniach. Listę widzi każdy z campaigns.use (żeby wiedział,
 * czemu adres odpada), dopisuje i zdejmuje tylko campaigns.manage.
 */
class EmailSuppressionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = EmailSuppression::query()->with('campaign:id,code')->orderByDesc('created_at')->orderByDesc('id');
        $search = trim((string) ($v['search'] ?? ''));
        if ($search !== '') {
            $query->where('email', 'like', '%'.addcslashes(mb_strtolower($search), '%_\\').'%');
        }
        $page = $query->paginate((int) ($v['per_page'] ?? 50));

        return response()->json([
            'data' => $page->getCollection()->map(fn (EmailSuppression $s): array => $this->present($s))->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeManage($user);
        // adresy trzymamy małymi literami — tak porównuje je wysyłka
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $v = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('email_suppressions', 'email')],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'email.unique' => 'Ten adres już jest na liście wypisanych.',
        ]);

        $suppression = EmailSuppression::query()->create([
            'email' => $v['email'],
            'reason' => EmailSuppression::REASON_MANUAL,
            'note' => $v['note'] ?? null,
            'created_by' => $user->id,
        ]);

        return response()->json($this->present($suppression->load('campaign:id,code')), 201);
    }

    public function destroy(Request $request, EmailSuppression $suppression): JsonResponse
    {
        $this->authorizeManage($request->user());
        $suppression->delete();

        return response()->json(['message' => 'Adres usunięty z listy wypisanych.']);
    }

    private function authorizeManage(User $user): void
    {
        if (! $user->can('campaigns.manage')) {
            abort(403, 'Listę wypisanych prowadzi administrator.');
        }
    }

    /** @return array<string, mixed> */
    private function present(EmailSuppression $s): array
    {
        return [
            'id' => $s->id,
            'email' => $s->email,
            'reason' => $s->reason,
            'note' => $s->note,
            'campaign' => $s->campaign === null ? null : ['id' => $s->campaign->id, 'code' => $s->campaign->code],
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
