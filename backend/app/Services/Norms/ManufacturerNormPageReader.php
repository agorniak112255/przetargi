<?php

declare(strict_types=1);

namespace App\Services\Norms;

/**
 * Czytnik norm ze strony wyrobu u producenta. Oddaje tylko to, co strona podaje dosłownie — bez składania kodu
 * z poziomów i bez dopisywania norm z domysłu. Tożsamość strony sprawdza wcześniej ManufacturerNormIdentity.
 */
interface ManufacturerNormPageReader
{
    public function supports(string $host): bool;

    public function read(string $html, string $url): ?NormPageReading;
}
