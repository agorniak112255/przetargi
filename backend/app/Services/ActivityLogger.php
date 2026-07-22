<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class ActivityLogger
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'api_key',
        'openai_api_key',
        'authorization',
    ];

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function log(
        string $action,
        ?User $user = null,
        ?Model $subject = null,
        ?array $meta = null,
        ?Request $request = null,
    ): ActivityLog {
        $request ??= request();

        $meta = $meta ?? [];
        if ($user !== null) {
            $meta['user_name'] = $meta['user_name'] ?? $user->name;
            $meta['user_email'] = $meta['user_email'] ?? $user->email;
        }

        return ActivityLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => $this->truncate((string) ($request?->userAgent() ?? ''), 512),
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sanitizePayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $out = [];
        foreach ($payload as $key => $value) {
            $keyStr = (string) $key;
            if ($this->isSensitiveKey($keyStr)) {
                $out[$keyStr] = '[ukryte]';
                continue;
            }
            if (is_array($value)) {
                $out[$keyStr] = $this->sanitizePayload($value);
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $out[$keyStr] = is_string($value) ? $this->truncate($value, 500) : $value;
            }
        }

        return $out;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($lower === $sensitive || str_contains($lower, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 1).'…';
    }
}
