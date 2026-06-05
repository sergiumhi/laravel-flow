<?php

namespace Workbench\App\Flows;

use Sergiumhi\LaravelFlow\Flow;
use Workbench\App\Tasks\BuildGreetingTask;
use Workbench\App\Tasks\RecordGreetingTask;
use Workbench\App\Tasks\ShoutGreetingTask;
use Generator;

/**
 * The simplest flow: three tasks in sequence where each task's output feeds the
 * next task's input via the generator. Demonstrates that output chaining survives
 * the orchestrator's rebuild-and-replay between wake-ups.
 *
 * The flow payload is passed explicitly to the first task (init($payload)); later
 * tasks are fed the previous task's output through the generator.
 *
 *     GreetingFlow::start(['name' => 'Ada']);
 */
class GreetingFlow extends Flow
{
    public function run(array $payload): Generator
    {
        $built = yield BuildGreetingTask::init($payload);
        $shouted = yield ShoutGreetingTask::init(['text' => $built['greeting']]);

        yield RecordGreetingTask::init(['message' => $shouted['shout']]);
    }
}
