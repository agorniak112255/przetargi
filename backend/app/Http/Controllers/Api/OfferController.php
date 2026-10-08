<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\ErpCustomer;
use App\Models\ErpItemLink;
use App\Models\Offer;
use App\Models\OfferInspectionLine;
use App\Models\OfferItem;
use App\Models\OfferRecipient;
use App\Models\OfferSend;
use App\Models\User;
use App\Services\Campaigns\CampaignBlocks;
use App\Services\Erp\ErpItemCards;
use App\Services\Offers\OfferItemPresenter;
use App\Services\Offers\OfferPdf;
use App\Services\Offers\OfferRenderer;
use App\Services\Offers\OfferSender;
use App\Services\Offers\OfferVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Oferty dla klientów (kontrakt: frontend/src/lib/offers.ts): pozycje z ceną, treść, podgląd maila, znacznik
 * kopiowania i wysyłka ze skrzynki autora. Ofertę widzi i zmienia tylko autor — cudza oferta to 404. Oferta jest
 * zawsze edytowalna; co dostał klient, zapisuje każda wysyłka (sends). Wyliczenia w App\Services\Offers.
 *
 * Dwa rodzaje (Offer::KINDS): products — produkty z ceną (uprawnienie offers.use, bez niego 403); inspection — oferta
 * przeglądu z modułu Przeglądy, bez cen, z wierszami terminów (uprawnienie inspections.offer, bez niego 404 jak cudza).
 * Tworzy ją InspectionOfferController; tu edycja wierszy, podgląd, PDF i wysyłka jak w ofercie z produktami.
 */
class OfferController extends Controller
{
    private const NO_NEWLINE = '/[\r\n]/';

