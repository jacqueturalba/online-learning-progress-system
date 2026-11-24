<?php

namespace App\Console;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\CommandFinished;
use RuntimeException;

class PreventDestructiveDatabaseCommands
{
    private const RESET_AUTHORIZATION_VARIABLE = 'ALLOW_LOCAL_ONLINE_LEARNING_RESET';

    private const RESET_AUTHORIZATION_VALUE = 'I_CONFIRM_RESET_LOCAL_ONLINE_LEARNING';

    private const BLOCKED_COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
        'db:wipe',
        'db:seed',
    ];

    private bool $authorizedSeedPending = false;

    public function handle(CommandStarting $event): void
    {
        if ($event->command === 'migrate:fresh' && $this->authorizeFreshSeed($event)) {
            $this->authorizedSeedPending = true;
            putenv(self::RESET_AUTHORIZATION_VARIABLE);
            unset($_ENV[self::RESET_AUTHORIZATION_VARIABLE], $_SERVER[self::RESET_AUTHORIZATION_VARIABLE]);

            return;
        }

        if ($event->command === 'db:seed' && $this->authorizeSeedInvokedByFresh($event)) {
            $this->authorizedSeedPending = false;

            return;
        }

        if (in_array($event->command, self::BLOCKED_COMMANDS, true)) {
            throw new RuntimeException("Blocked '{$event->command}': this project protects persistent local database records.");
        }
    }

    public function handleFinished(CommandFinished $event): void
    {
        if ($event->command === 'migrate:fresh') {
            $this->authorizedSeedPending = false;
        }
    }

    private function authorizeFreshSeed(CommandStarting $event): bool
    {
        if (getenv(self::RESET_AUTHORIZATION_VARIABLE) !== self::RESET_AUTHORIZATION_VALUE
            || ! app()->environment('local')
            || ! $event->input->hasParameterOption('--seed')) {
            return false;
        }

        foreach (['--database', '--drop-views', '--drop-types', '--force', '--path', '--realpath', '--schema-path', '--seeder', '--step'] as $option) {
            if ($event->input->hasParameterOption($option)) {
                return false;
            }
        }

        $connectionName = config('database.default');
        $connection = config("database.connections.{$connectionName}", []);
        $host = strtolower(trim((string) ($connection['host'] ?? '')));

        return $connectionName === 'mysql'
            && ($connection['driver'] ?? null) === 'mysql'
            && ($connection['database'] ?? null) === 'online_learning'
            && in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
            && empty($connection['url'])
            && empty($connection['unix_socket'])
            && empty($connection['read'])
            && empty($connection['write']);
    }

    private function authorizeSeedInvokedByFresh(CommandStarting $event): bool
    {
        if (! $this->authorizedSeedPending
            || ! app()->environment('local')
            || $event->input->getParameterOption('--database') !== 'mysql'
            || $event->input->getParameterOption('--class') !== 'Database\\Seeders\\DatabaseSeeder'
            || ! $event->input->hasParameterOption('--force')) {
            return false;
        }

        $connectionName = config('database.default');
        $connection = config("database.connections.{$connectionName}", []);

        return $connectionName === 'mysql'
            && ($connection['driver'] ?? null) === 'mysql'
            && ($connection['database'] ?? null) === 'online_learning'
            && in_array(strtolower(trim((string) ($connection['host'] ?? ''))), ['127.0.0.1', 'localhost', '::1'], true)
            && empty($connection['url'])
            && empty($connection['unix_socket'])
            && empty($connection['read'])
            && empty($connection['write']);
    }
}
