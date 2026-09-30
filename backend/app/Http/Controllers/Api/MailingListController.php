<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\EmailSuppression;
use App\Models\MailingList;
use App\Models\MailingListContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Grupy odbiorców kampanii: własne użytkownika i wspólne (prowadzi campaigns.manage). Kontakt (adres) jest jeden dla
 * wszystkich grup; w grupie ma podstawę wysyłki (stały klient / zgoda). Import z wklejonego tekstu.
 */
class MailingListController extends Controller
{
    /** Najwięcej niepustych linii w jednym imporcie. */
    public const IMPORT_MAX_LINES = 5000;

    /** Tyle błędnych linii wraca w odpowiedzi — reszta i tak do poprawy w źródle. */
    private const INVALID_SAMPLE = 50;

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $lists = $this->visible($user)
            ->with('user:id,name')
            ->withCount([
                'contacts',
                'contacts as customer_count' => fn (Builder $q) => $q->where('mailing_list_contact.basis', MailingListContact::BASIS_CUSTOMER),
                'contacts as consent_count' => fn (Builder $q) => $q->where('mailing_list_contact.basis', MailingListContact::BASIS_CONSENT),
            ])
            ->orderByDesc('is_shared')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $lists->map(fn (MailingList $l): array => $this->present($l, $user))->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'is_shared' => ['sometimes', 'boolean'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $shared = (bool) ($v['is_shared'] ?? false);
        if ($shared && ! $user->can('campaigns.manage')) {
            abort(403, 'Wspólne grupy prowadzi administrator.');
        }

        $list = MailingList::query()->create([
            'user_id' => $user->id,
            'name' => $v['name'],
            'is_shared' => $shared,
        ]);

        return response()->json($this->present($this->reload($list), $user), 201);
    }

