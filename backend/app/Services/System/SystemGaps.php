<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Models\Client;
use App\Models\ErpItem;
use App\Models\Tender;
use App\Models\User;
use App\Support\PolishTime;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * „Dane do uzupełnienia” na ekranie „Stan systemu”: braki, od których zależą raport skuteczności, pobieranie wyników
 * z Biuletynu i podpowiedzi z ERP XL. Liczniki to pojedyncze count(*) bez GROUP BY; lista wierszy ma limit
 * (config system_health.gap_rows_limit).
 */
class SystemGaps
{
    public const KINDS = [
        'tenders_without_time',
        'tenders_without_notice',
        'salespeople_without_operator',
        'clients_without_xl',
        'sold_items_without_card',
    ];

    public const LABELS = [
        'tenders_without_time' => 'Przetargi w toku bez godziny składania',
        'tenders_without_notice' => 'Przetargi bez numeru ogłoszenia w Biuletynie',
        'salespeople_without_operator' => 'Handlowcy bez przypisanego operatora ERP XL',
        'clients_without_xl' => 'Zamawiający bez powiązania z kontrahentem w ERP XL',
        'sold_items_without_card' => 'Sprzedawane towary bez karty produktu',
    ];

    /** Przetargi poza pracą (jak „w toku” na dashboardzie). */
    private const TENDER_CLOSED = ['exported', 'odrzucony', 'archiwum'];

    /** Numer ogłoszenia jest potrzebny także po złożeniu oferty — do pobrania wyniku. */
    private const NOTICE_SKIP_STATUSES = ['odrzucony', 'archiwum'];

    /** Bez numeru ogłoszenia liczą się przetargi z terminem najwyżej tyle dni temu (dawniejsze już nie dostaną wyniku). */
    private const NOTICE_LOOKBACK_DAYS = 60;

    /** Towar „sprzedawany” = sprzedaż w ostatnich tylu miesiącach (jak licznik ekranu Powiązania z ERP XL). */
    private const SOLD_MONTHS = 12;

    private const SALESPERSON_ROLE = 'handlowiec';

    /** @return list<array{kind: string, label: string, count: int}> */
    public function counts(): array
    {
        $out = [];
        foreach (self::KINDS as $kind) {
            $out[] = ['kind' => $kind, 'label' => self::LABELS[$kind], 'count' => $this->query($kind)->count()];
        }

        return $out;
    }

    /** @return list<array{id: int, label: string, detail: ?string, url: ?string}> */
    public function rows(string $kind): array
    {
        $limit = max(1, (int) config('system_health.gap_rows_limit', 100));
        $query = $this->query($kind)->limit($limit);

        return match ($kind) {
            'tenders_without_time', 'tenders_without_notice' => $query
                ->with('client:id,name')
                ->orderByRaw('deadline is null')
                ->orderBy('deadline')
                ->orderBy('id')
                ->get(['id', 'number', 'title', 'client_id', 'deadline', 'status'])
                ->map(static fn (Tender $t): array => [
                    'id' => (int) $t->id,
                    'label' => trim(($t->client?->name ?? $t->title ?? '').' · '.$t->number, ' ·'),
                    'detail' => $t->deadline !== null ? 'Termin składania '.PolishTime::formatDeadline($t) : 'Bez terminu składania',
                    'url' => '/tenders/'.$t->id,
                ])->values()->all(),
            'salespeople_without_operator' => $query
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(static fn (User $u): array => [
                    'id' => (int) $u->id,
                    'label' => (string) $u->name,
                    'detail' => (string) $u->email,
                    'url' => '/admin',
                ])->values()->all(),
            'clients_without_xl' => $query
                ->withCount('tenders')
                ->orderBy('name')
                ->get(['id', 'name', 'nip'])
                ->map(static fn (Client $c): array => [
                    'id' => (int) $c->id,
                    'label' => (string) $c->name,
                    'detail' => trim(($c->nip !== null && $c->nip !== '' ? 'NIP '.$c->nip.' · ' : '').'przetargi: '.(int) $c->getAttribute('tenders_count')),
                    'url' => '/clients',
                ])->values()->all(),
            'sold_items_without_card' => $query
                ->orderByDesc('last_sale_at')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'last_sale_at'])
                ->map(static fn (ErpItem $i): array => [
                    'id' => (int) $i->id,
                    'label' => trim($i->code.' · '.$i->name),
                    'detail' => $i->last_sale_at !== null ? 'Ostatnia sprzedaż '.PolishTime::format($i->last_sale_at, false) : null,
                    'url' => '/admin/erp-xl?status=unlinked&search='.rawurlencode((string) $i->code),
                ])->values()->all(),
            default => throw new InvalidArgumentException('Nieznany rodzaj braków: '.$kind),
        };
    }

    /** @return Builder<covariant \Illuminate\Database\Eloquent\Model> */
    private function query(string $kind): Builder
    {
        $today = PolishTime::today()->toDateString();

        return match ($kind) {
            'tenders_without_time' => Tender::query()
                ->whereNotIn('status', self::TENDER_CLOSED)
                ->whereNotNull('deadline')
                ->whereDate('deadline', '>=', $today)
                ->whereNull('deadline_time'),
            'tenders_without_notice' => Tender::query()
                ->whereNotIn('status', self::NOTICE_SKIP_STATUSES)
                ->whereNull('result_status')
                ->where(static fn (Builder $q) => $q->whereNull('notice_number')->orWhere('notice_number', ''))
                ->where(static fn (Builder $q) => $q->whereNull('deadline')
                    ->orWhereDate('deadline', '>=', PolishTime::today()->subDays(self::NOTICE_LOOKBACK_DAYS)->toDateString())),
            'salespeople_without_operator' => User::query()
                ->whereHas('roles', static fn (Builder $q) => $q->where('name', self::SALESPERSON_ROLE))
                ->where(static fn (Builder $q) => $q->whereNull('erp_operator_ident')->orWhere('erp_operator_ident', '')),
            'clients_without_xl' => Client::query()
                ->whereNull('xl_gid')
                ->whereHas('tenders'),
            'sold_items_without_card' => ErpItem::query()
                ->whereNull('removed_at')
                ->where('archived', false)
                ->where(static fn (Builder $q) => $q->whereNull('match_outcome')->orWhereNotIn('match_outcome', ['auto', 'confirmed']))
                ->whereNotNull('last_sale_at')
                ->where('last_sale_at', '>=', now()->subMonths(self::SOLD_MONTHS)->toDateString()),
            default => throw new InvalidArgumentException('Nieznany rodzaj braków: '.$kind),
        };
    }
}
