<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;

/**
 * Step 2 of GreetingFlow: shout the greeting produced by the previous task.
 * Demonstrates output chaining — its input comes from the prior yield's result.
 */
class ShoutGreetingTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        sleep(1.3);
        $text = $this->payload['text'] ?? '';

        return ['shout' => strtoupper($text).'!'];
    }
}
