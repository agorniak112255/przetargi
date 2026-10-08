<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use LogicException;
use Throwable;

/**
 * Pobranie pliku ponad limit łącznika w Http::fake tak, jak przerywa je curl w Guzzle (sprawdzone 08.10.2026 na
 * lokalnym serwerze, Laravel 12 + Guzzle 7): atrapa nie wywołuje on_headers ani progress sama, więc robi to tutaj.
 *
 * - nagłówek Content-Length ponad limit: wyjątek z on_headers przerywa transfer, a odpowiedź to RequestException
 *   „An error was encountered during the on_headers event” z naszym wyjątkiem w łańcuchu (Laravel robi z niej
 *   ConnectionException z tym samym, ogólnym komunikatem);
 * - bez nagłówka limit łapie dopiero progress — jego wyjątek wychodzi z curla bez opakowania.
 */
final class OversizedFileDownload
{
    /**
     * @param  array<string, mixed>  $options  opcje Guzzle żądania (drugi argument wywołania zwrotnego Http::fake)
     */
    public static function abortLikeCurl(Request $request, array $options, bool $withContentLength = true): PromiseInterface
    {
        if (! $withContentLength) {
            ($options['progress'])(0, 16_000_000, 0, 0);

            throw new LogicException('limit rozmiaru w progress nie przerwał pobierania');
        }

        try {
            ($options['on_headers'])(new Response(200, ['Content-Type' => 'application/pdf', 'Content-Length' => '20000000']));
        } catch (Throwable $e) {
            return Create::rejectionFor(new RequestException(
                'An error was encountered during the on_headers event',
                $request->toPsrRequest(),
                null,
                $e,
            ));
        }

        throw new LogicException('limit rozmiaru w on_headers nie przerwał pobierania');
    }
}
