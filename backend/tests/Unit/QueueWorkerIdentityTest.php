<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\QueueWorkerIdentity;
use Tests\TestCase;

final class QueueWorkerIdentityTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('QUEUE_WORKER_POOL');
        putenv('QUEUE_WORKER_INDEX');
        putenv('QUEUE_WORKER_COUNT');
        parent::tearDown();
    }

    public function test_numbers_prefetch_workers(): void
    {
        putenv('QUEUE_WORKER_POOL=prefetch');
        putenv('QUEUE_WORKER_INDEX=2');
        putenv('QUEUE_WORKER_COUNT=3');

        $this->assertSame('prefetch/2/3', QueueWorkerIdentity::label());
        $this->assertSame('SUPON-Prefetch/1.0 (prefetch/2/3)', QueueWorkerIdentity::userAgent('SUPON-Prefetch/1.0'));
    }

    public function test_falls_back_outside_queue_worker(): void
    {
        putenv('QUEUE_WORKER_POOL=');
        putenv('QUEUE_WORKER_INDEX=');
        putenv('QUEUE_WORKER_COUNT=');

        $this->assertSame('web', QueueWorkerIdentity::label());
    }
}
