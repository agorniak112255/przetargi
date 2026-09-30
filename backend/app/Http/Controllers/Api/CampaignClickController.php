<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignClick;
use App\Models\CampaignItem;
use App\Models\CampaignRecipient;
use App\Models\Product;
use App\Services\Campaigns\CampaignItemPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Publiczne linki z maila kampanii (bez logowania): zapis kliknięcia i przejście dalej — „Zapytaj o ofertę” (mail do
 * handlowca) albo strona produktu. Cel wynika z pozycji kampanii w bazie, nigdy z adresu (bez otwartego
 * przekierowania). Zły token albo pozycja z innej kampanii = ta sama ogólna strona 404.
 */
class CampaignClickController extends Controller
{
    /** Kliknięcie szybciej niż tyle sekund po wysłaniu = skaner poczty (Outlook, antywirus), nie człowiek. */
    private const BOT_SECONDS = 60;

    private const BOT_AGENT = '/bot|crawl|spider|scan|preview|safelinks|barracuda|mimecast|proofpoint|urldefense|symantec|trendmicro|headless|python|curl|wget|go-http|java\/|libwww|okhttp|existence discovery/i';

    /** Najwyżej tyle znaków opisu karty na stronie produktu. */
    private const DESCRIPTION_CHARS = 600;

    public function __construct(private readonly CampaignItemPresenter $presenter) {}

    public function offer(Request $request, string $token, int $item): RedirectResponse|Response
    {
        [$recipient, $campaignItem] = $this->resolve($token, $item);
        if ($recipient === null || $campaignItem === null) {
            return $this->notFound();
        }
        $this->record($request, $recipient, $campaignItem, CampaignClick::KIND_OFFER);

        $mailto = $this->mailto($recipient->campaign, $campaignItem);
        if ($mailto === null) {
            // skrzynka autora usunięta — zostaje strona produktu z danymi kontaktowymi firmy
            return $this->productPage($recipient, $campaignItem);
        }

        return redirect()->away($mailto)->header('Referrer-Policy', 'no-referrer');
    }

    public function product(Request $request, string $token, int $item): Response
    {
        [$recipient, $campaignItem] = $this->resolve($token, $item);
        if ($recipient === null || $campaignItem === null) {
            return $this->notFound();
        }
        $this->record($request, $recipient, $campaignItem, CampaignClick::KIND_PRODUCT);

        return $this->productPage($recipient, $campaignItem);
    }

    /** @return array{0: CampaignRecipient|null, 1: CampaignItem|null} */
    private function resolve(string $token, int $itemId): array
    {
        $recipient = strlen($token) === 40 ? CampaignRecipient::query()->with('campaign.user.mailAccount')->where('token', $token)->first() : null;
        if ($recipient === null || $recipient->campaign === null) {
            return [null, null];
        }
        $item = CampaignItem::query()->whereKey($itemId)->where('campaign_id', $recipient->campaign_id)->first();

        return [$recipient, $item];
    }

    /** Zapis kliknięcia; licznik odbiorcy tylko dla kliknięć ludzi (nie skanerów poczty). */
    private function record(Request $request, CampaignRecipient $recipient, CampaignItem $item, string $kind): void
    {
        $now = Carbon::now();
        $agent = mb_substr((string) $request->userAgent(), 0, 255);
        $bot = $request->isMethod('HEAD')
            || ($agent !== '' && preg_match(self::BOT_AGENT, $agent) === 1)
            || ($recipient->sent_at !== null && $recipient->sent_at->diffInSeconds($now, true) < self::BOT_SECONDS);

        CampaignClick::query()->create([
            'campaign_id' => $recipient->campaign_id,
            'campaign_recipient_id' => $recipient->id,
            'campaign_item_id' => $item->id,
            'kind' => $kind,
            'suspected_bot' => $bot,
            'user_agent' => $agent !== '' ? $agent : null,
            'clicked_at' => $now,
        ]);
        if (! $bot) {
            // jedno UPDATE — równoległe kliknięcia nie gubią licznika
            DB::table('campaign_recipients')->where('id', $recipient->id)->update([
                'clicks' => DB::raw('clicks + 1'),
                'first_clicked_at' => DB::raw('coalesce(first_clicked_at, '.DB::getPdo()->quote($now->toDateTimeString()).')'),
                'updated_at' => $now,
            ]);
        }
    }

