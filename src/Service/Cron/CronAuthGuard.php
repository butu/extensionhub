<?php

namespace App\Service\Cron;

/**
 * Pure token/basic-auth checks shared by all cron import endpoints; no env
 * or superglobal access so it stays trivially testable.
 */
final class CronAuthGuard
{
    public function isBasicAuthValid(
        string $expectedUser,
        string $expectedPassword,
        string $providedUser,
        string $providedPassword,
    ): bool {
        if ($expectedUser === '' && $expectedPassword === '') {
            return true;
        }

        $validUser = $expectedUser !== '' && hash_equals($expectedUser, $providedUser);
        $validPassword = $expectedPassword !== '' && hash_equals($expectedPassword, $providedPassword);

        return $validUser && $validPassword;
    }

    public function isTokenValid(string $expectedToken, string $providedToken): bool
    {
        return $expectedToken !== '' && hash_equals($expectedToken, $providedToken);
    }
}
