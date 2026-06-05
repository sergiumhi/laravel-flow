<?php

namespace Workbench\App\Tasks;

use Sergiumhi\LaravelFlow\Task;

/**
 * Step 1 of GreetingFlow: build a greeting string from the given name.
 */
class BuildGreetingTask extends Task
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $name = $this->payload['name'] ?? 'World';

        return ['greeting' => "Hello, {$name}"];
    }
}
