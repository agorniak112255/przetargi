<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik, który nie nadpisuje nazwy istniejącej karty. Nazwa ze źródła trafia tylko na nową kartę; karta już
 * w katalogu (powiązana z kontem albo znaleziona po kodzie) zachowuje swoją nazwę, a przebieg aktualizuje resztę.
 *
 * Decyzja użytkownika 15.09.2026 (Bollé): 254 karty Bolle mają polskie nazwy z cennika EMEA, sklep B2B podaje
 * angielskie nazwy rodzin („TRYON BSSI – Copper safety glasses”) — nadpisanie zmieniłoby wyszukiwanie i dopasowania.
 */
interface B2bKeepsExistingNames {}
