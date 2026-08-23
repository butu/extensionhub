<?php

declare(strict_types=1);

use App\Kernel;
use App\Service\Cron\CronAuthGuard;
use App\Service\Cron\CronCommandChainRunner;
use App\Service\Cron\CronDebugNotifier;
use App\Service\Cron\CronEnv;
use App\Service\Cron\CronLock;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$projectDir = dirname(__DIR__, 2);
(new Dotenv())->bootEnv($projectDir . '/.env');

$authGuard = new CronAuthGuard();

$providedHttpUser = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
$providedHttpPassword = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');

if (!$authGuard->isBasicAuthValid(
    CronEnv::read('CRON_HTTP_USER', ''),
    CronEnv::read('CRON_HTTP_PASSWORD', ''),
    $providedHttpUser,
    $providedHttpPassword,
)) {
    header('WWW-Authenticate: Basic realm="Extension Hub Cron"');
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Unauthorized\n";
    exit(1);
}

$providedToken = (string) ($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '');

if (!$authGuard->isTokenValid(CronEnv::read('CRON_EGO_TOKEN', ''), $providedToken)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Forbidden\n";
    exit(1);
}

ignore_user_abort(true);
set_time_limit(0);

$lock = new CronLock($projectDir . '/var/cron-import.lock');
if (!$lock->tryAcquire()) {
    http_response_code(409);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Another import is already running\n";
    exit(1);
}

$environment = CronEnv::read('APP_ENV', 'prod');
$debug = filter_var(CronEnv::read('APP_DEBUG', '0'), FILTER_VALIDATE_BOOL);

$kernel = new Kernel($environment, $debug);
$kernel->boot();

$application = new Application($kernel);
$application->setAutoExit(false);

$result = (new CronCommandChainRunner())->run(
    ['app:update-extensions', 'app:parse-comments', 'app:build-extension-snapshot'],
    static function (string $commandName) use ($application): array {
        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput([
            'command' => $commandName,
            '--no-interaction' => true,
        ]), $output);

        return [$code, $output->fetch()];
    },
);

(new CronDebugNotifier())->notify(
    CronEnv::read('CRON_DEBUG_EMAIL', 'ichbingenial@gmail.com'),
    (string) ($_SERVER['HTTP_HOST'] ?? 'unknown-host'),
    $environment,
    $result->exitCode,
    $result->lines,
);

$kernel->shutdown();
$lock->release();

header('Content-Type: text/plain; charset=UTF-8');
http_response_code($result->isSuccessful() ? 200 : 500);
echo implode("\n\n", $result->lines) . "\n";
exit($result->exitCode);
