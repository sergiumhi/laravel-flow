<?php

namespace Sergiumhi\LaravelFlow;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Sergiumhi\LaravelFlow\Console\Commands\FlowPruneCommand;
use Sergiumhi\LaravelFlow\Console\Commands\FlowRunCommand;
use Sergiumhi\LaravelFlow\Console\Commands\FlowSignalCommand;
use Sergiumhi\LaravelFlow\Console\Commands\FlowStatusCommand;
use Sergiumhi\LaravelFlow\Events\FlowStateChanged;
use Sergiumhi\LaravelFlow\Events\FlowTaskStateChanged;
use Sergiumhi\LaravelFlow\Models\FlowModel;
use Sergiumhi\LaravelFlow\Models\FlowTask;
use Sergiumhi\LaravelFlow\Support\FlowModels;

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
        $this->registerStateChangeEvents();
    }

    /**
     * Emit a domain event on every real status transition. Implemented as model
     * `updated` listeners (not a refactor of the orchestrator's ~38 status
     * writes) so it is correct by construction: `wasChanged('status')` fires
     * only on an actual change and `getOriginal('status')` yields the pre-save
     * enum, giving a clean from→to with no column-write noise.
     *
     * Listeners are bound to the *configured* models so a host app that swaps
     * `flow.flow_model` / `flow.flow_tasks_model` still emits. The two bulk
     * cleanup updates in FlowOrchestrator bypass Eloquent events and dispatch
     * their own per-row events directly.
     */
    private function registerStateChangeEvents(): void
    {
        if (! config('flow.events.enabled', true)) {
            return;
        }

        FlowModels::flow()::updated(static function (FlowModel $flow): void {
            if ($flow->wasChanged('status')) {
                event(new FlowStateChanged($flow, $flow->getOriginal('status'), $flow->status));
            }
        });

        FlowModels::task()::updated(static function (FlowTask $task): void {
            if ($task->wasChanged('status')) {
                event(new FlowTaskStateChanged($task, $task->getOriginal('status'), $task->status));
            }
        });
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
