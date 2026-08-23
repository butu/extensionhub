<?php

namespace App\Tests\Service\Cron;

use App\Service\Cron\CronLock;
use PHPUnit\Framework\TestCase;

class CronLockTest extends TestCase
{
    private string $lockPath;

    protected function setUp(): void
    {
        $this->lockPath = sys_get_temp_dir() . '/cron-lock-test-' . uniqid() . '.lock';
    }

    protected function tearDown(): void
    {
        @unlink($this->lockPath);
    }

    public function testFirstAcquireSucceeds(): void
    {
        $lock = new CronLock($this->lockPath);

        self::assertTrue($lock->tryAcquire());

        $lock->release();
    }

    public function testOverlappingAcquireFailsUntilReleased(): void
    {
        $firstRun = new CronLock($this->lockPath);
        $secondRun = new CronLock($this->lockPath);

        self::assertTrue($firstRun->tryAcquire());
        self::assertFalse($secondRun->tryAcquire(), 'a second, overlapping run must not acquire the shared lock');

        $firstRun->release();

        self::assertTrue($secondRun->tryAcquire(), 'after release, a new run must acquire the lock again');

        $secondRun->release();
    }

    public function testLockDirectoryIsCreatedWhenMissing(): void
    {
        $nestedPath = sys_get_temp_dir() . '/cron-lock-test-' . uniqid() . '/nested/import.lock';
        $lock = new CronLock($nestedPath);

        self::assertTrue($lock->tryAcquire());

        $lock->release();
        @unlink($nestedPath);
        @rmdir(dirname($nestedPath));
        @rmdir(dirname($nestedPath, 2));
    }
}
