<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;

/**
 * Charges the order. Whether the order needs manual review is driven by the
 * `requires_review` flag in the payload so the OrderFlow branch is easy to demo.
 */
class ProcessPaymentTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        // In a real app: Stripe::charge($this->payload['order_id']);
        return [
            'charge_id' => 'ch_'.($this->payload['order_id'] ?? 'unknown'),
            'requiresManualReview' => (bool) ($this->payload['requires_review'] ?? false),
        ];
    }
}
