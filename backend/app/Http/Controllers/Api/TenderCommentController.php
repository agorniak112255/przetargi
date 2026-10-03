<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Models\TenderComment;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Notifications\AppNotificationMessage;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\TenderActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

use function Illuminate\Support\defer;

class TenderCommentController extends Controller
{
    /** Najwięcej osób wspomnianych w jednym komentarzu. */
    private const MAX_MENTIONS = 20;

    public function __construct(
        private readonly TenderActivityLogger $activities,
        private readonly NotificationDispatcher $notifications,
    ) {}

    public function index(Tender $tender): JsonResponse
    {
        $comments = TenderComment::query()
            ->where('tender_id', $tender->id)
            ->with(['user:id,name,role', 'item:id,line_no'])
            ->latest('id')
            ->get();

        return response()->json(['data' => $this->withMentionedUsers($comments)]);
    }

    public function store(Request $request, Tender $tender): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:4000'],
            'tender_item_id' => ['nullable', 'integer', 'exists:tender_items,id'],
            'mentioned_user_ids' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_MENTIONS],
            'mentioned_user_ids.*' => ['integer', 'distinct'],
        ]);

        $author = $request->user();
        assert($author instanceof User);

        $item = null;
        if (! empty($data['tender_item_id'])) {
            $item = TenderItem::query()
                ->where('tender_id', $tender->id)
                ->where('id', $data['tender_item_id'])
                ->firstOrFail();
        }

        $mentioned = $this->validatedMentions($tender, $author, $data['mentioned_user_ids'] ?? []);

        $comment = TenderComment::query()->create([
            'tender_id' => $tender->id,
            'tender_item_id' => $item?->id,
            'user_id' => $author->id,
            'body' => $data['body'],
            'mentioned_user_ids' => $mentioned->isEmpty() ? null : $mentioned->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
        ]);

        $this->activities->log($tender, 'comment_added', $author, $item, [
            'comment_id' => $comment->id,
        ]);

        $tender->last_activity_at = now();
        $tender->save();

        if ($mentioned->isNotEmpty()) {
            $message = $this->mentionMessage($tender, $comment, $author, $item);
            $users = $mentioned->all();
            // e-mail nie może opóźniać zapisu komentarza — wysyłka po odpowiedzi
            defer(fn () => $this->notifications->send($users, $message));
        }

        $comment->load(['user:id,name,role', 'item:id,line_no']);

        return response()->json($this->withMentionedUsers(collect([$comment]))[0], 201);
    }

    /**
     * Osoby do wzmianki „@” w komentarzu: opiekun, zaproszeni i osoby z tenders.view_all, bez pytającego.
     */
    public function mentionCandidates(Request $request, Tender $tender): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $rows = $this->candidates($tender, $user)->map(static fn (array $row): array => [
            'id' => (int) $row['user']->id,
            'name' => (string) $row['user']->name,
            'role' => $row['role'],
        ])->values();

        return response()->json(['data' => $rows]);
    }

    public function destroy(Request $request, Tender $tender, TenderComment $comment): JsonResponse
    {
        if ($comment->tender_id !== $tender->id) {
            abort(404);
        }

        $user = $request->user();
        if ((int) $comment->user_id !== (int) $user->id && ! $user->can('admin.access')) {
            abort(403);
        }

        $comment->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Kandydaci do wzmianki po kolei: opiekun, zaproszeni, pozostali z dostępem do wszystkich przetargów
     * (alfabetycznie). Każda osoba raz, z rolą najbliższą przetargowi.
     *
     * @return Collection<int, array{user: User, role: string}>
     */
    private function candidates(Tender $tender, User $asking): Collection
    {
        /** @var array<int, array{user: User, role: string}> $out */
        $out = [];
        $owner = $tender->owner()->first(['id', 'name']);
        if ($owner instanceof User) {
            $out[(int) $owner->id] = ['user' => $owner, 'role' => 'Opiekun przetargu'];
        }

        $invited = User::query()
            ->whereIn('id', $tender->invitations()->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
        foreach ($invited as $user) {
            $out[(int) $user->id] ??= ['user' => $user, 'role' => 'Zaproszony do przetargu'];
        }

        try {
            $all = User::permission('tenders.view_all')->orderBy('name')->get(['users.id', 'users.name']);
        } catch (PermissionDoesNotExist) {
            $all = collect();
        }
        foreach ($all as $user) {
            $out[(int) $user->id] ??= ['user' => $user, 'role' => 'Widzi wszystkie przetargi'];
        }

        unset($out[(int) $asking->id]);

        return collect(array_values($out));
    }

    /**
     * Wspomniane osoby muszą być na liście kandydatów (dostęp do przetargu), bez autora komentarza.
     *
     * @param  array<int, mixed>|null  $ids
     * @return Collection<int, User>
     */
    private function validatedMentions(Tender $tender, User $author, ?array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids ?? [])));
        if ($ids === []) {
            return collect();
        }
        if (in_array((int) $author->id, $ids, true)) {
            throw ValidationException::withMessages([
                'mentioned_user_ids' => 'Nie możesz wspomnieć o sobie.',
            ]);
        }

        $candidates = $this->candidates($tender, $author)->keyBy(static fn (array $row): int => (int) $row['user']->id);
        $missing = array_values(array_filter($ids, static fn (int $id): bool => ! $candidates->has($id)));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'mentioned_user_ids' => 'Można wspomnieć tylko o osobach z dostępem do tego przetargu.',
            ]);
        }

        // pełne konta (e-mail i uprawnienia do powiadomień), w kolejności z komentarza
        $users = User::query()->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(static fn (int $id): ?User => $users->get($id))->filter()->values();
    }

    private function mentionMessage(Tender $tender, TenderComment $comment, User $author, ?TenderItem $item): AppNotificationMessage
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $comment->body) ?? '');
        $excerpt = mb_strlen($text) > 160 ? mb_substr($text, 0, 157).'…' : $text;
        $where = $tender->number.' · '.$tender->title.($item !== null ? ', pozycja '.$item->line_no : '');

        return new AppNotificationMessage(
            event: 'tender_mention',
            subjectKey: 'comment:'.$comment->id,
            title: $author->name.' wspomina o Tobie w komentarzu',
            body: $where.': „'.$excerpt.'”',
            url: '/tenders/'.$tender->id.'?tab=komentarze',
            data: [
                'tender_id' => $tender->id,
                'tender_number' => $tender->number,
                'tender_title' => $tender->title,
                'comment_id' => $comment->id,
                'author_id' => $author->id,
                'author_name' => $author->name,
            ],
        );
    }

    /**
     * Komentarze z listą wspomnianych osób ({id, name}) — jedno zapytanie o użytkowników dla całej listy.
     *
     * @param  Collection<int, TenderComment>  $comments
     * @return list<array<string, mixed>>
     */
    private function withMentionedUsers(Collection $comments): array
    {
        $ids = $comments
            ->flatMap(static fn (TenderComment $c): array => is_array($c->mentioned_user_ids) ? $c->mentioned_user_ids : [])
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $names = $ids->isEmpty() ? collect() : User::query()->whereIn('id', $ids)->pluck('name', 'id');

        return $comments->map(static function (TenderComment $comment) use ($names): array {
            $mentioned = [];
            foreach (is_array($comment->mentioned_user_ids) ? $comment->mentioned_user_ids : [] as $id) {
                if ($names->has((int) $id)) {
                    $mentioned[] = ['id' => (int) $id, 'name' => (string) $names->get((int) $id)];
                }
            }

            return [...$comment->toArray(), 'mentioned_users' => $mentioned];
        })->values()->all();
    }
}
