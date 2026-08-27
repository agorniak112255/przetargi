<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Str;

/**
 * Dodatkowe profile modeli z Ustawień AI. Każdy profil przejmuje wybrane zadania;
 * zadanie nieprzypisane nigdzie obsługuje konfiguracja główna.
 *
 * @phpstan-type Profile array{
 *     id: string,
 *     name: string,
 *     base_url: ?string,
 *     model: ?string,
 *     api_key: ?string,
 *     timeout_seconds: ?int,
 *     temperature: ?float,
 *     reasoning_effort: ?string,
 *     tasks: list<string>
 * }
 */
final class AiModelProfiles
{
    public const MAX_PROFILES = 12;

    /**
     * @param  list<array<string, mixed>>  $previous  profile już zapisane — źródło kluczy przy zamaskowanym wejściu
     * @return list<array<string, mixed>>
     */
    public static function normalize(mixed $raw, array $previous = []): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $keysById = [];
        foreach ($previous as $old) {
            $id = is_array($old) ? (string) ($old['id'] ?? '') : '';
            if ($id !== '' && is_string($old['api_key'] ?? null) && $old['api_key'] !== '') {
                $keysById[$id] = (string) $old['api_key'];
            }
        }

        $out = [];
        $claimed = [];
        foreach (array_values($raw) as $index => $item) {
            if (! is_array($item) || count($out) >= self::MAX_PROFILES) {
                continue;
            }

            $id = self::text($item['id'] ?? null) ?? Str::random(12);
            $profile = [
                'id' => $id,
                'name' => self::text($item['name'] ?? null) ?? 'Profil '.($index + 1),
                'base_url' => self::url($item['base_url'] ?? null),
                'model' => self::text($item['model'] ?? null),
                'api_key' => self::secret($item['api_key'] ?? null, $keysById[$id] ?? null),
                'timeout_seconds' => self::boundedInt($item['timeout_seconds'] ?? null, 10, 600),
                'temperature' => self::boundedFloat($item['temperature'] ?? null, 0.0, 2.0),
                'reasoning_effort' => ReasoningEffort::optional($item['reasoning_effort'] ?? null),
                'tasks' => self::tasks($item['tasks'] ?? null, $claimed),
            ];

            foreach ($profile['tasks'] as $task) {
                $claimed[$task] = true;
            }
            $out[] = $profile;
        }

        return $out;
    }

    /**
     * Wersja dla UI — klucz API tylko jako maska, nigdy w jawnej postaci.
     *
     * @param  list<array<string, mixed>>  $profiles
     * @return list<array<string, mixed>>
     */
    public static function publicView(array $profiles, ?callable $mask = null): array
    {
        return array_values(array_map(static function (array $profile) use ($mask): array {
            $key = is_string($profile['api_key'] ?? null) ? (string) $profile['api_key'] : '';

            return [
                'id' => (string) ($profile['id'] ?? ''),
                'name' => (string) ($profile['name'] ?? ''),
                'base_url' => $profile['base_url'] ?? null,
                'model' => $profile['model'] ?? null,
                'timeout_seconds' => $profile['timeout_seconds'] ?? null,
                'temperature' => $profile['temperature'] ?? null,
                'reasoning_effort' => $profile['reasoning_effort'] ?? null,
                'tasks' => array_values((array) ($profile['tasks'] ?? [])),
                'has_api_key' => $key !== '',
                'api_key_masked' => $key !== '' && $mask !== null ? $mask($key) : null,
            ];
        }, $profiles));
    }

    /**
     * @param  list<array<string, mixed>>  $profiles
     * @return array<string, mixed>|null
     */
    public static function forTask(array $profiles, AiTask $task): ?array
    {
        foreach ($profiles as $profile) {
            $tasks = (array) ($profile['tasks'] ?? []);
            if (in_array($task->value, $tasks, true)) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * @param  array<string, bool>  $claimed  zadania zajęte przez wcześniejszy profil
     * @return list<string>
     */
    private static function tasks(mixed $value, array $claimed): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $task) {
            $task = is_string($task) ? trim($task) : '';
            // Zadanie ma dokładnie jednego właściciela — inaczej nie dałoby się
            // przewidzieć, który profil wygra przy dwóch zaznaczeniach.
            if ($task === '' || isset($claimed[$task]) || in_array($task, $out, true)) {
                continue;
            }
            if (in_array($task, AiTask::keys(), true)) {
                $out[] = $task;
            }
        }

        return $out;
    }

    /** Zamaskowany albo pusty klucz z formularza oznacza „zostaw stary”. */
    private static function secret(mixed $incoming, ?string $existing): ?string
    {
        if (! is_string($incoming)) {
            return $existing;
        }
        $incoming = trim($incoming);
        if ($incoming === '' || str_contains($incoming, '*')) {
            return $existing;
        }

        return $incoming;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }

    private static function url(mixed $value): ?string
    {
        $value = self::text($value);

        return $value !== null ? rtrim($value, '/') : null;
    }

    private static function boundedInt(mixed $value, int $min, int $max): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max($min, min($max, (int) $value));
    }

    private static function boundedFloat(mixed $value, float $min, float $max): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max($min, min($max, (float) $value));
    }
}
