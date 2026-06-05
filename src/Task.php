<?php

namespace Sergiumhi\LaravelFlow;

/**
 * A Task is a thin wrapper around a unit of work. The actual work lives in handle().
 * There is no separate Laravel Job class to write — the framework generates and
 * dispatches the underlying queued job internally.
 *
 *     class ProcessPaymentTask extends Task
 *     {
 *         public function handle(): array
 *         {
 *             return ['charge_id' => 'ch_'.$this->payload['order_id']];
 *         }
 *     }
 */
abstract class Task
{
    /** @var array<string, mixed> */
    protected array $payload = [];

    protected ?string $revertClass = null;

    protected OnFailure $onFailure = OnFailure::PAUSE;

    /**
     * Perform the work. Read inputs from $this->payload. Return an array (the task's
     * output) or a SubTaskCollection to fan out into child tasks.
     *
     * @return array<string, mixed>|SubTaskCollection
     */
    abstract public function handle(): array|SubTaskCollection;

    /**
     * Build the task to yield, attaching the payload its handle() will read. The
     * payload is set once here — there is no separate ->with().
     *
     * @param  array<string, mixed>  $payload
     */
    public static function init(array $payload = []): static
    {
        $task = new static;
        $task->payload = $payload;

        return $task;
    }

    /**
     * Number of attempts before the task is considered finally failed. Override
     * per-task to enable retries.
     */
    public function tries(): int
    {
        return 1;
    }

    /**
     * Register the compensating task to run if this step is reverted.
     *
     * @param  class-string<Task>  $revertClass
     */
    public function revert(string $revertClass): static
    {
        $this->revertClass = $revertClass;

        return $this;
    }

    public function onFailure(OnFailure $onFailure): static
    {
        $this->onFailure = $onFailure;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * @return class-string<Task>|null
     */
    public function revertClass(): ?string
    {
        return $this->revertClass;
    }

    public function failureMode(): OnFailure
    {
        return $this->onFailure;
    }
}
