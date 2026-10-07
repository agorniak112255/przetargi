<?php

use App\Http\Middleware\EnsureTenderAccess;
use App\Http\Middleware\LogApiActivity;
use App\Models\B2bSyncRun;
use App\Services\Auth\NetworkAccessPolicy;
use App\Services\Enrichment\JinaAccountService;
use App\Services\System\ScheduledTaskRecorder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Cache;
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
    // POST /api/broadcasting/auth (token Bearer) — autoryzacja kanałów prywatnych Reverb; poza `log.activity`
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']])
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
        // propozycje łączenia kart dystrybutora z kartami producenta — po nocnych przebiegach B2B, przed pracą
        $schedule->command('products:match-candidates')->dailyAt('06:10')->withoutOverlapping();
        // Comarch ERP XL: kopia towarów ze stanami i zakupami, potem powiązania z kartami — tylko przy ERPXL_ENABLED.
        // Jedyny odczyt XL w ciągu doby, o 2:00: odczyt stanów mocno obciąża serwer SQL, więc bez odświeżania w dzień
        // (decyzja użytkownika 29.09.2026; erp:stock zostaje do ręcznego uruchomienia).
        $schedule->command('erp:sync --match')->dailyAt('02:00')->withoutOverlapping(180)
            ->when(static fn (): bool => (bool) config('erpxl.enabled'));
        // historia zapasów: dni zapisane starszymi regułami liczenia przeliczane od nowa z ruchów partii XL (zwykle nic —
        // bez zapytań do XL); osobny proces (CLI 128 MB, po erp:sync), XL z przerwami 5 s / 5 s, kontrola przed zapisem;
        // 03:45 UTC = 5:45 w Polsce — po nocnych odczytach XL, przed pracą w XL
        $schedule->command('erp:inventory-history --outdated')->dailyAt('03:45')->withoutOverlapping(90)
            ->when(static fn (): bool => (bool) config('erpxl.enabled'));
        // Zapasy → RW → PW: pary RW i PW tego samego towaru z 12 miesięcy — jedno zapytanie XL, też tylko w nocy
        $schedule->command('erp:rw-pw')->dailyAt('02:50')->withoutOverlapping(60)
            ->when(static fn (): bool => (bool) config('erpxl.enabled'));
        // Kampanie: kontrahenci XL z e-mailami i tym, co kupowali (FS/PA z 24 mies.) — też tylko w nocy
        $schedule->command('erp:customers')->dailyAt('03:10')->withoutOverlapping(60)
            ->when(static fn (): bool => (bool) config('erpxl.enabled'));
        // zakładka Klienci: kontrahenci z zakupami w bieżącym roku od 100 zł netto (karta, osoby, opiekun) — jedno
        // zapytanie sumujące i odczyt kart wybranych, kilka sekund
        $schedule->command('erp:clients')->dailyAt('03:20')->withoutOverlapping(30)
            ->when(static fn (): bool => (bool) config('erpxl.enabled'));
        // wynik kampanii „kupili odbiorcy”: faktury i paragony z towarami kampanii z ostatnich ~37 dni — po erp:customers
        $schedule->command('erp:campaign-sales')->dailyAt('03:25')->withoutOverlapping(30)
            ->when(static fn (): bool => (bool) config('erpxl.enabled'));
        // propozycje z wyszukiwarki (bez modelu) dla towarów XL bez kodu — w nocy, bo każdy towar to zapytanie wektorowe
        $schedule->command('erp:suggest --limit=2000')->dailyAt('03:30')->withoutOverlapping(240)
            ->when(static fn (): bool => (bool) config('erpxl.enabled'));
        // zdjęcia, których źródło chwilowo odmówiło przy przebiegu opisu (zapora ansell.com) — do 8 prób, około doby
        $schedule->command('products:retry-images')->everyThreeHours(15)->withoutOverlapping(120)->runInBackground();
        // cenniki B2B: konta z harmonogramem (od 02:00) i „Sprawdź teraz”; każde konto rusza we własnym procesie w tle
        // (pełny przebieg trwa > 1 h), a samo b2b:sync-due kończy się w kilka sekund — stąd krótka blokada; co minutę,
        // żeby „Sprawdź teraz” ruszało bez czekania do 5 min (decyzja użytkownika 15.09.2026)
        $schedule->command('b2b:sync-due')->everyMinute()->withoutOverlapping(10)->runInBackground();
        // Kampanie: kolejna partia maili w limicie godzinowym skrzynki każdego nadawcy; blokada, żeby dwa przebiegi
        // nie wysłały tego samego adresu
        $schedule->command('campaigns:dispatch')->everyMinute()->withoutOverlapping(10)->runInBackground();
        // odpowiedzi klientów na kampanie: nagłówki skrzynek handlowców (IMAP, tylko odczyt), przyrostowo
        $schedule->command('campaigns:replies')->everyTenMinutes()->withoutOverlapping(30)->runInBackground();
        // wynik kampanii: stan pozycji po 7 i 30 dniach od wysyłki — po nocnym odczycie XL
        $schedule->command('campaigns:stock-followup')->dailyAt('04:40')->withoutOverlapping(30);
        // czat: nieodebrane i opuszczone rozmowy głosowe/wideo (uzgadnianie z LiveKit, żądania do 3 s na rozmowę)
        $schedule->command('chat:calls-expire')->everyMinute()->withoutOverlapping(5)->runInBackground();
        // sygnał, że cron serwera (schedule:run) działa — okno „Sprawdź teraz” ostrzega, gdy go brak
        $schedule->call(static function (): void {
            Cache::forever(B2bSyncRun::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String());
        })->everyMinute()->name('b2b-scheduler-heartbeat')->withoutOverlapping();
        // próbka salda Jina co godzinę — z niej panel liczy zużycie na dobę i datę wyczerpania
        $schedule->call(static function (): void {
            try {
                app(JinaAccountService::class)->snapshot();
            } catch (Throwable $e) {
                Log::info('Jina usage snapshot skipped', ['error' => $e->getMessage()]);
            }
        })->hourly()->name('jina-usage-snapshot')->withoutOverlapping();

        // Nowe zadania (od 03.10.2026) planowane w czasie polskim — istniejące wyżej zostają w UTC.
        // Biuletyn Zamówień Publicznych: ogłoszenia o zamówieniu i o wyniku z ostatnich dni, łączenie z przetargami
        $schedule->command('bzp:fetch')->dailyAt('06:30')->timezone('Europe/Warsaw')->withoutOverlapping(60);
        // przypomnienia o terminach składania i o wpisaniu wyniku (dzienne po 7:00, „3 godziny przed” w oknie terminu)
        $schedule->command('tenders:remind')->everyFifteenMinutes()->withoutOverlapping(10);
        // Stan systemu: harmonogram, konta dostawców, kolejka analiz — alert e-mailem dla administratora
        $schedule->command('system:check')->everyTenMinutes()->withoutOverlapping(10);
        // stare przebiegi zadań, wpisy wysłanych powiadomień i treść nieprzypiętych ogłoszeń z Biuletynu
        $schedule->command('system:prune')->dailyAt('04:35')->timezone('Europe/Warsaw');
        // karta klienta i cele handlowców: nagłówki faktur i paragonów klientów z zakładki Klienci (36 miesięcy) —
        // XL tylko w nocy, po erp:clients
        $schedule->command('erp:client-documents')->dailyAt('05:40')->timezone('Europe/Warsaw')->withoutOverlapping(30)
            ->when(static fn (): bool => (bool) config('erpxl.enabled'));
        // Przeglądy: katalog usług XL i pozycje faktur z usługami i towarami pozycji (okno 60 dni), potem terminy —
        // XL tylko w nocy, przed erp:client-documents
        $schedule->command('erp:inspections')->dailyAt('05:10')->timezone('Europe/Warsaw')->withoutOverlapping(60)
            ->when(static fn (): bool => (bool) config('erpxl.enabled'));
        // Przeglądy: adresy e-mail klientów bez adresu ze stron WWW (propozycje do zatwierdzenia) — w nocy, z limitem;
        // strony czyta wspólna kolejka czytnika, więc po opisach produktów, przed porannym odczytem XL
        $schedule->command('inspections:find-emails')->dailyAt('01:30')->timezone('Europe/Warsaw')->withoutOverlapping(180);
        // powiązania zapytań z klientami (zawsze) i podpowiedzi „możliwe zamówienie z oferty” (pozycje z XL tylko przy
        // ERPXL_ENABLED — sprawdza samo polecenie) — po erp:client-documents
        $schedule->command('inquiries:order-hints')->dailyAt('05:55')->timezone('Europe/Warsaw')->withoutOverlapping(30);
        // przypomnienia z notatek o klientach i o kończącej się ważności ofert (dzienne od 7:00)
        $schedule->command('crm:remind')->everyFifteenMinutes()->withoutOverlapping(10);

        // zapis przebiegów zadań (scheduled_task_runs) — musi być ostatni, żeby objął wszystkie zadania wyżej
        app(ScheduledTaskRecorder::class)->attach($schedule);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // klucz odrzucony przez dostęp z sieci (AppServiceProvider) — aplikacja wylogowuje i pokazuje ten komunikat
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->attributes->get('network_access_denied') !== true) {
                return null;
            }

            return response()->json([
                'message' => NetworkAccessPolicy::DENIED_MESSAGE,
                'reason' => 'network',
            ], 401);
        });
        $exceptions->render(function (UnauthorizedException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Brak uprawnień.'], 403);
            }

            return null;
        });
    })->create();
