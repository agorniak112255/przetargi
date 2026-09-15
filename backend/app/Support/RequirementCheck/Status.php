<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

/**
 * Wynik porównania parametru wymagania z kartą. `missing` to „karta tego nie podaje” — nigdy
 * „nie spełnia”; `unclear` to wartość na karcie, której nie da się porównać bez człowieka
 * (inna nazwa cechy, sprzeczne pola, brak jednostki). Wniosek nigdy nie daje `ok`.
 * Klucze trafiają do JSON-a dla frontendu, więc ich nie zmieniamy.
 */
enum Status: string
{
    case Ok = 'ok';

    case Fail = 'fail';

    case Missing = 'missing';

    case Unclear = 'unclear';

    /**
     * Status wiersza z werdyktów pól karty: brak znalezisk → missing; pola sobie przeczą
     * (jedno spełnia, drugie wprost nie) → unclear, bo nie wiemy, któremu wierzyć; inaczej najgorszy.
     *
     * @param  list<self>  $verdicts
     */
    public static function fromCardVerdicts(array $verdicts): self
    {
        if ($verdicts === []) {
            return self::Missing;
        }
        if (in_array(self::Ok, $verdicts, true) && in_array(self::Fail, $verdicts, true)) {
            return self::Unclear;
        }

        return self::worst($verdicts);
    }

    /**
     * Najgorszy z listy (pozycje jednego kodu, np. EN 388): fail > unclear > missing > ok.
     * Pusta lista → missing.
     *
     * @param  list<self>  $statuses
     */
    public static function worst(array $statuses): self
    {
        foreach ([self::Fail, self::Unclear, self::Missing] as $candidate) {
            if (in_array($candidate, $statuses, true)) {
                return $candidate;
            }
        }

        return $statuses === [] ? self::Missing : self::Ok;
    }
}
