<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\ErpItemLink;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\OfferRecipient;
use App\Models\OfferSend;
use App\Models\User;
use App\Services\Erp\ErpItemCards;
use App\Services\Offers\OfferItemPresenter;
use App\Services\Offers\OfferRenderer;
use App\Services\Offers\OfferSender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Oferty dla klientów (kontrakt: frontend/src/lib/offers.ts): pozycje z ceną, treść, podgląd maila, znacznik
 * kopiowania i wysyłka ze skrzynki autora. Ofertę widzi i zmienia tylko autor — cudza oferta to 404. Oferta jest
 * zawsze edytowalna; co dostał klient, zapisuje każda wysyłka (sends). Wyliczenia w App\Services\Offers.
 */
class OfferController extends Controller
{
    private const NO_NEWLINE = '/[\r\n]/';

    public function __construct(
        private readonly OfferItemPresenter $presenter,
        private readonly OfferRenderer $renderer,
        private readonly OfferSender $sender,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $offers = Offer::query()
            ->where('user_id', $user->id)
            ->withCount('items')
            // adresy z udaną wysyłką — ten sam adres w kilku wysyłkach (także innymi literami) liczony raz
            ->selectSub(
                OfferRecipient::query()->selectRaw('count(distinct lower(email))')
                    ->whereColumn('offer_recipients.offer_id', 'offers.id')
                    ->where('status', OfferRecipient::STATUS_SENT)
                    ->toBase(),
                'recipients_count',
            )
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $offers->map(static fn (Offer $o): array => [
                'id' => (int) $o->id,
                'code' => $o->code,
                'subject' => (string) $o->subject,
                'items_count' => (int) $o->getAttribute('items_count'),
                'recipients_count' => (int) $o->getAttribute('recipients_count'),
                'last_sent_at' => $o->last_sent_at?->toIso8601String(),
                'last_copied_at' => $o->last_copied_at?->toIso8601String(),
                'updated_at' => $o->updated_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate($this->itemIdRules(), $this->itemIdMessages());
        /** @var User $user */
        $user = $request->user();

        $offer = DB::transaction(function () use ($v, $user): Offer {
            $offer = Offer::query()->create(['user_id' => $user->id, 'subject' => '', 'layout' => 'grid3']);
            $this->appendItems($offer, $user, $v['erp_item_ids'] ?? [], $v['product_ids'] ?? []);

            return $offer;
        });

        return response()->json($this->present($offer->fresh() ?? $offer, $user), 201);
    }

    public function show(Request $request, Offer $offer): JsonResponse
    {
        $this->authorizeOwner($request, $offer);

        return response()->json($this->present($offer, $request->user()));
    }

    public function update(Request $request, Offer $offer): JsonResponse
    {
        $this->authorizeOwner($request, $offer);
        $v = $request->validate([
            // pusty temat zapisuje się (autozapis w trakcie pisania); wysyłka go wymaga
            'subject' => ['sometimes', 'nullable', 'string', 'max:200', 'not_regex:'.self::NO_NEWLINE],
            'intro' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'layout' => ['sometimes', 'required', 'string', Rule::in(Campaign::LAYOUTS)],
            'valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ], [
            'subject.not_regex' => 'Temat musi być jedną linią.',
            'subject.max' => 'Temat może mieć najwyżej 200 znaków.',
            'intro.max' => 'Wstęp może mieć najwyżej 5000 znaków.',
            'layout.in' => 'Wybierz układ produktów z listy.',
            'valid_until.date_format' => 'Data ważności musi mieć postać RRRR-MM-DD.',
        ]);

        $data = [];
        if (array_key_exists('subject', $v)) {
            $data['subject'] = trim((string) $v['subject']);
        }
        if (array_key_exists('intro', $v)) {
            $data['intro'] = is_string($v['intro']) && trim($v['intro']) !== '' ? trim($v['intro']) : null;
        }
        if (array_key_exists('layout', $v)) {
            $data['layout'] = $v['layout'];
        }
        if (array_key_exists('valid_until', $v)) {
            $data['valid_until'] = $v['valid_until'];
        }
        if ($data !== []) {
            $offer->update($data);
        }

        return response()->json($this->present($offer->fresh() ?? $offer, $request->user()));
    }

    public function destroy(Request $request, Offer $offer): Response
    {
        $this->authorizeOwner($request, $offer);
        // pozycje, zapisy wysyłek i adresy idą kaskadą
        $offer->delete();

        return response()->noContent();
    }

    public function addItems(Request $request, Offer $offer): JsonResponse
    {
        $this->authorizeOwner($request, $offer);
        $v = $request->validate($this->itemIdRules(), $this->itemIdMessages());
        /** @var User $user */
        $user = $request->user();

        $this->locked($offer, fn (Offer $locked) => $this->appendItems($locked, $user, $v['erp_item_ids'] ?? [], $v['product_ids'] ?? []));

        return response()->json($this->present($offer->fresh() ?? $offer, $user));
    }

    public function updateItem(Request $request, Offer $offer, OfferItem $item): JsonResponse
    {
        $this->authorizeOwner($request, $offer);
        $this->ensureItemOf($offer, $item);
        $v = $request->validate([
            'price_net' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
            // krótki opis w mailu (układy „z opisem”); pusty = wycinek opisu karty
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'position' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ], [
            'price_net.numeric' => 'Cena musi być liczbą.',
            'price_net.min' => 'Cena nie może być ujemna.',
            'note.max' => 'Uwaga może mieć najwyżej 300 znaków.',
            'description.max' => 'Opis może mieć najwyżej 300 znaków.',
        ]);

        $this->locked($offer, function (Offer $locked) use ($item, $v): void {
            $data = array_intersect_key($v, array_flip(['price_net', 'note', 'description']));
            if (array_key_exists('price_net', $data) && $data['price_net'] !== null) {
                $data['price_net'] = round((float) $data['price_net'], 2);
            }
            foreach (['note', 'description'] as $field) {
                if (array_key_exists($field, $data) && is_string($data[$field])) {
                    $data[$field] = trim($data[$field]) !== '' ? trim($data[$field]) : null;
                }
            }
            if ($data !== []) {
                $item->update($data);
            }
            if (array_key_exists('position', $v)) {
                $this->moveItem($locked, $item, (int) $v['position']);
            }
            // zmiana pozycji to zmiana oferty — lista ofert sortuje po updated_at
            $locked->touch();
        });

        return response()->json($this->present($offer->fresh() ?? $offer, $request->user()));
    }

    public function removeItem(Request $request, Offer $offer, OfferItem $item): JsonResponse
    {
        $this->authorizeOwner($request, $offer);
        $this->ensureItemOf($offer, $item);

        $this->locked($offer, function (Offer $locked) use ($item): void {
            $item->delete();
            $this->renumber($locked);
            $locked->touch();
        });

        return response()->json($this->present($offer->fresh() ?? $offer, $request->user()));
    }

    public function preview(Request $request, Offer $offer): JsonResponse
    {
        $this->authorizeOwner($request, $offer);
        // podgląd = to, co handlowiec kopiuje do Thunderbirda — bez podpisu, bo doda go program pocztowy
        $rendered = $this->renderer->render($offer, $request->user(), null, false);

        return response()->json([
            'subject' => $rendered['subject'],
            'from' => $rendered['from'],
            'html' => $rendered['html'],
            'text' => $rendered['text'],
            'missing_prices' => OfferSender::missingPrices($offer->items()->get()),
            'public_url_missing' => rtrim((string) config('campaigns.public_url'), '/') === '',
        ]);
    }

    /** Znacznik „skopiowana do wklejenia w programie pocztowym” — nic poza datą nie zapisuje. */
    public function copied(Request $request, Offer $offer): Response
    {
        $this->authorizeOwner($request, $offer);
        // bez updated_at — „Zmieniona” na liście to zmiana treści, nie kopiowanie
        $offer->timestamps = false;
        $offer->forceFill(['last_copied_at' => Carbon::now()])->save();

        return response()->noContent();
    }

    public function send(Request $request, Offer $offer): JsonResponse
    {
        $this->authorizeOwner($request, $offer);
        $v = $request->validate([
            'emails' => ['required', 'array', 'max:100'],
            'emails.*' => ['nullable', 'string', 'max:320'],
        ], [
            'emails.required' => 'Wpisz co najmniej jeden adres e-mail.',
        ]);
        /** @var User $user */
        $user = $request->user();

        $result = $this->sender->send($offer, $user, array_values(array_map(static fn ($e): string => (string) $e, $v['emails'])));

        return response()->json([
            'offer' => $this->present($offer->fresh() ?? $offer, $user),
            'results' => $result['results'],
        ]);
    }

    /** Dokładnie to, co dostali klienci w tej wysyłce. */
    public function showSend(Request $request, Offer $offer, OfferSend $send): JsonResponse
    {
        $this->authorizeOwner($request, $offer);
        if ((int) $send->offer_id !== (int) $offer->id) {
            abort(404);
        }

        return response()->json([
            'subject' => (string) $send->subject,
            'html' => (string) $send->html,
            'text' => (string) $send->text,
            'created_at' => $send->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Komunikaty po polsku (aplikacja nie ma tłumaczeń walidacji) — handlowiec zaznacza wiersze na liście, więc
     * „za dużo” i „już nie istnieje” to zwykłe sytuacje, nie błędy techniczne.
     *
     * @return array<string, string>
     */
    private function itemIdMessages(): array
    {
        $tooMany = 'Oferta mieści najwyżej :max pozycji — zaznacz mniej.';
        $gone = 'Część zaznaczonych pozycji już nie istnieje — odśwież listę i zaznacz ponownie.';

        return [
            'erp_item_ids.max' => $tooMany,
            'product_ids.max' => $tooMany,
            'erp_item_ids.*.exists' => $gone,
            'product_ids.*.exists' => $gone,
            'erp_item_ids.*.integer' => $gone,
            'product_ids.*.integer' => $gone,
            'erp_item_ids.*.distinct' => 'Ta sama pozycja jest zaznaczona dwa razy.',
            'product_ids.*.distinct' => 'Ta sama pozycja jest zaznaczona dwa razy.',
        ];
    }

    /** @return array<string, list<mixed>> */
    private function itemIdRules(): array
    {
        $max = (int) config('offers.max_items');

        return [
            'erp_item_ids' => ['nullable', 'array', 'max:'.$max],
            'erp_item_ids.*' => ['integer', 'distinct', Rule::exists('erp_items', 'id')->whereNull('removed_at')],
            'product_ids' => ['nullable', 'array', 'max:'.$max],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')],
        ];
    }

    /**
     * Dopisuje pozycje na koniec, pomijając już obecne (ten sam towar XL albo ta sama karta). Karta bez towaru XL
     * dostaje towar z pierwszego pewnego powiązania, jeśli jest (jak CampaignController::appendItems). Cena nowej
     * pozycji = cena sugerowana (koszt × (1 + marża autora)); bez kosztu zostaje pusta do uzupełnienia.
     *
     * @param  list<int>  $erpItemIds
     * @param  list<int>  $productIds
     */
    private function appendItems(Offer $offer, User $user, array $erpItemIds, array $productIds): void
    {
        $existing = $offer->items()->get(['id', 'position', 'erp_item_id', 'product_id']);
        $erpIds = $existing->pluck('erp_item_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $cardIds = $existing->pluck('product_id')->filter()->map(fn ($id): int => (int) $id)->all();

        $new = [];
        foreach ($erpItemIds as $id) {
            $id = (int) $id;
            if (! in_array($id, $erpIds, true)) {
                $erpIds[] = $id;
                $new[] = ['erp_item_id' => $id, 'product_id' => null];
            }
        }

        $links = $this->linkedErpItems(array_map('intval', $productIds));
        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            $erpId = $links[$productId] ?? null;
            if (in_array($productId, $cardIds, true) || ($erpId !== null && in_array($erpId, $erpIds, true))) {
                continue;
            }
            $cardIds[] = $productId;
            if ($erpId !== null) {
                $erpIds[] = $erpId;
            }
            $new[] = ['erp_item_id' => $erpId, 'product_id' => $productId];
        }

        $max = (int) config('offers.max_items');
        if ($existing->count() + count($new) > $max) {
            throw ValidationException::withMessages([
                'items' => ["Oferta może mieć najwyżej {$max} pozycji (teraz: {$existing->count()})."],
            ]);
        }
        if ($new === []) {
            return;
        }

        $position = (int) $existing->max('position');
        $created = [];
        foreach ($new as $row) {
            $created[] = $offer->items()->create([...$row, 'position' => ++$position]);
        }
        $created = OfferItem::query()->whereIn('id', array_map(static fn (OfferItem $i): int => (int) $i->id, $created))->orderBy('id')->get();
        foreach ($this->presenter->presentMany($created, $user) as $row) {
            if ($row['suggested_price'] !== null) {
                OfferItem::query()->whereKey($row['id'])->update(['price_net' => $row['suggested_price']]);
            }
        }
        $offer->touch();
    }

    /**
     * Karta → towar XL z pierwszego pewnego powiązania (potwierdzone przed automatycznym, potem najstarsze).
     *
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    private function linkedErpItems(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $out = [];
        $links = ErpItemCards::linked(ErpItemLink::query())
            ->whereIn('product_id', $productIds)
            ->whereHas('item', fn (Builder $q) => $q->whereNull('removed_at'))
            ->orderByRaw('case when status = ? then 0 else 1 end', [ErpItemLink::STATUS_CONFIRMED])
            ->orderBy('id')
            ->get(['id', 'product_id', 'erp_item_id', 'status']);
        foreach ($links as $link) {
            $out[(int) $link->product_id] ??= (int) $link->erp_item_id;
        }

        return $out;
    }

    /** Przesuwa pozycję na miejsce N (od 1) i numeruje resztę po kolei. */
    private function moveItem(Offer $offer, OfferItem $item, int $position): void
    {
        $ids = $offer->items()->pluck('id')->map(fn ($id): int => (int) $id)->reject(fn (int $id): bool => $id === (int) $item->id)->values()->all();
        $index = max(0, min(count($ids), $position - 1));
        array_splice($ids, $index, 0, [(int) $item->id]);
        $this->writePositions($ids);
    }

    private function renumber(Offer $offer): void
    {
        $this->writePositions($offer->items()->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    /** @param list<int> $ids */
    private function writePositions(array $ids): void
    {
        foreach ($ids as $i => $id) {
            OfferItem::query()->whereKey($id)->where('position', '!=', $i + 1)->update(['position' => $i + 1]);
        }
    }

    /**
     * Zmiana pozycji pod blokadą oferty — dwa równoległe dopisania nie przekroczą limitu ani nie dadzą tej samej
     * pozycji dwa razy.
     *
     * @param  callable(Offer): mixed  $change
     */
    private function locked(Offer $offer, callable $change): void
    {
        DB::transaction(function () use ($offer, $change): void {
            $change(Offer::query()->lockForUpdate()->findOrFail($offer->id));
        });
    }

    /** Ofertę widzi i zmienia tylko autor; cudza = 404 (bez zdradzania, że istnieje). */
    private function authorizeOwner(Request $request, Offer $offer): void
    {
        if ((int) $offer->user_id !== (int) $request->user()->id) {
            abort(404);
        }
    }

    private function ensureItemOf(Offer $offer, OfferItem $item): void
    {
        if ((int) $item->offer_id !== (int) $offer->id) {
            abort(404);
        }
    }

    /** @return array<string, mixed> pełna oferta (kontrakt „Offer”) */
    private function present(Offer $offer, User $viewer): array
    {
        $items = $offer->items()->get();
        $sends = $offer->sends()->with('recipients')->get(['id', 'offer_id', 'created_at']);

        return [
            'id' => (int) $offer->id,
            'code' => $offer->code,
            'subject' => (string) $offer->subject,
            'intro' => $offer->intro,
            'layout' => $offer->layout,
            'valid_until' => $offer->valid_until?->toDateString(),
            'last_sent_at' => $offer->last_sent_at?->toIso8601String(),
            'last_copied_at' => $offer->last_copied_at?->toIso8601String(),
            'items' => $this->presenter->presentMany($items, $viewer),
            'sends' => $sends->map(static fn (OfferSend $s): array => [
                'id' => (int) $s->id,
                'created_at' => $s->created_at?->toIso8601String(),
                'recipients' => $s->recipients->map(static fn (OfferRecipient $r): array => [
                    'email' => (string) $r->email,
                    'status' => (string) $r->status,
                    'error' => $r->error,
                    'sent_at' => $r->sent_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
            'limits' => [
                'max_items' => (int) config('offers.max_items'),
                'max_recipients' => (int) config('offers.max_recipients'),
            ],
        ];
    }
}
