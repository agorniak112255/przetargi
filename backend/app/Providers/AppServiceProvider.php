<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\MailSettingsService;
use App\Services\Presta\PrestaCatalogGateway;
use App\Services\Presta\PrestaShopCatalogClient;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PrestaCatalogGateway::class, PrestaShopCatalogClient::class);
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
