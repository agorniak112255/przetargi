<?php

declare(strict_types=1);

namespace App\Services\Presta;

final class PrestaImageUrlBuilder
{
    /**
     * PrestaShop: 9329 → 9/3/2/9
     */
    public static function filesystemDir(int $imageId): string
    {
        if ($imageId <= 0) {
            return '';
        }

        return implode('/', str_split((string) $imageId));
    }

    /**
     * @return list<string>
     */
    public static function urls(string $shopUrl, int $imageId, string $linkRewrite): array
    {
        $shop = rtrim($shopUrl, '/');
        $dir = self::filesystemDir($imageId);
        if ($shop === '' || $imageId <= 0 || $dir === '') {
            return [];
        }

        $rewrite = $linkRewrite !== '' ? $linkRewrite : 'image';
        $out = [
            $shop.'/img/p/'.$dir.'/'.$imageId.'.jpg',
            $shop.'/img/p/'.$dir.'/'.$imageId.'-large_default.jpg',
            $shop.'/'.$imageId.'-large_default/'.$rewrite.'.jpg',
            $shop.'/'.$imageId.'-large_default/'.$rewrite.'.webp',
        ];

        return array_values(array_unique($out));
    }
}
