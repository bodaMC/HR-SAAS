<?php

use Illuminate\Console\Application;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Symfony\Component\Console\Command\Command;

// Opt-in test runner: php vendor/bin/pest --bootstrap tests/bootstrap-isolated.php
// Only SQLite memory is permitted. No account provisioning or production configuration.
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

if (is_file($root.'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Use an isolated checkout without cached configuration.');
}

// Avoid missing dotenv warnings without reading or overwriting an existing test environment.
$testEnv = $root.'/.env.testing';
if (! file_exists($testEnv)) {
    file_put_contents($testEnv, '# Temporary isolated-test marker'.PHP_EOL);
    register_shutdown_function(static function () use ($testEnv): void {
        unlink($testEnv);
    });
}

foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'BCRYPT_ROUNDS' => '4',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}

// Fail closed if any existing test attempts a forbidden command.
Application::starting(function ($artisan) {
    foreach (['migrate:fresh', 'db:wipe', 'migrate:reset', 'migrate:refresh'] as $name) {
        $artisan->add(new class($name) extends Command
        {
            protected function execute($input, $output): int
            {
                throw new RuntimeException('Destructive command prohibited in isolated runner.');
            }
        });
    }
});

$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if ($app['config']['database.default'] !== 'sqlite' || $app['config']['database.connections.sqlite.database'] !== ':memory:') {
    throw new RuntimeException('Refusing to test outside SQLite memory.');
}
$connection = $app['db']->connection();
$connection->statement('PRAGMA foreign_keys = ON');
if ($kernel->call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
    throw new RuntimeException('Schema creation failed: '.$kernel->output());
}
RefreshDatabaseState::$inMemoryConnections[null] = $connection->getPdo();
RefreshDatabaseState::$inMemoryConnections['sqlite'] = $connection->getPdo();
RefreshDatabaseState::$migrated = true;

HandleExceptions::flushState();
