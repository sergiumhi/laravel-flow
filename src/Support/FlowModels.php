<?php

namespace Sergiumhi\LaravelFlow\Support;

use Sergiumhi\LaravelFlow\Models\FlowModel;
use Sergiumhi\LaravelFlow\Models\FlowTask;

/**
 * Resolves the Eloquent model classes backing the `flows` / `flow_tasks` tables
 * from configuration. Override `flow.flow_model` / `flow.flow_tasks_model` with
 * your own subclasses to extend the engine's persistence layer.
 */
final class FlowModels
{
    /**
     * @return class-string<FlowModel>
     */
    public static function flow(): string
    {
        return config('flow.flow_model', FlowModel::class);
    }

    /**
     * @return class-string<FlowTask>
     */
    public static function task(): string
    {
        return config('flow.flow_tasks_model', FlowTask::class);
    }
}
