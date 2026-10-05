<?php

declare(strict_types=1);

namespace App\Services\Offers;

use App\Models\Offer;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\Campaigns\CampaignBlocks;
use App\Services\Campaigns\CampaignRenderer;

/**
 * Mail oferty — wygląd kampanii (CampaignRenderer::renderItems: baner, kafelki, układ produktów), ale do jednego
 * klienta: bez linii wypisu, bez linków mierzonych, „Zapytaj o ofertę” na adres autora. Ten sam HTML w podglądzie,
 * kopii do Thunderbirda i wysyłce. Nie final — testy podmieniają zależności.
 */
class OfferRenderer
{
    public function __construct(private readonly CampaignRenderer $renderer) {}

    /**
     * @param  string|null  $notice  informacja na górze maila (kopia dla nadawcy) — klient jej nie dostaje
     * @return array{subject: string, from: string, html: string, text: string}
     */
    public function render(Offer $offer, User $viewer, ?string $notice = null): array
    {
        $offer->loadMissing('user.mailAccount');
        // nadawcą i podpisem jest zawsze autor (oglądać ofertę może tylko on — $viewer liczy ceny tak samo)
        $author = $offer->user ?? $viewer;
        /** @var UserMailAccount|null $account */
        $account = $author->mailAccount;
        $fromAddress = $account !== null ? trim((string) $account->from_address) : '';

        $blocks = [['type' => 'header', 'logo' => null]];
        $intro = trim((string) $offer->intro);
        if ($intro !== '') {
            $blocks[] = ['type' => 'text', 'text' => $intro];
        }
        $blocks[] = ['type' => 'products', 'layout' => CampaignBlocks::layout((string) $offer->layout)];
        $blocks[] = ['type' => 'footer', 'text' => ''];

        $rendered = $this->renderer->renderItems(
            // pozycje świeżo z bazy — załadowana relacja mogła się zestarzeć po zmianie ceny
            OfferItemPresenter::campaignItems($offer->items()->get()),
            $author,
            (string) $offer->code,
            false,
            $blocks,
            null,
            [
                'subject' => (string) $offer->subject,
                'preheader' => null,
                'validUntil' => $offer->valid_until !== null
                    ? 'Ceny netto. Oferta ważna do '.$offer->valid_until->format('d.m.Y')
                    : 'Ceny netto.',
                // oferta do jednego klienta — nie mailing, nie ma z czego się wypisywać
                'unsubscribeUrl' => null,
                'notice' => $notice,
            ],
            $author,
            null,
            // bez skrzynki pytanie klienta trafia na adres konta autora
            $fromAddress !== '' ? $fromAddress : trim((string) $author->email),
        );

        $fromName = $account !== null ? trim((string) $account->from_name) : '';

        return [
            'subject' => $rendered['subject'],
            'from' => $fromAddress === '' ? '' : ($fromName !== '' ? $fromName.' <'.$fromAddress.'>' : $fromAddress),
            'html' => $rendered['html'],
            'text' => $rendered['text'],
        ];
    }
}
