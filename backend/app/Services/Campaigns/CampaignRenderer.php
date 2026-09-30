<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignAsset;
use App\Models\CampaignRecipient;
use App\Models\User;
use App\Models\UserMailAccount;
use Illuminate\Support\Carbon;

/**
 * Treść maila kampanii (Blade emails/campaign + campaign-text) — ten sam HTML w podglądzie, teście i wysyłce.
 * Bez odbiorcy link wypisu to „#”, a zdjęcie i nazwa bez linku do strony produktu; u odbiorcy strona produktu idzie
 * przez aplikację (zapis kliknięcia, CampaignClickController). „Zapytaj o ofertę” to zawsze zwykły mailto — przez
 * przekierowanie przeglądarka otwierałaby kartę i pytała o zgodę na program pocztowy (sprawdzone 30.09.2026). Nie final — testy podmieniają zależności.
 *
 * Układ maila to bloki (CampaignBlocks) w zapisanej kolejności; puste i niekompletne bloki są pomijane (renderer nigdy
 * nie rzuca). Zawsze, niezależnie od bloków: „Ceny netto ważne…” nad produktami, podpis nadawcy po blokach i linia
 * wypisu na końcu.
 */
class CampaignRenderer
{
    private const COLUMNS = ['grid3' => 3, 'grid2' => 2, 'list' => 1];

    /** Szerokość treści maila (640) bez marginesów bocznych. */
    private const CONTENT_WIDTH = 592;

    private const LOGO_MAX_HEIGHT = 60;

    private const LOGO_MAX_WIDTH = 300;

    /** Kod kampanii w temacie „Zapytaj o ofertę” w podglądzie szablonu (bez kampanii). */
    private const SAMPLE_CODE = 'K-0000';

    public function __construct(private readonly CampaignItemPresenter $presenter) {}

    /**
     * @param  string|null  $notice  informacja na górze maila (kopia dla nadawcy, test) — klient jej nie dostaje
     * @return array{subject: string, html: string, text: string}
     */
    public function render(Campaign $campaign, ?CampaignRecipient $recipient = null, ?User $sender = null, ?string $notice = null): array
    {
        return $this->renderBlocks($campaign, $campaign->effectiveBlocks(), $campaign->brand_color, $recipient, $sender, $notice);
    }

