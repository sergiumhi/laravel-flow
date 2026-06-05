<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;

/**
 * Compensating task for ChargeShippingTask.
 */
class CancelShippingTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return ['cancelled_shipment' => $this->payload['shipment_id'] ?? null];
    }
}
