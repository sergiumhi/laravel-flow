<?php

namespace Sergiumhi\LaravelFlow;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Sergiumhi\LaravelFlow\Console\Commands\FlowPruneCommand;
use Sergiumhi\LaravelFlow\Console\Commands\FlowRunCommand;
use Sergiumhi\LaravelFlow\Console\Commands\FlowSignalCommand;
use Sergiumhi\LaravelFlow\Console\Commands\FlowStatusCommand;

class FlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/flow.php', 'flow');

        $this->app->singleton(FlowOrchestrator::class);
    }

    public function boot(): void
    {
        // Load-only: do NOT also publish migrations. publishesMigrations()
        // re-timestamps on publish, so a consumer that publishes and then runs
        // `migrate` would create the same tables twice. Load them and let the
        // host app run `php artisan migrate` as usual.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/flow.php' => $this->app->configPath('flow.php'),
            ], 'flow-config');

            $this->commands([
                FlowRunCommand::class,
                FlowStatusCommand::class,
                FlowSignalCommand::class,
                FlowPruneCommand::class,
            ]);
        }

        $this->registerPruneSchedule();
    }

    /**
     * Register the daily prune, but only when explicitly enabled — a library
     * should not silently delete data on every install.
     */
    private function registerPruneSchedule(): void
    {
        if (! config('flow.prune.schedule', false)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(config('flow.prune.command', FlowPruneCommand::class))->daily();
        });
    }
}
