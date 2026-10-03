<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Bzp\NoticeItemsReader;
use Tests\TestCase;

/**
 * NoticeItemsReader::verify — okno pozycji (ten sam cytat w dwóch częściach, cytat obejmujący kilka towarów, pozycja
 * bez znalezionego cytatu, nagłówki części rzymskie), przeczenia przy cechach, jednostka tylko z cytatu, pamięć.
 */
final class NoticeItemsVerifyTest extends TestCase
{
    public function test_same_quote_in_two_lots_gets_own_lot_occurrence(): void
    {
        $text = "Część 1\n- rękawice ochronne – 10 par, rozmiar 9\nCzęść 2\n- rękawice ochronne – 10 par, rozmiar 10";
        $rows = $this->verify([
            // model wypisał najpierw część 2 — cytat trafia w wystąpienie w części 2, nie w pierwsze w tekście
            ['lot_no' => 2, 'name' => 'Rękawice ochronne', 'spec' => ['rozmiar 10', 'rozmiar 9'], 'quantity' => 10, 'unit' => 'par', 'quote' => 'rękawice ochronne – 10 par', 'bhp' => true],
            ['lot_no' => 1, 'name' => 'Rękawice ochronne', 'spec' => ['rozmiar 9', 'rozmiar 10'], 'quantity' => 10, 'unit' => 'par', 'quote' => 'rękawice ochronne – 10 par', 'bhp' => true],
        ], $text, [1, 2]);

        $this->assertSame([2, 1], array_column($rows, 'lot_no'));
        $this->assertSame(['rozmiar 10'], $rows[0]['spec_fragments']);
        $this->assertSame(['rozmiar 9'], $rows[1]['spec_fragments']);
    }

    public function test_same_quote_without_lots_takes_next_free_occurrence(): void
    {
        $text = "1. Kamizelka ostrzegawcza – 5 szt., kolor żółty\n2. Kamizelka ostrzegawcza – 5 szt., kolor pomarańczowy";
        $rows = $this->verify([
            ['lot_no' => null, 'name' => 'Kamizelka ostrzegawcza', 'spec' => ['kolor żółty'], 'quantity' => 5, 'unit' => 'szt.', 'quote' => 'Kamizelka ostrzegawcza – 5 szt.', 'bhp' => true],
            // druga pozycja (inny kolor) — drugie wystąpienie cytatu, jej cecha nie trafia do pierwszej
            ['lot_no' => null, 'name' => 'Kamizelka ostrzegawcza pomarańczowa', 'spec' => ['kolor pomarańczowy', 'kolor żółty'], 'quantity' => 5, 'unit' => 'szt.', 'quote' => 'Kamizelka ostrzegawcza – 5 szt.', 'bhp' => true],
        ], $text, []);

        $this->assertSame(['kolor żółty'], $rows[0]['spec_fragments']);
        $this->assertSame(['kolor pomarańczowy'], $rows[1]['spec_fragments']);
    }

    public function test_window_ends_at_next_quote_inside_shared_quote_and_at_unverified_name(): void
    {
        $text = "- hełm i rękawice skórzane EN 659 – po 10 szt.\n- buty robocze S3 – 10 par\n- kalosze PCV S5 – 4 pary";
        $rows = $this->verify([
            // cytat obejmuje dwa towary — cechy rękawic („skórzane”, „EN 659”) nie należą do hełmu
            ['lot_no' => null, 'name' => 'Hełm', 'spec' => ['skórzane', 'EN 659'], 'quantity' => 10, 'unit' => 'szt.', 'quote' => 'hełm i rękawice skórzane EN 659 – po 10 szt.', 'bhp' => true],
            ['lot_no' => null, 'name' => 'Rękawice', 'spec' => ['skórzane', 'EN 659'], 'quantity' => 10, 'unit' => 'szt.', 'quote' => 'rękawice skórzane EN 659 – po 10 szt.', 'bhp' => true],
            // „S5” to cecha kaloszy — ich cytatu model nie przepisał dokładnie, ale ich nazwa kończy okno butów
            ['lot_no' => null, 'name' => 'Buty robocze', 'spec' => ['S3', 'S5'], 'quantity' => 10, 'unit' => 'par', 'quote' => 'buty robocze S3 – 10 par', 'bhp' => true],
            ['lot_no' => null, 'name' => 'Kalosze', 'spec' => ['S5'], 'quantity' => 4, 'unit' => 'pary', 'quote' => 'kalosze z PCV S5 – 4 pary', 'bhp' => true],
        ], $text, []);

        $this->assertNull($rows[0]['spec']);
        $this->assertSame(['skórzane', 'EN 659'], $rows[1]['spec_fragments']);
        $this->assertSame(['S3'], $rows[2]['spec_fragments']);
        $this->assertFalse($rows[3]['quote_found']);
        $this->assertNull($rows[3]['spec']);
    }