    public function __construct(
        private readonly OfferItemPresenter $presenter,
        private readonly OfferRenderer $renderer,
        private readonly OfferSender $sender,
        private readonly OfferPdf $pdf,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $authors = OfferVisibility::authorIds($user);
        $offers = Offer::query()
            // swoje; z offers.view_all — wszystkie, z offers.view_selected — także osób wybranych w roli
            ->when($authors !== null, static fn ($q) => $q->whereIn('user_id', $authors))
            // tylko rodzaje, do których konto ma uprawnienie
            ->whereIn('kind', $this->allowedKinds($user))
            ->withCount(['items', 'inspectionLines'])
            // adresy z udaną wysyłką — ten sam adres w kilku wysyłkach (także innymi literami) liczony raz
            ->selectSub(
                OfferRecipient::query()->selectRaw('count(distinct lower(email))')
                    ->whereColumn('offer_recipients.offer_id', 'offers.id')
                    ->where('status', OfferRecipient::STATUS_SENT)
                    ->toBase(),
                'recipients_count',
            )
            ->with('user:id,name,email')
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->get();
        // adresy z udaną wysyłką (do wyszukiwania na liście) — jednym zapytaniem, bez powtórzeń
        $sentTo = [];
        foreach ($offers->pluck('id')->chunk(500) as $chunk) {
            foreach (OfferRecipient::query()->whereIn('offer_id', $chunk->all())->where('status', OfferRecipient::STATUS_SENT)
                ->orderBy('id')->get(['offer_id', 'email']) as $r) {
                $sentTo[(int) $r->offer_id][mb_strtolower((string) $r->email)] ??= (string) $r->email;
            }
        }
        $customers = $this->customers($offers->filter(static fn (Offer $o): bool => $o->isInspection())->pluck('customer_xl_gid')->all());

        return response()->json([
            'data' => $offers->map(fn (Offer $o): array => [
                'id' => (int) $o->id,
                'kind' => $o->isInspection() ? Offer::KIND_INSPECTION : Offer::KIND_PRODUCTS,
                'customer_name' => $o->isInspection() ? InspectionOfferController::customerName((int) $o->customer_xl_gid, $customers[(int) $o->customer_xl_gid] ?? null) : null,
                'code' => $o->code,
                'subject' => (string) $o->subject,
                // oferta przeglądu: liczba wierszy przeglądu
                'items_count' => (int) $o->getAttribute($o->isInspection() ? 'inspection_lines_count' : 'items_count'),
                'recipients_count' => (int) $o->getAttribute('recipients_count'),
                'recipient_emails' => array_values($sentTo[(int) $o->id] ?? []),
                // autor oferty — nadawca maili
                'author' => $o->user !== null ? ['id' => (int) $o->user->id, 'name' => (string) $o->user->name, 'email' => (string) $o->user->email] : null,
                // cudza oferta (widoczna z offers.view_all / view_selected) — tylko podgląd
                'can_edit' => (int) $o->user_id === (int) $user->id,
                'last_sent_at' => $o->last_sent_at?->toIso8601String(),
                'last_copied_at' => $o->last_copied_at?->toIso8601String(),
                'updated_at' => $o->updated_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        // oferta z produktami; ofertę przeglądu tworzy POST /inspections/offers
        if (! $user->can('offers.use')) {
            abort(403, 'Brak uprawnienia do ofert z produktami.');
        }
        $v = $request->validate($this->itemIdRules(), $this->itemIdMessages());

        $offer = DB::transaction(function () use ($v, $user): Offer {
            $offer = Offer::query()->create(['user_id' => $user->id, 'subject' => '', 'layout' => 'grid3']);
            $this->appendItems($offer, $user, $v['erp_item_ids'] ?? [], $v['product_ids'] ?? []);

            return $offer;
        });

        return response()->json($this->present($offer->fresh() ?? $offer, $user), 201);
    }

    public function show(Request $request, Offer $offer): JsonResponse
    {
        $this->authorizeView($request, $offer);

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
            // forma: produkty w treści maila, krótki mail z PDF albo oba
            'delivery' => ['sometimes', 'required', 'string', Rule::in(Offer::DELIVERIES)],
            // ceny w mailu netto albo brutto (handlowiec wpisuje netto)
            'price_mode' => ['sometimes', 'required', 'string', Rule::in(Offer::PRICE_MODES)],
        ], [
            'subject.not_regex' => 'Temat musi być jedną linią.',
            'subject.max' => 'Temat może mieć najwyżej 200 znaków.',
            'intro.max' => 'Wstęp może mieć najwyżej 5000 znaków.',
            'layout.in' => 'Wybierz układ produktów z listy.',
            'valid_until.date_format' => 'Data ważności musi mieć postać RRRR-MM-DD.',
            'delivery.in' => 'Wybierz formę oferty z listy.',
            'delivery.required' => 'Wybierz formę oferty z listy.',
            'price_mode.in' => 'Wybierz ceny netto albo brutto.',
            'price_mode.required' => 'Wybierz ceny netto albo brutto.',
            'price_mode.string' => 'Wybierz ceny netto albo brutto.',
        ]);
        if (array_key_exists('price_mode', $v) && $offer->isInspection()) {
            throw ValidationException::withMessages(['price_mode' => ['Oferta przeglądu nie ma cen.']]);
        }

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
        if (array_key_exists('delivery', $v)) {
            $data['delivery'] = $v['delivery'];
        }
        if (array_key_exists('price_mode', $v)) {
            $data['price_mode'] = $v['price_mode'];
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
        if ($offer->isInspection()) {
            throw ValidationException::withMessages(['items' => [
                'Do oferty przeglądu nie dopisuje się produktów — ma tylko wiersze przeglądu z modułu Przeglądy.',
            ]]);
        }
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
            // przycisk z linkiem (np. do sklepu) — jak drugi przycisk pozycji kampanii; pusty link = bez przycisku
            'link_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'link_label' => ['required_with:link_url', 'nullable', 'string', 'max:40', 'not_regex:'.self::NO_NEWLINE],
            'link_color' => ['required_with:link_url', 'nullable', 'string', Rule::in(CampaignBlocks::BRAND_COLORS)],
            // jednostka ceny w mailu; null = jednostka towaru XL (bez niej „szt”)
            'price_unit' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(OfferItem::PRICE_UNITS))],
            // rozmiary w mailu („Rozmiary: S, XXXL”); pusty = bez linii rozmiarów
            'sizes' => ['sometimes', 'nullable', 'string', 'max:200', 'not_regex:'.self::NO_NEWLINE],
        ], [
            'price_unit.in' => 'Wybierz jednostkę ceny z listy.',
            'price_unit.string' => 'Wybierz jednostkę ceny z listy.',
            'sizes.string' => 'Rozmiary wpisz jako tekst, np. S, XXXL.',
            'sizes.max' => 'Rozmiary mogą mieć najwyżej 200 znaków.',
            'sizes.not_regex' => 'Rozmiary wpisz w jednej linii.',
            'price_net.numeric' => 'Cena musi być liczbą.',
            'price_net.min' => 'Cena nie może być ujemna.',
            'note.max' => 'Uwaga może mieć najwyżej 300 znaków.',
            'description.max' => 'Opis może mieć najwyżej 300 znaków.',
            'link_url.max' => 'Link może mieć najwyżej 500 znaków.',
            'link_label.required_with' => 'Wpisz nazwę przycisku.',
            'link_label.max' => 'Nazwa przycisku może mieć najwyżej 40 znaków.',
            'link_label.not_regex' => 'Nazwa przycisku musi być jedną linią.',
            'link_color.required_with' => 'Wybierz kolor przycisku.',
            'link_color.in' => 'Wybierz kolor przycisku z listy.',
        ]);
        $link = $this->itemLink($v);

