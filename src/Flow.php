<?php

namespace Sergiumhi\LaravelFlow;

use Generator;
use Sergiumhi\LaravelFlow\Jobs\FlowOrchestratorJob;
use Sergiumhi\LaravelFlow\Models\FlowModel;
use Sergiumhi\LaravelFlow\Support\FlowModels;

/**
 * Base class for a flow definition. Subclasses implement run() as a generator;
 * each `yield` hands a Task (or Signal) to the orchestrator.
 *
 * IMPORTANT: run() is re-executed from the start on every orchestrator wake-up to
 * rebuild the generator and fast-forward it. Keep run() side-effect free — no DB
 * writes, no API calls, no randomness. Everything with a side effect belongs in
 * Task::handle().
 *
 *     class OrderFlow extends Flow
 *     {
 *         public function run(array $payload): Generator
 *         {
 *             $payment = yield ProcessPaymentTask::init($payload)->revert(RefundPaymentTask::class);
 *             yield SendReceiptTask::init($payload);
 *         }
 *     }
 *
 *     OrderFlow::start(payload: ['order_id' => 1]);
 */
abstract class Flow
{
    protected FlowModel $record;

    /**
     * Define the sequence of tasks. Must be side-effect free.
     *
     * Receives the payload passed to start(), so control-flow decisions can read
     * it directly instead of reaching through the record.
     *
     * @param  array<string, mixed>  $payload
     */
    abstract public function run(array $payload): Generator;

    /**
     * Start a new flow run: persist the record, pre-seed statically-knowable
     * tasks, and dispatch the orchestrator.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function start(array $payload = []): static
    {
        $flowModel = FlowModels::flow()::query()->create([
            'flow_class' => static::class,
            'status' => FlowStatus::Pending,
            'payload' => $payload,
        ]);

        $flow = new static;
        $flow->record = $flowModel;

        app(FlowOrchestrator::class)->init($flowModel);

        FlowOrchestratorJob::dispatch($flowModel->public_id, static::class);

        return $flow;
    }

    /**
     * Load an existing flow run by its public id, instantiating the correct
     * flow subclass so control methods can be called on it.
     */
    public static function find(string $publicId): ?static
    {
        $record = FlowModels::flow()::where('public_id', $publicId)->first();

        if ($record === null) {
            return null;
        }

        $class = $record->flow_class;

        /** @var static $flow */
        $flow = new $class;
        $flow->record = $record;

        return $flow;
    }

    /**
     * Send a named signal to a flow that is waiting for it. Sending a signal a
     * flow is not currently waiting for (wrong name, or not waiting) is ignored.
     *
     * @param  array<string, mixed>  $payload
     */
    public function signal(string $name, array $payload = []): void
    {
        app(FlowOrchestrator::class)->deliverSignal($this->record, $name, $payload);
    }

    /**
     * Halt a flow without reverting: the remaining (not-yet-run) tasks are marked
     * cancelled and the flow ends `cancelled`. Completed work is left in place — no
     * compensating tasks run. Use cancelAndRevert() to also undo completed work.
     */
    public function cancel(): void
    {
        app(FlowOrchestrator::class)->cancel($this->record);
    }

    /**
     * Halt a flow and run the saga: completed tasks are reverted in reverse order,
     * the remaining tasks are marked cancelled, and the flow ends `reverted`.
     */
    public function cancelAndRevert(): void
    {
        app(FlowOrchestrator::class)->cancelAndRevert($this->record);
    }

    /**
     * Re-dispatch the currently failed task from scratch.
     */
    public function retryCurrentTask(): void
    {
        app(FlowOrchestrator::class)->retryCurrentTask($this->record);
    }

    /**
     * Re-dispatch only the failed children of a failed subtask parent, keeping
     * already-completed children. The flow resumes once every child finishes.
     */
    public function retryFailedSubtasks(): void
    {
        app(FlowOrchestrator::class)->retryFailedSubtasks($this->record);
    }

    /**
     * Reset the target task and everything after it, then re-run from there.
     */
    public function retryFromTask(string $taskPublicId): void
    {
        app(FlowOrchestrator::class)->retryFromTask($this->record, $taskPublicId);
    }

    /**
     * Skip the currently failed task and advance the flow.
     */
    public function skipCurrentTask(): void
    {
        app(FlowOrchestrator::class)->skipCurrentTask($this->record);
    }

    public function publicId(): string
    {
        return $this->record->public_id;
    }

    public function status(): FlowStatus
    {
        return $this->record->fresh()->status;
    }

    public function record(): FlowModel
    {
        return $this->record;
    }

    /**
     * Bind an existing record to this instance (used internally by the orchestrator).
     */
    public function bindRecord(FlowModel $record): void
    {
        $this->record = $record;
    }
}
