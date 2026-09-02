<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\MailSettingsService;
use App\Services\Presta\PrestaCatalogGateway;
use App\Services\Presta\PrestaExportGateway;
use App\Services\Presta\PrestaShopCatalogClient;
use App\Services\Presta\PrestaShopExportClient;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PrestaCatalogGateway::class, PrestaShopCatalogClient::class);
        $this->app->bind(PrestaExportGateway::class, PrestaShopExportClient::class);
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
