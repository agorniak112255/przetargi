<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\B2b\VmFootwearSharePoint;
use App\Support\ImageReencoder;
use PHPUnit\Framework\TestCase;

/**
 * Dopasowanie PNG bez tła z folderu VM Footwear do kodu karty. Nazwy folderów i plików — prawdziwe, ze spisu
 * udostępnienia z 08.10.2026 (pisownia kodu z „_”, „-” i spacją, plik cudzego modelu w folderze, plik wspólny dla
 * kilku wersji, wersje „60”, „25” wyglądające jak numer ujęcia).
 */
final class VmFootwearSharePointTest extends TestCase
{
    public function test_files_with_the_card_code_in_the_name_in_gallery_order(): void
    {
        $index = self::index([
            '3705-S1PSESD_PEKING' => ['3705-S1PSBOA PEKING_top.png', '3705-S1PSBOA PEKING_01.png'],
            '2075-S1PLESD_KAMPALA' => [
                '2075-S1PLESD_KAMPALA_bottom.png', '2075-S1PLESD_KAMPALA_02.png', '2075-S1PLESD_KAMPALA_right.png',
                '2075-S1PLESD_KAMPALA_top.png', '2075-S1PLESD_KAMPALA_image_01.png', '2075-S1PLESD_KAMPALA_01.png',
                '2075-S1PLESD_KAMPALA_03.png',
            ],
        ]);

        $match = VmFootwearSharePoint::imagesFor('2075-S1PLESD', $index);

        $this->assertTrue($match['exact']);
        $this->assertSame([
            '2075-S1PLESD_KAMPALA_image_01.png', '2075-S1PLESD_KAMPALA_01.png', '2075-S1PLESD_KAMPALA_02.png',
            '2075-S1PLESD_KAMPALA_03.png', '2075-S1PLESD_KAMPALA_right.png', '2075-S1PLESD_KAMPALA_top.png',
        ], self::names($match['paths']), 'najwyżej 6 ujęć: widok z przodu najpierw, podeszwa odpada jako siódma');
    }

    public function test_file_without_a_view_suffix_is_the_main_one_and_one_file_serves_several_versions(): void
    {
        $index = self::index([
            '2880-S1_SEVILLA' => ['2880_O1_S1_S3.png'],
            '2880-S3W_SEVILLA' => ['2880_S3W.png'],
            '6580-O2_BLACKBURN' => ['6580_O2.png'],
        ]);

        $this->assertSame(['2880_O1_S1_S3.png'], self::names(VmFootwearSharePoint::imagesFor('2880-S3', $index)['paths']), 'S3W to inna wersja niż S3');
        $this->assertSame(['2880_O1_S1_S3.png'], self::names(VmFootwearSharePoint::imagesFor('2880-S1', $index)['paths']));
        $this->assertSame(['2880_S3W.png'], self::names(VmFootwearSharePoint::imagesFor('2880-S3W', $index)['paths']));
        $this->assertSame(['6580_O2.png'], self::names(VmFootwearSharePoint::imagesFor('6580-O2', $index)['paths']));
    }

    public function test_a_file_of_another_model_in_the_folder_does_not_belong_to_the_card(): void
    {
        $index = self::index([
            '8015-O6_DERBY' => ['8010-S7L LIVERPOOL_bottom.png', '8015-O6_S7L_DERBY_image_01.png'],
            '8010-S7L LIVERPOOL' => ['8010-S7L LIVERPOOL_01.png'],
        ]);

        $this->assertSame(['8015-O6_S7L_DERBY_image_01.png'], self::names(VmFootwearSharePoint::imagesFor('8015-O6', $index)['paths']));
        $this->assertSame(['8010-S7L LIVERPOOL_01.png', '8010-S7L LIVERPOOL_bottom.png'], self::names(VmFootwearSharePoint::imagesFor('8010-S7L', $index)['paths']));
    }

    public function test_two_digit_version_is_not_a_view_number(): void
    {
        $index = self::index(['4095-60_TEST' => ['4095_25.png', '4095_60.png'], '8005' => ['8005_2.png', '8005_1.png']]);

        $this->assertSame(['4095_60.png'], self::names(VmFootwearSharePoint::imagesFor('4095-60', $index)['paths']));
        $this->assertSame(['4095_25.png'], self::names(VmFootwearSharePoint::imagesFor('4095-25', $index)['paths']));
        $this->assertSame(['8005_1.png', '8005_2.png'], self::names(VmFootwearSharePoint::imagesFor('8005', $index)['paths']));
    }

    public function test_another_version_of_the_model_is_taken_when_the_card_code_has_no_png(): void
    {
        $index = self::index([
            '6655-O2_ACCRA' => ['6655-O2 ACCRA_01.png', '6655-O2 ACCRA_image_01.png'],
            '2290-S3BOA_WISCONSIN' => ['2290_S3_BOA.png'],
            '2290-S3ESD_WISCONSIN' => ['2290_S3ESD.png'],
        ]);

        $accra = VmFootwearSharePoint::imagesFor('6655-O6', $index);
        $this->assertFalse($accra['exact']);
        $this->assertSame(['6655-O2 ACCRA_image_01.png', '6655-O2 ACCRA_01.png'], self::names($accra['paths']));
        $this->assertSame(['2290_S3_BOA.png'], self::names(VmFootwearSharePoint::imagesFor('2290-S3LBOA', $index)['paths']));
        $this->assertSame(['2290_S3ESD.png'], self::names(VmFootwearSharePoint::imagesFor('2290-S3LESD', $index)['paths']));
    }

