<?php

namespace App\Tests\Service\GitHub;

use App\Service\GitHub\TokenProvider;
use PHPUnit\Framework\TestCase;

class TokenProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['GITHUB_TOKEN'], $_ENV['GITHUB_TOKEN']);
        putenv('GITHUB_TOKEN');
    }

    public function testReturnsNullWhenTokenIsNotSet(): void
    {
        unset($_SERVER['GITHUB_TOKEN'], $_ENV['GITHUB_TOKEN']);
        putenv('GITHUB_TOKEN');

        self::assertNull((new TokenProvider())->getToken());
    }

    public function testReturnsNullWhenTokenIsEmptyString(): void
    {
        $_SERVER['GITHUB_TOKEN'] = '   ';

        self::assertNull((new TokenProvider())->getToken());
    }

    public function testReturnsTrimmedTokenWhenSet(): void
    {
        $_SERVER['GITHUB_TOKEN'] = '  ghp_example  ';

        self::assertSame('ghp_example', (new TokenProvider())->getToken());
    }
}
