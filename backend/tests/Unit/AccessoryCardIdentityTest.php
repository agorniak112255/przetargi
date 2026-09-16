<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductSearchIdentity;
use Tests\TestCase;

/**
 * Część zamienna ma w nazwie człon zgodności („…do półmaski SECURA 3000”), który mówi,
 * z czym wyrób współpracuje, a nie czym jest. Karta całego urządzenia zawiera ten człon
 * w całości, więc przechodziła jako karta części: pierścień zaczepowy dostawał opis
 * półmaski, a nagłowie opis zestawu. Druga usterka z tych samych testów: karta półbutów
 * 20 kV przechodziła jako karta kaloszy 5 kV, bo obie to „obuwie”.
 */
final class AccessoryCardIdentityTest extends TestCase
{
    private function identity(): ProductSearchIdentity
    {
        return app(ProductSearchIdentity::class);
    }

    private function part(string $sku, string $name): Product
    {
        return new Product(['sku' => $sku, 'name' => $name, 'manufacturer' => 'SECURA']);
    }

    /** Karta urządzenia nadrzędnego nie jest kartą części. */
    public function test_device_card_is_not_the_part_card(): void
    {
        $product = $this->part('S56212-50', 'Pierścień z zaczepami zaworu wydechowego do półmaski SECURA 3000');

        $this->assertTrue($this->identity()->accessoryCardDisagrees(
            'https://www.supon.rzeszow.pl/maski-i-polmaski-wielokrotnego-uzytku/297-polmaska-ochronna-secura-3000-z-bagnetowym-mocowaniem-pochlaniaczy.html',
            'Półmaska ochronna SECURA 3000 z bagnetowym mocowaniem pochłaniaczy',
            $product
        ));
        $this->assertTrue($this->identity()->accessoryCardDisagrees(
            'https://centrumelektronarzedzi.pl/pl/p/Zestaw-SECURA-3000-LAK-w-pudelku-maska-rozmiar-M/48403',
            'Zestaw SECURA 3000 LAK w pudełku, maska rozmiar M',
            $product
        ));
    }

    /** Karta tej właśnie części zostaje. */
    public function test_real_part_card_passes(): void
    {
        $product = $this->part('S56212-50', 'Pierścień z zaczepami zaworu wydechowego do półmaski SECURA 3000');

        $this->assertFalse($this->identity()->accessoryCardDisagrees(
            'https://sklep.example/pierscien-z-zaczepami-zaworu-wydechowego-secura-3000',
            'Pierścień z zaczepami zaworu wydechowego SECURA 3000',
            $product
        ));
    }

    /** Część do innego urządzenia z tej samej serii odpada. */
    public function test_part_for_another_device_in_the_series_is_rejected(): void
    {
        $product = $this->part('S5621230', 'Płatek zaworu wydechowego do półmaski SECURA 3000');

        $this->assertTrue($this->identity()->accessoryCardDisagrees(
            'https://domtechniczny24.pl/platek-zaworu-wydechowego-secura-2000.html',
            'Płatek zaworu wydechowego SECURA 2000',
            $product
        ));
        $this->assertFalse($this->identity()->accessoryCardDisagrees(
            'https://domtechniczny24.pl/platek-zaworu-wydechowego-secura-3000-do-polmasek.html',
            'Płatek zaworu wydechowego SECURA 3000 do półmasek',
            $product
        ));
    }

    /** Zwykła nazwa opisowa nie jest członem zgodności — reguła nie może jej tykać. */
    public function test_descriptive_name_without_device_is_untouched(): void
    {
        $glove = new Product([
            'sku' => '34525098',
            'name' => 'Rękawice nitrylowe do prac montażowych i konserwacyjnych',
            'manufacturer' => 'MAPA',
        ]);

        $this->assertFalse($this->identity()->accessoryCardDisagrees(
            'https://icd.pl/rekawice-mapa-ultrane-525',
            'Rękawice MAPA Ultrane 525',
            $glove
        ));
    }

    /** Kalosze to nie półbuty, chodnik to nie dywanik — jedna rodzina, inny wyrób. */
    public function test_other_subtype_from_the_same_family_is_rejected(): void
    {
        $boots = new Product([
            'sku' => 'T5911400',
            'name' => 'Kalosze elektroizolacyjne 5 kV - ANTYAMPER',
            'manufacturer' => 'SECURA',
        ]);
        $mat = new Product([
            'sku' => 'T5921003',
            'name' => 'Chodnik elektroizolacyjny 20 KV',
            'manufacturer' => 'SECURA',
        ]);

        $this->assertTrue($this->identity()->cardNamesAnotherSubtype(
            'https://centrumelektronarzedzi.pl/pl/p/Polbuty-elektroizolacyjne-20-kV-ANTYAMPER-rozmiar-5-Secura/55440',
            'Półbuty elektroizolacyjne 20 kV ANTYAMPER rozmiar 5',
            $boots
        ));
        $this->assertTrue($this->identity()->cardNamesAnotherSubtype(
            'https://centrumelektronarzedzi.pl/pl/p/Dywanik-elektroizolacyjny-20-KV-wymiary-0,75-x-0,75-m-Secura/48600',
            'Dywanik elektroizolacyjny 20 KV wymiary 0,75 x 0,75 m',
            $mat
        ));
        $this->assertFalse($this->identity()->cardNamesAnotherSubtype(
            'https://centrumelektronarzedzi.pl/pl/p/Chodnik-elektroizolacyjny-20-KV-wymiary-1,1-x-2-m-Secura/48601',
            'Chodnik elektroizolacyjny 20 KV wymiary 1,1 x 2 m',
            $mat
        ));
    }

    /** Wodery i spodniobuty to w handlu ten sam wyrób — tej pary reguła nie rozdziela. */
    public function test_waders_and_chest_waders_are_not_split(): void
    {
        $waders = new Product([
            'sku' => 'SB04 AIR',
            'name' => 'Spodniobuty oddychające AIR',
            'manufacturer' => 'AJ GROUP',
        ]);

        $this->assertFalse($this->identity()->cardNamesAnotherSubtype(
            'https://pros.pl/pl/wodery-oddychajace/104-wodery-air.html',
            'Wodery oddychające AIR',
            $waders
        ));
    }
}
