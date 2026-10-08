<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ManufacturerProfiles;
use App\Support\ImageUrlBlocklist;
use Tests\TestCase;

/** Bramka adresów zdjęć po audycie kart Coby (08.10.2026): grafiki witryn po członach nazwy, wzorce z profilu. */
final class ImageUrlBlocklistTest extends TestCase
{
    private const HEALTH = 'https://www.coba.com/wp-content/uploads/2024/01/StandUpforHealth-PL.png';

    private const PHOTO = 'https://www.coba.com/wp-content/uploads/2021/03/HI010002-hygimat.png';

    public function test_generic_members_block_site_graphics_but_not_product_photos(): void
    {
        foreach ([
            'https://shop.example/img/logo.png',
            'https://shop.example/img/site-logo.png',
            'https://shop.example/img/Logo_Firmy.PNG',
            'https://shop.example/images/logo/firma.jpg',
            'https://shop.example/img/logotyp.svg',
            'https://shop.example/wp-content/uploads/placeholder.jpg',
            'https://shop.example/img/no-image.png',
            'https://shop.example/img/no_image.png',
            'https://shop.example/img/noimage.gif',
            'https://rsdelivers.example/img/product-image-coming-soon.jpg',
            'https://rsdelivers.example/img/comingsoon.jpg',
            'https://voelkner.example/media/banner_top.jpg',
            'https://shop.example/media/baner.jpg',
            'https://checkrego.example/cdn-cgi/captcha.png',
            'https://checkrego.example/img/cloudflare-challenge.png',
            'https://shop.example/favicon.ico',
            'https://shop.example/img/sprite.png',
            'https://discourse-cdn.example/user_avatar/unity.png',
            'https://shop.example/img/payment-visa.png',
            'https://shop.example/img/flag-pl.png',
            'https://shop.example/img/icon-cart.png',
            'https://shop.example/img/Modal%20Elephant%20logo.png',
        ] as $url) {
            $this->assertNotNull(ImageUrlBlocklist::blocked($url), $url);
        }

        foreach ([
            'https://shop.example/img/catalogo.jpg',
            'https://shop.example/img/rekawice-iconic-9.jpg',
            'https://shop.example/img/flagship-boots.jpg',
            'https://shop.example/img/avatarek-kask.jpg',
            'https://shop.example/img/paymentsafe-gloves.jpg',
            'https://shop.example/img/boots.jpg?utm=logo',
            self::PHOTO,
            self::HEALTH,
        ] as $url) {
            $this->assertNull(ImageUrlBlocklist::blocked($url), $url);
        }

        $this->assertSame(
            'grafika witryny, nie zdjęcie wyrobu („logo” w adresie)',
            ImageUrlBlocklist::blocked('https://shop.example/img/site-logo.png')
        );
    }

    public function test_profile_patterns_apply_only_to_their_brand(): void
    {
        $profiles = app(ManufacturerProfiles::class);
        $coba = $profiles->for(new Product(['sku' => 'HI010002', 'name' => 'Hygimat Szary', 'manufacturer' => 'Coba']));
        $ansell = $profiles->for(new Product(['sku' => '11135100', 'name' => 'HyFlex 11135', 'manufacturer' => 'Ansell']));
        $elephant = 'https://www.coba.com/wp-content/uploads/2024/01/modal_elephant.png';

        $this->assertSame(['/StandUpforHealth/i', '/Modal_Elephant/i'], $coba?->imageUrlBlocklist);
        $this->assertSame([], $ansell?->imageUrlBlocklist);
        $this->assertSame(
            'grafika witryny producenta, nie zdjęcie wyrobu (lista profilu coba)',
            ImageUrlBlocklist::blocked(self::HEALTH, $coba)
        );
        $this->assertNotNull(ImageUrlBlocklist::blocked($elephant, $coba), 'bez rozróżniania wielkości liter');
        $this->assertNull(ImageUrlBlocklist::blocked(self::PHOTO, $coba));
        $this->assertNull(ImageUrlBlocklist::blocked(self::HEALTH, $ansell));
        $this->assertNull(ImageUrlBlocklist::blocked(self::HEALTH, null));
        // wzorce ogólne obowiązują także z profilem
        $this->assertNotNull(ImageUrlBlocklist::blocked('https://www.coba.com/wp-content/uploads/logo.png', $coba));
    }

    /** Nazwa pliku Presty to link_rewrite wyrobu; adresy naszego sklepu bez wzorców ogólnych, profil obowiązuje. */
    public function test_presta_product_images_skip_generic_members(): void
    {
        config()->set('prestashop.shop_url', 'https://supon.rzeszow.pl');
        $coba = app(ManufacturerProfiles::class)->for(new Product(['sku' => 'HI010002', 'name' => 'Hygimat', 'manufacturer' => 'Coba']));

        $this->assertNull(ImageUrlBlocklist::blocked('https://supon.rzeszow.pl/1234-large_default/kamizelka-z-logo.jpg'));
        $this->assertNull(ImageUrlBlocklist::blocked('https://inny-sklep.example/77-home_default/baner-uwaga-wysokie-napiecie.jpg'));
        $this->assertNull(ImageUrlBlocklist::blocked('https://www.supon.rzeszow.pl/modules/galeria/logo-odblaskowe.png'));
        $this->assertNotNull(ImageUrlBlocklist::blocked('https://inny-sklep.example/themes/img/logo.png'));
        $this->assertNotNull(
            ImageUrlBlocklist::blocked('https://supon.rzeszow.pl/1234-large_default/modal_elephant.jpg', $coba),
            'wzorce profilu także na adresach Presty'
        );
    }

    public function test_broken_profile_pattern_is_ignored(): void
    {
        config()->set('manufacturer_profiles.profiles.coba.image_url_blocklist', ['/(/', '', '/Modal_Elephant/i']);
        $coba = app(ManufacturerProfiles::class)->for(new Product(['sku' => 'HI010002', 'name' => 'Hygimat', 'manufacturer' => 'Coba']));

        $this->assertSame(['/(/', '/Modal_Elephant/i'], $coba?->imageUrlBlocklist);
        $this->assertNull(ImageUrlBlocklist::blocked(self::PHOTO, $coba));
        $this->assertNotNull(ImageUrlBlocklist::blocked('https://www.coba.com/x/Modal_Elephant.png', $coba));
    }
}
