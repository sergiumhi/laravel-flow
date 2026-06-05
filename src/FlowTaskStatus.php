<?php

namespace Sergiumhi\LaravelFlow;

/**
 * Lifecycle states for a single task or subtask (the `flow_tasks` table).
 */
enum FlowTaskStatus: string
{
    case Abandoned = 'abandoned'; // seeded at start, never reached (branch not taken)
    case Cancelled = 'cancelled'; // halted by a cancel before it could run
    case Completed = 'completed';
    case Failed = 'failed';
    case Pending = 'pending';
    case Reverted = 'reverted';
    case Reverting = 'reverting';
    case Running = 'running';
    case Skipped = 'skipped';
    case Waiting = 'waiting';
}
