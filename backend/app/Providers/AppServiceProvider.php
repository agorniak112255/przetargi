<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\Ai\AiServedProviderTally;
use App\Services\Auth\NetworkAccessPolicy;
use App\Services\B2b\B2bSyncLauncher;
use App\Services\B2b\BackgroundB2bSyncLauncher;
use App\Services\B2b\InlineB2bSyncLauncher;
use App\Services\Enrichment\EnrichmentAttemptLog;
use App\Services\Enrichment\EnrichmentLiveProgress;
use App\Services\Erp\ErpXlClient;
use App\Services\Erp\ErpXlGateway;
use App\Services\MailSettingsService;
use App\Services\Presta\PrestaCatalogGateway;
use App\Services\Presta\PrestaExportGateway;
use App\Services\Presta\PrestaShopCatalogClient;
use App\Services\Presta\PrestaShopExportClient;
use App\Support\BrandDictionary;
use App\Support\ProductVariantFacts;
use App\Support\StorageOwnership;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AiServedProviderTally::class);
        // adresy sieci lokalnej czytane raz na żądanie
        $this->app->scoped(NetworkAccessPolicy::class);
        // jedna instancja na żądanie albo zadanie kolejki — słownik czytany raz, nie przy każdej karcie
        $this->app->scoped(BrandDictionary::class);
        $this->app->scoped(EnrichmentAttemptLog::class);
        $this->app->scoped(EnrichmentLiveProgress::class);
        // warianty kart czytane raz na żądanie/zadanie (bramka wariantu, ocena AI, wybór wariantu do oferty)
        $this->app->scoped(ProductVariantFacts::class);
        $this->app->bind(PrestaCatalogGateway::class, PrestaShopCatalogClient::class);
        $this->app->bind(PrestaExportGateway::class, PrestaShopExportClient::class);
        $this->app->bind(ErpXlGateway::class, ErpXlClient::class);
        // b2b:sync-due: w testach przebieg w tym samym procesie (bez podprocesów), poza testami — proces w tle na konto
        $this->app->bind(B2bSyncLauncher::class, function ($app): B2bSyncLauncher {
            if ($app->runningUnitTests()) {
                return $app->make(InlineB2bSyncLauncher::class);
            }

            return new BackgroundB2bSyncLauncher(
                $app->make(InlineB2bSyncLauncher::class),
                PHP_BINARY,
                base_path('artisan'),
                storage_path('logs/b2b-sync.log'),
            );
        });
    }

    public function boot(): void
    {
        // Polecenie artisan puszczone jako root zostawia w storage pliki należące do roota, a aplikacja
        // (php-fpm, cron, kolejki) działa jako właściciel witryny i nie może ich nadpisać — po każdym
        // poleceniu prostujemy właściciela. Nie-root kończy się na sprawdzeniu identyfikatora użytkownika.
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            $basePath = $this->app->basePath();
            register_shutdown_function(static function () use ($basePath): void {
                $fixed = StorageOwnership::restoreAfterRoot($basePath);
                if ($fixed > 0 && defined('STDERR')) {
                    fwrite(STDERR, 'Przywrócono właściciela plików w storage po uruchomieniu jako root: '.$fixed.PHP_EOL);
                }
            });
        }

        // Logowanie hasłem: 10 prób na minutę na e-mail i adres (adres biura jest wspólny, więc nie sam adres).
        // Za hasłem stoi kod e-mailem dla kont spoza sieci — limit chroni też przed zgadywaniem hasła na wyścigi.
        RateLimiter::for('login', static fn (Request $request): Limit => Limit::perMinute(10)
            ->by(mb_strtolower(trim((string) $request->input('email'))).'|'.$request->ip())
            ->response(static fn (Request $request, array $headers) => response()->json(
                ['message' => 'Za dużo prób logowania. Spróbuj ponownie za minutę.'],
                429,
                $headers,
            )));
        // Wysyłka i sprawdzanie kodu e-mailem — z jednego adresu; limity na konto liczy NetworkAccessCodeService.
        RateLimiter::for('network-code', static fn (Request $request): Limit => Limit::perMinute(20)
            ->by($request->ip())
            ->response(static fn (Request $request, array $headers) => response()->json(
                ['message' => 'Za dużo prób. Spróbuj ponownie za minutę.', 'retry_after' => 60],
                429,
                $headers,
            )));

        // Dostęp z sieci przy każdym żądaniu z kluczem (wszystkie trasy auth:sanctum, także autoryzacja kanałów czatu).
        // Klucze sprzed zmiany ustawienia przestają działać od razu. Logowanie sesją (guard web) tego nie sprawdza —
        // dziś go nie ma (bez statefulApi()); włączenie go wymaga tej samej kontroli.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            if (! $isValid || ! $token->tokenable instanceof User) {
                return $isValid;
            }
            $request = $this->app->make('request');
            $policy = $this->app->make(NetworkAccessPolicy::class);
            if ($policy->allows($token->tokenable, $request->ip())) {
                return true;
            }
            // odpowiedź 401 dostaje powód (i czy można potwierdzić dostęp kodem), żeby aplikacja wylogowała
            // i powiedziała dlaczego (bootstrap/app.php)
            $request->attributes->set('network_access_denied', $policy->deniedBody($token->tokenable));

            return false;
        });

        try {
            $this->app->make(MailSettingsService::class)->applyToConfig();
        } catch (Throwable) {
            // migracje / brak DB — zostaw config z .env
        }
    }
}
