<?php

namespace Deployer;

require 'recipe/symfony.php';

use RuntimeException;

use function Deployer\after;
use function Deployer\askConfirmation;
use function Deployer\before;
use function Deployer\desc;
use function Deployer\download;
use function Deployer\get;
use function Deployer\info;
use function Deployer\import;
use function Deployer\parse;
use function Deployer\run;
use function Deployer\runLocally;
use function Deployer\set;
use function Deployer\task;
use function Deployer\test;

set('application', 'extensionhub');
set('repository', 'git@github-extensionhub:butu/extensionhub.git');

import(__DIR__ . '/.hosts.yaml');

set('keep_releases', 2);
set('allow_anonymous_stats', false);
set('env', [
    'APP_ENV' => 'prod',
    'APP_DEBUG' => '0',
]);

set('shared_files', ['.env.local']);
set('shared_dirs', ['var/log', 'public/data']);
set('writable_dirs', ['var']);
set('writable_mode', 'chmod');
set('writable_use_sudo', false);
set('writable_chmod_mode', '775');

set('bin/php', '/usr/bin/php');
set('composer_options', '--verbose --prefer-dist --no-progress --no-interaction --no-dev --optimize-autoloader --no-scripts');
set('symfony_console', '{{bin/php}} {{release_path}}/bin/console');

desc('Run Symfony cache clear in production mode');
task('deploy:cache_prod', function () {
    run('cd {{release_path}} && {{bin/php}} bin/console cache:clear --env=prod --no-debug');
});

after('deploy:vendors', 'deploy:cache_prod');

desc('Run Doctrine migrations');
task('app:migrate', function () {
    run('cd {{release_path}} && {{bin/php}} bin/console doctrine:migrations:migrate --no-interaction --env=prod --no-debug --allow-no-migration');
});

after('deploy:symlink', 'app:migrate');

desc('Generate extension snapshot on server');
task('app:build_snapshot', function () {
    run('cd {{release_path}} && {{bin/php}} bin/console app:build-extension-snapshot --env=prod --no-debug');
});

after('app:migrate', 'app:build_snapshot');

set('db_dump_options', '--single-transaction --quick --lock-tables=false --default-character-set=utf8mb4');
set('db_dump_dir', 'var/db');
set('db_import', true);

desc('Pull the production database and import it into the local DDEV database');
task('db:pull', function () {
    $credentials = databaseCredentials(remoteDatabaseUrl(), 'remote .env.local');

    $timestamp = date('Ymd-His');
    $remoteFile = parse("/tmp/{{application}}-db-$timestamp.sql.gz");
    $dumpDir = parse('{{db_dump_dir}}');
    $localFile = "$dumpDir/" . parse("{{application}}-db-$timestamp.sql.gz");

    info('Dumping remote database ' . $credentials['name']);
    run(sprintf(
        'MYSQL_PWD=%s mysqldump --host=%s --port=%d --user=%s %s %s | gzip -9 > %s',
        escapeshellarg($credentials['pass']),
        escapeshellarg($credentials['host']),
        $credentials['port'],
        escapeshellarg($credentials['user']),
        get('db_dump_options'),
        escapeshellarg($credentials['name']),
        escapeshellarg($remoteFile),
    ), ['timeout' => 1800]);

    runLocally('mkdir -p ' . escapeshellarg($dumpDir));
    download($remoteFile, $localFile);
    run('rm -f ' . escapeshellarg($remoteFile));

    info("Dump stored in $localFile");

    if (!get('db_import')) {
        return;
    }

    if (!askConfirmation("Overwrite the local DDEV database with $localFile?", true)) {
        info('Import skipped.');

        return;
    }

    $local = databaseCredentials(localDatabaseUrl(), 'local .env.local');

    // The DDEV web container ships a ~/.my.cnf with root credentials, and option files
    // outrank MYSQL_PWD. Locally the password therefore has to be passed as an option.
    $localClient = sprintf(
        '--host=%s --port=%d --user=%s --password=%s',
        escapeshellarg($local['host']),
        $local['port'],
        escapeshellarg($local['user']),
        escapeshellarg($local['pass']),
    );

    runLocally(sprintf(
        'mysql %s -e %s',
        $localClient,
        escapeshellarg(sprintf('DROP DATABASE IF EXISTS `%s`; CREATE DATABASE `%s`;', $local['name'], $local['name'])),
    ));

    runLocally(sprintf(
        'gunzip -c %s | mysql %s %s',
        escapeshellarg($localFile),
        $localClient,
        escapeshellarg($local['name']),
    ), ['timeout' => 1800]);

    info('Local database ' . $local['name'] . ' updated.');
});

/**
 * Reads DATABASE_URL from the shared env file on the target host.
 */
function remoteDatabaseUrl(): string
{
    $envFile = parse('{{deploy_path}}/shared/.env.local');

    if (!test('[ -f ' . escapeshellarg($envFile) . ' ]')) {
        throw new RuntimeException("Remote env file not found: $envFile");
    }

    $line = run('grep -m1 "^DATABASE_URL=" ' . escapeshellarg($envFile) . ' || true');

    if ($line === '') {
        throw new RuntimeException("No DATABASE_URL found in $envFile");
    }

    return $line;
}

/**
 * Reads DATABASE_URL from the local env files, `.env.local` wins over `.env`.
 */
function localDatabaseUrl(): string
{
    foreach ([__DIR__ . '/.env.local', __DIR__ . '/.env'] as $envFile) {
        if (!is_readable($envFile)) {
            continue;
        }

        if (preg_match('/^DATABASE_URL=.*$/m', (string)file_get_contents($envFile), $matches) === 1) {
            return $matches[0];
        }
    }

    throw new RuntimeException('No DATABASE_URL found in local .env.local or .env');
}

/**
 * Turns a `DATABASE_URL=...` env line into connection parts.
 *
 * @return array{host: string, port: int, user: string, pass: string, name: string}
 */
function databaseCredentials(string $envLine, string $source): array
{
    $url = trim(trim(substr(trim($envLine), strlen('DATABASE_URL='))), "\"'");
    $dsn = parse_url($url);

    if ($dsn === false || !isset($dsn['scheme'], $dsn['path'])) {
        throw new RuntimeException("Could not parse DATABASE_URL from $source");
    }

    if (!in_array($dsn['scheme'], ['mysql', 'mysqli', 'mariadb'], true)) {
        throw new RuntimeException(sprintf('db:pull supports MySQL/MariaDB only, %s uses "%s".', $source, $dsn['scheme']));
    }

    return [
        'host' => $dsn['host'] ?? '127.0.0.1',
        'port' => (int)($dsn['port'] ?? 3306),
        'user' => rawurldecode($dsn['user'] ?? ''),
        'pass' => rawurldecode($dsn['pass'] ?? ''),
        'name' => ltrim(rawurldecode($dsn['path']), '/'),
    ];
}

after('deploy:failed', 'deploy:unlock');