    public function test_set_with_only_the_sole_or_the_top_is_never_the_main_photo(): void
    {
        $index = self::index([
            '3660-S1PSESD_BERLIN' => ['3660-S1PSESD_berlin_bottom.png'],
            '3660-S1PLESD_BERLIN' => ['3660-S1PLESD_BERLIN_top.png', '3660-S1PLESD_BERLIN_image_01.png'],
            '9999-O1_SOLO' => ['9999_O1_bottom.png', '9999_O1_top.png'],
        ]);

        $berlin = VmFootwearSharePoint::imagesFor('3660-S1PSESD', $index);
        $this->assertFalse($berlin['exact']);
        $this->assertSame(['3660-S1PLESD_BERLIN_image_01.png', '3660-S1PLESD_BERLIN_top.png'], self::names($berlin['paths']));
        $this->assertSame([], VmFootwearSharePoint::imagesFor('9999-O1', $index)['paths']);
        $this->assertSame([], VmFootwearSharePoint::imagesFor('E1/15LI', $index)['paths']);
    }

    public function test_the_same_file_under_two_names_is_taken_once(): void
    {
        $index = [
            ['folder' => '4435-25_FONTANA', 'name' => '4435_25.png', 'path' => '/r/4435_25.png', 'size' => 955743],
            ['folder' => '4435-25_FONTANA', 'name' => '4435_25_image_01.png', 'path' => '/r/4435_25_image_01.png', 'size' => 955743],
            ['folder' => '4435-25_FONTANA', 'name' => '4435_25_02.png', 'path' => '/r/4435_25_02.png', 'size' => 812000],
        ];

        $this->assertSame(['/r/4435_25.png', '/r/4435_25_02.png'], VmFootwearSharePoint::imagesFor('4435-25', $index)['paths']);
    }

    public function test_card_code_is_split_into_model_number_and_version(): void
    {
        $this->assertSame(['6655', 'O6'], VmFootwearSharePoint::splitCode('6655-O6'));
        $this->assertSame(['E1', '15LI'], VmFootwearSharePoint::splitCode('E1/15LI'));
        $this->assertSame(['1020R', ''], VmFootwearSharePoint::splitCode('1020R'));
        $this->assertSame(['2295', 'S3LBOA'], VmFootwearSharePoint::splitCode('2295-S3LBOA'));
    }

    public function test_share_link_is_read_from_the_download_button_only(): void
    {
        $html = '<a href="https://www.sharepoint.com/x">info</a>'
            .'<a href="https://evil.example/:f:/g/personal/x/Abc">fałszywy</a>'
            .'<a href="https://vmfootwearcz-my.sharepoint.com/:f:/g/personal/share_vmfootwear_cz/EirGm?e=1&amp;x=2" target="_blank">Do pobrania</a>';

        $this->assertSame(
            'https://vmfootwearcz-my.sharepoint.com/:f:/g/personal/share_vmfootwear_cz/EirGm?e=1&x=2',
            VmFootwearSharePoint::shareLinkFrom($html),
        );
        $this->assertNull(VmFootwearSharePoint::shareLinkFrom('<a href="/obuv/">Obuwie</a>'));
    }

    public function test_reencoder_makes_bytes_smaller_and_keeps_transparency(): void
    {
        $image = imagecreatetruecolor(3000, 1200);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 1000, 400, 2000, 800, 0x3366CC);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $this->assertTrue(ImageReencoder::hasAlphaChannel($png));
        $smaller = ImageReencoder::fromBytes($png, 2000, 10_000);

        $this->assertNotNull($smaller);
        $this->assertSame(['image/png', 2000, 800], [$smaller['mime'], $smaller['width'], $smaller['height']]);
        $this->assertTrue(ImageReencoder::hasAlphaChannel($smaller['bytes']));
        $this->assertNull(ImageReencoder::fromBytes($png, 2000, 2500), 'dłuższy bok ponad limit źródła');
        $this->assertNull(ImageReencoder::fromBytes('to nie obraz', 2000, 10_000));

        $jpeg = imagecreatetruecolor(10, 10);
        ob_start();
        imagejpeg($jpeg);
        $this->assertFalse(ImageReencoder::hasAlphaChannel((string) ob_get_clean()));
    }

    /**
     * @param  array<string, list<string>>  $folders
     * @return list<array{folder: string, name: string, path: string, size: int}>
     */
    private static function index(array $folders): array
    {
        $out = [];
        foreach ($folders as $folder => $files) {
            foreach ($files as $name) {
                $out[] = ['folder' => $folder, 'name' => $name, 'path' => '/root/obuv - shoes/'.$folder.'/'.$name, 'size' => crc32($folder.$name)];
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function names(array $paths): array
    {
        return array_map('basename', $paths);
    }
}
