<?php

namespace Sergiumhi\LaravelFlow\Events;

use Sergiumhi\LaravelFlow\FlowTaskStatus;
use Sergiumhi\LaravelFlow\Models\FlowTask;

/**
 * Dispatched whenever a single task's status changes from one state to another.
 *
 * Like {@see FlowStateChanged}, this is a plain domain event with no broadcast
 * coupling. Use `$task->flow` to reach the owning flow, and `from`/`to` to react
 * to a specific transition (e.g. a task moving to FlowTaskStatus::Failed).
 */
final class FlowTaskStateChanged
{
    public function __construct(
        public FlowTask $task,
        public FlowTaskStatus $from,
        public FlowTaskStatus $to,
    ) {}
}
