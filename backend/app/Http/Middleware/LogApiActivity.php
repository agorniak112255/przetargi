<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\ActivityActionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LogApiActivity
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly ActivityActionResolver $resolver,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $this->shouldLog($request, $response)) {
            return;
        }

        try {
            /** @var User|null $user */
            $user = $request->user();
            $resolved = $this->resolver->resolve($request);

            $meta = $resolved['meta'];
            $input = $request->except(['password', 'password_confirmation', 'token', 'api_key', 'openai_api_key']);
            if ($input !== []) {
                $meta['payload'] = $this->logger->sanitizePayload($input);
            }

            $this->logger->log(
                action: $resolved['action'],
                user: $user,
                subject: $resolved['subject'],
                meta: $meta,
                request: $request,
            );
        } catch (\Throwable) {
            // Audit nie może psuć odpowiedzi API.
        }
    }

    private function shouldLog(Request $request, Response $response): bool
    {
        if ($response->getStatusCode() >= 400) {
            return false;
        }

        $method = strtoupper($request->method());
        $path = trim($request->path(), '/');
        if (str_starts_with($path, 'api/')) {
            $path = substr($path, 4);
        }

        if ($path === 'logout' || str_starts_with($path, 'admin/activity-logs')) {
            return false;
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return true;
        }

        // Eksporty oferty (GET) też są istotnymi akcjami biznesowymi.
        return $method === 'GET' && preg_match('#^tenders/\d+/export/(excel|pdf|docx)$#', $path) === 1;
    }
}
