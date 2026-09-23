<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
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