        $this->locked($offer, function (Offer $locked) use ($item, $v, $link): void {
            $data = array_intersect_key($v, array_flip(['price_net', 'note', 'description', 'price_unit', 'sizes']));
            if (array_key_exists('price_net', $data) && $data['price_net'] !== null) {
                $data['price_net'] = round((float) $data['price_net'], 2);
            }
            foreach (['note', 'description', 'sizes'] as $field) {
                if (array_key_exists($field, $data) && is_string($data[$field])) {
                    $data[$field] = trim($data[$field]) !== '' ? trim($data[$field]) : null;
                }
            }
            if ($link !== null) {
                $data = [...$data, ...$link];
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

    /** Wiersz oferty przeglądu: ilość, termin, uwaga, miejsce na liście. W ofercie z produktami — 404. */
    public function updateInspectionLine(Request $request, Offer $offer, OfferInspectionLine $line): JsonResponse
    {
        $this->authorizeOwner($request, $offer);
        $this->ensureInspectionLineOf($offer, $line);
        $v = $request->validate([
            'quantity' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999999'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
            'position' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ], [
            'quantity.numeric' => 'Ilość musi być liczbą.',
            'quantity.min' => 'Ilość nie może być ujemna.',
            'quantity.max' => 'Ilość jest za duża.',
            'due_on.date_format' => 'Termin przeglądu musi mieć postać RRRR-MM-DD.',
            'note.max' => 'Uwaga może mieć najwyżej 300 znaków.',
            'position.integer' => 'Miejsce na liście musi być liczbą od 1.',
            'position.min' => 'Miejsce na liście musi być liczbą od 1.',
            'position.max' => 'Miejsce na liście może być najwyżej 1000.',
        ]);

        $this->locked($offer, function (Offer $locked) use ($line, $v): void {
            $data = array_intersect_key($v, array_flip(['quantity', 'due_on', 'note']));
            if (array_key_exists('quantity', $data) && $data['quantity'] !== null) {
                $data['quantity'] = round((float) $data['quantity'], 3);
            }
            if (array_key_exists('note', $data) && is_string($data['note'])) {
                $data['note'] = trim($data['note']) !== '' ? trim($data['note']) : null;
            }
            if ($data !== []) {
                $line->update($data);
            }
            if (array_key_exists('position', $v)) {
                $this->moveRow($locked->inspectionLines()->pluck('id'), OfferInspectionLine::class, (int) $line->id, (int) $v['position']);
            }
            // zmiana wiersza to zmiana oferty — lista ofert sortuje po updated_at
            $locked->touch();
        });

        return response()->json($this->present($offer->fresh() ?? $offer, $request->user()));
    }

    public function removeInspectionLine(Request $request, Offer $offer, OfferInspectionLine $line): JsonResponse
    {
        $this->authorizeOwner($request, $offer);
        $this->ensureInspectionLineOf($offer, $line);

        $this->locked($offer, function (Offer $locked) use ($line): void {
            $line->delete();
            $this->writePositions($locked->inspectionLines()->pluck('id')->map(fn ($id): int => (int) $id)->all(), OfferInspectionLine::class);
            $locked->touch();
        });

        return response()->json($this->present($offer->fresh() ?? $offer, $request->user()));
    }

    public function preview(Request $request, Offer $offer): JsonResponse
    {
        $this->authorizeView($request, $offer);
        // podgląd = to, co handlowiec kopiuje do Thunderbirda — bez podpisu, bo doda go program pocztowy; forma
        // „pdf” — krótki mail bez produktów (oferta w załączniku)
        $delivery = $this->delivery($offer);
        $rendered = $this->renderer->render($offer, $request->user(), null, false, $delivery);

        return response()->json([
            'subject' => $rendered['subject'],
            'from' => $rendered['from'],
            'html' => $rendered['html'],
            'text' => $rendered['text'],
            // oferta przeglądu nie ma cen
            'missing_prices' => $offer->isInspection() ? [] : OfferSender::missingPrices($offer->items()->get()),
            'public_url_missing' => rtrim((string) config('campaigns.public_url'), '/') === '',
            'delivery' => $delivery,
            'pdf_filename' => $delivery === 'body' ? null : OfferPdf::filename($offer),
        ]);
    }

    /**
     * PDF bieżącej oferty (do podejrzenia i ręcznego dołączenia przy kopiowaniu) — pełna oferta z produktami i podpisem
     * handlowca, niezależnie od formy. Te same warunki co wysyłka: pozycje w mailu i ceny.
     */
    public function pdf(Request $request, Offer $offer): Response
    {
        $this->authorizeView($request, $offer);
        OfferSender::assertReady($offer, 'przed pobraniem PDF');

        return $this->pdfResponse($this->pdf->render($offer, $request->user()), OfferPdf::filename($offer));
    }

    /** PDF, który dostali klienci w tej wysyłce — zapisany przy wysyłce, nie składany na nowo. */
    public function sendPdf(Request $request, Offer $offer, OfferSend $send): Response
    {
        $this->authorizeView($request, $offer);
        if ((int) $send->offer_id !== (int) $offer->id || $send->pdf_path === null) {
            abort(404);
        }
        $bytes = Storage::disk('local')->exists((string) $send->pdf_path) ? Storage::disk('local')->get((string) $send->pdf_path) : null;
        if (! is_string($bytes) || $bytes === '') {
            abort(404, 'Plik PDF tej wysyłki nie jest już dostępny.');
        }

        return $this->pdfResponse($bytes, OfferPdf::filename($offer));
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
        $this->authorizeView($request, $offer);
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
     * Pola przycisku z linkiem z żądania (jak CampaignController::itemLink): null = żądanie ich nie zmienia; pusty
     * link = przycisk usunięty (wszystkie trzy null). Link tylko https:// jak w mailu kampanii.
     *
     * @param  array<string, mixed>  $v
     * @return array{link_url: string|null, link_label: string|null, link_color: string|null}|null
     */
    private function itemLink(array $v): ?array
    {
        if (! array_key_exists('link_url', $v)) {
            return null;
        }
        $url = trim((string) ($v['link_url'] ?? ''));
        if ($url === '') {
            return ['link_url' => null, 'link_label' => null, 'link_color' => null];
        }
        if (! CampaignBlocks::validUrl($url, false)) {
            throw ValidationException::withMessages(['link_url' => 'Link musi zaczynać się od https:// i nie może zawierać spacji.']);
        }
        $label = trim((string) ($v['link_label'] ?? ''));
        if ($label === '') {
            throw ValidationException::withMessages(['link_label' => 'Wpisz nazwę przycisku.']);
        }

        return ['link_url' => $url, 'link_label' => $label, 'link_color' => (string) $v['link_color']];
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
        $this->moveRow($offer->items()->pluck('id'), OfferItem::class, (int) $item->id, $position);
    }

    /**
     * Wiersz oferty (pozycja albo wiersz przeglądu) na miejsce N (od 1), reszta po kolei.
     *
     * @param  Collection<int, mixed>  $orderedIds  id wierszy oferty w bieżącej kolejności
     * @param  class-string<Model>  $model
     */
    private function moveRow(Collection $orderedIds, string $model, int $rowId, int $position): void
    {
        $ids = $orderedIds->map(fn ($id): int => (int) $id)->reject(fn (int $id): bool => $id === $rowId)->values()->all();
        $index = max(0, min(count($ids), $position - 1));
        array_splice($ids, $index, 0, [$rowId]);
        $this->writePositions($ids, $model);
    }

    private function renumber(Offer $offer): void
    {
        $this->writePositions($offer->items()->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    /**
     * @param  list<int>  $ids
     * @param  class-string<Model>  $model  OfferItem albo OfferInspectionLine
     */
    private function writePositions(array $ids, string $model = OfferItem::class): void
    {
        foreach ($ids as $i => $id) {
            $model::query()->whereKey($id)->where('position', '!=', $i + 1)->update(['position' => $i + 1]);
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

    private function pdfResponse(string $bytes, string $filename): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Forma zapisana przy ofercie; nieznana wartość = treść maila. */
    private function delivery(Offer $offer): string
    {
        return in_array($offer->delivery, Offer::DELIVERIES, true) ? (string) $offer->delivery : 'body';
    }

    /**
     * Ofertę widzi i zmienia tylko autor; cudza = 404 (bez zdradzania, że istnieje). Oferta przeglądu bez uprawnienia
     * inspections.offer — też 404; oferta z produktami bez offers.use — 403.
     */
    /**
     * Podgląd: rodzaj oferty w uprawnieniach konta i autor w zakresie widoczności (swoje; offers.view_all — wszystkie;
     * offers.view_selected — osób wybranych w roli). Niewidoczna oferta = 404 (bez zdradzania, że istnieje).
     */
    private function authorizeView(Request $request, Offer $offer): void
    {
        /** @var User $user */
        $user = $request->user();
        if (! OfferVisibility::canView($user, (int) $offer->user_id)) {
            abort(404);
        }
        if ($offer->isInspection()) {
            if (! $user->can('inspections.offer')) {
                abort(404);
            }
        } elseif (! $user->can('offers.use')) {
            abort(403, 'Brak uprawnienia do ofert z produktami.');
        }
    }

    /** Zmiana, wysyłka, usunięcie — tylko autor (mail wychodzi z jego skrzynki); cudza widoczna oferta = 403. */
    private function authorizeOwner(Request $request, Offer $offer): void
    {
        $this->authorizeView($request, $offer);
        if ((int) $offer->user_id !== (int) $request->user()->id) {
            abort(403, 'To oferta innej osoby — zmienia ją i wysyła tylko jej autor.');
        }
    }

    /** @return list<string> rodzaje ofert, które konto widzi */
    private function allowedKinds(User $user): array
    {
        $kinds = [];
        if ($user->can('offers.use')) {
            $kinds[] = Offer::KIND_PRODUCTS;
        }
        if ($user->can('inspections.offer')) {
            $kinds[] = Offer::KIND_INSPECTION;
        }

        return $kinds;
    }

    private function ensureInspectionLineOf(Offer $offer, OfferInspectionLine $line): void
    {
        if (! $offer->isInspection() || (int) $line->offer_id !== (int) $offer->id) {
            abort(404);
        }
    }

    /**
     * Klienci XL ofert przeglądu po numerze XL.
     *
     * @param  list<mixed>  $xlGids
     * @return array<int, ErpCustomer>
     */
    private function customers(array $xlGids): array
    {
        $xlGids = array_values(array_unique(array_filter(array_map('intval', $xlGids))));
        if ($xlGids === []) {
            return [];
        }

        return ErpCustomer::query()->whereIn('xl_gid', $xlGids)
            ->get(['id', 'xl_gid', 'acronym', 'name', 'city', 'emails'])
            ->keyBy(static fn (ErpCustomer $c): int => (int) $c->xl_gid)->all();
    }

    /**
     * Klient oferty przeglądu (kontrakt OfferCustomer); klienta nie ma w kartotece — akronim „Klient XL {numer}”.
     *
     * @return array{xl_gid: int, acronym: string, name: string|null, city: string|null, emails: list<string>}
     */
    private function presentCustomer(int $xlGid, ?ErpCustomer $customer): array
    {
        $acronym = trim((string) $customer?->acronym);

        return [
            'xl_gid' => $xlGid,
            'acronym' => $acronym !== '' ? $acronym : 'Klient XL '.$xlGid,
            'name' => $customer?->name,
            'city' => $customer?->city,
            'emails' => InspectionOfferController::customerEmails($customer),
        ];
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
        $sends = $offer->sends()->with('recipients')->get(['id', 'offer_id', 'delivery', 'pdf_path', 'created_at']);
        $inspection = $offer->isInspection();
        $xlGid = (int) $offer->customer_xl_gid;

        $offer->loadMissing('user:id,name,email');

        return [
            'id' => (int) $offer->id,
            'kind' => $inspection ? Offer::KIND_INSPECTION : Offer::KIND_PRODUCTS,
            'author' => $offer->user !== null
                ? ['id' => (int) $offer->user->id, 'name' => (string) $offer->user->name, 'email' => (string) $offer->user->email]
                : null,
            // cudza oferta (widoczna z offers.view_all / offers.view_selected) — tylko podgląd
            'can_edit' => (int) $offer->user_id === (int) $viewer->id,
            // oferta przeglądu: klient z kartoteki XL i wiersze terminów (bez cen); oferta z produktami: null i []
            'customer' => $inspection ? $this->presentCustomer($xlGid, $this->customers([$xlGid])[$xlGid] ?? null) : null,
            'inspection_lines' => $inspection ? $offer->inspectionLines()->get()->map(static fn (OfferInspectionLine $l): array => [
                'id' => (int) $l->id,
                'position' => (int) $l->position,
                'inspection_position_id' => $l->inspection_position_id,
                'xl_gid' => $l->xl_gid,
                'name' => (string) $l->name,
                'unit' => $l->unit,
                'quantity' => $l->quantity !== null ? (float) $l->quantity : null,
                'last_on' => $l->last_on?->toDateString(),
                'due_on' => $l->due_on?->toDateString(),
                'note' => $l->note,
            ])->values()->all() : [],
            'code' => $offer->code,
            'subject' => (string) $offer->subject,
            'intro' => $offer->intro,
            'layout' => $offer->layout,
            'valid_until' => $offer->valid_until?->toDateString(),
            'delivery' => $this->delivery($offer),
            // ceny w mailu: netto albo brutto ze stałą stawką VAT (pozycje mają zawsze price_net i price_gross)
            'price_mode' => $offer->isGross() ? 'gross' : 'net',
            'vat_percent' => Offer::vatPercent(),
            'last_sent_at' => $offer->last_sent_at?->toIso8601String(),
            'last_copied_at' => $offer->last_copied_at?->toIso8601String(),
            'items' => $this->presenter->presentMany($items, $viewer),
            'sends' => $sends->map(static fn (OfferSend $s): array => [
                'id' => (int) $s->id,
                'created_at' => $s->created_at?->toIso8601String(),
                'delivery' => in_array($s->delivery, Offer::DELIVERIES, true) ? (string) $s->delivery : 'body',
                'has_pdf' => $s->pdf_path !== null,
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