    public function update(Request $request, MailingList $list): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeEdit($user, $list);
        $v = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'is_shared' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('is_shared', $v) && (bool) $v['is_shared'] !== (bool) $list->is_shared && ! $user->can('campaigns.manage')) {
            abort(403, 'Wspólne grupy prowadzi administrator.');
        }

        $list->update(array_intersect_key($v, array_flip(['name', 'is_shared'])));

        return response()->json($this->present($this->reload($list), $user));
    }

    public function destroy(Request $request, MailingList $list): JsonResponse
    {
        $this->authorizeEdit($request->user(), $list);
        $list->delete();

        return response()->json(['message' => 'Usunięto grupę.']);
    }

    public function contacts(Request $request, MailingList $list): JsonResponse
    {
        $this->authorizeView($request->user(), $list);
        $v = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = $list->contacts()->orderBy('contacts.email');
        $search = trim((string) ($v['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('contacts.email', 'like', $like)
                ->orWhere('contacts.name', 'like', $like)
                ->orWhere('contacts.company', 'like', $like));
        }
        $page = $query->paginate((int) ($v['per_page'] ?? 50));
        $suppressed = array_flip(EmailSuppression::query()
            ->whereIn('email', $page->getCollection()->pluck('email')->all())
            ->pluck('email')
            ->all());

        return response()->json([
            'data' => $page->getCollection()->map(static fn (Contact $c): array => [
                'id' => $c->id,
                'email' => $c->email,
                'name' => $c->name,
                'company' => $c->company,
                'basis' => $c->pivot->basis,
                'basis_note' => $c->pivot->basis_note,
                'added_at' => $c->pivot->created_at !== null ? Carbon::parse($c->pivot->created_at)->toIso8601String() : null,
                'suppressed' => isset($suppressed[$c->email]),
            ])->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * Import wklejonych linii: „email;imię nazwisko;firma” albo sam e-mail (separator ; , albo tabulator). E-mail może
     * stać w dowolnej kolumnie — bierzemy pierwsze pole wyglądające na adres, pozostałe to kolejno nazwa i firma.
     * Istniejącemu kontaktowi nie nadpisujemy niepustych danych; kontakt już w grupie zostaje z dotychczasową podstawą.
     */
    public function import(Request $request, MailingList $list): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeEdit($user, $list);
        $v = $request->validate([
            'text' => ['required', 'string', 'max:2000000'],
            'basis' => ['required', 'string', Rule::in(MailingListContact::BASES)],
            'basis_note' => ['nullable', 'string', 'max:255'],
        ]);

        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', (string) $v['text']) ?: []),
            static fn (string $l): bool => $l !== '',
        ));
        if (count($lines) > self::IMPORT_MAX_LINES) {
            throw ValidationException::withMessages([
                'text' => ['Najwyżej '.self::IMPORT_MAX_LINES.' linii w jednym imporcie (wklejono '.count($lines).') — podziel listę.'],
            ]);
        }

        [$rows, $invalid, $duplicates] = $this->parse($lines);
        $result = DB::transaction(fn (): array => $this->storeRows($list, $rows, $v['basis'], $v['basis_note'] ?? null, $user));
        $list->touch();

        return response()->json([
            'added' => $result['added'],
            'already' => $result['already'] + $duplicates,
            'invalid' => array_slice($invalid, 0, self::INVALID_SAMPLE),
            'suppressed' => $result['suppressed'],
        ]);
    }

    public function removeContact(Request $request, MailingList $list, Contact $contact): JsonResponse
    {
        $this->authorizeEdit($request->user(), $list);
        if ($list->contacts()->detach($contact->id) === 0) {
            abort(404);
        }
        $list->touch();

        return response()->json(['message' => 'Usunięto adres z grupy.']);
    }

    /**
     * Wynik: adresy (bez powtórzeń) → dane, błędne linie, liczba powtórzeń w tekście.
     *
     * @param  list<string>  $lines
     * @return array{0: array<string, array{name: string|null, company: string|null}>, 1: list<string>, 2: int}
     */
    private function parse(array $lines): array
    {
        $rows = [];
        $invalid = [];
        $duplicates = 0;
        foreach ($lines as $line) {
            $email = null;
            $rest = [];
            foreach (preg_split('/[;,\t]/', $line) ?: [] as $field) {
                $field = trim($field, " \t\"'");
                if ($field === '') {
                    continue;
                }
                $candidate = mb_strtolower(trim($field, '<>'));
                if ($email === null && strlen($candidate) <= 255 && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                    $email = $candidate;
                } else {
                    $rest[] = $field;
                }
            }
            if ($email === null) {
                $invalid[] = mb_substr($line, 0, 200);

                continue;
            }
            if (isset($rows[$email])) {
                $duplicates++;

                continue;
            }
            $rows[$email] = [
                'name' => isset($rest[0]) ? mb_substr($rest[0], 0, 200) : null,
                'company' => isset($rest[1]) ? mb_substr($rest[1], 0, 250) : null,
            ];
        }

        return [$rows, $invalid, $duplicates];
    }

    /**
     * @param  array<string, array{name: string|null, company: string|null}>  $rows
     * @return array{added: int, already: int, suppressed: int}
     */
    private function storeRows(MailingList $list, array $rows, string $basis, ?string $basisNote, User $user): array
    {
        $added = 0;
        $already = 0;
        $suppressed = 0;
        $now = now();
        foreach (array_chunk($rows, 500, true) as $chunk) {
            $emails = array_map('strval', array_keys($chunk));
            $contacts = Contact::query()->whereIn('email', $emails)->get()->keyBy('email');
            foreach ($chunk as $email => $row) {
                $email = (string) $email;
                $contact = $contacts->get($email);
                if ($contact === null) {
                    $contact = $this->createContact($email, $row);
                    $contacts->put($email, $contact);
                }
                if (! $contact->wasRecentlyCreated) {
                    // uzupełniamy tylko puste pola — nie nadpisujemy tego, co już ktoś wpisał
                    $fill = [];
                    if (blank($contact->name) && $row['name'] !== null) {
                        $fill['name'] = $row['name'];
                    }
                    if (blank($contact->company) && $row['company'] !== null) {
                        $fill['company'] = $row['company'];
                    }
                    if ($fill !== []) {
                        $contact->update($fill);
                    }
                }
            }

            $ids = $contacts->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $onList = array_flip(MailingListContact::query()
                ->where('mailing_list_id', $list->id)
                ->whereIn('contact_id', $ids)
                ->pluck('contact_id')
                ->map(fn ($id): int => (int) $id)
                ->all());
            $blocked = array_flip(EmailSuppression::query()->whereIn('email', $emails)->pluck('email')->all());

            $insert = [];
            $inserting = 0;
            foreach ($contacts as $email => $contact) {
                if (isset($onList[(int) $contact->id])) {
                    $already++;

                    continue;
                }
                $inserting++;
                $insert[] = [
                    'mailing_list_id' => $list->id,
                    'contact_id' => $contact->id,
                    'basis' => $basis,
                    'basis_note' => $basisNote,
                    'added_by' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $added++;
                // wypisany trafia do grupy (widać go na liście), ale i tak nie dostanie maila
                if (isset($blocked[(string) $email])) {
                    $suppressed++;
                }
            }
            if ($insert !== []) {
                // równoległy import mógł już dopisać ten adres do grupy — taki wiersz liczy się jako „już był”
                $ignored = $inserting - MailingListContact::query()->insertOrIgnore($insert);
                $added -= $ignored;
                $already += $ignored;
            }
        }

        return ['added' => $added, 'already' => $already, 'suppressed' => $suppressed];
    }

    /**
     * Nowy kontakt; gdy równoległy import właśnie dodał ten adres — tamten kontakt.
     *
     * @param  array{name: string|null, company: string|null}  $row
     */
    private function createContact(string $email, array $row): Contact
    {
        try {
            // savepoint: nieudany INSERT nie psuje transakcji importu
            return DB::transaction(static fn (): Contact => Contact::query()->create(['email' => $email, 'name' => $row['name'], 'company' => $row['company']]));
        } catch (UniqueConstraintViolationException) {
            // odczyt blokujący widzi wiersz zatwierdzony po starcie transakcji (zwykły SELECT w MySQL — nie)
            return Contact::query()->where('email', $email)->sharedLock()->firstOrFail();
        }
    }

    /** @return Builder<MailingList> grupy własne i wspólne */
    private function visible(User $user): Builder
    {
        return MailingList::query()->where(fn (Builder $q) => $q->where('user_id', $user->id)->orWhere('is_shared', true));
    }

    private function canEdit(User $user, MailingList $list): bool
    {
        return $list->is_shared ? $user->can('campaigns.manage') : (int) $list->user_id === (int) $user->id;
    }

    private function authorizeView(User $user, MailingList $list): void
    {
        if (! $list->is_shared && (int) $list->user_id !== (int) $user->id) {
            abort(404);
        }
    }

    private function authorizeEdit(User $user, MailingList $list): void
    {
        $this->authorizeView($user, $list);
        if (! $this->canEdit($user, $list)) {
            abort(403, 'Wspólne grupy prowadzi administrator.');
        }
    }

    private function reload(MailingList $list): MailingList
    {
        return MailingList::query()
            ->whereKey($list->id)
            ->with('user:id,name')
            ->withCount([
                'contacts',
                'contacts as customer_count' => fn (Builder $q) => $q->where('mailing_list_contact.basis', MailingListContact::BASIS_CUSTOMER),
                'contacts as consent_count' => fn (Builder $q) => $q->where('mailing_list_contact.basis', MailingListContact::BASIS_CONSENT),
            ])
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(MailingList $list, User $user): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'is_shared' => (bool) $list->is_shared,
            'owner' => ['id' => (int) $list->user_id, 'name' => (string) $list->user?->name],
            'contacts_count' => (int) $list->getAttribute('contacts_count'),
            'basis_counts' => [
                'customer' => (int) $list->getAttribute('customer_count'),
                'consent' => (int) $list->getAttribute('consent_count'),
            ],
            'can_edit' => $this->canEdit($user, $list),
            'updated_at' => $list->updated_at?->toIso8601String(),
        ];
    }
}
