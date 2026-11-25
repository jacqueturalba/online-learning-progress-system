<?php

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

const LOCAL_RESET_AUTHORIZATION = 'ALLOW_LOCAL_ONLINE_LEARNING_RESET';
const LOCAL_RESET_AUTHORIZATION_VALUE = 'I_CONFIRM_RESET_LOCAL_ONLINE_LEARNING';

it('uses only an isolated in-memory database for feature tests', function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
});

it('blocks destructive Artisan database commands', function (string $command) {
    $event = new CommandStarting($command, new ArrayInput([]), new BufferedOutput());

    expect(fn () => Event::dispatch($event))
        ->toThrow(RuntimeException::class, "Blocked '{$command}'");
})->with([
    'migrate:fresh',
    'migrate:refresh',
    'migrate:reset',
    'migrate:rollback',
    'db:wipe',
    'db:seed',
]);

it('allows only an explicitly authorized local online_learning fresh seed and its nested seeder', function () {
    config([
        'app.env' => 'local',
        'database.default' => 'mysql',
        'database.connections.mysql.driver' => 'mysql',
        'database.connections.mysql.database' => 'online_learning',
        'database.connections.mysql.host' => '127.0.0.1',
        'database.connections.mysql.url' => null,
        'database.connections.mysql.unix_socket' => null,
        'database.connections.mysql.read' => [],
        'database.connections.mysql.write' => [],
    ]);
    app()->instance('env', 'local');
    putenv(LOCAL_RESET_AUTHORIZATION.'='.LOCAL_RESET_AUTHORIZATION_VALUE);

    try {
        Event::dispatch(new CommandStarting('migrate:fresh', new ArrayInput(['--seed' => true]), new BufferedOutput()));

        expect(getenv(LOCAL_RESET_AUTHORIZATION))->toBeFalse();

        Event::dispatch(new CommandStarting('db:seed', new ArrayInput([
            '--database' => 'mysql',
            '--class' => 'Database\\Seeders\\DatabaseSeeder',
            '--force' => true,
        ]), new BufferedOutput()));
        Event::dispatch(new CommandFinished('migrate:fresh', new ArrayInput([]), new BufferedOutput(), 0));
    } finally {
        putenv(LOCAL_RESET_AUTHORIZATION);
    }
});

it('refuses reset authorization for unsafe environment or database targets', function (string $configKey, mixed $value) {
    config([
        'app.env' => 'local',
        'database.default' => 'mysql',
        'database.connections.mysql.driver' => 'mysql',
        'database.connections.mysql.database' => 'online_learning',
        'database.connections.mysql.host' => '127.0.0.1',
        'database.connections.mysql.url' => null,
        'database.connections.mysql.unix_socket' => null,
        'database.connections.mysql.read' => [],
        'database.connections.mysql.write' => [],
        $configKey => $value,
    ]);
    app()->instance('env', $configKey === 'app.env' ? $value : 'local');
    putenv(LOCAL_RESET_AUTHORIZATION.'='.LOCAL_RESET_AUTHORIZATION_VALUE);

    try {
        expect(fn () => Event::dispatch(new CommandStarting('migrate:fresh', new ArrayInput(['--seed' => true]), new BufferedOutput())))
            ->toThrow(RuntimeException::class, "Blocked 'migrate:fresh'");
    } finally {
        putenv(LOCAL_RESET_AUTHORIZATION);
    }
})->with([
    'non-local environment' => ['app.env', 'production'],
    'unexpected database name' => ['database.connections.mysql.database', 'production'],
    'remote database host' => ['database.connections.mysql.host', 'db.example.com'],
    'database URL' => ['database.connections.mysql.url', 'mysql://db.example.com/online_learning'],
]);
