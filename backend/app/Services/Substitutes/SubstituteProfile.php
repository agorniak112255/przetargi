<?php

declare(strict_types=1);

namespace App\Services\Substitutes;

/**
 * Profil ochronny karty do porównania zamienników: poziomy podane wprost (z cytatem), normy, rodzaj wyrobu i cechy
 * odczytane regułą z tekstu karty (te są wnioskiem automatu — `inferred` w dowodach).
 *
 * `levels`: klucz LevelChecker (en388, en407, ppe_category, footwear_class, ffp, snr) → wartość karty i krótki zapis
 * wymagania („fragment”), którym ten parametr sprawdza się na karcie zamiennika.
 */
final readonly class SubstituteProfile
{
    /**
     * @param  array<string, array{value: string, fragment: string, text: string, source: string, quote: ?string}>  $levels
     * @param  array<string, array{label: string, source: ?string}>  $norms  klucz normy (np. „en388”, „en352-2”, „iso18889”) → zapis i pole karty
     * @param  array{k: array<string, bool|string|null>, c: array<string, array{weak: bool, strong: bool}>}  $flags  cechy rodzaju (k) i dodatkowe (c)
     * @param  list<string>  $markings  oznaczenia obuwia z ciągów przy klasie, ze wszystkich pól (wymagania karty głównej)
     * @param  list<string>  $strongMarkings  te same, ale tylko z mocnych pól (potwierdzenie u zamiennika)
     */
    public function __construct(
        public int $productId,
        public string $family,
        public ?string $articleType,
        public bool $articleTypeFromName,
        public array $levels,
        public array $norms,
        public array $flags,
        public array $markings,
        public string $brandKey,
        public string $fingerprint,
        public array $strongMarkings = [],
    ) {}
}
