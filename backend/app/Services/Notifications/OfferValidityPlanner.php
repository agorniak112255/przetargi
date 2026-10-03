<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\ClientInquiry;
use App\Models\User;
use App\Support\OfferValidity;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;

/**
 * „Kończy się ważność mojej oferty, a klient nie zamówił” (offer_validity_ending): do autora zapytania od ostatniego
 * dnia roboczego przed końcem ważności (OfferValidity::until z warunku „Ważność oferty” i dnia odpowiedzi) do końca
 * ważności włącznie, gdy wynik zapytania jest pusty; tylko odpowiedzi z ostatnich 120 dni. Przypomnienie wychodzi od
 * godziny daily_from (07:00 czasu polskiego), raz na ofertę: subjectKey inquiry:{id}, okres validity:{dzień końca}.
 * Ta sama lista zasila sprawę „Do zrobienia dziś” (DashboardController).
 */
class OfferValidityPlanner implements ReminderPlanner
{
    /** Odpowiedzi starsze niż tyle dni nie są już sprawą do przypomnienia (oferta ważna najwyżej rok jest rzadka). */
    public const REPLIED_WITHIN_DAYS = 120;

    private const WEEKDAYS = [1 => 'poniedziałek', 2 => 'wtorek', 3 => 'środa', 4 => 'czwartek', 5 => 'piątek', 6 => 'sobota', 7 => 'niedziela'];

    public function due(CarbonImmutable $now): iterable
    {
        $now = $now->setTimezone(PolishTime::TIMEZONE);
        $today = $now->startOfDay();
        if ($now->lessThan($this->dailyFrom($today))) {
            return;
        }

        foreach ($this->ending($today) as $row) {
            $inquiry = $row['inquiry'];
            $user = $inquiry->user;
            if (! $user instanceof User) {
                continue;
            }
            yield [
                'user' => $user,
                'message' => $this->message($inquiry, $row['valid_until']),
                'period' => 'validity:'.$row['valid_until']->toDateString(),
            ];
        }
    }

    public function sent(array $reminder, array $result): void {}

    /**
     * Zapytania z ofertą, której ważność kończy się „teraz”: dziś mieści się w [ostatni dzień roboczy przed końcem
     * ważności, koniec ważności], wynik pusty, odpowiedź z ostatnich 120 dni. Kolejność: najbliższy koniec ważności.
     *
     * @return list<array{inquiry: ClientInquiry, valid_until: CarbonImmutable}>
     */
    public function ending(CarbonImmutable $today, ?int $userId = null): array
    {
        $today = $today->setTimezone(PolishTime::TIMEZONE)->startOfDay();
        $since = $today->subDays(self::REPLIED_WITHIN_DAYS)->setTimezone((string) config('app.timezone', 'UTC'));
        $query = ClientInquiry::query()
            ->whereNotNull('replied_at')
            ->whereNull('outcome')
            ->whereNotNull('offer_terms')
            ->where('replied_at', '>=', $since->format('Y-m-d H:i:s'))
            ->when($userId !== null, static fn ($q) => $q->where('user_id', $userId))
            ->with(['client:id,name', 'user'])
            ->select(['id', 'user_id', 'client_id', 'contact', 'source_from_name', 'source_from_email', 'source_subject', 'reply_subject', 'offer_terms', 'replied_at']);

        $out = [];
        foreach ($query->lazyById(200) as $inquiry) {
            /** @var ClientInquiry $inquiry */
            $terms = is_array($inquiry->offer_terms) ? $inquiry->offer_terms : [];
            $text = is_string($terms['validity'] ?? null) ? $terms['validity'] : null;
            $until = OfferValidity::until($text, CarbonImmutable::instance($inquiry->replied_at));
            if ($until === null || $today->greaterThan($until) || $today->lessThan(PolishTime::lastBusinessDayBefore($until->toDateString()))) {
                continue;
            }
            $out[] = ['inquiry' => $inquiry, 'valid_until' => $until];
        }
        usort($out, static fn (array $a, array $b): int => [$a['valid_until']->toDateString(), $a['inquiry']->id] <=> [$b['valid_until']->toDateString(), $b['inquiry']->id]);

        return $out;
    }

    /** Klient zapytania dla ludzi: karta klienta, firma ze stopki, nadawca. */
    public static function clientLabel(ClientInquiry $inquiry): ?string
    {
        $company = is_array($inquiry->contact) && is_string($inquiry->contact['company'] ?? null) && trim($inquiry->contact['company']) !== ''
            ? trim($inquiry->contact['company'])
            : null;

        return $inquiry->client?->name ?? $company ?? $inquiry->source_from_name ?? $inquiry->source_from_email;
    }

    private function message(ClientInquiry $inquiry, CarbonImmutable $validUntil): AppNotificationMessage
    {
        $client = self::clientLabel($inquiry);
        $subject = $inquiry->source_subject ?? $inquiry->reply_subject;

        return new AppNotificationMessage(
            event: 'offer_validity_ending',
            subjectKey: 'inquiry:'.$inquiry->id,
            title: 'Oferta ważna do: '.self::WEEKDAYS[(int) $validUntil->isoWeekday()].' '.$validUntil->format('j.n.Y').', klient jeszcze nie zamówił',
            body: implode("\n", array_filter([
                implode(' · ', array_filter([$client, $subject], static fn (?string $p): bool => $p !== null && trim($p) !== '')),
                'Odpowiedź wysłana '.PolishTime::format($inquiry->replied_at, false).'. Wynik zapytania nie jest wpisany — warto zadzwonić do klienta.',
            ], static fn (string $line): bool => $line !== '')),
            url: '/inquiries/'.$inquiry->id,
            data: [
                'inquiry_id' => (int) $inquiry->id,
                'valid_until' => $validUntil->toDateString(),
            ],
        );
    }

    private function dailyFrom(CarbonImmutable $today): CarbonImmutable
    {
        $from = (string) config('notifications.daily_from', '07:00');
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $from, $m) !== 1) {
            return $today->setTime(7, 0);
        }

        return $today->setTime((int) $m[1], (int) $m[2]);
    }
}
