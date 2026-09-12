<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

/**
 * Odróżnia awarię wyszukiwarki od realnego braku wyników.
 *
 * Rozróżnienie jest wspólne dla dwóch decyzji, więc mieszka w jednym miejscu:
 * czy produkt bez karty idzie do „wpisz ręcznie”, czy wraca do kolejki
 * (ProductEnrichmentService), oraz czy wolno zapamiętać, że zapytanie nic nie
 * dało (DuckDuckGoHtmlSearch). Zapamiętanie awarii jako „nie ma nic” zamroziłoby
 * na godziny wynik, którego nikt nie sprawdził.
 */
final class SearchEngineOutage
{
    public static function matches(string $detail): bool
    {
        $msg = mb_strtolower($detail);

        if (str_contains($msg, 'silniki zablokowane')
            || str_contains($msg, 'too many requests')
            || str_contains($msg, 'captcha')
            || str_contains($msg, 'bez fallbacku publicznego')
            || str_contains($msg, 'nie odpowiada')
            || str_contains($msg, 'curl error')
        ) {
            return true;
        }

        // „Google HTTP 429”, „DuckDuckGo HTTP 202”, „Qwant HTTP 403” — silnik
        // odmówił odpowiedzi; 404 zostawiamy, bo to realnie brak strony.
        return preg_match('/\bhttp (202|401|403|407|429|5\d\d)\b/', $msg) === 1;
    }
}
