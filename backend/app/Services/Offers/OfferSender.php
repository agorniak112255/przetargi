<?php

declare(strict_types=1);

namespace App\Services\Offers;

use App\Models\EmailSuppression;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\OfferRecipient;
use App\Models\OfferSend;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\Campaigns\CampaignSender;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Wysyłka oferty ze skrzynki autora od razu w żądaniu (najwyżej offers.max_recipients adresów): osobny mail do
 * każdego adresu, ten sam HTML zapisany przed wysyłką (OfferSend), wynik każdego adresu (OfferRecipient), na końcu
 * kopia „[Kopia]” do nadawcy. Błąd skrzynki nadawcy przerywa wysyłkę — reszta adresów „pominięta”, skrzynka
 * odpoczywa jak w kampaniach (CampaignSender::pause). Błędy walidacji: ValidationException (422). Nie final.
 */
class OfferSender
{
    /**
     * Blokada jednej oferty — dwa kliknięcia „Wyślij” nie wyślą klientom dwóch maili. Dłuższa niż set_time_limit:
     * na Linuksie limit czasu PHP nie liczy czekania na serwer poczty, więc żądanie może trwać dłużej niż 120 s.
     */
    private const LOCK_SECONDS = 600;

    public const SKIPPED_SENDER_ERROR = 'Nie wysłano — błąd skrzynki nadawcy';

    private const SUPPRESSION_REASONS = [
        EmailSuppression::REASON_UNSUBSCRIBE => 'klient wypisał się z mailingu',
        EmailSuppression::REASON_BOUNCE => 'adres nie istnieje (wrócił zwrot poczty)',
        EmailSuppression::REASON_MANUAL => 'adres dopisany do listy wykluczonych',
    ];

    public function __construct(
        private readonly OfferRenderer $renderer,
        private readonly CampaignSender $campaigns,
    ) {}

    /**
     * Pozycje, które trafią do maila (z towarem XL albo kartą) — pozycja bez obu (dane usunięte) nie idzie do klienta.
     *
     * @param  Collection<int, OfferItem>  $items
     * @return Collection<int, OfferItem>
     */
    public static function mailItems(Collection $items): Collection
    {
        return $items->filter(static fn (OfferItem $i): bool => $i->erp_item_id !== null || $i->product_id !== null)->values();
    }

    /**
     * Id pozycji w mailu bez ceny — wysłać i skopiować można dopiero po ich uzupełnieniu.
     *
     * @param  Collection<int, OfferItem>  $items
     * @return list<int>
     */
    public static function missingPrices(Collection $items): array
    {
        return self::mailItems($items)->filter(static fn (OfferItem $i): bool => $i->price_net === null)
            ->map(static fn (OfferItem $i): int => (int) $i->id)->values()->all();
    }

