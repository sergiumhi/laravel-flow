<?php

namespace Sergiumhi\LaravelFlow\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Sergiumhi\LaravelFlow\FlowOrchestrator;
use Sergiumhi\LaravelFlow\Support\FlowModels;

/**
 * Runs a single task or subtask's handle() method. Retries and failure routing
 * are managed by the orchestrator (not Laravel's failed-job machinery) so the
 * behaviour is identical under the sync and async queue drivers.
 */
class FlowTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $taskPublicId,
        public bool $revert = false,
        public ?string $taskClass = null,
    ) {}

    public function displayName(): string
    {
        return $this->taskClass ?? self::class;
    }

    public function handle(FlowOrchestrator $orchestrator): void
    {
        $task = FlowModels::task()::where('public_id', $this->taskPublicId)->first();

        if ($task === null) {
            return;
        }

        if ($this->revert) {
            $orchestrator->runRevertTask($task);

            return;
        }

        $orchestrator->runTask($task);
    }
}
