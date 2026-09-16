<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use RuntimeException;

final class B2bConnectorRegistry
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
    ];

    /**
     * @return list<array{key: string, label: string, host: string, requires_password: bool, uses_discount_rules: bool}>
     */
    public function options(): array
    {
        return array_map(
            static fn (string $class): array => [
                'key' => $class::key(),
                'label' => $class::label(),
                'host' => $class::host(),
                // Witryna publiczna (protekt.pl) nie ma konta u dostawcy — formularz ukrywa pole hasła,
                // a cena zakupu powstaje z ceny katalogowej i rabatów zapisanych przy koncie.
                'requires_password' => ! is_a($class, B2bPublicSite::class, true),
                'uses_discount_rules' => is_a($class, B2bPublicSite::class, true),
            ],
            self::CONNECTORS,
        );
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
