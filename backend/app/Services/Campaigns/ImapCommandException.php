<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use RuntimeException;

/** Serwer IMAP odpowiedział NO/BAD na komendę (połączenie działa dalej) — np. folderu nie da się otworzyć. */
class ImapCommandException extends RuntimeException {}
