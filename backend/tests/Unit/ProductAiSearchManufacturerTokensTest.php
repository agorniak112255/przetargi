<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ProductAiSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * Producent z zapytania (bez modelu): skróty norm, klas i materiałów pisane wersalikami
 * nie są marką, a „marka spoza katalogu” wymaga sygnału (prod., cudzysłów, krótkie zapytanie).
 * Wzorzec: 15 opisów „przetargu opisowego 15” (AUDYT B 1.1).
 */
final class ProductAiSearchManufacturerTokensTest extends TestCase
{
    use RefreshDatabase;

    private const CERVA_BOOTS = 'BUTY gumowe DAMSKIE antyelektrostatyczne rozm. 35-41 TRONCHETTO OB. SRA prod.CERVA · EN ISO 20347';

    public function test_norm_class_and_material_abbreviations_are_not_manufacturers(): void
    {
        $svc = $this->service();
        foreach (Opisowy15Fixture::items() as $line) {
            $query = (string) $line['requirement'];
            $label = 'poz. '.$line['line_no'];
            $this->assertSame([], $this->invokePrivate($svc, 'manufacturerTokensFromQuery', $query), $label);
            $intent = $this->invokePrivate($svc, 'localIntent', $query);
            $this->assertFalse($intent['manufacturer_absent_in_catalog'], $label);
            $this->assertNull($intent['manufacturer_requested'], $label);
        }

        // Tokeny z interpunkcją i skróty, które produkcja brała za markę.
        foreach ([
            'Trzewiki S3 SRC',
            'dopuszczony do kontaktu z żywnością (zgodność z wymaganiami FDA).',
            'właściwości antyelektrostatyczne (ESD) umożliwiające kontrolę ładunków',
            'zgodność z normą PN-EN 140:2004 (EN 140:1998); oznakowanie CE',
            'dzianina z przędzy UHMWPE, włókna szklanego, nylonu',
            'poziom AQL 1,5; zgodność z przepisami',
            'odporność na poślizg SRA. Rozmiary 41–45',
            'poniżej NDS dla par organicznych; ŚOI kategorii III',
            'tkanina powlekana PVC, szwy zgrzewane, TPR',
        ] as $query) {
            $this->assertSame([], $this->invokePrivate($svc, 'manufacturerTokensFromQuery', $query), $query);
        }
    }

    public function test_named_brands_are_still_recognised_as_manufacturers(): void
    {
        $svc = $this->service();

        $this->assertContains('CERVA', $this->invokePrivate($svc, 'manufacturerTokensFromQuery', self::CERVA_BOOTS));
        $this->assertSame(['RTELA'], $this->invokePrivate($svc, 'manufacturerTokensFromQuery', 'Rękawice nitrylowe RTELA'));
        $this->assertContains('Nortex', $this->invokePrivate($svc, 'manufacturerTokensFromQuery', 'Rękawice ocieplane pokryte gumą „Nortex”'));
        // Marka w nawiasie: token to sama nazwa, nie „(UVEX)” — inaczej nie trafi w listę producentów katalogu.
        $this->assertSame(['UVEX'], $this->invokePrivate($svc, 'manufacturerTokensFromQuery', 'Okulary ochronne (UVEX) z powłoką'));
        $this->assertSame('UVEX', $this->invokePrivate($svc, 'localIntent', 'Okulary ochronne (UVEX) z powłoką')['manufacturer_requested']);

        // Krótkie zapytanie: wersaliki to marka spoza katalogu (zamienniki).
        $short = $this->invokePrivate($svc, 'localIntent', 'Rękawice nitrylowe RTELA');
        $this->assertTrue($short['manufacturer_absent_in_catalog']);
        $this->assertSame('RTELA', $short['manufacturer_requested']);

        $quoted = $this->invokePrivate($svc, 'localIntent', 'Rękawice ocieplane pokryte gumą „Nortex”');
        $this->assertTrue($quoted['manufacturer_absent_in_catalog']);
        $this->assertSame('Nortex', $quoted['manufacturer_requested']);

        // Długi opis: marka tylko po „prod.” — te same wersaliki bez sygnału to nie marka.
        $tail = ' do prac montażowych w suchych pomieszczeniach, z podnoskiem kompozytowym, podeszwa odporna na oleje i paliwa, rozmiary 39-47';
        $withProd = $this->invokePrivate($svc, 'localIntent', 'Trzewiki ochronne S3 prod. ZORKOTEX'.$tail);
        $this->assertTrue($withProd['manufacturer_absent_in_catalog']);
        $this->assertSame('ZORKOTEX', $withProd['manufacturer_requested']);

        $bare = $this->invokePrivate($svc, 'localIntent', 'Trzewiki ochronne S3 ZORKOTEX'.$tail);
        $this->assertFalse($bare['manufacturer_absent_in_catalog']);
        $this->assertNull($bare['manufacturer_requested']);
    }

    private function service(): ProductAiSearchService
    {
        return $this->app->make(ProductAiSearchService::class);
    }

    private function invokePrivate(object $object, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($object, $method);
        $ref->setAccessible(true);

        return $ref->invoke($object, ...$args);
    }
}
