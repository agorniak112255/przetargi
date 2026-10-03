<?php

declare(strict_types=1);

namespace App\Services\Bzp;

use App\Support\CompanyName;

/**
 * Rozpoznanie naszej firmy wśród wykonawców z ogłoszenia o wyniku (config bzp.our_company).
 *
 * Najpierw NIP: wykonawca z poprawnym NIP-em jest nami tylko wtedy, gdy to nasz NIP — nazwa wtedy nie ma znaczenia
 * (inna firma o podobnej nazwie nie zostanie uznana za nas). Bez poprawnego NIP-u decyduje nazwa: klucz nazwy
 * (CompanyName::key) równy kluczowi jednej z naszych nazw albo zawierający ją jako całe słowa
 * („PHT SUPON Sp. z o.o.” → „pht supon” zawiera „supon”).
 */
final class OurCompany
{
    public static function nip(): ?string
    {
        return CompanyName::nip((string) config('bzp.our_company.nip', ''));
    }

    public static function matches(?string $name, ?string $nationalId): bool
    {
        $nip = CompanyName::nip($nationalId);
        if ($nip !== null) {
            return $nip === self::nip();
        }

        $key = CompanyName::key($name);
        if ($key === '') {
            return false;
        }
        foreach ((array) config('bzp.our_company.names', []) as $ourName) {
            $ourKey = CompanyName::key(is_string($ourName) ? $ourName : null);
            if ($ourKey !== '' && ($key === $ourKey || str_contains(' '.$key.' ', ' '.$ourKey.' '))) {
                return true;
            }
        }

        return false;
    }
}
