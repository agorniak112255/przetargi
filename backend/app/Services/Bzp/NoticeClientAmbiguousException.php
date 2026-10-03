<?php

declare(strict_types=1);

namespace App\Services\Bzp;

use RuntimeException;

/**
 * Zamawiającemu z ogłoszenia odpowiada kilku klientów (NoticeClientMatcher) — przetarg nie powstaje, dopóki człowiek
 * nie wskaże klienta (client_id). Niesie listę kandydatów do pokazania w oknie „Załóż przetarg”.
 */
final class NoticeClientAmbiguousException extends RuntimeException
{
    /**
     * @param  list<array{id: int, name: string, nip: ?string, city: ?string}>  $candidates
     */
    public function __construct(public readonly array $candidates)
    {
        parent::__construct('W zakładce Klienci jest kilku klientów pasujących do zamawiającego z ogłoszenia. Wybierz, którego użyć.');
    }
}
