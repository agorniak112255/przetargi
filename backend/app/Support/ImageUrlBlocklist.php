<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Enrichment\ManufacturerProfile;

/**
 * Adresy grafik, które nie są zdjęciem wyrobu, a wchodziły na karty (audyt kart Coby 08.10.2026): zaślepka sklepu
 * „New Product Image Coming Soon” (rsdelivers, karta 11082), loga, banery, ekrany weryfikacji, ikony — po członach
 * nazwy pliku i ścieżki — oraz grafiki reklamowe witryny producenta po wzorcach z profilu
 * (config/manufacturer_profiles.php, coba: StandUpforHealth-PL.png, Modal_Elephant.png). Jedna bramka dla listy
 * zdjęć strony (ProductPageFetcher) i pobierania (ProductImageDownloader::downloadMany).
 *
 * Po nazwie nie da się złapać zdjęcia innego wyrobu („Ansell-PU610-DG-Gloves.jpg” na HyFlex 11-135) ani loga
 * zapisanego jako zwykłe zdjęcie („cropped-photo_….png” na portolana.pl — to ProductImageDownloader::isSiteIdentityGraphicUrl).
 */
final class ImageUrlBlocklist
{
    /**
     * Człony ścieżki adresu (małe litery). „-”, „_”, „.”, „/” i spacja rozdzielają człony tak samo, więc „no_image”
     * to to samo co „no-image”, a „icon-cart.png” i „icon.png” odpadają jednakowo. Tylko cały człon, nie podciąg:
     * „logo.png” i „site-logo.png” odpadają, „catalogo.jpg” zostaje; „flag-pl.png” odpada, „flagship-boots.jpg” zostaje.
     */
    private const GENERIC_MEMBERS = [
        'placeholder', 'no-image', 'noimage', 'coming-soon', 'comingsoon', 'image-coming',
        'logo', 'logotyp', 'banner', 'baner', 'captcha', 'cloudflare', 'favicon', 'sprite', 'avatar', 'payment',
        'flag', 'icon',
    ];

    /**
     * Powód pominięcia (do lastFailures / komunikatu karty) albo null, gdy adres może być zdjęciem wyrobu.
     * Wzorce ogólne patrzą na ścieżkę (bez zapytania), wzorce profilu — wyrażenia regularne — na cały adres.
     */
    public static function blocked(string $url, ?ManufacturerProfile $profile = null): ?string
    {
        $path = mb_strtolower(urldecode((string) (parse_url($url, PHP_URL_PATH) ?? '')), 'UTF-8');
        // „-standupforhealth-pl-png-”: każdy człon stoi między separatorami, więc „-logo-” nie trafi w „catalogo”
        $members = '-'.trim((string) preg_replace('/[-_.\/\s]+/u', '-', $path), '-').'-';
        foreach (self::isGenericExempt($url, $path) ? [] : self::GENERIC_MEMBERS as $member) {
            if (str_contains($members, '-'.$member.'-')) {
                return 'grafika witryny, nie zdjęcie wyrobu („'.$member.'” w adresie)';
            }
        }

        if ($profile !== null) {
            foreach ($profile->imageUrlBlocklist as $pattern) {
                // zły wzorzec w configu nie zatrzymuje pobierania — jak @getimagesizefromstring, bez ostrzeżenia
                if (@preg_match($pattern, $url) === 1) {
                    return 'grafika witryny producenta, nie zdjęcie wyrobu (lista profilu '
                        .($profile->profileKey ?? $profile->brandKey).')';
                }
            }
        }

        return null;
    }

    /**
     * Zdjęcie wyrobu PrestaShopu („/1234-large_default/kamizelka-z-logo.jpg”) i każdy adres naszego sklepu
     * (prestashop.shop_url): nazwa pliku to link_rewrite wyrobu, więc „kamizelka-z-logo” albo „baner-uwaga” to nazwa
     * towaru, nie grafika witryny — import z Presty (PrestaCatalogApplyService) tracił przez to wszystkie zdjęcia karty.
     * Wzorce z profilu producenta obowiązują dalej.
     */
    private static function isGenericExempt(string $url, string $path): bool
    {
        if (preg_match('#/\d+-[a-z0-9_]+_default/[^/]+$#', $path) === 1) {
            return true;
        }
        $shopHost = self::bareHost((string) config('prestashop.shop_url', ''));

        return $shopHost !== '' && self::bareHost($url) === $shopHost;
    }

    private static function bareHost(string $url): string
    {
        $host = mb_strtolower(trim((string) (parse_url(trim($url), PHP_URL_HOST) ?? '')), 'UTF-8');

        return (string) preg_replace('/^www\./', '', rtrim($host, '.'));
    }
}
