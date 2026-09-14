<?php

use App\Http\Middleware\EnsureTenderAccess;
use App\Http\Middleware\LogApiActivity;
use App\Services\Enrichment\JinaAccountService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'log.activity' => LogApiActivity::class,
            'tender.access' => EnsureTenderAccess::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('activity-logs:prune')->dailyAt('02:15');
        $schedule->command('search-events:prune')->dailyAt('02:25');
        $schedule->command('storage:prune')->hourly();
        // cenniki B2B: konta z harmonogramem (od 02:00) i „Sprawdź teraz”; pełny przebieg trwa > 1 h
        $schedule->command('b2b:sync-due')->everyFiveMinutes()->withoutOverlapping(360)->runInBackground();
        // próbka salda Jina co godzinę — z niej panel liczy zużycie na dobę i datę wyczerpania
        $schedule->call(static function (): void {
            try {
                app(JinaAccountService::class)->snapshot();
            } catch (Throwable $e) {
                Log::info('Jina usage snapshot skipped', ['error' => $e->getMessage()]);
            }
        })->hourly()->name('jina-usage-snapshot')->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (UnauthorizedException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Brak uprawnień.'], 403);
            }

            return null;
        });
    })->create();
