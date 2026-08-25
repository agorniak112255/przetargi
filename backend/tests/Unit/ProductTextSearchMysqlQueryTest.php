<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Search\ProductTextSearch;
use Tests\TestCase;

/**
 * Zestaw testów chodzi na SQLite, więc wariant FULLTEXT nie wykona się tu nigdy.
 * Sprawdzamy więc samo zapytanie — bez łączenia się z serwerem, bo PDO w Laravelu
 * jest leniwe i `toSql()` potrzebuje wyłącznie gramatyki MySQL.
 */
final class ProductTextSearchMysqlQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.mysql_probe' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => '3306',
                'database' => 'probe',
                'username' => 'probe',
                'password' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            'database.default' => 'mysql_probe',
        ]);
    }

    public function test_family_query_keeps_whole_assortment_and_ranks_by_relevance(): void
    {
        $query = $this->app->make(ProductTextSearch::class)
            ->fullTextQuery(['spodnie', '250gsm'], 'apparel', 50);

        $sql = $query->toSql();

        $this->assertStringContainsString('MATCH(search_blob) AGAINST (? IN BOOLEAN MODE) AS relevance', $sql);
        $this->assertStringContainsString('order by `relevance` desc', $sql);
        $this->assertStringContainsString('`ppe_family` = ?', $sql);
        $this->assertStringContainsString('limit 50', $sql);

        // Rodzina jest jedynym warunkiem — karty bez trafienia we frazę mają wejść
        // do wyniku z relevance 0, a nie wypaść z niego.
        $this->assertStringNotContainsString('where MATCH', $sql);
        $this->assertSame(['spodnie* 250gsm*', 'apparel'], $query->getBindings());
    }

    public function test_query_without_family_returns_only_matches(): void
    {
        $query = $this->app->make(ProductTextSearch::class)
            ->fullTextQuery(['kominiarka'], null, 20);

        $sql = $query->toSql();

        $this->assertStringContainsString('where MATCH(search_blob) AGAINST (? IN BOOLEAN MODE)', $sql);
        $this->assertStringNotContainsString('ppe_family', $sql);
        $this->assertSame(['kominiarka*', 'kominiarka*'], $query->getBindings());
    }

    public function test_tokens_are_normalized_and_canonicalized(): void
    {
        $tokens = $this->app->make(ProductTextSearch::class)
            ->tokens(['SPODNIE robocze', 'gramatura 250 g/m²', 'EN ISO 20471', 'XL']);

        $this->assertContains('spodnie', $tokens);
        $this->assertContains('250gsm', $tokens);
        $this->assertContains('en20471', $tokens);
        // Tokeny krótsze niż `innodb_ft_min_token_size` i tak nie wejdą do indeksu.
        $this->assertNotContains('xl', $tokens);
    }
}
