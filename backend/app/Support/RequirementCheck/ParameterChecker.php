<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

/**
 * Jedna grupa parametrów porównania (wymiary, poziomy i klasy, cechy, kolor). Bez wywołań
 * modelu — wynik ma być powtarzalny i każdy werdykt ma dać się wskazać w źródle.
 */
interface ParameterChecker
{
    /** Klucz grupy w JSON-ie: dimensions | levels | flags | color. */
    public function group(): string;

    /**
     * @param  list<CardSource>  $cardSources
     * @return list<CheckRow>
     */
    public function check(string $requirement, array $cardSources): array;
}