    private function mailto(?Campaign $campaign, CampaignItem $item): ?string
    {
        $address = (string) ($campaign?->user?->mailAccount?->from_address ?? '');
        if ($campaign === null || $address === '') {
            return null;
        }
        $code = (string) ($item->snap_code ?? $item->erpItem?->code ?? '');

        return 'mailto:'.$address.'?subject='.rawurlencode(trim('Zapytanie '.$campaign->code.' '.$code));
    }

    private function productPage(CampaignRecipient $recipient, CampaignItem $item): Response
    {
        $campaign = $recipient->campaign;
        $author = $campaign->user;
        $row = $author !== null ? $this->presenter->present($item, $author) : null;
        $snap = $row['snapshot'] ?? null;
        $account = $author?->mailAccount;
        $cardId = $row['card']['id'] ?? null;
        $description = $cardId !== null ? (string) (Product::query()->whereKey($cardId)->value('description') ?? '') : '';
        $description = trim((string) preg_replace('/\s+/u', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], ' ', $description))));

        $price = $snap['price'] ?? $row['promo_price_net'] ?? null;
        $before = $row['price_before_net'] ?? null;
        $stock = $snap['stock'] ?? $row['stock'] ?? null;
        $stockAt = $snap['stock_at'] ?? $row['stock_synced_at'] ?? null;
        $unit = $item->snap_unit ?? $row['unit'] ?? 'szt';
        $publicUrl = rtrim((string) config('campaigns.public_url'), '/');

        return response()
            ->view('campaigns.product', [
                'company' => (string) config('campaigns.company_name'),
                'tagline' => (string) config('campaigns.company_tagline'),
                'name' => (string) ($snap['name'] ?? $row['name'] ?? $item->snap_name ?? ''),
                'code' => (string) ($snap['code'] ?? $row['code'] ?? ''),
                'image' => $snap['image_url'] ?? $row['image_url'] ?? null,
                'price' => $price !== null ? $this->money((float) $price) : null,
                'priceBefore' => $price !== null && $before !== null && (float) $before > (float) $price ? $this->money((float) $before) : null,
                'unit' => (string) $unit,
                'stock' => $stock !== null && (float) $stock > 0
                    ? $this->quantity((float) $stock).' '.$unit.($stockAt !== null ? ' (stan z '.Carbon::parse($stockAt)->format('d.m.Y').')' : '')
                    : null,
                'note' => $item->note,
                'description' => $description !== '' ? Str::limit($description, self::DESCRIPTION_CHARS) : null,
                'validUntil' => $campaign->valid_until?->format('d.m.Y'),
                'askUrl' => $this->mailto($campaign, $item) !== null && $publicUrl !== ''
                    ? $publicUrl.'/api/k/'.$recipient->token.'/o/'.$item->id
                    : null,
                'fromName' => $account !== null ? (string) $account->from_name : (string) ($author?->name ?? ''),
                'fromAddress' => $account !== null ? (string) $account->from_address : null,
                'signature' => $account?->signature,
                'unsubscribeUrl' => $publicUrl !== '' ? $publicUrl.'/api/wypis/'.$recipient->token : null,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }

    private function notFound(): Response
    {
        return response()
            ->view('campaigns.product', ['company' => (string) config('campaigns.company_name'), 'missing' => true], 404)
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function money(float $value): string
    {
        return number_format($value, 2, ',', "\u{00A0}")."\u{00A0}zł";
    }

    private function quantity(float $value): string
    {
        $decimals = abs($value - round($value)) < 0.0005 ? 0 : 3;
        $formatted = number_format($value, $decimals, ',', "\u{00A0}");

        return $decimals > 0 ? rtrim(rtrim($formatted, '0'), ',') : $formatted;
    }
}
