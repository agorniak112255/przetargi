<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\CampaignRecipient;
use App\Models\EmailSuppression;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Notifications\CampaignScheduleFailedNotification;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Throwable;

/**
 * Wysyłka kampanii ze skrzynki autora: test, start (snapshoty + odbiorcy), planowanie i start o czasie, pojedynczy
 * odbiorca (dla campaigns:dispatch), anulowanie i test samej skrzynki. Błędy walidacji startu: \Illuminate\Validation\ValidationException (422).
 * Nie final — testy podmieniają zależności.
 */
class CampaignSender
{
    /** Po błędzie połączenia/logowania skrzynka nadawcy odpoczywa tyle minut (campaigns:dispatch ją pomija). */
    public const PAUSE_MINUTES = 15;

    public function __construct(
        private readonly UserMailerFactory $mailers,
        private readonly CampaignRenderer $renderer,
        private readonly AudienceResolver $audience,
        private readonly CampaignItemPresenter $presenter,
    ) {}

    public static function pauseKey(int $userId): string
    {
        return 'campaigns.pause.'.$userId;
    }

    public static function isPaused(int $userId): bool
    {
        return Cache::has(self::pauseKey($userId));
    }

    /** Mail testowy na wskazany adres (bez wypisu i bez zapisu odbiorcy). */
    public function sendTest(Campaign $campaign, string $to): void
    {
        $account = $this->accountOf($campaign);
        if ($account === null) {
            throw $this->invalid('Nie ustawiono skrzynki w „Moje konto → Moja poczta”.');
        }
        $mail = $this->renderer->render($campaign, null, $campaign->user);
        try {
            $this->deliver($account, $to, null, '[TEST] '.$mail['subject'], $mail['html'], $mail['text']);
        } catch (Throwable $e) {
            throw $this->invalid('Nie udało się wysłać maila testowego: '.$this->errorText($e, $account));
        }
    }

    /**
     * Start wysyłki: blokada kampanii, ponowne sprawdzenie statusu draft, walidacja (pozycje, temat, skrzynka autora,
     * public_url, odbiorcy > 0), snapshoty pozycji, odbiorcy, status sending. Kopia do nadawcy po commit.
     */
    public function start(Campaign $campaign, User $actor): Campaign
    {
        $this->assertAuthor($campaign, $actor, 'Wysłać kampanię może tylko jej autor.');
        $this->assertPublicUrl();

        $campaign = DB::transaction(function () use ($campaign): Campaign {
            /** @var Campaign $locked */
            $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            // zaplanowaną startuje campaigns:dispatch o jej godzinie
            if (! in_array($locked->status, [Campaign::STATUS_DRAFT, Campaign::STATUS_SCHEDULED], true)) {
                throw $this->invalid('Kampania została już wysłana — zduplikuj ją, żeby zmienić.');
            }
            [$items, $author] = $this->assertReady($locked);

            // co dostał klient: zapis pozycji w chwili startu (późniejsze zmiany kart i stanów nie zmieniają historii)
            foreach ($this->presenter->presentMany($items, $author) as $i => $row) {
                /** @var CampaignItem $item */
                $item = $items[$i];
                $item->forceFill([
                    'snap_name' => mb_substr($row['name'], 0, 300),
                    'snap_code' => mb_substr($row['code'], 0, 60),
                    'snap_unit' => $row['unit'] !== null ? mb_substr($row['unit'], 0, 20) : null,
                    'snap_price' => $row['promo_price_net'],
                    'snap_stock' => $row['stock'],
                    'snap_stock_at' => $row['stock_synced_at'] !== null ? Carbon::parse($row['stock_synced_at'])->toDateString() : null,
                    'snap_image_url' => $row['image_url'] !== null && strlen($row['image_url']) <= 500 ? $row['image_url'] : null,
                ])->save();
            }

            $count = $this->audience->materialize($locked);
            if ($count === 0) {
                throw $this->invalid('Kampania nie ma odbiorców — wybierz grupę albo klientów z ERP XL.');
            }
            $locked->forceFill([
                'status' => Campaign::STATUS_SENDING,
                'schedule_error' => null,
                'sending_started_at' => Carbon::now(),
                'totals' => ['recipients' => $count, 'sent' => 0, 'failed' => 0, 'skipped' => 0],
            ])->save();

            return $locked;
        });

        // kopia do nadawcy dopiero po zapisie — jej błąd nie cofa startu
        $account = $this->accountOf($campaign);
        if ($account !== null && $account->copy_to_self) {
            try {
                $mail = $this->renderer->render($campaign, null, $campaign->user);
                $this->deliver($account, (string) $account->from_address, (string) $account->from_name, '[Kopia] '.$mail['subject'], $mail['html'], $mail['text']);
            } catch (Throwable $e) {
                Log::warning('Kampania: kopia do nadawcy nie wyszła', ['campaign' => $campaign->id, 'error' => $this->errorText($e, $account)]);
            }
        }

        return $campaign->fresh() ?? $campaign;
    }

