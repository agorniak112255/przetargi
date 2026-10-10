<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PriceList;
use App\Services\PriceLists\ReadOnlyGuard;
use App\Services\PriceLists\ReadOnlyViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

final class ReadOnlyGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_result_and_restores_cache_and_queue_config(): void
    {
        config(['cache.default' => 'file', 'queue.default' => 'sync']);

        $seen = app(ReadOnlyGuard::class)->run(static fn (): array => [config('cache.default'), config('queue.default'), PriceList::query()->count()]);

        $this->assertSame(['array', 'null', 0], $seen);
        $this->assertSame('file', config('cache.default'));
        $this->assertSame('sync', config('queue.default'));
    }

    public function test_write_throws_violation_and_is_rolled_back(): void
    {
        $list = $this->list();

        try {
            app(ReadOnlyGuard::class)->run(static fn () => PriceList::query()->whereKey($list->id)->update(['version' => 'zmienione']));
            $this->fail('Brak ReadOnlyViolation');
        } catch (ReadOnlyViolation $e) {
            $this->assertStringContainsString('update', mb_strtolower($e->getMessage()));
        }

        $this->assertSame('v1', $list->fresh()?->version);
    }

    public function test_swallowed_violation_is_rethrown_after_callback(): void
    {
        $this->expectException(ReadOnlyViolation::class);

        app(ReadOnlyGuard::class)->run(function (): string {
            try {
                DB::table('price_lists')->insert(['manufacturer' => 'X', 'manufacturer_key' => 'x', 'version' => '1', 'rows_total' => 0, 'products_created' => 0, 'products_updated' => 0, 'rows_skipped' => 0]);
            } catch (Throwable) {
                // kod podglądu połknął wyjątek
            }

            return 'ok';
        });
    }

    public function test_cache_writes_stay_in_array_store_and_writes_work_after_guard(): void
    {
        config(['cache.default' => 'array']);
        app(ReadOnlyGuard::class)->run(static fn () => Cache::put('podglad', 1, 60));

        $list = $this->list();
        $list->update(['version' => 'v2']);
        $this->assertSame('v2', $list->fresh()?->version);
    }

    public function test_write_is_blocked_before_execution_even_when_exception_is_swallowed(): void
    {
        $list = $this->list();

        $seen = null;
        try {
            app(ReadOnlyGuard::class)->run(function () use ($list, &$seen): void {
                try {
                    PriceList::query()->whereKey($list->id)->update(['version' => 'zmienione']);
                } catch (ReadOnlyViolation) {
                    // kod podglądu połknął wyjątek
                }
                // w tej samej transakcji: zapis nie został wykonany
                $seen = PriceList::query()->whereKey($list->id)->value('version');
            });
            $this->fail('Brak ReadOnlyViolation');
        } catch (ReadOnlyViolation) {
        }

        $this->assertSame('v1', $seen);
    }

    public function test_config_is_restored_when_callback_fails(): void
    {
        config(['cache.default' => 'file', 'queue.default' => 'sync']);

        try {
            app(ReadOnlyGuard::class)->run(static function (): void {
                throw new \RuntimeException('błąd podglądu');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('file', config('cache.default'));
        $this->assertSame('sync', config('queue.default'));
    }

    public function test_is_write_recognizes_write_statements(): void
    {
        $this->assertTrue(ReadOnlyGuard::isWrite('insert into x values (1)'));
        $this->assertTrue(ReadOnlyGuard::isWrite('  UPDATE x set a = 1'));
        $this->assertTrue(ReadOnlyGuard::isWrite('/* c */ delete from x'));
        $this->assertTrue(ReadOnlyGuard::isWrite('truncate table x'));
        $this->assertFalse(ReadOnlyGuard::isWrite('select * from updates'));
        $this->assertFalse(ReadOnlyGuard::isWrite('SAVEPOINT trans2'));
    }

    private function list(): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => 'Anro', 'manufacturer_key' => 'anro', 'version' => 'v1',
            'rows_total' => 0, 'products_created' => 0, 'products_updated' => 0, 'rows_skipped' => 0,
        ]);
    }
}
