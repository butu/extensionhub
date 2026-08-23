<?php

namespace App\Service\GitHub;

/**
 * Reads the GitHub API token from the process environment only.
 * The token is never persisted, logged, or stored anywhere by this class.
 */
class TokenProvider
{
    private const ENV_NAME = 'GITHUB_TOKEN';

    public function getToken(): ?string
    {
        $token = $_SERVER[self::ENV_NAME] ?? $_ENV[self::ENV_NAME] ?? getenv(self::ENV_NAME);

        if (!is_string($token)) {
            return null;
        }

        $token = trim($token);

        return $token === '' ? null : $token;
    }
}
