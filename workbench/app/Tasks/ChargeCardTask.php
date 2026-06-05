<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;
use RuntimeException;

/**
 * Charges the card. Retries up to three times (tries() = 3). When the payload
 * `fail_charge` flag is set it fails every attempt, exhausts its retries, and —
 * because BookingFlow declares OnFailure::REVERT for it — triggers the saga that
 * cancels the earlier reservation.
 */
class ChargeCardTask extends Task
{
    public function tries(): int
    {
        return 3;
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        if ($this->payload['fail_charge'] ?? false) {
            throw new RuntimeException('Card declined.');
        }

        return ['charge_id' => 'ch_'.($this->payload['room'] ?? 'unknown')];
    }
}
