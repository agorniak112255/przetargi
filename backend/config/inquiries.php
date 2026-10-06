<?php

declare(strict_types=1);

return [
    /**
     * Domeny naszych skrzynek (po przecinku w INQUIRY_INTERNAL_EMAIL_DOMAINS). Mail z takiego adresu to
     * przekazanie albo odpowiedź handlowca, nie klient — blok Outlooka od tego adresu nie jest wcześniejszą
     * wiadomością klienta i cięcie maila traktuje go jak cytat (InquiryMailText, ClientInquiryService::threadSender).
     */
    'internal_email_domains' => array_values(array_filter(array_map(
        static fn (string $domain): string => mb_strtolower(trim($domain)),
        explode(',', (string) env('INQUIRY_INTERNAL_EMAIL_DOMAINS', 'supon.rzeszow.pl')),
    ))),
];
