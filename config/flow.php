<?php

use Sergiumhi\LaravelFlow\Console\Commands\FlowPruneCommand;
use Sergiumhi\LaravelFlow\Models\FlowModel;
use Sergiumhi\LaravelFlow\Models\FlowTask;

return [

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | The Eloquent models backing the `flows` and `flow_tasks` tables. Swap
    | these out for your own subclasses to extend the engine's persistence
    | layer — the orchestrator resolves them through these config keys.
    |
    */

    'flow_model' => FlowModel::class,

    'flow_tasks_model' => FlowTask::class,

    /*
    |--------------------------------------------------------------------------
    | Flows Folder
    |--------------------------------------------------------------------------
    |
    | The directory (under app/) and corresponding namespace segment that holds
    | your flow classes, e.g. App\Flows. Used by `flow:run` to resolve a flow
    | from a short name.
    |
    */

    'flows_folder' => 'Flows',

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | When enabled, the engine dispatches a plain domain event on every flow and
    | task status transition (FlowStateChanged / FlowTaskStateChanged), each
    | carrying the model plus the `from` and `to` status. These events are NOT
    | broadcastable by design — subscribe in your app and decide how to deliver
    | them (broadcast over websockets, publish to Redis for SSE, send a
    | notification, log, etc.). Turn this off to skip dispatching entirely.
    |
    */

    'events' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    |
    | Old, finished flow runs can be removed on a schedule via the prune
    | command. `retention_days` is how long a finished flow is kept (measured
    | from its last update) before it becomes eligible for deletion. Set
    | `schedule` to true to register the daily prune automatically — it is off
    | by default so the package never deletes data without opt-in.
    |
    */

    'prune' => [
        'command' => FlowPruneCommand::class,
        'retention_days' => 30,
        'schedule' => false,
    ],

];
