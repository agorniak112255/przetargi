<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Enrichment\SearchEngineOutage;
use PHPUnit\Framework\TestCase;

final class SearchEngineOutageTest extends TestCase
{
    public function test_engine_failures_count_as_outage(): void
    {
        foreach ([
            'SearXNG: silniki zablokowane (429/CAPTCHA)',
            'Google HTTP 429',
            'DuckDuckGo HTTP 202',
            'cURL error 28: Connection timed out',
            'SearXNG nie odpowiada',
        ] as $message) {
            $this->assertTrue(SearchEngineOutage::matches($message), $message);
        }
    }

    public function test_reader_details_in_a_manual_message_are_not_an_outage(): void
    {
        // Szczegół „reader: HTTP 403” w komunikacie o zaporze sklepu wysyłał produkt
        // do ponowienia zamiast do ręki — bo wzorzec „http 403” to sygnatura awarii
        // wyszukiwarki. Szczegóły readera nie mogą tej sygnatury przypominać.
        foreach ([
            'reader: odmowa 403',
            'reader: limit 429',
            'reader: timeout albo brak połączenia',
            'zapora także u readera',
            'Karta produktu X prawdopodobnie tu: https://a.pl/x — sklep blokuje pobieranie automatyczne,'
                .' reader też nie przeszedł (reader: odmowa 403 ×2). Otwórz w przeglądarce.',
        ] as $message) {
            $this->assertFalse(SearchEngineOutage::matches($message), $message);
        }
    }
}
