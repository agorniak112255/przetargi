<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Decyzja przeglądu opisu niemożliwa w obecnym stanie karty (HTTP 409): wersja nie czeka już na decyzję, opis jest
 * ze sklepu B2B, wersja z przebiegu w cieniu, na karcie czeka propozycja.
 */
final class ProductReviewConflict extends RuntimeException {}
