<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;

/**
 * Final BookingFlow step: confirm the booking once the room is reserved and the
 * card is charged.
 */
class ConfirmBookingTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return ['confirmed' => true];
    }
}
