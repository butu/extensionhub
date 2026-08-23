<?php

namespace App\Service\Cron;

/**
 * Sends the debug e-mail cron endpoints have always sent after a run. The
 * mailer callable defaults to PHP's mail() but can be swapped out in tests.
 */
final class CronDebugNotifier
{
    /**
     * @param callable(string, string, string, string): bool $mailer
     */
    public function __construct(
        private $mailer = 'mail',
    ) {}

    /**
     * @param string[] $lines
     */
    public function notify(string $recipient, string $host, string $environment, int $exitCode, array $lines): void
    {
        if ($recipient === '') {
            return;
        }

        $status = $exitCode === 0 ? 'OK' : 'FAILED';
        $subject = sprintf('[Extension Hub Cron] %s (%s)', $status, $host);
        $body = sprintf(
            "Host: %s\nEnvironment: %s\nStatus: %s\nTimestamp: %s\n\n%s\n",
            $host,
            $environment,
            $status,
            date(DATE_ATOM),
            implode("\n\n", $lines)
        );
        $headers = sprintf('From: cron@%s', preg_replace('/^www\./', '', $host));

        ($this->mailer)($recipient, $subject, $body, $headers);
    }
}
