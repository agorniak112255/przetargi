<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use RuntimeException;

/**
 * Bez „final” — testy API panelu podmieniają w kontenerze make() na atrapę łącznika.
 */
class B2bConnectorRegistry
{
    /**
     * Nowa witryna B2B = nowa klasa łącznika dopisana tutaj.
     *
     * @var list<class-string<B2bConnector>>
     */
    private const CONNECTORS = [
        AnroB2bConnector::class,
        SignProjectB2bConnector::class,
        JspB2bConnector::class,
        BolleB2bConnector::class,
        UvexB2bConnector::class,
        ProtektB2bConnector::class,
        ArtraB2bConnector::class,
        AtgB2bConnector::class,
        TegroB2bConnector::class,
        PolstarB2bConnector::class,
        ArdonB2bConnector::class,
        RawpolB2bConnector::class,
        DeltaplusB2bConnector::class,
        ProceraB2bConnector::class,
        MmmB2bConnector::class,
        MascotB2bConnector::class,
        JhkB2bConnector::class,
        P4sB2bConnector::class,
        MaviboB2bConnector::class,
    ];

    /** Reguły rabatu konta liczą cenę zakupu z ceny katalogowej (witryna publiczna, protekt.pl). */
    public const DISCOUNT_RULES_PRICE = 'price';

    /**
     * Reguły rabatu konta to rabat standardowy dostawcy (UVEX): cena konta porównana z cennikiem bazowym
     * pokazuje cenę specjalną, a cena zakupu zostaje ceną konta.
     */
    public const DISCOUNT_RULES_STANDARD = 'standard';

    /**
     * @return list<array{key: string, label: string, host: string, requires_password: bool, uses_discount_rules: bool, discount_rules_mode: 'price'|'standard'|null, requires_login_code: bool}>
     */
    public function options(): array
    {
        return array_map(
            static function (string $class): array {
                $mode = self::modeForClass($class);

                return [
                    'key' => $class::key(),
                    'label' => $class::label(),
                    'host' => $class::host(),
                    // Witryna publiczna (protekt.pl) nie ma konta u dostawcy — formularz ukrywa pole hasła,
                    // a cena zakupu powstaje z ceny katalogowej i rabatów zapisanych przy koncie.
                    'requires_password' => ! is_a($class, B2bPublicSite::class, true),
                    // Łącznik treści (artra.pl) żadnej ceny nie pobiera, więc rabat nie ma od czego liczyć —
                    // formularz nie ma po co pytać o reguły rabatowe.
                    'uses_discount_rules' => $mode !== null,
                    // To samo pole reguł, dwa znaczenia — panel musi opisać, co wpisany rabat zmieni.
                    'discount_rules_mode' => $mode,
                    // Witryna z kodem jednorazowym z e-maila (3M) — panel pokazuje „Zaloguj kodem” przy koncie.
                    'requires_login_code' => is_a($class, B2bCodeLoginSite::class, true),
                ];
            },
            self::CONNECTORS,
        );
    }

    /**
     * Znaczenie reguł rabatu konta dla łącznika: DISCOUNT_RULES_PRICE, DISCOUNT_RULES_STANDARD albo null
     * (łącznik reguł nie używa, także nieznany klucz).
     *
     * @return 'price'|'standard'|null
     */
    public function discountRulesMode(?string $key): ?string
    {
        $class = $key !== null ? $this->classFor($key) : null;

        return $class !== null ? self::modeForClass($class) : null;
    }

    /** Czy reguły rabatu konta znaczą rabat standardowy dostawcy (B2bStandardDiscountSite, UVEX). */
    public function usesStandardDiscounts(?string $key): bool
    {
        return $this->discountRulesMode($key) === self::DISCOUNT_RULES_STANDARD;
    }

    /**
     * Klucz łącznika konta: zapisany przy koncie, a przy starszych kontach bez niego — z witryn.
     * To samo rozstrzygnięcie co w make().
     */
    public function keyForAccount(B2bAccount $account): ?string
    {
        $key = trim((string) $account->connector);

        return $key !== '' ? $key : $this->keyForSites($account->sites ?? []);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(static fn (string $class): string => $class::key(), self::CONNECTORS);
    }

    public function has(string $key): bool
    {
        return $this->classFor($key) !== null;
    }

    /** Czy łącznik loguje się u dostawcy. Witryna publiczna (protekt.pl) hasła nie ma. */
    public function requiresPassword(?string $key): bool
    {
        $class = $key !== null ? $this->classFor($key) : null;

        return $class === null || ! is_a($class, B2bPublicSite::class, true);
    }

    /** Czy łącznik loguje się kodem jednorazowym z e-maila (B2bCodeLoginSite). */
    public function requiresLoginCode(?string $key): bool
    {
        $class = $key !== null ? $this->classFor($key) : null;

        return $class !== null && is_a($class, B2bCodeLoginSite::class, true);
    }

    public function label(?string $key): ?string
    {
        $class = $key !== null ? $this->classFor($key) : null;

        return $class !== null ? $class::label() : null;
    }

    /**
     * @param  list<string>  $sites
     */
    public function keyForSites(array $sites): ?string
    {
        foreach (self::CONNECTORS as $class) {
            foreach ($sites as $site) {
                if (str_contains(mb_strtolower($site), $class::host())) {
                    return $class::key();
                }
            }
        }

        return null;
    }

    public function make(B2bAccount $account, int $delayMs = 150): B2bConnector
    {
        $key = trim((string) $account->connector);
        if ($key === '') {
            $key = (string) $this->keyForSites($account->sites ?? []);
        }
        $class = $this->classFor($key);
        if ($class === null) {
            throw new RuntimeException(
                'Dla witryn tego konta nie ma łącznika. Obsługiwane: '
                .implode(', ', array_map(static fn (string $c): string => $c::host(), self::CONNECTORS))
            );
        }

        return $class::forAccount($account, $delayMs);
    }

    /**
     * @param  class-string<B2bConnector>  $class
     * @return 'price'|'standard'|null
     */
    private static function modeForClass(string $class): ?string
    {
        if (is_a($class, B2bStandardDiscountSite::class, true)) {
            return self::DISCOUNT_RULES_STANDARD;
        }
        if (is_a($class, B2bPublicSite::class, true) && ! is_a($class, B2bContentOnlySite::class, true)) {
            return self::DISCOUNT_RULES_PRICE;
        }

        return null;
    }

    /**
     * @return class-string<B2bConnector>|null
     */
    private function classFor(string $key): ?string
    {
        foreach (self::CONNECTORS as $class) {
            if ($class::key() === $key) {
                return $class;
            }
        }

        return null;
    }
}
