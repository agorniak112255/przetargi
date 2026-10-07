<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Odmowa w logowaniu z kodem (NetworkAccessCodeService) — gotowa odpowiedź JSON według kontraktu:
 * - 422 reason `challenge_invalid`: logowanie trzeba zacząć od hasła,
 * - 422 errors.code: zły albo przeterminowany kod, można próbować dalej,
 * - 422 bez reason: nie udało się wysłać maila,
 * - 429 retry_after: za wcześnie albo za dużo prób.
 */
final class NetworkAccessCodeException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $body
     */
    private function __construct(string $message, private readonly int $status, private readonly array $body)
    {
        parent::__construct($message);
    }

    public static function invalid(string $message): self
    {
        return new self($message, 422, ['message' => $message, 'reason' => 'challenge_invalid']);
    }

    public static function wrongCode(string $message): self
    {
        return new self($message, 422, ['message' => $message, 'errors' => ['code' => [$message]]]);
    }

    public static function mailFailed(): self
    {
        $message = 'Nie udało się wysłać kodu e-mailem. Spróbuj ponownie za chwilę albo poproś administratora o pomoc.';

        return new self($message, 422, ['message' => $message]);
    }

    public static function tooMany(string $message, int $retryAfter): self
    {
        return new self($message, 429, ['message' => $message, 'retry_after' => max(1, $retryAfter)]);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json($this->body, $this->status);
    }
}
