<?php

namespace App\Tests\Service\Cron;

use PHPUnit\Framework\TestCase;

class GithubCronEndpointTest extends TestCase
{
    public function testRefreshAndDiscoveryUseSeparateCommands(): void
    {
        $cronDirectory = dirname(__DIR__, 3) . '/public/cron';
        $refresh = file_get_contents($cronDirectory . '/import-github-refresh.php');
        $discovery = file_get_contents($cronDirectory . '/import-github-discover.php');

        self::assertNotFalse($refresh);
        self::assertNotFalse($discovery);
        self::assertStringContainsString("['--refresh'] = true", $refresh);
        self::assertStringNotContainsString("['--discover'] = true", $refresh);
        self::assertStringContainsString("['--discover'] = true", $discovery);
        self::assertStringNotContainsString("['--refresh'] = true", $discovery);
        self::assertFileDoesNotExist($cronDirectory . '/import-github.php');
    }
}
