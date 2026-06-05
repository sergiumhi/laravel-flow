<?php

namespace Workbench\App\Flows;

use Sergiumhi\LaravelFlow\Flow;
use Sergiumhi\LaravelFlow\OnFailure;
use Workbench\App\Tasks\CancelReservationTask;
use Workbench\App\Tasks\ChargeCardTask;
use Workbench\App\Tasks\ConfirmBookingTask;
use Workbench\App\Tasks\ReserveRoomTask;
use Generator;

/**
 * Demonstrates automatic compensation (saga) and retries. ChargeCardTask retries
 * three times; if it still fails, OnFailure::REVERT immediately rolls back every
 * completed task in reverse — here cancelling the room reservation.
 *
 *     BookingFlow::start(['room' => '101']);                       // happy path
 *     BookingFlow::start(['room' => '101', 'fail_charge' => true]); // retries, then reverts
 */
class BookingFlow extends Flow
{
    public function run(array $payload): Generator
    {
        yield ReserveRoomTask::init($payload)
            ->revert(CancelReservationTask::class);

        yield ChargeCardTask::init($payload)
            ->onFailure(OnFailure::REVERT);

        yield ConfirmBookingTask::init();
    }
}
