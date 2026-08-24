<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Wyszukiwarki nie znają tego produktu — zwykle kod wewnętrzny bez odpowiednika
 * w katalogu producenta. Ponawianie nic nie da, opis musi wpisać człowiek.
 */
final class ProductSourcesNotFoundException extends RuntimeException {}
