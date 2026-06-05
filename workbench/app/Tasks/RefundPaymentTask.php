<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;

/**
 * Compensating task for ProcessPaymentTask. Receives the original payment output
 * (e.g. charge_id) merged over the flow payload.
 */
class RefundPaymentTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        // In a real app: Stripe::refund($this->payload['charge_id']);
        return ['refunded' => $this->payload['charge_id'] ?? null];
    }
}
