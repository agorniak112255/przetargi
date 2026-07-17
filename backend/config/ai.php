<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('AI_ENABLED', false),
    'provider' => env('AI_PROVIDER', 'openai_compatible'),
    'base_url' => rtrim((string) env('AI_BASE_URL', 'https://api.openai.com/v1'), '/'),
    'api_key' => env('AI_API_KEY'),
    'model' => env('AI_MODEL', 'gpt-4o-mini'),
    'timeout_seconds' => (int) env('AI_TIMEOUT', 90),
    'temperature' => (float) env('AI_TEMPERATURE', 0.1),
];
