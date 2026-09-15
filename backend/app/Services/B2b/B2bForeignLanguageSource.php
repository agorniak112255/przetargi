<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik, którego sklep podaje nazwy i opisy w obcym języku. Opis zapisany przez import (i nazwa nowej karty
 * łącznika B2bKeepsExistingNames) trafia po zapisie do tłumaczenia na polski (TranslateB2bProductTextJob).
 * Sam łącznik nadal podaje tekst dosłownie ze źródła.
 *
 * Decyzja użytkownika 15.09.2026: opisy i nazwy nowych kart z importu mają być po polsku, bez przechowywania
 * oryginalnego opisu. Język źródła jest cechą łącznika, a nie zgadywaniem z treści — karty JSP po polsku mają
 * angielskie wstawki („INNER PACK – Height: 12CM”), a krótkie sekcje Bollé nie mają angielskich słów funkcyjnych.
 */
interface B2bForeignLanguageSource {}