    public function test_roman_lot_headers_end_window(): void
    {
        $text = "Część I\n- rękawice robocze – 10 par\nwymagania: EN 388\nCzęść II\n- buty – 5 par\nwymagania: EN ISO 20345";
        $rows = $this->verify([
            ['lot_no' => 1, 'name' => 'Rękawice robocze', 'spec' => ['EN 388', 'EN ISO 20345'], 'quantity' => 10, 'unit' => 'par', 'quote' => 'rękawice robocze – 10 par', 'bhp' => true],
        ], $text, [1, 2]);

        $this->assertSame(['EN 388'], $rows[0]['spec_fragments']);
    }

    public function test_negation_before_fragment_is_kept_in_excerpt(): void
    {
        $text = 'Rękawice nitrylowe nie zawierające lateksu, bez pudru, rozmiar M – 100 op.';
        $rows = $this->verify([
            ['lot_no' => null, 'name' => 'Rękawice nitrylowe', 'spec' => ['zawierające lateksu', 'pudru', 'rozmiar M'], 'quantity' => 100, 'unit' => 'opak.', 'quote' => 'Rękawice nitrylowe nie zawierające lateksu, bez pudru, rozmiar M – 100 op.', 'bhp' => true],
        ], $text, []);

        // przeczenie z ogłoszenia wchodzi do wycinka — cecha nie zmienia sensu
        $this->assertSame(['nie zawierające lateksu', 'bez pudru', 'rozmiar M'], $rows[0]['spec_fragments']);
        // jednostki „opak.” nie ma w cytacie (jest „op.”) — null, nie zgadujemy
        $this->assertNull($rows[0]['unit']);
    }

    public function test_unit_only_from_quote(): void
    {
        $text = "- ubranie specjalne – 23 kpl\n- buty – 5 par";
        $rows = $this->verify([
            ['lot_no' => null, 'name' => 'Ubranie specjalne', 'quantity' => 23, 'unit' => 'kpl.', 'quote' => 'ubranie specjalne – 23 kpl', 'bhp' => true],
            ['lot_no' => null, 'name' => 'Buty', 'quantity' => 5, 'unit' => 'szt.', 'quote' => 'buty – 5 par', 'bhp' => true],
        ], $text, []);

        $this->assertSame(['kpl', null], array_column($rows, 'unit'));
    }

    public function test_unusual_spaces_in_notice_still_find_the_quote(): void
    {
        // wąska spacja U+2009 i spacja U+2002 z edytora zamawiającego (przegląd runda 2)
        $text = "- hełm\u{2009}strażacki –\u{2002}23 szt.,\n- ubranie specjalne – 23 szt.";
        $rows = $this->verify([
            ['lot_no' => null, 'name' => 'Hełm strażacki', 'quantity' => 23, 'unit' => 'szt.', 'quote' => 'hełm strażacki – 23 szt.', 'bhp' => true],
        ], $text, []);

        $this->assertTrue($rows[0]['quote_found']);
        $this->assertSame(23, $rows[0]['quantity']);
    }

    public function test_large_text_stays_within_memory(): void
    {
        $lines = [];
        $rows = [];
        for ($i = 1; $i <= 300; $i++) {
            $line = "- rękawice ochronne wzór {$i}, EN 388:2016, rozmiar ".(7 + $i % 5)." – {$i} par";
            $lines[] = $line;
            $rows[] = ['lot_no' => null, 'name' => "Rękawice wzór {$i}", 'spec' => ['EN 388:2016', 'rozmiar '.(7 + $i % 5), 'odporność na przecięcie'], 'quantity' => $i, 'unit' => 'par', 'quote' => mb_substr($line, 2), 'bhp' => true];
        }
        $text = mb_substr(implode("\n", $lines).str_repeat(' Treść ogólna zamówienia.', 2000), 0, 60000);

        gc_collect_cycles();
        memory_reset_peak_usage();
        $base = memory_get_usage();
        $out = $this->verify($rows, $text, []);
        $peak = memory_get_peak_usage() - $base;

        $this->assertCount(300, $out);
        $this->assertSame(['EN 388:2016', 'rozmiar 8'], $out[0]['spec_fragments']);
        $this->assertLessThan(24 * 1024 * 1024, $peak, 'Weryfikacja 60 tys. znaków zajęła '.round($peak / 1048576, 1).' MB');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $lots
     * @return list<array<string, mixed>>
     */
    private function verify(array $rows, string $text, array $lots): array
    {
        return app(NoticeItemsReader::class)->verify($rows, $text, $lots);
    }
}
