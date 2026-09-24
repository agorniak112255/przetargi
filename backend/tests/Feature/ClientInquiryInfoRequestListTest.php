<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ClientInquiryService;
use Tests\TestCase;

/**
 * Zapytania #44 i #57 z 24.09.2026: listy próśb o warunki i o dokumenty szły jako pozycje zamówienia. W #44 pięć
 * punktów listy przegłosowało jedyny wyrób z rozbitej tabeli, w #57 deklaracja CE i instrukcja szły do katalogu.
 */
final class ClientInquiryInfoRequestListTest extends TestCase
{
    public function test_header_wrapped_in_emphasis_from_html_mail_opens_information_requests(): void
    {
        $items = app(ClientInquiryService::class)->parseLineItemsFromBody(
            "Prosze o przeslanie oferty cenowej na pozycje ponizej:\n\n"
            ."OSLONY BOCZNE DO OKULAROW O CIENKICH ZAUSZNIKACH DO 4MM INDEKS BS-948003\n\n20\n\nPAA\n\n"
            ."*_Proszę również o podanie:_*\n\n"
            ." 1. *Terminu realizacji*\n 2. *Warunków oraz kosztów dostawy*\n 3. *Formy oraz warunków płatności*\n"
            ." 4. *Ważności oferty*\n 5. *Dodatkowych opłat oraz informacji niezbędnych do realizacji\n    zamówienia.*\n"
        );

        $this->assertSame([], $items, 'punkty listy warunków to nie pozycje');
    }

    public function test_list_of_documents_is_not_an_order(): void
    {
        $items = app(ClientInquiryService::class)->parseLineItemsFromBody(
            "Prosze o dokumenty na zatyczki BilsoM 303L-30 dla klienta\n\n"
            ."*Dokumenty dotyczące wkładek*\n\n"
            ." 1. *Deklarację zgodności UE* – może być dołączona do produktu albo\n    wskazana jako adres internetowy.\n"
            ." 2. *Instrukcję użytkowania w języku polskim*, obejmującą sposób zakładania.\n"
            ." 3. Dokumentację potwierdzającą *oznakowanie CE* produktu.\n"
            ." 4. *Kartę produktu lub specyfikację producenta* z parametrami tłumienia hałasu.\n"
        );

        $this->assertSame([], $items, 'lista dokumentów to prośba o papiery, nie pozycje');
    }

    public function test_numbered_goods_after_a_plain_heading_stay_items(): void
    {
        $items = app(ClientInquiryService::class)->parseLineItemsFromBody(
            "*Zamawiamy:*\n\n1. Rękawice nitrylowe 100 par\n2. Buty robocze S3 rozmiar 44\n"
        );

        $this->assertSame(['Rękawice nitrylowe', 'Buty robocze S3'], array_column($items, 'query'));
    }
}
