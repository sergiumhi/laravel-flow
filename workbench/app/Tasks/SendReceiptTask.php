<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;

/**
 * Final OrderFlow step: send the receipt.
 */
class SendReceiptTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return ['receipt_sent' => true];
    }
}