    /**
     * @param  list<string>  $emails
     * @return array{send: OfferSend, results: list<array{email: string, status: string, error: string|null}>}
     */
    public function send(Offer $offer, User $actor, array $emails): array
    {
        @set_time_limit(120);
        $lock = Cache::lock('offer-send:'.$offer->id, self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['offer' => ['Wysyłka tej oferty już trwa — poczekaj na jej wynik.']]);
        }
        try {
            return $this->sendLocked($offer->fresh() ?? $offer, $actor, $emails);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<string>  $emails
     * @return array{send: OfferSend, results: list<array{email: string, status: string, error: string|null}>}
     */
    private function sendLocked(Offer $offer, User $actor, array $emails): array
    {
        if ((int) $offer->user_id !== (int) $actor->id) {
            // nadawcą jest skrzynka autora — nikt inny nie wysyła w jego imieniu
            throw ValidationException::withMessages(['offer' => ['Wysłać ofertę może tylko jej autor.']]);
        }
        $emails = $this->validEmails($emails);

        $items = $offer->items()->get();
        if (self::mailItems($items)->isEmpty()) {
            throw ValidationException::withMessages(['items' => ['Dodaj do oferty co najmniej jeden produkt.']]);
        }
        $missing = self::missingPrices($items);
        if ($missing !== []) {
            throw ValidationException::withMessages(['items' => [
                count($missing) === 1
                    ? 'Jedna pozycja nie ma ceny — uzupełnij ją przed wysyłką.'
                    : 'Pozycje bez ceny: '.count($missing).' — uzupełnij je przed wysyłką.',
            ]]);
        }
        if (trim((string) $offer->subject) === '') {
            throw ValidationException::withMessages(['subject' => ['Wpisz temat maila.']]);
        }
        // oferta jest zawsze edytowalna i wysyłana kolejnym osobom — stara data ważności nie może dojść do klienta
        if ($offer->valid_until !== null && $offer->valid_until->lt(Carbon::today())) {
            throw ValidationException::withMessages(['valid_until' => [
                'Data „Oferta ważna do” ('.$offer->valid_until->format('d.m.Y').') już minęła — zmień ją albo wyczyść pole.',
            ]]);
        }
        $this->assertNotSuppressed($emails);

        // konto czytane świeżo — handlowiec mógł właśnie poprawić hasło
        $account = UserMailAccount::query()->where('user_id', $offer->user_id)->first();
        if ($account === null) {
            throw ValidationException::withMessages(['offer' => ['Nie ustawiono skrzynki w „Moje konto → Moja poczta” — oferta wychodzi z Twojej skrzynki.']]);
        }
        if (CampaignSender::isPaused((int) $offer->user_id)) {
            throw ValidationException::withMessages(['offer' => [
                'Skrzynka nadawcy odpoczywa po błędzie serwera poczty (do '.CampaignSender::PAUSE_MINUTES.' minut) — spróbuj za chwilę. Błąd: '
                .(string) Cache::get(CampaignSender::pauseKey((int) $offer->user_id)),
            ]]);
        }
        if (rtrim((string) config('campaigns.public_url'), '/') === '') {
            throw ValidationException::withMessages(['offer' => ['Brak publicznego adresu aplikacji (CAMPAIGNS_PUBLIC_URL) — zdjęcia i baner nie dojdą do klienta.']]);
        }

        // jeden render dla wszystkich adresów; zapis przed wysyłką — nawet przerwane żądanie zostawia ślad, co wyszło
        $mail = $this->renderer->render($offer, $actor);
        $send = OfferSend::query()->create([
            'offer_id' => $offer->id,
            'subject' => mb_substr($mail['subject'], 0, 255),
            'html' => $mail['html'],
            'text' => $mail['text'],
        ]);

        $results = [];
        $senderError = null;
        foreach ($emails as $email) {
            if ($senderError !== null) {
                $results[] = $this->record($offer, $send, $email, OfferRecipient::STATUS_SKIPPED, self::SKIPPED_SENDER_ERROR);

                continue;
            }
            try {
                // osobny mail na adres, bez nagłówków wypisu — to oferta do klienta, nie mailing
                $messageId = $this->campaigns->deliver($account, $email, null, $mail['subject'], $mail['html'], $mail['text']);
            } catch (Throwable $e) {
                $error = $this->campaigns->errorText($e, $account);
                if ($this->campaigns->isSenderError($e)) {
                    // połączenie, logowanie, odmowa serwera — kolejne adresy skończyłyby się tak samo
                    $senderError = $error;
                    $this->campaigns->pause((int) $offer->user_id, $account, $error);
                }
                $results[] = $this->record($offer, $send, $email, OfferRecipient::STATUS_FAILED, $error);

                continue;
            }
            $results[] = $this->record($offer, $send, $email, OfferRecipient::STATUS_SENT, null, $messageId);
        }

        $sent = array_values(array_filter($results, static fn (array $r): bool => $r['status'] === OfferRecipient::STATUS_SENT));
        if ($sent !== []) {
            // bez updated_at — „Zmieniona” na liście to zmiana treści, nie wysyłka
            $offer->timestamps = false;
            $offer->forceFill(['last_sent_at' => Carbon::now()])->save();
            $offer->timestamps = true;
            if ($senderError === null) {
                $this->copyToSender($offer, $actor, $account, array_column($sent, 'email'));
            }
        }

        return ['send' => $send, 'results' => $results];
    }

    /**
     * Adresy z żądania: 1..max_recipients, poprawne (bez CR/LF), bez powtórzeń (wielkość liter bez znaczenia).
     *
     * @param  list<string>  $emails
     * @return list<string>
     */
    private function validEmails(array $emails): array
    {
        $max = (int) config('offers.max_recipients');
        $out = [];
        $seen = [];
        foreach ($emails as $email) {
            $email = trim((string) $email);
            if ($email === '') {
                continue;
            }
            if (preg_match('/[\r\n]/', $email) === 1 || mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages(['emails' => ['Niepoprawny adres e-mail: '.preg_replace('/[\r\n]+/', ' ', $email).'.']]);
            }
            $key = mb_strtolower($email);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['emails' => ['Adres '.$email.' podano dwa razy.']]);
            }
            $seen[$key] = true;
            $out[] = $email;
        }
        if ($out === []) {
            throw ValidationException::withMessages(['emails' => ['Wpisz co najmniej jeden adres e-mail.']]);
        }
        if (count($out) > $max) {
            throw ValidationException::withMessages(['emails' => ["Jedna wysyłka może mieć najwyżej {$max} adresów (wpisano: ".count($out).').']]);
        }

