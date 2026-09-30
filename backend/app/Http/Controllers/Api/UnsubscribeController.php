<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CampaignRecipient;
use App\Models\EmailSuppression;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Publiczny wypis z mailingu kampanii (link z maila, bez logowania). GET tylko pokazuje przycisk — skanery linków
 * w poczcie otwierają GET; wypisuje POST (przycisk albo List-Unsubscribe-Post z programu pocztowego, RFC 8058).
 * Zły token = ta sama ogólna strona, bez zdradzania, czy adres istnieje.
 */
class UnsubscribeController extends Controller
{
    public function show(string $token): Response
    {
        $recipient = $this->recipient($token);
        if ($recipient === null) {
            return $this->page('invalid', null, 404);
        }
        $suppressed = $recipient->unsubscribed_at !== null
            || EmailSuppression::query()->where('email', mb_strtolower($recipient->email))->exists();

        return $this->page($suppressed ? 'already' : 'confirm', $recipient);
    }

    /** Idempotentny: drugi POST niczego nie zmienia i odpowiada tak samo (200). */
    public function confirm(string $token): Response
    {
        $recipient = $this->recipient($token);
        if ($recipient === null) {
            return $this->page('invalid', null, 404);
        }

        DB::transaction(function () use ($recipient): void {
            $email = mb_strtolower($recipient->email);
            if (! EmailSuppression::query()->where('email', $email)->exists()) {
                try {
                    // savepoint: nieudany INSERT nie psuje transakcji
                    DB::transaction(static fn () => EmailSuppression::query()->create([
                        'email' => $email,
                        'reason' => EmailSuppression::REASON_UNSUBSCRIBE,
                        'campaign_id' => $recipient->campaign_id,
                        'note' => 'link w mailu kampanii',
                    ]));
                } catch (UniqueConstraintViolationException) {
                    // równoległy wypis (przycisk + List-Unsubscribe-Post z programu pocztowego) już wpisał adres — wynik
                    // ten sam; bez ponownego odczytu, który w MySQL wewnątrz transakcji nie widzi świeżego wiersza
                }
            }
            if ($recipient->unsubscribed_at === null) {
                $recipient->forceFill(['unsubscribed_at' => Carbon::now()])->save();
            }
        });

        return $this->page('done', $recipient);
    }

    private function recipient(string $token): ?CampaignRecipient
    {
        return strlen($token) === 40 ? CampaignRecipient::query()->where('token', $token)->first() : null;
    }

    private function page(string $state, ?CampaignRecipient $recipient, int $status = 200): Response
    {
        return response()
            ->view('campaigns.unsubscribe', [
                'state' => $state,
                'masked' => $recipient !== null ? self::mask((string) $recipient->email) : null,
                'company' => (string) config('campaigns.company_name'),
            ], $status)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    }

    /** j***@firma.pl — potwierdza właścicielowi adres, obcemu go nie zdradza. */
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($email, 0, 1).'***'.substr($email, $at);
    }
}
