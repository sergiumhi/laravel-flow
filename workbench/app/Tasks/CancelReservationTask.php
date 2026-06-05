<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;

/**
 * Compensating task for ReserveRoomTask.
 */
class CancelReservationTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return ['cancelled_reservation' => $this->payload['reservation_id'] ?? null];
    }
}
