<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** @var list<string> */
    private array $strayRequests = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Test nie wychodzi do sieci. Kod aplikacji często połyka błąd żądania (kursy NBP, sonda limitu vLLM)
        // i idzie dalej na wartości zastępczej — wtedy sam wyjątek nie oblałby testu, więc zablokowane
        // żądania są zapisywane i oblewają test w tearDown.
        Http::preventStrayRequests();
        Http::globalMiddleware(fn (callable $handler): callable => function ($request, array $options) use ($handler) {
            try {
                return $handler($request, $options);
            } catch (StrayRequestException $e) {
                $this->strayRequests[] = (string) $request->getUri();

                throw $e;
            }
        });
    }

    protected function tearDown(): void
    {
        $stray = array_values(array_unique($this->strayRequests));
        $this->strayRequests = [];
        parent::tearDown();

        if ($stray !== []) {
            throw new AssertionFailedError('Test wysłał żądanie HTTP bez Http::fake: '.implode(', ', $stray));
        }
    }

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $this->rejectUnsafeRefreshDatabase($app);
        // Kontrolery zapytań klienta i eksportu Presta ustawiają set_time_limit(180) dla żądania HTTP.
        // W teście limit zostawał w procesie roboczym paratestu na kolejne pliki, a na Windows liczy
        // czas zegarowy — wolny test łącznika z PDF (Delta Plus, ATG) padał „Maximum execution time”
        // zależnie od tego, co ten proces uruchomił wcześniej. Każdy test startuje bez limitu.
        set_time_limit(0);

        return $app;
    }

    private function rejectUnsafeRefreshDatabase(Application $app): void
    {
        if (! isset($this->traitsUsedByTest[RefreshDatabase::class])) {
            return;
        }

        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");
        if ($connection === 'sqlite' && $database === ':memory:') {
            return;
        }

        throw new RuntimeException(
            'RefreshDatabase wolno tylko na sqlite :memory:. Uruchom testy z backend/phpunit.xml — bez --no-configuration.'
        );
    }
}
