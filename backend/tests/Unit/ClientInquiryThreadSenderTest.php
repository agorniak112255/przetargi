<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ClientInquiryService;
use Tests\TestCase;

/**
 * Adres nadawcy do cięcia maila: blok Outlooka od tego adresu w tym samym wątku jest wcześniejszą wiadomością
 * klienta. Nasza skrzynka odpada — handel@ przekazujący mail dalej wciągnąłby do analizy naszą starą ofertę.
 */
final class ClientInquiryThreadSenderTest extends TestCase
{
    public function test_client_address_is_kept(): void
    {
        $this->assertSame('Anna.Nowak@firma.pl', ClientInquiryService::threadSender('Nowak, Anna <Anna.Nowak@firma.pl>'));
        $this->assertSame('anna@firma.pl', ClientInquiryService::threadSender('anna@firma.pl'));
        // podobna nazwa domeny to nie nasza domena
        $this->assertSame('jan@niesupon.rzeszow.pl', ClientInquiryService::threadSender('jan@niesupon.rzeszow.pl'));
    }

    public function test_our_mailboxes_and_missing_sender_give_null(): void
    {
        config(['inquiries.internal_email_domains' => ['supon.rzeszow.pl']]);

        $this->assertNull(ClientInquiryService::threadSender('Handel - Supon Rzeszów <handel@supon.rzeszow.pl>'));
        $this->assertNull(ClientInquiryService::threadSender('HANDEL@SUPON.RZESZOW.PL'));
        $this->assertNull(ClientInquiryService::threadSender('sklep@b2b.supon.rzeszow.pl'));
        $this->assertNull(ClientInquiryService::threadSender(null));
        $this->assertNull(ClientInquiryService::threadSender(''));
    }

    public function test_internal_domains_come_from_config(): void
    {
        config(['inquiries.internal_email_domains' => ['firma.pl']]);

        $this->assertNull(ClientInquiryService::threadSender('anna@firma.pl'));
        $this->assertSame('handel@supon.rzeszow.pl', ClientInquiryService::threadSender('handel@supon.rzeszow.pl'));
    }
}
