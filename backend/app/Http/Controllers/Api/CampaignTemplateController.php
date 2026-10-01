<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CampaignTemplate;
use App\Models\User;
use App\Services\Campaigns\CampaignBlocks;
use App\Services\Campaigns\CampaignRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Szablony maili kampanii: własne użytkownika i wspólne (prowadzi campaigns.manage) — jak grupy odbiorców. Cudzy
 * prywatny szablon = 404. Kampania dostaje kopię bloków szablonu (CampaignController::applyTemplate).
 */
class CampaignTemplateController extends Controller
{
    private const SHARED_ONLY_ADMIN = 'Wspólne szablony prowadzi administrator.';

    public function __construct(private readonly CampaignRenderer $renderer) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $templates = $this->visible($user)
            ->with('owner:id,name')
            ->orderByDesc('is_shared')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $templates->map(fn (CampaignTemplate $t): array => $this->present($t, $user))->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'blocks' => ['required', 'array'],
            'brand_color' => ['sometimes', 'nullable', 'string', Rule::in(CampaignBlocks::BRAND_COLORS)],
            'is_shared' => ['sometimes', 'boolean'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $shared = (bool) ($v['is_shared'] ?? false);
        if ($shared && ! $user->can('campaigns.manage')) {
            abort(403, self::SHARED_ONLY_ADMIN);
        }
        $blocks = CampaignBlocks::validate($request->input('blocks'), false);

        $template = CampaignTemplate::query()->create([
            'user_id' => $user->id,
            'name' => trim((string) $v['name']),
            'is_shared' => $shared,
            'brand_color' => $v['brand_color'] ?? null,
            'blocks' => $blocks,
        ]);

        return response()->json($this->present($template->fresh(['owner:id,name']), $user), 201);
    }

    public function update(Request $request, CampaignTemplate $template): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeEdit($user, $template);
        $v = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'blocks' => ['sometimes', 'array'],
            'brand_color' => ['sometimes', 'nullable', 'string', Rule::in(CampaignBlocks::BRAND_COLORS)],
            'is_shared' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('is_shared', $v) && (bool) $v['is_shared'] !== (bool) $template->is_shared && ! $user->can('campaigns.manage')) {
            abort(403, self::SHARED_ONLY_ADMIN);
        }

        $data = array_intersect_key($v, array_flip(['name', 'brand_color', 'is_shared']));
        if (array_key_exists('name', $data)) {
            $data['name'] = trim((string) $data['name']);
        }
        if ($request->has('blocks')) {
            $data['blocks'] = CampaignBlocks::validate($request->input('blocks'), false);
        }
        if ($data !== []) {
            $template->update($data);
        }

        return response()->json($this->present($template->fresh(['owner:id,name']), $user));
    }

    /** Kampanie z tego szablonu zachowują swoje bloki; ich template_id zeruje klucz obcy. */
    public function destroy(Request $request, CampaignTemplate $template): Response
    {
        $this->authorizeEdit($request->user(), $template);
        $template->delete();

        return response()->noContent();
    }

    /** Podgląd niezapisanych bloków z trzema przykładowymi produktami (bez kampanii i odbiorcy). */
    public function preview(Request $request): JsonResponse
    {
        $v = $request->validate([
            'blocks' => ['present', 'array'],
            'brand_color' => ['sometimes', 'nullable', 'string', Rule::in(CampaignBlocks::BRAND_COLORS)],
        ]);
        $blocks = CampaignBlocks::validate($request->input('blocks'), false);
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->renderer->renderSample($blocks, $v['brand_color'] ?? null, $user));
    }

    /** @return Builder<CampaignTemplate> szablony własne i wspólne */
    private function visible(User $user): Builder
    {
        return CampaignTemplate::query()->where(fn (Builder $q) => $q->where('user_id', $user->id)->orWhere('is_shared', true));
    }

    private function canEdit(User $user, CampaignTemplate $template): bool
    {
        return $template->is_shared ? $user->can('campaigns.manage') : (int) $template->user_id === (int) $user->id;
    }

    private function authorizeEdit(User $user, CampaignTemplate $template): void
    {
        if (! $template->is_shared && (int) $template->user_id !== (int) $user->id) {
            abort(404);
        }
        if (! $this->canEdit($user, $template)) {
            abort(403, self::SHARED_ONLY_ADMIN);
        }
    }

    /** @return array<string, mixed> TemplateRow */
    private function present(CampaignTemplate $template, User $user): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'is_shared' => (bool) $template->is_shared,
            'owner' => $template->owner !== null ? ['id' => (int) $template->owner->id, 'name' => (string) $template->owner->name] : null,
            'can_edit' => $this->canEdit($user, $template),
            'brand_color' => $template->brand_color,
            'blocks' => is_array($template->blocks) ? CampaignBlocks::upgrade($template->blocks) : CampaignBlocks::standard(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }
}
