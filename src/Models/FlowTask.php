<?php

namespace Sergiumhi\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Sergiumhi\LaravelFlow\FlowTaskStatus;
use Sergiumhi\LaravelFlow\OnFailure;
use Sergiumhi\LaravelFlow\Support\FlowModels;

/**
 * Eloquent record for a single task or subtask within a flow run (the
 * `flow_tasks` table). A row with `signal_name` set and `task_class` null
 * represents a Signal pause point rather than a unit of work.
 *
 * @property int $id
 * @property string $public_id
 * @property int $flow_id
 * @property int|null $parent_id
 * @property int|null $index
 * @property int|null $source_order
 * @property int|null $branch_point_source_order
 * @property class-string|null $task_class
 * @property class-string|null $revert_class
 * @property string|null $subtask_mode
 * @property string|null $signal_name
 * @property array<string, mixed>|null $signal_payload
 * @property OnFailure|null $on_failure
 * @property FlowTaskStatus $status
 * @property array<string, mixed>|null $payload
 * @property mixed $output
 * @property int $attempts
 */
class FlowTask extends Model
{
    protected $primaryKey = 'id';

    protected $keyType = 'int';

    public $incrementing = true;

    protected $table = 'flow_tasks';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected string $publicIDPrefix = 'task';

    protected static function booted(): void
    {
        static::creating(static function (FlowTask $model): void {
            if ($model->public_id === null) {
                $model->public_id = $model->publicIDPrefix.'_'.Str::ulid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'output' => 'array',
            'signal_payload' => 'array',
            'status' => FlowTaskStatus::class,
            'on_failure' => OnFailure::class,
            'job_dispatched_at' => 'datetime',
            'job_started_at' => 'datetime',
            'job_finished_at' => 'datetime',
            'signal_waited_at' => 'datetime',
            'signal_received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FlowModel, $this>
     */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(FlowModels::flow(), 'flow_id');
    }

    /**
     * @return BelongsTo<FlowTask, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(FlowModels::task(), 'parent_id');
    }

    /**
     * @return HasMany<FlowTask, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(FlowModels::task(), 'parent_id')->orderBy('index');
    }

    /**
     * Whether this row represents a Signal pause point rather than a task.
     */
    public function isSignal(): bool
    {
        return $this->signal_name !== null;
    }
}
