<?php

namespace Sergiumhi\LaravelFlow\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Sergiumhi\LaravelFlow\Models\FlowModel;
use Sergiumhi\LaravelFlow\Models\FlowTask;
use Sergiumhi\LaravelFlow\Tests\TestCase;
use Workbench\App\Flows\GreetingFlow;

/**
 * Verifies the config-driven model swapping (config('flow.flow_model') /
 * flow_tasks_model). The rest of the suite always uses the default models, so a
 * relationship or lookup left hardcoded would not be caught there — this test
 * binds custom subclasses and asserts the engine actually resolves them.
 */
class ModelResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_the_configured_model_classes(): void
    {
        config([
            'flow.flow_model' => CustomFlow::class,
            'flow.flow_tasks_model' => CustomFlowTask::class,
        ]);

        $flow = GreetingFlow::start(['name' => 'Ada']);

        // The flow record itself is the configured subclass.
        $this->assertInstanceOf(CustomFlow::class, $flow->record());

        // Rows read back through the relationships are the configured subclass —
        // proving tasks(), and the lookups the orchestrator made while running,
        // all went through config rather than the hardcoded default.
        $tasks = $flow->record()->fresh()->topLevelTasks()->get();
        $this->assertCount(3, $tasks);
        $this->assertInstanceOf(CustomFlowTask::class, $tasks->first());

        $reloaded = CustomFlow::where('public_id', $flow->publicId())->first();
        $this->assertInstanceOf(CustomFlowTask::class, $reloaded->currentTask()->getRelated());
    }
}

class CustomFlow extends FlowModel {}

class CustomFlowTask extends FlowTask {}