        return $out;
    }

    /**
     * Adresy z listy wypisanych (rezygnacja, zwrot poczty, wpis ręczny) blokują wysyłkę — handlowiec decyduje,
     * czy usunąć adres z oferty, czy wysłać ze swojego programu pocztowego.
     *
     * @param  list<string>  $emails
     */
    private function assertNotSuppressed(array $emails): void
    {
        $lower = array_map('mb_strtolower', $emails);
        $suppressed = EmailSuppression::query()->whereIn('email', $lower)->get(['email', 'reason'])->keyBy('email');
        if ($suppressed->isEmpty()) {
            return;
        }
        $messages = [];
        foreach ($emails as $email) {
            $row = $suppressed->get(mb_strtolower($email));
            if ($row !== null) {
                $messages[] = 'Adres '.$email.' jest na liście wypisanych z mailingu ('
                    .(self::SUPPRESSION_REASONS[$row->reason] ?? 'wpis na liście wykluczonych').') — usuń go z adresów.';
            }
        }
        throw ValidationException::withMessages(['emails' => $messages]);
    }

    /** @return array{email: string, status: string, error: string|null} */
    private function record(Offer $offer, OfferSend $send, string $email, string $status, ?string $error, ?string $messageId = null): array
    {
        OfferRecipient::query()->create([
            'offer_id' => $offer->id,
            'offer_send_id' => $send->id,
            'email' => $email,
            'status' => $status,
            'error' => $error !== null ? mb_substr($error, 0, 500) : null,
            'message_id' => $messageId !== null ? mb_substr($messageId, 0, 255) : null,
            'sent_at' => $status === OfferRecipient::STATUS_SENT ? Carbon::now() : null,
        ]);

        return ['email' => $email, 'status' => $status, 'error' => $error];
    }

    /**
     * Kopia dla nadawcy z listą adresów, do których wyszło — jej błąd nie zmienia wyników wysyłki.
     *
     * @param  list<string>  $sentTo
     */
    private function copyToSender(Offer $offer, User $actor, UserMailAccount $account, array $sentTo): void
    {
        try {
            $copy = $this->renderer->render($offer, $actor, 'Kopia dla Ciebie — wysłano do: '.implode(', ', $sentTo));
            $this->campaigns->deliver($account, (string) $account->from_address, (string) $account->from_name, '[Kopia] '.$copy['subject'], $copy['html'], $copy['text']);
        } catch (Throwable $e) {
            Log::warning('Oferta: kopia do nadawcy nie wyszła', ['offer' => $offer->id, 'error' => $this->campaigns->errorText($e, $account)]);
        }
    }
}
