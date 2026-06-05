<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;
use Illuminate\Support\Facades\Log;

/**
 * Step 3 of GreetingFlow: record the final shouted greeting.
 */
class RecordGreetingTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        sleep(2.2);
        $message = $this->payload['message'] ?? '';

        Log::info('GreetingFlow recorded', ['message' => $message]);

        return ['recorded' => $message];
    }
}
