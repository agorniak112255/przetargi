<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\User;
use App\Models\UserMailAccount;
use Illuminate\Support\Carbon;

/**
 * Treść maila kampanii (Blade emails/campaign + campaign-text) — ten sam HTML w podglądzie, teście i wysyłce.
 * Bez odbiorcy link wypisu to „#”, a zdjęcie i nazwa bez linku do strony produktu; u odbiorcy strona produktu idzie
 * przez aplikację (zapis kliknięcia, CampaignClickController). „Zapytaj o ofertę” to zawsze zwykły mailto — przez
 * przekierowanie przeglądarka otwierałaby kartę i pytała o zgodę na program pocztowy (sprawdzone 30.09.2026). Nie final — testy podmieniają zależności.
 */
class CampaignRenderer
{
    private const COLUMNS = ['grid3' => 3, 'grid2' => 2, 'list' => 1];

    public function __construct(private readonly CampaignItemPresenter $presenter) {}

    /**
     * @param  string|null  $notice  informacja na górze maila (kopia dla nadawcy, test) — klient jej nie dostaje
     * @return array{subject: string, html: string, text: string}
     */
    public function render(Campaign $campaign, ?CampaignRecipient $recipient = null, ?User $sender = null, ?string $notice = null): array
    {
        $author = $campaign->user;
        $sender ??= $author;
        /** @var UserMailAccount|null $account */
        $account = $sender?->mailAccount;
        $fromAddress = $account !== null ? (string) $account->from_address : '';
        $code = (string) $campaign->code;
        // po starcie wysyłki klient dostaje to, co zapisano w snapshotach — nie bieżący stan
        $useSnapshot = ! $campaign->isDraft();

        $items = $campaign->items()->get();
        $snapUnits = $items->pluck('snap_unit', 'id')->all();
        $publicUrl = rtrim((string) config('campaigns.public_url'), '/');
        $track = $recipient !== null && $publicUrl !== '' ? $publicUrl.'/api/k/'.$recipient->token : null;
        $products = [];
        foreach ($this->presenter->presentMany($items, $author) as $row) {
            $snap = $useSnapshot ? $row['snapshot'] : null;
            $name = $snap !== null ? $snap['name'] : $row['name'];
            if ($name === null || trim((string) $name) === '') {
                // pozycja bez towaru i karty (usunięte) — nie trafia do maila
                continue;
            }
            $itemCode = (string) ($snap !== null ? ($snap['code'] ?? '') : $row['code']);
            $price = $snap !== null ? $snap['price'] : $row['promo_price_net'];
            $before = $row['price_before_net'];
            $stock = $snap !== null ? $snap['stock'] : $row['stock'];
            $stockAt = $snap !== null ? $snap['stock_at'] : $row['stock_synced_at'];
            $unit = ($snap !== null ? ($snapUnits[$row['id']] ?? null) : null) ?? $row['unit'] ?? 'szt';

            $products[] = [
                'name' => (string) $name,
                'code' => $itemCode,
                'unit' => $unit,
                'price' => $price !== null ? $this->money((float) $price) : null,
                // cena „przed” tylko wpisana ręcznie i wyższa od ceny kampanii
                'price_before' => $price !== null && $before !== null && (float) $before > (float) $price ? $this->money((float) $before) : null,
                'stock' => $stock !== null && (float) $stock > 0
                    ? 'Na stanie: '.$this->quantity((float) $stock).' '.$unit.($stockAt !== null ? ' ('.Carbon::parse($stockAt)->format('d.m').')' : '')
                    : null,
                'image_url' => $snap !== null ? $snap['image_url'] : $row['image_url'],
                'note' => $row['note'] !== null && trim((string) $row['note']) !== '' ? (string) $row['note'] : null,
                'ask_url' => $fromAddress !== ''
                    ? 'mailto:'.$fromAddress.'?subject='.rawurlencode(trim('Zapytanie '.$code.' '.$itemCode))
                    : '#',
                'product_url' => $track !== null ? $track.'/p/'.$row['id'] : null,
            ];
        }

        $columns = self::COLUMNS[$campaign->layout] ?? 3;
        $rows = array_chunk($products, $columns);
        $company = (string) config('campaigns.company_name');
        $validUntil = $campaign->valid_until !== null
            ? 'Ceny netto ważne do '.$campaign->valid_until->format('d.m.Y').' lub do wyczerpania zapasów'
            : 'Ceny netto ważne do wyczerpania zapasów';

        $data = [
            'subject' => $this->line((string) $campaign->subject),
            'preheader' => $campaign->preheader !== null ? $this->line($campaign->preheader) : null,
            'heading' => $campaign->heading,
            'intro' => $campaign->intro,
            'layout' => array_key_exists((string) $campaign->layout, self::COLUMNS) ? (string) $campaign->layout : 'grid3',
            'columns' => $columns,
            'rows' => $rows,
            'products' => $products,
            'company' => $company,
            'tagline' => (string) config('campaigns.company_tagline'),
            'footerNote' => (string) config('campaigns.footer_note'),
            'validUntil' => $validUntil,
            'fromName' => $account !== null ? (string) $account->from_name : ($sender !== null ? (string) $sender->name : ''),
            'fromAddress' => $fromAddress,
            'signature' => $account?->signature !== null && trim((string) $account->signature) !== '' ? (string) $account->signature : null,
            'unsubscribeUrl' => $recipient !== null && $publicUrl !== '' ? $publicUrl.'/api/wypis/'.$recipient->token : '#',
            'notice' => $notice,
        ];

        return [
            'subject' => $data['subject'],
            'html' => view('emails.campaign', $data)->render(),
            'text' => view('emails.campaign-text', $data)->render(),
        ];
    }

    /** Temat i nagłówki w jednej linii — bez CR/LF. */
    private function line(string $value): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
    }

    private function money(float $value): string
    {
        // twarde spacje: cena nie łamie się w wąskiej kolumnie
        return number_format($value, 2, ',', "\u{00A0}")."\u{00A0}zł";
    }

    private function quantity(float $value): string
    {
        $decimals = abs($value - round($value)) < 0.0005 ? 0 : 3;
        $formatted = number_format($value, $decimals, ',', "\u{00A0}");

        return $decimals > 0 ? rtrim(rtrim($formatted, '0'), ',') : $formatted;
    }
}
