<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Wyszukiwarki nie znają tego produktu — zwykle kod wewnętrzny bez odpowiednika
 * w katalogu producenta. Ponawianie nic nie da, opis musi wpisać człowiek.
 *
 * Nie jest final: ManufacturerPageMissingException (etap 3 opisów z cenników) to ten sam stan karty („manual”)
 * z powodem przeglądu manufacturer_missing — każde miejsce, które łapie ten wyjątek, obsługuje też tamten.
 */
class ProductSourcesNotFoundException extends RuntimeException {}
