<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;
use RuntimeException;

/**
 * Charges shipping. Fails on demand (payload `fail_shipping`) to demonstrate the
 * OnFailure::PAUSE behaviour and the manual retry/skip/cancel actions.
 */
class ChargeShippingTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        if ($this->payload['fail_shipping'] ?? false) {
            throw new RuntimeException('Shipping provider unavailable.');
        }

        return ['shipment_id' => 'shp_'.($this->payload['order_id'] ?? 'unknown')];
    }
}
