<?php

namespace App\Service\Cron;

/**
 * Reads a cron entry point's environment value from $_SERVER, $_ENV or
 * getenv(), in that order, matching how Symfony's Dotenv populates them.
 */
final class CronEnv
{
    public static function read(string $name, string $default): string
    {
        if (array_key_exists($name, $_SERVER) && $_SERVER[$name] !== null) {
            return (string) $_SERVER[$name];
        }

        if (array_key_exists($name, $_ENV) && $_ENV[$name] !== null) {
            return (string) $_ENV[$name];
        }

        $value = getenv($name);
        if ($value !== false) {
            return (string) $value;
        }

        return $default;
    }
}