    /**
     * Mail kampanii z podanymi blokami i kolorem (podgląd niezapisanego projektu) i prawdziwymi pozycjami.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array{subject: string, html: string, text: string}
     */
    public function renderBlocks(Campaign $campaign, array $blocks, ?string $brandColor, ?CampaignRecipient $recipient = null, ?User $sender = null, ?string $notice = null): array
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
        foreach ($author !== null ? $this->presenter->presentMany($items, $author) : [] as $row) {
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
                'ask_url' => $this->askUrl($fromAddress, $code, $itemCode),
                'product_url' => $track !== null ? $track.'/p/'.$row['id'] : null,
            ];
        }

        $validUntil = $campaign->valid_until !== null
            ? 'Ceny netto ważne do '.$campaign->valid_until->format('d.m.Y').' lub do wyczerpania zapasów'
            : 'Ceny netto ważne do wyczerpania zapasów';

        return $this->compose($blocks, $brandColor, $products, [
            'subject' => $this->line((string) $campaign->subject),
            'preheader' => $campaign->preheader !== null ? $this->line($campaign->preheader) : null,
            'validUntil' => $validUntil,
            'unsubscribeUrl' => $recipient !== null && $publicUrl !== '' ? $publicUrl.'/api/wypis/'.$recipient->token : '#',
            'notice' => $notice,
        ], $sender, $account);
    }

    /**
     * Podgląd szablonu: te same bloki z trzema przykładowymi produktami, bez kampanii i odbiorcy. Podpis i adres
     * „Zapytaj o ofertę” ze skrzynki oglądającego.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array{subject: string, html: string, text: string}
     */
    public function renderSample(array $blocks, ?string $brandColor, ?User $sender = null): array
    {
        /** @var UserMailAccount|null $account */
        $account = $sender?->mailAccount;
        $fromAddress = $account !== null ? (string) $account->from_address : '';
        $products = [];
        foreach ([1, 2, 3] as $i) {
            $products[] = [
                'name' => 'Przykładowy produkt '.$i,
                'code' => 'PRZYKLAD'.$i,
                'unit' => 'szt',
                'price' => $this->money(99),
                'price_before' => null,
                'stock' => null,
                'image_url' => null,
                'note' => null,
                'ask_url' => $this->askUrl($fromAddress, self::SAMPLE_CODE, 'PRZYKLAD'.$i),
                'product_url' => null,
            ];
        }

        $rendered = $this->compose($blocks, $brandColor, $products, [
            'subject' => '',
            'preheader' => null,
            'validUntil' => 'Ceny netto ważne do wyczerpania zapasów',
            'unsubscribeUrl' => '#',
            'notice' => null,
        ], $sender, $account);

        return ['subject' => '', 'html' => $rendered['html'], 'text' => $rendered['text']];
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $products
     * @param  array{subject: string, preheader: string|null, validUntil: string, unsubscribeUrl: string, notice: string|null}  $mail
     * @return array{subject: string, html: string, text: string}
     */
    private function compose(array $blocks, ?string $brandColor, array $products, array $mail, ?User $sender, ?UserMailAccount $account): array
    {
        $data = [
            ...$mail,
            'blocks' => $this->viewBlocks($blocks, $products),
            'products' => $products,
            'brand' => in_array($brandColor, CampaignBlocks::BRAND_COLORS, true) ? $brandColor : CampaignBlocks::DEFAULT_COLOR,
            'company' => (string) config('campaigns.company_name'),
            'tagline' => (string) config('campaigns.company_tagline'),
            'footerNote' => (string) config('campaigns.footer_note'),
            'fromName' => $account !== null ? (string) $account->from_name : ($sender !== null ? (string) $sender->name : ''),
            'fromAddress' => $account !== null ? (string) $account->from_address : '',
            'signature' => $account?->signature !== null && trim((string) $account->signature) !== '' ? (string) $account->signature : null,
        ];

        return [
            'subject' => $data['subject'],
            'html' => view('emails.campaign', $data)->render(),
            'text' => view('emails.campaign-text', $data)->render(),
        ];
    }

    /**
     * Bloki gotowe do widoku; puste i niekompletne pominięte. Kolejne nagłówki i teksty idą w jedną grupę „content”
     * (jedna komórka tabeli — odstępy jak w dawnym mailu z nagłówkiem i wstępem).
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function viewBlocks(array $blocks, array $products): array
    {
        $uuids = [];
        foreach ($blocks as $block) {
            foreach (['logo', 'asset'] as $field) {
                if (is_array($block) && is_string($block[$field] ?? null) && $block[$field] !== '') {
                    $uuids[] = $block[$field];
                }
            }
        }
        $assets = $uuids === [] ? collect() : CampaignAsset::query()->whereIn('uuid', array_unique($uuids))->get()->keyBy('uuid');

        $out = [];
        foreach ($blocks as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if ($type === 'header') {
                $logo = is_string($block['logo'] ?? null) ? $assets->get($block['logo']) : null;
                $scale = $logo !== null ? min(1, self::LOGO_MAX_HEIGHT / max(1, $logo->height), self::LOGO_MAX_WIDTH / max(1, $logo->width)) : 1;
                $out[] = [
                    'type' => 'header',
                    'logo_url' => $logo !== null ? CampaignAssetStore::url($logo->uuid) : null,
                    'logo_width' => $logo !== null ? max(1, (int) round($logo->width * $scale)) : null,
                    'logo_height' => $logo !== null ? max(1, (int) round($logo->height * $scale)) : null,
                ];
            } elseif ($type === 'heading' || $type === 'text') {
                $text = is_string($block['text'] ?? null) ? trim($block['text']) : '';
                if ($text === '') {
                    continue;
                }
                $part = ['type' => $type, 'text' => $type === 'heading' ? $this->line($text) : $text];
                $last = array_key_last($out);
                if ($last !== null && $out[$last]['type'] === 'content') {
                    $out[$last]['parts'][] = $part;
                } else {
                    $out[] = ['type' => 'content', 'parts' => [$part]];
                }
            } elseif ($type === 'image') {
                $asset = is_string($block['asset'] ?? null) ? $assets->get($block['asset']) : null;
                if ($asset === null) {
                    continue;
                }
                $width = min((int) $asset->width, self::CONTENT_WIDTH);
                $link = is_string($block['url'] ?? null) ? trim($block['url']) : '';
                $out[] = [
                    'type' => 'image',
                    'url' => CampaignAssetStore::url($asset->uuid),
                    'width' => max(1, $width),
                    'height' => max(1, (int) round($asset->height * $width / max(1, $asset->width))),
                    'alt' => is_string($block['alt'] ?? null) ? $this->line($block['alt']) : '',
                    // projekt zapisuje niedokończone adresy — w mailu tylko poprawny https://, inaczej obrazek bez linku
                    'link' => $link !== '' && CampaignBlocks::validUrl($link, false) ? $link : null,
                ];
            } elseif ($type === 'products') {
                $layout = is_string($block['layout'] ?? null) && isset(self::COLUMNS[$block['layout']]) ? $block['layout'] : 'grid3';
                $columns = self::COLUMNS[$layout];
                $out[] = ['type' => 'products', 'layout' => $layout, 'columns' => $columns, 'rows' => array_chunk($products, $columns), 'products' => $products];
            } elseif ($type === 'button') {
                $label = is_string($block['label'] ?? null) ? $this->line($block['label']) : '';
                $url = is_string($block['url'] ?? null) ? trim($block['url']) : '';
                // niedokończony albo niepoprawny adres (projekt zapisuje go w trakcie pisania) — przycisk pominięty
                if ($label !== '' && CampaignBlocks::validUrl($url, true)) {
                    $out[] = ['type' => 'button', 'label' => $label, 'url' => $url];
                }
            } elseif ($type === 'footer') {
                $text = is_string($block['text'] ?? null) ? trim($block['text']) : '';
                if ($text !== '') {
                    $out[] = ['type' => 'footer', 'text' => $text];
                }
            }
        }

        return $out;
    }

    private function askUrl(string $fromAddress, string $code, string $itemCode): string
    {
        return $fromAddress !== ''
            ? 'mailto:'.$fromAddress.'?subject='.rawurlencode(trim('Zapytanie '.$code.' '.$itemCode))
            : '#';
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
