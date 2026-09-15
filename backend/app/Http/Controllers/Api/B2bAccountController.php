<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\Pricing\ProductEffectivePrice;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class B2bAccountController extends Controller
{
    public function __construct(private readonly B2bConnectorRegistry $connectors) {}

    public function index(): JsonResponse
    {
        return response()->json(
            B2bAccount::query()
                ->with(['creator:id,name', 'updater:id,name'])
                ->orderBy('id')
                ->get()
                ->map(fn (B2bAccount $account): array => $this->view($account))
                ->values()
        );
    }

    public function connectors(): JsonResponse
    {
        return response()->json($this->connectors->options());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, creating: true);

        $account = B2bAccount::query()->create([
            ...$data,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json($this->view($account->fresh()->load(['creator:id,name', 'updater:id,name'])), 201);
    }

    public function update(Request $request, B2bAccount $b2bAccount): JsonResponse
    {
        $data = $this->validated($request, creating: false);

        // Puste hasło w edycji = zostaw zapisane (formularz nie zna starego hasła).
        if (! isset($data['password']) || $data['password'] === '') {
            unset($data['password']);
        }

        $b2bAccount->update([
            ...$data,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json($this->view($b2bAccount->fresh()->load(['creator:id,name', 'updater:id,name'])));
    }

    /**
     * Ceny konta znikają z kart przed usunięciem konta: cena obowiązująca wraca do cennika z pliku albo innego konta
     * (klucz obcy slotu tylko zeruje b2b_account_id — slot „b2b:{id}” zostałby na karcie i nadal wygrywał).
     */
    public function destroy(B2bAccount $b2bAccount, ProductEffectivePrice $effectivePrices): JsonResponse
    {
        $sourceKey = ProductSourcePrice::b2bKey((int) $b2bAccount->id);

        DB::transaction(function () use ($b2bAccount, $effectivePrices, $sourceKey): void {
            $productIds = ProductSourcePrice::query()
                ->where('source_key', $sourceKey)
                ->orderBy('product_id')
                ->pluck('product_id')
                ->all();
            foreach (array_chunk($productIds, 500) as $chunk) {
                foreach (Product::query()->whereIn('id', $chunk)->get() as $product) {
                    $effectivePrices->deleteSlot($product, $sourceKey);
                }
            }

            $b2bAccount->delete();
        });

        return response()->json(['ok' => true]);
    }

    /**
     * POST, żeby każde odsłonięcie hasła trafiło do dziennika aktywności.
     */
    public function revealPassword(B2bAccount $b2bAccount): JsonResponse
    {
        try {
            $password = $b2bAccount->password;
        } catch (DecryptException) {
            return response()->json([
                'message' => 'Nie można odszyfrować hasła (zmieniony klucz aplikacji). Zapisz hasło ponownie.',
            ], 422);
        }

        return response()->json(['password' => $password ?? '']);
    }

    /**
     * „Sprawdź teraz” — przebieg rusza z harmonogramu (b2b:sync-due, co minutę) w ciągu minuty.
     */
    public function requestSync(B2bAccount $b2bAccount): JsonResponse
    {
        if (! $this->connectors->has((string) $b2bAccount->connector)) {
            return response()->json([
                'message' => 'Dla witryn tego konta nie ma jeszcze importera — automatyczne pobieranie cennika nie jest dostępne.',
            ], 422);
        }

        // Drugie zlecenie w trakcie przebiegu dałoby podwójne pobieranie tuż po zakończeniu pierwszego.
        $running = $b2bAccount->last_sync_status === B2bSyncRun::STATUS_RUNNING
            || $b2bAccount->syncRuns()->where('status', B2bSyncRun::STATUS_RUNNING)->exists();
        if ($running) {
            return response()->json(['message' => 'Pobieranie już trwa.'], 409);
        }

        $b2bAccount->forceFill(['sync_requested_at' => now()])->save();

        return response()->json($this->view($b2bAccount->fresh()->load(['creator:id,name', 'updater:id,name'])));
    }

    /**
     * Okno postępu: najnowszy przebieg z dziennikiem, ostatnie przebiegi i czy cron harmonogramu żyje.
     */
    public function syncProgress(B2bAccount $b2bAccount): JsonResponse
    {
        $lastSeen = Cache::get(B2bSyncRun::SCHEDULER_HEARTBEAT_KEY);
        $lastSeenAt = is_string($lastSeen) && $lastSeen !== '' ? Carbon::parse($lastSeen) : null;

        $latest = $b2bAccount->syncRuns()->orderByDesc('id')->first();
        $recent = $b2bAccount->syncRuns()
            ->select(array_merge(['id', 'b2b_account_id'], self::RUN_COLUMNS))
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json([
            'scheduler' => [
                'last_seen_at' => $lastSeenAt?->toIso8601String(),
                'healthy' => $lastSeenAt !== null && $lastSeenAt->greaterThanOrEqualTo(now()->subMinutes(3)),
            ],
            'sync_requested_at' => $b2bAccount->sync_requested_at?->toIso8601String(),
            'run' => $latest !== null ? [
                ...$this->runView($latest),
                'log' => array_values($latest->log ?? []),
                'price_changes' => array_values($latest->price_changes ?? []),
            ] : null,
            'recent_runs' => $recent->map(fn (B2bSyncRun $run): array => $this->runView($run))->values(),
        ]);
    }

    /**
     * Zatrzymanie sprawdzane przez przebieg między produktami (co kilka sekund).
     */
    public function cancelSync(B2bAccount $b2bAccount): JsonResponse
    {
        $run = $b2bAccount->syncRuns()
            ->where('status', B2bSyncRun::STATUS_RUNNING)
            ->orderByDesc('id')
            ->first();
        if ($run === null) {
            return response()->json(['message' => 'Nie trwa żadne pobieranie.'], 422);
        }

        if ($run->cancel_requested_at === null) {
            $run->forceFill(['cancel_requested_at' => now()])->save();
        }

        return response()->json(['ok' => true]);
    }

    private const RUN_COLUMNS = [
        'status', 'trigger', 'started_at', 'finished_at', 'updated_at', 'total', 'processed', 'created',
        'updated', 'unchanged', 'skipped', 'prices_changed', 'descriptions', 'images', 'current_sku',
        'message', 'cancel_requested_at', 'progress_unit',
    ];

    /**
     * @return array<string, mixed>
     */
    private function runView(B2bSyncRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'trigger' => $run->trigger,
            // products = postęp liczony w kartach; variants = w wersjach (liczniki nadal w kartach).
            'progress_unit' => (string) ($run->progress_unit ?: 'products'),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
            'total' => $run->total,
            'processed' => (int) $run->processed,
            'created' => (int) $run->created,
            'updated' => (int) $run->updated,
            'unchanged' => (int) $run->unchanged,
            'skipped' => (int) $run->skipped,
            'prices_changed' => (int) $run->prices_changed,
            'descriptions' => (int) $run->descriptions,
            'images' => (int) $run->images,
            'current_sku' => $run->current_sku,
            'message' => $run->message,
            'cancel_requested' => $run->cancel_requested_at !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            // Trzecie pole logowania — tylko dla witryn, które go wymagają (np. UVEX).
            'contractor_code' => ['nullable', 'string', 'max:100'],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'max:1000'],
            'sites' => ['required', 'array', 'min:1', 'max:20'],
            // Puste wiersze (ConvertEmptyStringsToNull → null) pomijamy, nie odrzucamy formularza.
            'sites.*' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
            'connector' => ['nullable', 'string', Rule::in($this->connectors->keys())],
            'sync_frequency' => ['sometimes', 'string', Rule::in(B2bAccount::FREQUENCIES)],
            'sync_images' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('contractor_code', $data)) {
            $data['contractor_code'] = trim((string) $data['contractor_code']) ?: null;
        }

        $data['sites'] = array_values(array_unique(array_filter(
            array_map(static fn (?string $site): string => trim((string) $site), $data['sites']),
            static fn (string $site): bool => $site !== '',
        )));

        if ($data['sites'] === []) {
            throw ValidationException::withMessages(['sites' => 'Podaj co najmniej jedną witrynę.']);
        }

        $data['connector'] = ($data['connector'] ?? null) ?: $this->connectors->keyForSites($data['sites']);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function view(B2bAccount $account): array
    {
        $raw = $account->getRawOriginal('password');

        return [
            'id' => $account->id,
            'username' => $account->username,
            'contractor_code' => $account->contractor_code,
            'has_password' => is_string($raw) && $raw !== '',
            'sites' => $account->sites ?? [],
            'note' => $account->note,
            'connector' => $account->connector,
            'connector_label' => $this->connectors->label($account->connector),
            'sync_frequency' => $account->sync_frequency ?? 'off',
            'sync_images' => (bool) ($account->sync_images ?? true),
            'sync_requested_at' => $account->sync_requested_at?->toIso8601String(),
            'last_sync_status' => $account->last_sync_status,
            'last_sync_started_at' => $account->last_sync_started_at?->toIso8601String(),
            'last_sync_finished_at' => $account->last_sync_finished_at?->toIso8601String(),
            'last_sync_message' => $account->last_sync_message,
            'last_price_list_id' => $account->last_price_list_id,
            'created_by' => $account->creator?->only(['id', 'name']),
            'updated_by' => $account->updater?->only(['id', 'name']),
            'created_at' => $account->created_at?->toIso8601String(),
            'updated_at' => $account->updated_at?->toIso8601String(),
        ];
    }
}
