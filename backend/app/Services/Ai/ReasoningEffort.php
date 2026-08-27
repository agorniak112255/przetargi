<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Qwen3.8 myśli w xhigh, dopóki żądanie nie obniży reasoning_effort
 * albo nie wyłączy myślenia (none).
 */
final class ReasoningEffort
{
    public const AUTO = 'auto';

    public const OFF = 'off';

    public const NONE = 'none';

    public const LOW = 'low';

    public const MEDIUM = 'medium';

    public const XHIGH = 'xhigh';

    /** @var list<string> */
    public const ALL = [
        self::AUTO,
        self::OFF,
        self::NONE,
        self::LOW,
        self::MEDIUM,
        self::XHIGH,
    ];

    public static function normalize(mixed $value, string $fallback = self::AUTO): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, self::ALL, true) ? $value : $fallback;
    }

    public static function optional(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = self::normalize($value, '');

        return $normalized !== '' ? $normalized : null;
    }
}
