<?php

namespace Sergiumhi\LaravelFlow;

/**
 * Lifecycle states for a flow run (the `flows` table).
 */
enum FlowStatus: string
{
    case Cancelled = 'cancelled'; // halted by cancel() — remaining tasks cancelled, no revert
    case Completed = 'completed';
    case Failed = 'failed';
    case Paused = 'paused';
    case Pending = 'pending';
    case Reverted = 'reverted';   // halted by cancelAndRevert() — completed tasks compensated
    case Reverting = 'reverting';
    case Running = 'running';
    case Waiting = 'waiting';
}