    /**
     * Planowanie: te same warunki co start (pozycje, temat, skrzynka, publiczny adres) i co najmniej jeden odbiorca
     * teraz. Odbiorców, snapshoty i stany wylicza dopiero start o tej godzinie (świeże wypisy i odczyt XL).
     */
    public function schedule(Campaign $campaign, User $actor, Carbon $at): Campaign
    {
        $this->assertAuthor($campaign, $actor, 'Zaplanować kampanię może tylko jej autor — wyjdzie z jego skrzynki.');
        $this->assertPublicUrl();

        $campaign = DB::transaction(function () use ($campaign, $at): Campaign {
            /** @var Campaign $locked */
            $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isDraft()) {
                throw $this->invalid('Zaplanować można tylko projekt kampanii.');
            }
            $this->assertReady($locked);
            if ((int) $this->audience->preview($locked)['final'] === 0) {
                throw $this->invalid('Kampania nie ma odbiorców — wybierz grupę albo klientów z ERP XL.');
            }
            $locked->forceFill(['status' => Campaign::STATUS_SCHEDULED, 'scheduled_at' => $at, 'schedule_error' => null])->save();

            return $locked;
        });

        return $campaign->fresh() ?? $campaign;
    }

    /** Cofnięcie planowania: zaplanowana → projekt (do zmian albo innej godziny). */
    public function unschedule(Campaign $campaign): Campaign
    {
        $campaign = DB::transaction(function () use ($campaign): Campaign {
            /** @var Campaign $locked */
            $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Campaign::STATUS_SCHEDULED) {
                throw $this->invalid('Kampania nie jest zaplanowana — mogła już wystartować.');
            }
            $locked->forceFill(['status' => Campaign::STATUS_DRAFT, 'scheduled_at' => null])->save();

            return $locked;
        });

        return $campaign->fresh() ?? $campaign;
    }

    /**
     * Start zaplanowanych kampanii, których godzina minęła (campaigns:dispatch co minutę). Gdy start się nie uda
     * (np. usunięta skrzynka, zero odbiorców), kampania wraca do projektu z powodem, a autor dostaje powiadomienie.
     *
     * @return array{started: int, failed: int}
     */
    public function startDue(): array
    {
        $stats = ['started' => 0, 'failed' => 0];
        $due = Campaign::query()->where('status', Campaign::STATUS_SCHEDULED)->where('scheduled_at', '<=', Carbon::now())
            ->orderBy('scheduled_at')->orderBy('id')->get();
        foreach ($due as $campaign) {
            $author = $campaign->user;
            try {
                if ($author === null) {
                    throw $this->invalid('Autor kampanii nie istnieje.');
                }
                $this->start($campaign, $author);
                $stats['started']++;
            } catch (Throwable $e) {
                if (! $e instanceof ValidationException) {
                    report($e);
                }
                $reason = $e instanceof ValidationException
                    ? implode(' ', array_merge(...array_values($e->errors())))
                    : 'Nieoczekiwany błąd: '.mb_substr($e->getMessage(), 0, 300);
                $reverted = Campaign::query()->whereKey($campaign->id)->where('status', Campaign::STATUS_SCHEDULED)
                    ->update(['status' => Campaign::STATUS_DRAFT, 'schedule_error' => mb_substr($reason, 0, 1000), 'updated_at' => Carbon::now()]);
                if ($reverted === 1) {
                    $stats['failed']++;
                    $author?->notify(new CampaignScheduleFailedNotification($campaign, $reason));
                }
            }
        }

        return $stats;
    }

    /** Anulowanie: sending → cancelled, odbiorcy pending → skipped. */
    public function cancel(Campaign $campaign): Campaign
    {
        $campaign = DB::transaction(function () use ($campaign): Campaign {
            /** @var Campaign $locked */
            $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Campaign::STATUS_SENDING) {
                throw $this->invalid('Anulować można tylko kampanię w trakcie wysyłki.');
            }
            CampaignRecipient::query()
                ->where('campaign_id', $locked->id)
                ->where('status', CampaignRecipient::STATUS_PENDING)
                ->update(['status' => CampaignRecipient::STATUS_SKIPPED, 'error' => 'kampania anulowana', 'updated_at' => Carbon::now()]);
            $locked->forceFill(['status' => Campaign::STATUS_CANCELLED, 'totals' => self::totals($locked)])->save();

            return $locked;
        });

        return $campaign->fresh() ?? $campaign;
    }

    /** Wysyła do jednego zarezerwowanego odbiorcy (status sending) i zapisuje wynik. */
    public function sendOne(CampaignRecipient $recipient): void
    {
        $recipient->refresh();
        if ($recipient->status !== CampaignRecipient::STATUS_SENDING) {
            return;
        }
        $campaign = Campaign::query()->find($recipient->campaign_id);
        if ($campaign === null || $campaign->status !== Campaign::STATUS_SENDING) {
            $this->finish($recipient, CampaignRecipient::STATUS_SKIPPED, 'kampania anulowana');

            return;
        }
        if (EmailSuppression::query()->where('email', mb_strtolower($recipient->email))->exists()) {
            $this->finish($recipient, CampaignRecipient::STATUS_SKIPPED, 'adres wypisany z mailingu');

            return;
        }
        // dwie kampanie w wysyłce naraz (np. dwóch handlowców tego samego dnia): druga nie wysyła do adresu,
        // który w oknie limitu dostał już inną kampanię
        $capDays = (int) config('campaigns.frequency_cap_days', 14);
        if ($capDays > 0 && CampaignRecipient::query()
            ->where('email', mb_strtolower($recipient->email))
            ->where('campaign_id', '!=', $recipient->campaign_id)
            ->where('status', CampaignRecipient::STATUS_SENT)
            ->where('sent_at', '>=', Carbon::now()->subDays($capDays))
            ->exists()) {
            $this->finish($recipient, CampaignRecipient::STATUS_SKIPPED, 'limit częstotliwości');

            return;
        }
        // konto czytane świeżo — handlowiec mógł poprawić hasło w trakcie wysyłki
        $account = UserMailAccount::query()->where('user_id', $campaign->user_id)->first();
        if ($account === null) {
            $this->pauseSender($recipient, null, (int) $campaign->user_id, 'Brak skrzynki nadawcy (Moja poczta).');

            return;
        }

        try {
            $mail = $this->renderer->render($campaign, $recipient, $campaign->user);
            $url = rtrim((string) config('campaigns.public_url'), '/').'/api/wypis/'.$recipient->token;
            $sent = $this->deliver($account, (string) $recipient->email, $recipient->name, $mail['subject'], $mail['html'], $mail['text'], $url);
        } catch (Throwable $e) {
            if ($this->isSenderError($e)) {
                $this->pauseSender($recipient, $account, (int) $campaign->user_id, $this->errorText($e, $account));
            } else {
                $attempts = (int) $recipient->attempts + 1;
                $this->finish(
                    $recipient,
                    $attempts >= (int) config('campaigns.max_attempts', 3) ? CampaignRecipient::STATUS_FAILED : CampaignRecipient::STATUS_PENDING,
                    $this->errorText($e, $account),
                    ['attempts' => $attempts],
                );
            }

            return;
        }

        $this->finish($recipient, CampaignRecipient::STATUS_SENT, null, [
            'sent_at' => Carbon::now(),
            'message_id' => $sent !== null ? mb_substr($sent, 0, 255) : null,
        ]);
    }

    /**
     * Test skrzynki: krótki mail na from_address; ustawia verified_at albo last_error.
     *
     * @return array{ok: bool, message: string}
     */
    public function testAccount(UserMailAccount $account): array
    {
        $to = (string) $account->from_address;
        $text = "To jest test skrzynki, z której będą wychodzić Twoje kampanie.\nJeśli widzisz tę wiadomość, wysyłka działa.";
        try {
            $this->deliver($account, $to, (string) $account->from_name, 'Test skrzynki — kampanie', nl2br(e($text), false), $text);
        } catch (Throwable $e) {
            $error = $this->errorText($e, $account);
            $account->forceFill(['last_error' => $error])->save();

            return ['ok' => false, 'message' => 'Nie udało się wysłać: '.$error];
        }
        $account->forceFill(['verified_at' => Carbon::now(), 'last_error' => null])->save();

        return ['ok' => true, 'message' => 'Wysłano wiadomość testową na '.$to.'.'];
    }

    /**
     * Liczniki odbiorców kampanii (sending liczy się jako pending — wynik jeszcze nieznany).
     *
     * @return array{recipients: int, sent: int, failed: int, skipped: int}
     */
    public static function totals(Campaign $campaign): array
    {
        $counts = CampaignRecipient::query()->where('campaign_id', $campaign->id)
            ->groupBy('status')->selectRaw('status, count(*) as c')->pluck('c', 'status');

        return [
            'recipients' => (int) $counts->sum(),
            'sent' => (int) ($counts[CampaignRecipient::STATUS_SENT] ?? 0),
            'failed' => (int) ($counts[CampaignRecipient::STATUS_FAILED] ?? 0),
            'skipped' => (int) ($counts[CampaignRecipient::STATUS_SKIPPED] ?? 0),
        ];
    }

    /**
     * Wysyłka jednej wiadomości (HTML + tekst); zwraca Message-ID albo null.
     */
    private function deliver(UserMailAccount $account, string $to, ?string $toName, string $subject, string $html, string $text, ?string $unsubscribeUrl = null): ?string
    {
        $mailer = $this->mailers->make($account);
        $from = (string) $account->from_address;
        $sent = $mailer->send(
            ['html' => new HtmlString($html), 'text' => new HtmlString($text)],
            [],
            static function (Message $m) use ($account, $from, $to, $toName, $subject, $unsubscribeUrl): void {
                $m->from($from, (string) $account->from_name)->to($to, $toName)->subject($subject);
                if ($unsubscribeUrl !== null) {
                    // RFC 8058: wypis jednym kliknięciem z poziomu programu pocztowego (Gmail, Outlook)
                    $headers = $m->getSymfonyMessage()->getHeaders();
                    $headers->addTextHeader('List-Unsubscribe', '<'.$unsubscribeUrl.'>, <mailto:'.$from.'?subject=wypis>');
                    $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                }
            },
        );

        $id = $sent?->getMessageId();

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Błąd skrzynki nadawcy (połączenie, TLS, logowanie, odrzucony nadawca, nieczytelne hasło, każda odmowa 4xx) — nie
     * adresata. Adresat: trwałe odrzucenie (5xx) przy RCPT TO albo po treści wiadomości, zły format adresu.
     */
    protected function isSenderError(Throwable $e): bool
    {
        if ($e instanceof DecryptException) {
            return true;
        }
        if ($e instanceof RfcComplianceException) {
            return false;
        }
        if ($e instanceof UnexpectedResponseException) {
            $code = (int) $e->getCode();
            // 4xx = odmowa tymczasowa (limit tempa, szare listy, zamknięcie połączenia) — dotyczy skrzynki, nie adresu:
            // pauza nadawcy bez liczenia prób; 530/534/535/538 = wymagane/nieudane logowanie
            if (($code >= 400 && $code < 500) || in_array($code, [530, 534, 535, 538], true)) {
                return true;
            }
            if (str_contains($e->getMessage(), '"250/251/252"')) {
                return false;
            }
            // odrzucony MAIL FROM (adres nadawcy) dotyczy całej skrzynki; odrzucona treść — tego adresata.
            // Ostatnie polecenie wysłane do serwera widać w zapisie rozmowy („[czas] > POLECENIE”).
            $last = '';
            foreach (preg_split('/\R/', $e->getDebug()) ?: [] as $line) {
                if (preg_match('/^\[[^\]]*\] > (.*)$/', $line, $m) === 1) {
                    $last = $m[1];
                }
            }

            return stripos($last, 'MAIL FROM') === 0;
        }

        // połączenie, limit czasu, STARTTLS, logowanie (TransportException bez kodu odpowiedzi)
        return $e instanceof TransportExceptionInterface;
    }

    /** Przerwa skrzynki nadawcy na PAUSE_MINUTES (campaigns:dispatch ją pomija) z błędem widocznym w „Moja poczta”. */
    public function pause(int $userId, ?UserMailAccount $account, string $error): void
    {
        $error = mb_substr($error, 0, 500);
        $account?->forceFill(['last_error' => $error])->save();
        Cache::put(self::pauseKey($userId), $error, Carbon::now()->addMinutes(self::PAUSE_MINUTES));
        Log::warning('Kampania: skrzynka nadawcy wstrzymana', ['user' => $userId, 'error' => $error]);
    }

    private function pauseSender(CampaignRecipient $recipient, ?UserMailAccount $account, int $userId, string $error): void
    {
        // rezerwacja wraca do kolejki bez zwiększania prób — adres nie jest winny
        $this->finish($recipient, CampaignRecipient::STATUS_PENDING, $recipient->error);
        $this->pause($userId, $account, $error);
    }

    /**
     * Wynik odbiorcy pod blokadą kampanii — anulowanie (też pod blokadą) widzi go przed albo po, nigdy w połowie.
     * Kampania anulowana w trakcie wysyłki: powrót do kolejki = pominięty, a liczniki przeliczone.
     *
     * @param  array<string, mixed>  $extra
     */
    private function finish(CampaignRecipient $recipient, string $status, ?string $error, array $extra = []): void
    {
        DB::transaction(function () use ($recipient, $status, $error, $extra): void {
            $campaign = Campaign::query()->whereKey($recipient->campaign_id)->lockForUpdate()->first();
            $cancelled = $campaign?->status === Campaign::STATUS_CANCELLED;
            if ($cancelled && $status === CampaignRecipient::STATUS_PENDING) {
                [$status, $error] = [CampaignRecipient::STATUS_SKIPPED, 'kampania anulowana'];
            }
            $recipient->forceFill(['status' => $status, 'error' => $error, ...$extra])->save();
            if ($cancelled) {
                $campaign->forceFill(['totals' => self::totals($campaign)])->save();
            }
        });
    }

    private function assertAuthor(Campaign $campaign, User $actor, string $message): void
    {
        if ((int) $actor->id !== (int) $campaign->user_id) {
            // nadawcą jest skrzynka autora — nikt inny nie wysyła w jego imieniu
            throw $this->invalid($message);
        }
    }

    private function assertPublicUrl(): void
    {
        if (rtrim((string) config('campaigns.public_url'), '/') === '') {
            throw $this->invalid('Brak publicznego adresu aplikacji (CAMPAIGNS_PUBLIC_URL) — link wypisu i zdjęcia nie zadziałają.');
        }
    }

    /**
     * Warunki wysyłki wspólne dla startu i planowania: pozycje (bez osieroconych), temat, skrzynka autora.
     *
     * @return array{0: Collection<int, CampaignItem>, 1: User}
     */
    private function assertReady(Campaign $locked): array
    {
        $items = $locked->items()->get();
        if ($items->isEmpty()) {
            throw $this->invalid('Dodaj do kampanii co najmniej jedną pozycję.');
        }
        if ($items->contains(static fn (CampaignItem $i): bool => $i->erp_item_id === null && $i->product_id === null)) {
            throw $this->invalid('Usuń pozycje bez towaru i bez karty — ich dane zostały usunięte.');
        }
        if (trim((string) $locked->subject) === '') {
            throw $this->invalid('Wpisz temat maila.');
        }
        $author = $locked->user;
        if ($author === null || $author->mailAccount === null) {
            throw $this->invalid('Nie ustawiono skrzynki w „Moje konto → Moja poczta”.');
        }

        return [$items, $author];
    }

    private function accountOf(Campaign $campaign): ?UserMailAccount
    {
        return UserMailAccount::query()->where('user_id', $campaign->user_id)->first();
    }

    /** Treść błędu do bazy i komunikatu: bez hasła, najwyżej 500 znaków. */
    private function errorText(Throwable $e, ?UserMailAccount $account): string
    {
        $message = trim($e->getMessage()) !== '' ? trim($e->getMessage()) : class_basename($e);
        if ($e instanceof DecryptException) {
            $message = 'Nie da się odczytać zapisanego hasła skrzynki — wpisz je ponownie w „Moja poczta”.';
        } elseif ($account !== null) {
            try {
                $password = (string) $account->password;
                if ($password !== '') {
                    $message = str_replace($password, '***', $message);
                }
            } catch (Throwable) {
                // hasło nieczytelne — w treści błędu i tak go nie ma
            }
        }

        return mb_substr($message, 0, 500);
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['campaign' => $message]);
    }
}
