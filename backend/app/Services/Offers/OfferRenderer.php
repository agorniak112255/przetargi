<?php

declare(strict_types=1);

namespace App\Services\Offers;

use App\Models\Offer;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\Campaigns\CampaignBlocks;
use App\Services\Campaigns\CampaignRenderer;
use Illuminate\Support\Collection;

/**
 * Mail oferty — wygląd kampanii (CampaignRenderer::renderItems: baner, kafelki, układ produktów), ale do jednego
 * klienta: bez linii wypisu i notki o administratorze danych mailingu, bez linków mierzonych, „Zapytaj o ofertę” na adres
 * autora. Podgląd i kopia do Thunderbirda są bez podpisu (program pocztowy doda podpis handlowca), wysyłka z aplikacji —
 * z podpisem ze skrzynki „Moja poczta”. Forma „pdf” = krótki mail bez produktów (oferta w załączniku, OfferPdf).
 * Oferta przeglądu (kind = inspection): zamiast produktów tabela terminów bez cen (InspectionOfferRenderer).
 * Nie final — testy podmieniają zależności.
 */
class OfferRenderer
{
    public function __construct(
        private readonly CampaignRenderer $renderer,
        private readonly InspectionOfferRenderer $inspection,
    ) {}

    /**
     * @param  string|null  $notice  informacja na górze maila (kopia dla nadawcy) — klient jej nie dostaje
     * @param  bool  $withSignature  false = bez podpisu (podgląd i kopia do Thunderbirda)
     * @param  string|null  $delivery  forma (Offer::DELIVERIES); null = zapisana przy ofercie. 'pdf' = krótki mail
     *                                 (wstęp albo „w załączeniu przesyłam ofertę…”) bez produktów i bez linii „Ceny netto…”
     *                                 — oferta jest w załączniku; 'body' i 'both' = pełny mail z produktami
     * @return array{subject: string, from: string, html: string, text: string}
     */
    public function render(Offer $offer, User $viewer, ?string $notice = null, bool $withSignature = true, ?string $delivery = null): array
    {
        $offer->loadMissing('user.mailAccount');
        // nadawcą i podpisem jest zawsze autor (oglądać ofertę może tylko on — $viewer liczy ceny tak samo)
        $author = $offer->user ?? $viewer;
        /** @var UserMailAccount|null $account */
        $account = $author->mailAccount;
        $fromAddress = $account !== null ? trim((string) $account->from_address) : '';
        $delivery ??= (string) ($offer->delivery ?? 'body');

        $blocks = [['type' => 'header', 'logo' => null]];
        $intro = trim((string) $offer->intro);
        // oferta przeglądu (bez cen): zamiast bloku produktów akapit ze znacznikiem, podmieniany niżej na tabelę
        // „Co wymaga przeglądu” — bez linii „Ceny netto…” (jest w bloku produktów) i bez „Zapytaj o ofertę”
        $tableToken = null;
        if ($offer->isInspection() && $delivery !== 'pdf') {
            $tableToken = $this->inspection->token();
            if ($intro !== '') {
                $blocks[] = ['type' => 'text', 'text' => $intro];
            }
            $blocks[] = ['type' => 'text', 'text' => $tableToken];
        } elseif ($delivery === 'pdf') {
            // linia „Ceny netto…” jest w szablonie częścią bloku produktów — bez bloku nie ma i jej
            $blocks[] = ['type' => 'text', 'text' => $intro !== '' ? $intro : "Dzień dobry,\n\nw załączeniu przesyłam ofertę ".$offer->code.'.'];
        } else {
            if ($intro !== '') {
                $blocks[] = ['type' => 'text', 'text' => $intro];
            }
            $blocks[] = ['type' => 'products', 'layout' => CampaignBlocks::layout((string) $offer->layout)];
        }
        $blocks[] = ['type' => 'footer', 'text' => ''];

        $rendered = $this->renderer->renderItems(
            // pozycje świeżo z bazy — załadowana relacja mogła się zestarzeć po zmianie ceny; krótki mail ich nie pokazuje
            $delivery === 'pdf' || $tableToken !== null ? new Collection : OfferItemPresenter::campaignItems($offer->items()->get()),
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
                'footerNote' => '',
                // klient dostaje cenę — „Zapytaj o ofertę” zbędne; zamiast niego opcjonalny link przy pozycji
                'askButton' => false,
                'withSignature' => $withSignature,
            ],
            $author,
            null,
            // bez skrzynki pytanie klienta trafia na adres konta autora
            $fromAddress !== '' ? $fromAddress : trim((string) $author->email),
        );

        if ($tableToken !== null) {
            $rendered = $this->inspection->insertTable($rendered, $tableToken, $offer);
        }
        $fromName = $account !== null ? trim((string) $account->from_name) : '';

        return [
            'subject' => $rendered['subject'],
            'from' => $fromAddress === '' ? '' : ($fromName !== '' ? $fromName.' <'.$fromAddress.'>' : $fromAddress),
            'html' => $rendered['html'],
            'text' => $rendered['text'],
        ];
    }
}
