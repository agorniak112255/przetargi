<?php

declare(strict_types=1);

namespace App\Services\PriceLists\Importers;

use InvalidArgumentException;

/**
 * Rejestr importerów cenników z plików (10.10.2026) — jak B2bConnectorRegistry: nowy cennik = nowa klasa dopisana
 * tutaj. Cennik wiąże się z importerem kolumną price_lists.importer_key (price-lists:bind albo wybór administratora
 * w panelu) — nigdy po id cennika (różne id lokalnie i na produkcji).
 */
class PriceListImporterRegistry
{
    /** @var list<class-string<PriceListImporter>> */
    public const IMPORTERS = [
        Mapa\MapaPriceListImporter::class,
    ];

    /** @return class-string<PriceListImporter>|null */
    public function classFor(?string $key): ?string
    {
        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }
        foreach ($this->classes() as $class) {
            if ($class::key() === $key) {
                return $class;
            }
        }

        return null;
    }

    public function make(string $key): PriceListImporter
    {
        $class = $this->classFor($key);
        if ($class === null) {
            throw new InvalidArgumentException('Nie ma importera o kluczu „'.$key.'”.');
        }

        return app($class);
    }

    /** @return list<array{key: string, label: string, version: int, manufacturer_keys: list<string>}> */
    public function options(): array
    {
        $options = [];
        foreach ($this->classes() as $class) {
            $options[] = [
                'key' => $class::key(),
                'label' => $class::label(),
                'version' => $class::version(),
                'manufacturer_keys' => $class::manufacturerKeys(),
            ];
        }
        usort($options, fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $options;
    }

    /** @return list<class-string<PriceListImporter>> */
    public function classes(): array
    {
        return self::IMPORTERS;
    }
}
