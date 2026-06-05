<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;

/**
 * Reserves a room. Has a compensating task (CancelReservationTask) so it can be
 * rolled back if a later step triggers a revert.
 */
class ReserveRoomTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return ['reservation_id' => 'res_'.($this->payload['room'] ?? 'unknown')];
    }
}
