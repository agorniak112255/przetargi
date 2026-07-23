<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\MailSettingsService;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        try {
            $this->app->make(MailSettingsService::class)->applyToConfig();
        } catch (Throwable) {
            // migracje / brak DB — zostaw config z .env
        }
    }
}
