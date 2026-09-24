<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Warunek oferty w liście do klienta. Handlowiec często wpisuje samą liczbę
 * („7”, „20,00”), a w liście „Termin realizacji: 7” nie mówi, czy to dni, czy
 * złotówki. Samej liczbie dopisujemy jednostkę, której to pole zawsze używa.
 * Każdy inny tekst („przedpłata”, „7 dni roboczych”, „25 zł brutto”) idzie do
 * listu dokładnie tak, jak go wpisano — nie zgadujemy, co autor miał na myśli.
 *
 * Dni to po prostu „dni”: dopisanie „roboczych” byłoby obietnicą, której
 * handlowiec nie złożył. W polu formularza zostaje to, co wpisano; jednostka
 * powstaje tylko w liście.
 */
final class OfferTermText
{
    /** Pola, w których sama liczba oznacza liczbę dni. */
    private const DAY_TERMS = ['lead_time', 'payment', 'validity'];

    public static function forLetter(string $key, string $value): string
    {
        $value = trim($value);

        if ($key === 'delivery') {
            if (preg_match('/^\d+(?:[.,]\d{1,2})?$/', $value) !== 1) {
                return $value;
            }

            // Ceny w liście są netto, więc koszt dostawy też.
            return number_format((float) str_replace(',', '.', $value), 2, ',', ' ').' zł netto';
        }

        if (in_array($key, self::DAY_TERMS, true) && preg_match('/^\d+$/', $value) === 1) {
            return $value.((int) $value === 1 ? ' dzień' : ' dni');
        }

        return $value;
    }
}
