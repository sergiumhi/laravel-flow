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
 * Wakes the orchestrator to advance a flow: rebuild the generator, replay
 * completed steps, and dispatch the next task (or mark the flow completed).
 */
class FlowOrchestratorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $flowPublicId,
        public ?string $flowClass = null,
    ) {}

    public function displayName(): string
    {
        return $this->flowClass ?? self::class;
    }

    public function handle(FlowOrchestrator $orchestrator): void
    {
        $flow = FlowModels::flow()::where('public_id', $this->flowPublicId)->first();

        if ($flow === null) {
            return;
        }

        $orchestrator->advance($flow);
    }
}
