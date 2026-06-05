<?php

namespace Sergiumhi\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Sergiumhi\LaravelFlow\FlowStatus;
use Sergiumhi\LaravelFlow\Support\FlowModels;

/**
 * @property int $id
 * @property string $public_id
 * @property string $flow_class
 * @property FlowStatus $status
 * @property array<string, mixed>|null $payload
 * @property int|null $current_task_id
 */
class FlowModel extends Model
{
    protected $primaryKey = 'id';

    protected $keyType = 'int';

    public $incrementing = true;

    protected $table = 'flows';

    // Fully unguarded: these are internal engine tables, and the orchestrator
    // mass-assigns columns like current_task_id / revert_outcome. A partial
    // $fillable would silently drop them (when $fillable is non-empty it wins
    // over $guarded), so we rely on $guarded = [] for true unguarding.
    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected string $publicIDPrefix = 'flow';

    protected static function booted(): void
    {
        static::creating(static function (FlowModel $model): void {
            if ($model->public_id === null) {
                $model->public_id = $model->publicIDPrefix.'_'.Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => FlowStatus::class,
        ];
    }

    /**
     * @return HasMany<FlowTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(FlowModels::task(), 'flow_id');
    }

    /**
     * Execution-only top-level tasks: rows with a runtime-assigned index (not seeded
     * previews or abandoned rows). Ordered by runtime position. Use this everywhere
     * that needs to reason about what actually ran or will run next.
     *
     * @return HasMany<FlowTask, $this>
     */
    public function topLevelTasks(): HasMany
    {
        return $this->tasks()->whereNull('parent_id')->whereNotNull('index')->orderBy('index');
    }

    /**
     * All top-level tasks including seeded previews and abandoned rows. Ordered by
     * their logical source position so the full DAG is visible up front.
     *
     * @return HasMany<FlowTask, $this>
     */
    public function allTopLevelTasks(): HasMany
    {
        return $this->tasks()
            ->whereNull('parent_id')
            ->orderByRaw('COALESCE(source_order, 999999)')
            ->orderBy('index');
    }

    /**
     * @return BelongsTo<FlowTask, $this>
     */
    public function currentTask(): BelongsTo
    {
        return $this->belongsTo(FlowModels::task(), 'current_task_id');
    }
}
