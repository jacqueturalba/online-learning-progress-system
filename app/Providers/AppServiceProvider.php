<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\OpenAiProvider;
use App\Support\AiProviderRegistry;
use App\Support\AiIntegrationPolicy;
use App\Support\AiServiceAssignmentPolicy;
use App\Models\AiIntegration;
use App\Models\AiServiceAssignment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Event;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\CommandFinished;
use App\Services\WhisperxProcessRunner;
use App\Services\SymfonyWhisperxProcessRunner;
use App\Console\PreventDestructiveDatabaseCommands;
use App\Services\WhisperxWorkerHeartbeat;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WhisperxProcessRunner::class, SymfonyWhisperxProcessRunner::class);
        $this->app->singleton(AiProviderRegistry::class, function (): AiProviderRegistry {
            $registry = new AiProviderRegistry();
            $registry->register(new OpenAiProvider());
            return $registry;
        });
    }
    public function boot(): void
    {
        Gate::policy(AiIntegration::class, AiIntegrationPolicy::class);
        Gate::policy(AiServiceAssignment::class, AiServiceAssignmentPolicy::class);
        $databaseCommandGuard = app(PreventDestructiveDatabaseCommands::class);
        Event::listen(CommandStarting::class, [$databaseCommandGuard, 'handle']);
        Event::listen(CommandFinished::class, [$databaseCommandGuard, 'handleFinished']);
        Event::listen(Looping::class, [app(WhisperxWorkerHeartbeat::class), 'looping']);
        Event::listen(WorkerStopping::class, [app(WhisperxWorkerHeartbeat::class), 'stopping']);
    }
}
