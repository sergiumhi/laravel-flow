<?php

namespace Sergiumhi\LaravelFlow;

use Generator;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use Sergiumhi\LaravelFlow\Events\FlowTaskStateChanged;
use Sergiumhi\LaravelFlow\Jobs\FlowOrchestratorJob;
use Sergiumhi\LaravelFlow\Jobs\FlowTaskJob;
use Sergiumhi\LaravelFlow\Models\FlowModel;
use Sergiumhi\LaravelFlow\Models\FlowTask;
use Sergiumhi\LaravelFlow\Support\FlowModels;
use UnexpectedValueException;

/**
 * The engine behind the continuation pattern. It rebuilds a flow's generator on
 * every wake-up, fast-forwards it by replaying completed steps, and dispatches
 * the next unit of work — persisting all state to the `flows` / `flow_tasks`
 * tables along the way.
 */
#[Singleton]
class FlowOrchestrator
{
    /**
     * Parsed, name-resolved ASTs keyed by file path — so a source file is
     * parsed once across the seeding of a single flow.
     *
     * @var array<string, Node\Stmt[]>
     */
    private array $parsedFiles = [];

    /**
     * Statically analyse run() to pre-seed every yield in source order. Both
     * sides of conditional branches are seeded as preview rows (index=null).
     * Rows are promoted to execution rows (index assigned) by reconcileTaskRow /
     * reconcileSignalRow as the flow actually advances.
     */
    public function init(FlowModel $flowModel): void
    {
        try {
            $class = $flowModel->flow_class;
            $file = (new \ReflectionClass($class))->getFileName();

            $ast = $this->parseFile($file);

            $finder = new NodeFinder;

            $classNode = $finder->findFirst($ast, fn (Node $node): bool => $node instanceof Node\Stmt\Class_
                && $node->namespacedName?->toString() === ltrim($class, '\\'));

            $runMethod = $classNode === null ? null : $finder->findFirst(
                $classNode,
                fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod && $node->name->toString() === 'run',
            );

            if ($runMethod === null) {
                return;
            }

            $sourceOrder = 0;

            // Counted yields so far, as [source_order => start file position],
            // in ascending source order. Used to resolve a branch arm's
            // divergence point (the last spine yield before its enclosing if).
            $countedYields = [];

            // findInstanceOf walks the tree in source order, so both sides of a
            // conditional are seeded in the order they appear.
            foreach ($finder->findInstanceOf($runMethod, Node\Expr\Yield_::class) as $yield) {
                // Unwrap the fluent chain (->revert()/->onFailure()/...) to the root call.
                $root = $yield->value;

                while ($root instanceof Node\Expr\MethodCall) {
                    $root = $root->var;
                }

                if (! $root instanceof Node\Expr\StaticCall || ! $root->name instanceof Node\Identifier) {
                    continue;
                }

                if ($root->name->toString() === 'init' && $root->class instanceof Node\Name) {
                    $fqcn = $root->class->toString();

                    if (class_exists($fqcn) && is_a($fqcn, Task::class, true)) {
                        $branchPoint = $this->branchPointFor($yield, $countedYields);
                        $countedYields[$sourceOrder] = $yield->getStartFilePos();
                        $parentRow = $this->persistSeededTaskRow($flowModel, $sourceOrder++, $fqcn::init(), null, $branchPoint);

                        // Tasks fan out by returning a SubTaskCollection from
                        // handle() — invisible from run(). Reflect into the task's
                        // handle() to seed preview children for the fan-out.
                        $this->seedSubtaskPreviews($flowModel, $parentRow, $fqcn);
                    }

                    continue;
                }

                // Only signals with a literal name can be seeded; a dynamic name
                // (e.g. waitFor('ack_'.$channel)) is left for runtime.
                if ($root->name->toString() === 'waitFor') {
                    $arg = $root->args[0] ?? null;

                    if ($arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_) {
                        $branchPoint = $this->branchPointFor($yield, $countedYields);
                        $countedYields[$sourceOrder] = $yield->getStartFilePos();
                        $this->persistSeededSignalRow($flowModel, $sourceOrder++, $arg->value->value, $branchPoint);
                    }
                }
            }
        } catch (\Throwable) {
            // Pre-seeding is purely cosmetic; ignore all failures.
        }

    }

    /**
     * Parse a PHP file into a name-resolved AST. Cached per path so a file is
     * parsed once even when several seeded tasks live in it.
     *
     * @return Node\Stmt[]
     */
    private function parseFile(string $path): array
    {
        if (isset($this->parsedFiles[$path])) {
            return $this->parsedFiles[$path];
        }

        $ast = (new ParserFactory)->createForHostVersion()->parse(file_get_contents($path)) ?? [];

        // Resolve every name reference to its fully-qualified form so the
        // ::init() / ::waitFor() / SubTaskCollection class references are absolute.
        // ParentConnectingVisitor lets the seeder walk up to each yield's
        // enclosing if/else when computing branch divergence points.
        $ast = (new NodeTraverser(new NameResolver, new ParentConnectingVisitor))->traverse($ast);

        return $this->parsedFiles[$path] = $ast;
    }

    /**
     * The divergence point of a seeded yield: the source_order of the spine
     * yield it forks from, or null when the yield is on the main spine (not
     * inside any if/else). Derived purely from AST structure, so it works for
     * branches keyed on anything (task output, signal, payload flag).
     *
     * @param  array<int, int>  $countedYields  [source_order => start file pos], ascending
     */
    private function branchPointFor(Node\Expr\Yield_ $yield, array $countedYields): ?int
    {
        $if = $this->enclosingIf($yield);

        if ($if === null) {
            return null;
        }

        $ifPos = $if->getStartFilePos();
        $branchPoint = null;

        // $countedYields is in ascending source order, so the last entry whose
        // start position precedes the if is the spine yield the branch forks from.
        foreach ($countedYields as $sourceOrder => $startPos) {
            if ($startPos < $ifPos) {
                $branchPoint = $sourceOrder;
            }
        }

        return $branchPoint;
    }

    /**
     * The nearest enclosing if statement of a node, by walking up the parent
     * attributes set by ParentConnectingVisitor. The innermost If_ is found
     * first (nested branches resolve correctly); a yield in an else/elseif
     * resolves to the same outer If_ its arm belongs to.
     */
    private function enclosingIf(Node $node): ?Node\Stmt\If_
    {
        $current = $node->getAttribute('parent');

        while ($current instanceof Node) {
            if ($current instanceof Node\Stmt\If_) {
                return $current;
            }

            $current = $current->getAttribute('parent');
        }

        return null;
    }

    /**
     * Statically inspect a seeded task's handle() for a SubTaskCollection it
     * returns, and seed one preview child per declared subtask. The count is
     * only known when handle() returns a literal array of ::init() calls; a
     * dynamically built collection (array_map, spread, …) is previewed as one
     * row per distinct subtask class. Purely cosmetic and best-effort.
     */
    private function seedSubtaskPreviews(FlowModel $flow, FlowTask $parentRow, string $taskFqcn): void
    {
        try {
            $file = (new \ReflectionClass($taskFqcn))->getFileName();

            if ($file === false) {
                return;
            }

            $ast = $this->parseFile($file);
            $finder = new NodeFinder;

            $classNode = $finder->findFirst($ast, fn (Node $node): bool => $node instanceof Node\Stmt\Class_
                && $node->namespacedName?->toString() === ltrim($taskFqcn, '\\'));

            $handle = $classNode === null ? null : $finder->findFirst(
                $classNode,
                fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod && $node->name->toString() === 'handle',
            );

            if ($handle === null) {
                return;
            }

            // The SubTaskCollection::parallel()/sequential() call(s) inside handle().
            $collections = $finder->find($handle, fn (Node $node): bool => $node instanceof Node\Expr\StaticCall
                && $node->class instanceof Node\Name
                && $node->class->toString() === ltrim(SubTaskCollection::class, '\\')
                && $node->name instanceof Node\Identifier
                && in_array($node->name->toString(), ['parallel', 'sequential'], true));

            if ($collections === []) {
                return;
            }

            // The first collection determines the fan-out mode shown on the parent.
            $first = $collections[0];
            $parentRow->update([
                'subtask_mode' => $first->name->toString() === 'sequential'
                    ? SubTaskCollection::MODE_SEQUENTIAL
                    : SubTaskCollection::MODE_PARALLEL,
            ]);

            $childClasses = $this->subtaskChildClasses($finder, $first);

            // source_order is per-sibling here (0..N), mirroring the index a real
            // fan-out child gets — it gives the previews a stable display order
            // rather than relying on insertion order (their index is null).
            foreach ($childClasses as $childOrder => $childClass) {
                $this->persistSeededTaskRow($flow, $childOrder, $childClass::init(), $parentRow->id);
            }
        } catch (\Throwable) {
            // Best-effort preview; never break seeding.
        }
    }

    /**
     * Resolve the subtask classes to preview for a SubTaskCollection call.
     * A literal array argument yields one entry per ::init() element (repeats
     * kept, count known); any other argument yields one entry per distinct class.
     *
     * @return list<class-string<Task>>
     */
    private function subtaskChildClasses(NodeFinder $finder, Node\Expr\StaticCall $collection): array
    {
        $arg = $collection->args[0] ?? null;
        $literal = $arg instanceof Node\Arg && $arg->value instanceof Node\Expr\Array_;

        $classes = [];
        $seen = [];

        foreach ($finder->find($collection, fn (Node $node): bool => $node instanceof Node\Expr\StaticCall
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'init') as $init) {
            $fqcn = $init->class->toString();

            if (! class_exists($fqcn) || ! is_a($fqcn, Task::class, true)) {
                continue;
            }

            // Non-literal: collapse duplicates (count is unknown at this point).
            if (! $literal && isset($seen[$fqcn])) {
                continue;
            }

            $seen[$fqcn] = true;
            $classes[] = $fqcn;
        }

        return $classes;
    }

    /**
     * Advance the flow to the next step: replay completed steps, promote or
     * create the next task row, then dispatch it (or park on a signal / mark
     * the flow completed).
     */
    public function advance(FlowModel $flowModel): void
    {
        $flowModel->refresh();

        if (in_array($flowModel->status, [FlowStatus::Completed, FlowStatus::Failed, FlowStatus::Cancelled, FlowStatus::Reverted], true)) {
            return;
        }

        if ($flowModel->status === FlowStatus::Reverting) {
            $this->continueRevert($flowModel);

            return;
        }

        $generator = $this->instantiate($flowModel)->run($flowModel->payload ?? []);
        $completed = $this->completedSteps($flowModel);

        $generator->current(); // prime — a send() on a fresh generator discards its value
        foreach ($completed as $step) {
            $generator->send($this->replayValue($step));
        }

        $index = count($completed);

        if (! $generator->valid()) {
            $this->abandonUnmatchedSeedRows($flowModel);
            $flowModel->update(['status' => FlowStatus::Completed, 'current_task_id' => null]);

            return;
        }

        $value = $generator->current();

        if ($value instanceof Signal) {
            $row = $this->reconcileSignalRow($flowModel, $index, $value);
            $row->update([
                'status' => FlowTaskStatus::Waiting,
                'signal_waited_at' => $row->signal_waited_at ?? now(),
            ]);
            $flowModel->update(['status' => FlowStatus::Waiting, 'current_task_id' => $row->id]);

            return;
        }

        if ($value instanceof Task) {
            $row = $this->reconcileTaskRow($flowModel, $index, $value);

            // Failed and awaiting a manual decision — leave it paused, don't redispatch.
            if ($row->status === FlowTaskStatus::Failed) {
                $flowModel->update(['status' => FlowStatus::Paused, 'current_task_id' => $row->id]);

                return;
            }

            // Already in flight (idempotent re-entry) — nothing to do.
            if ($row->status === FlowTaskStatus::Running) {
                return;
            }

            $flowModel->update(['status' => FlowStatus::Running, 'current_task_id' => $row->id]);
            $this->dispatchTask($row, $value);

            return;
        }

        throw new UnexpectedValueException('Flow yielded an unsupported value: '.get_debug_type($value));
    }

    /**
     * Execute a task's handle(). Called from FlowTaskJob.
     */
    public function runTask(FlowTask $row): void
    {
        $row->refresh();
        $task = $this->buildTask($row);

        $row->update([
            'status' => FlowTaskStatus::Running,
            'job_started_at' => now(),
            'attempts' => $row->attempts + 1,
        ]);

        try {
            $result = $task->handle();
        } catch (\Throwable $e) {
            $row->update(['job_finished_at' => now()]);
            $this->handleTaskException($row, $task, $e);

            return;
        }

        if ($result instanceof SubTaskCollection) {
            $this->handleSubtasksReturned($row, $result);

            return;
        }

        $row->update([
            'status' => FlowTaskStatus::Completed,
            'output' => $result,
            'job_finished_at' => now(),
        ]);

        $this->afterTaskCompleted($row);
    }

    /**
     * Execute a compensating (revert) task. Called from FlowTaskJob with revert=true.
     */
    public function runRevertTask(FlowTask $row): void
    {
        $row->refresh();

        $row->update([
            'status' => FlowTaskStatus::Reverting,
            'job_started_at' => now(),
        ]);

        try {
            // The revert receives the original task's output. An associative output
            // (e.g. ['charge_id' => ...]) is merged over the flow payload so its
            // fields are directly accessible; the raw output is always available
            // under 'output' (useful for a subtask parent's aggregated list).
            $output = (array) ($row->output ?? []);
            $payload = $row->flow->payload ?? [];

            if ($output !== [] && ! array_is_list($output)) {
                $payload = array_merge($payload, $output);
            }

            $payload['output'] = $output;

            /** @var Task $task */
            $task = $row->revert_class::init($payload);

            $task->handle();
        } catch (\Throwable) {
            // Best-effort compensation: even if the revert fails we mark it reverted
            // and continue walking back, rather than getting stuck mid-saga.
        }

        $row->update([
            'status' => FlowTaskStatus::Reverted,
            'job_finished_at' => now(),
        ]);

        $this->continueRevert($row->flow);
    }

    /**
     * Deliver an external signal to a waiting flow. Ignored unless the flow is
     * currently waiting for exactly this signal name.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deliverSignal(FlowModel $flowModel, string $name, array $payload = []): void
    {
        $flowModel->refresh();

        if ($flowModel->status !== FlowStatus::Waiting) {
            return;
        }

        $current = $flowModel->currentTask;

        if ($current === null || ! $current->isSignal() || $current->signal_name !== $name) {
            return;
        }

        $current->update([
            'status' => FlowTaskStatus::Completed,
            'signal_payload' => $payload,
            'signal_received_at' => now(),
        ]);

        FlowOrchestratorJob::dispatch($flowModel->public_id, $flowModel->flow_class);
    }

    /**
     * Halt a flow without compensating: mark every not-yet-run task Cancelled and
     * the flow Cancelled. Completed work is left in place (no revert tasks run).
     */
    public function cancel(FlowModel $flowModel): void
    {
        $flowModel->refresh();
        $this->cancelRemainingTasks($flowModel);
        $flowModel->update(['status' => FlowStatus::Cancelled]);
    }

    /**
     * Halt a flow and run the revert saga: walk back through completed tasks
     * running their reverts, then mark remaining tasks Cancelled and the flow
     * Reverted. The 'reverted' outcome is persisted because continueRevert()
     * re-enters across separate jobs and must know the terminal state.
     */
    public function cancelAndRevert(FlowModel $flowModel): void
    {
        $flowModel->update(['revert_outcome' => 'reverted']);
        $this->startRevert($flowModel);
    }

    /**
     * Begin the saga: walk back through completed tasks running their reverts.
     * Entry point for OnFailure::REVERT (failure-driven; ends the flow failed)
     * and, via cancelAndRevert(), for a user-initiated cancel (ends reverted).
     */
    public function startRevert(FlowModel $flowModel): void
    {
        $flowModel->refresh();
        $flowModel->update(['status' => FlowStatus::Reverting]);
        $this->continueRevert($flowModel);
    }

    /**
     * Run the next revert (highest-index completed task with a revert_class), or
     * finish the flow when there are none left. The terminal state depends on why
     * the saga was started: a user cancelAndRevert ends Reverted (with remaining
     * tasks Cancelled); a failure-driven revert ends Failed (with unmatched seeds
     * Abandoned).
     */
    public function continueRevert(FlowModel $flowModel): void
    {
        $flowModel->refresh();

        $next = $flowModel->topLevelTasks()
            ->reorder()
            ->orderByDesc('index')
            ->where('status', FlowTaskStatus::Completed->value)
            ->whereNotNull('revert_class')
            ->first();

        if ($next === null) {
            if ($flowModel->revert_outcome === 'reverted') {
                $this->cancelRemainingTasks($flowModel);
                $flowModel->update(['status' => FlowStatus::Reverted]);
            } else {
                $this->abandonUnmatchedSeedRows($flowModel);
                $flowModel->update(['status' => FlowStatus::Failed]);
            }

            return;
        }

        $next->update([
            'status' => FlowTaskStatus::Reverting,
            'job_dispatched_at' => now(),
        ]);

        FlowTaskJob::dispatch($next->public_id, revert: true, taskClass: $next->revert_class);
    }

    /**
     * Re-dispatch the currently failed task from scratch.
     */
    public function retryCurrentTask(FlowModel $flowModel): void
    {
        $flowModel->refresh();
        $current = $flowModel->currentTask;

        if ($current === null) {
            return;
        }

        // A subtask parent re-runs its handle() from scratch, regenerating the
        // whole fan-out. Drop the previous children first so completeParent does
        // not aggregate a stale, duplicated set. Use retryFailedSubtasks() to
        // re-run only the failed children instead.
        $current->children()->delete();

        $current->update([
            'status' => FlowTaskStatus::Pending,
            'attempts' => 0,
            'output' => null,
            'subtask_mode' => null,
            'job_started_at' => null,
            'job_finished_at' => null,
        ]);

        $flowModel->update(['status' => FlowStatus::Running]);
        FlowOrchestratorJob::dispatch($flowModel->public_id, $flowModel->flow_class);
    }

    /**
     * Re-dispatch only the failed children of a failed subtask parent, leaving
     * already-completed children in place. The flow resumes (the parent
     * aggregates and advances) only once every child has finished — completing
     * one retried child while siblings are still failed/pending does not advance.
     */
    public function retryFailedSubtasks(FlowModel $flowModel): void
    {
        $flowModel->refresh();
        $parent = $flowModel->currentTask;

        // Only valid for a failed subtask parent. Anything else is a no-op so
        // callers can route a generic "retry" without inspecting the DAG shape.
        if ($parent === null || $parent->subtask_mode === null || $parent->status !== FlowTaskStatus::Failed) {
            return;
        }

        $failed = $parent->children()
            ->where('status', FlowTaskStatus::Failed->value)
            ->orderBy('index')
            ->get();

        if ($failed->isEmpty()) {
            return;
        }

        foreach ($failed as $child) {
            $child->update([
                'status' => FlowTaskStatus::Pending,
                'attempts' => 0,
                'output' => null,
                'job_dispatched_at' => null,
                'job_started_at' => null,
                'job_finished_at' => null,
            ]);
        }

        $parent->update(['status' => FlowTaskStatus::Running, 'job_finished_at' => null]);
        $flowModel->update(['status' => FlowStatus::Running, 'current_task_id' => null]);

        if ($parent->subtask_mode === SubTaskCollection::MODE_SEQUENTIAL) {
            // Dispatch the earliest failed child; handleSubtaskFinished walks
            // forward through the remaining pending children in index order.
            $this->dispatchChild($failed->first());

            return;
        }

        foreach ($failed as $child) {
            $this->dispatchChild($child);
        }
    }

    /**
     * Skip the currently failed task and advance.
     */
    public function skipCurrentTask(FlowModel $flowModel): void
    {
        $flowModel->refresh();
        $current = $flowModel->currentTask;

        if ($current === null) {
            return;
        }

        $current->update(['status' => FlowTaskStatus::Skipped]);
        $flowModel->update(['status' => FlowStatus::Running]);
        FlowOrchestratorJob::dispatch($flowModel->public_id, $flowModel->flow_class);
    }

    /**
     * Reset the target top-level task and everything after it to pending, then
     * re-run from there. Seeded preview and abandoned rows are left untouched.
     */
    public function retryFromTask(FlowModel $flowModel, string $taskPublicId): void
    {
        $flowModel->refresh();

        $target = $flowModel->topLevelTasks()->where('public_id', $taskPublicId)->first();

        if ($target === null) {
            return;
        }

        $rows = $flowModel->topLevelTasks()->where('index', '>=', $target->index)->get();

        foreach ($rows as $row) {
            $row->children()->delete();
            $row->update([
                'status' => FlowTaskStatus::Pending,
                'attempts' => 0,
                'output' => null,
                'subtask_mode' => null,
                'signal_payload' => null,
                'signal_received_at' => null,
                'signal_waited_at' => null,
                'job_dispatched_at' => null,
                'job_started_at' => null,
                'job_finished_at' => null,
            ]);
        }

        $flowModel->update(['status' => FlowStatus::Running, 'current_task_id' => null]);
        FlowOrchestratorJob::dispatch($flowModel->public_id, $flowModel->flow_class);
    }

    // ── internals ──────────────────────────────────────────────────────────

    private function instantiate(FlowModel $flow): Flow
    {
        $class = $flow->flow_class;
        /** @var Flow $instance */
        $instance = new $class;

        $instance->bindRecord($flow);

        return $instance;
    }

    /**
     * The contiguous prefix of execution top-level rows that are "done" and can
     * be replayed into the generator, stopping at the first that is not.
     *
     * @return array<int, FlowTask>
     */
    private function completedSteps(FlowModel $flow): array
    {
        $steps = [];

        foreach ($flow->topLevelTasks()->get() as $row) {
            if (! $this->isReplayable($row)) {
                break;
            }

            $steps[] = $row;
        }

        return $steps;
    }

    private function isReplayable(FlowTask $row): bool
    {
        if ($row->isSignal()) {
            return $row->signal_received_at !== null;
        }

        return in_array($row->status, [FlowTaskStatus::Completed, FlowTaskStatus::Skipped], true);
    }

    /**
     * The value to send() into the generator for a replayed step.
     */
    private function replayValue(FlowTask $row): mixed
    {
        if ($row->isSignal()) {
            return $row->signal_payload ?? [];
        }

        if ($row->status === FlowTaskStatus::Skipped) {
            return null;
        }

        return $row->output;
    }

    /**
     * Find or create the execution row for a task yield at runtime position
     * $index. Prefers promoting an unmatched seeded row (matched by task class)
     * over creating a fresh row.
     */
    private function reconcileTaskRow(FlowModel $flow, int $index, Task $task): FlowTask
    {
        $class = $task::class;

        // Existing execution row at this index (re-entry / already reconciled).
        $existing = $flow->topLevelTasks()->where('index', $index)->first();

        if ($existing !== null) {
            // Refresh declarative config for accurate DAG display.
            $existing->update([
                'revert_class' => $task->revertClass(),
                'on_failure' => $task->failureMode(),
            ]);

            return $existing;
        }

        // Promote the oldest unmatched seeded row with the same class.
        $seeded = $flow->tasks()
            ->whereNull('parent_id')
            ->whereNull('index')
            ->where('task_class', $class)
            ->where('status', FlowTaskStatus::Pending->value)
            ->orderBy('source_order')
            ->first();

        if ($seeded !== null) {
            $seeded->update([
                'index' => $index,
                'revert_class' => $task->revertClass(),
                'on_failure' => $task->failureMode(),
            ]);

            return $seeded;
        }

        return $this->persistTaskRow($flow, null, $index, $task, FlowTaskStatus::Pending);
    }

    /**
     * Find or create the execution row for a signal yield at runtime position $index.
     */
    private function reconcileSignalRow(FlowModel $flow, int $index, Signal $signal): FlowTask
    {
        // Existing execution row at this index.
        $existing = $flow->topLevelTasks()->where('index', $index)->first();

        if ($existing !== null) {
            return $existing;
        }

        // Promote the oldest unmatched seeded row with the same signal name.
        $seeded = $flow->tasks()
            ->whereNull('parent_id')
            ->whereNull('index')
            ->whereNull('task_class')
            ->where('signal_name', $signal->name)
            ->where('status', FlowTaskStatus::Pending->value)
            ->orderBy('source_order')
            ->first();

        if ($seeded !== null) {
            $seeded->update([
                'index' => $index,
                'status' => FlowTaskStatus::Waiting,
                'signal_waited_at' => $seeded->signal_waited_at ?? now(),
            ]);

            return $seeded;
        }

        return $this->persistSignalRow($flow, null, $index, $signal->name, FlowTaskStatus::Waiting);
    }

    /**
     * Mark all unmatched seeded rows (index=null, Pending) as Abandoned. Called
     * when the flow reaches a terminal state (Completed or Failed).
     */
    private function abandonUnmatchedSeedRows(FlowModel $flow): void
    {
        // index IS NULL uniquely identifies seeded preview rows — both top-level
        // seeds and subtask preview children (real subtasks always have an index).
        $rows = $flow->tasks()
            ->whereNull('index')
            ->where('status', FlowTaskStatus::Pending->value)
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        // Bulk-update for a single query; query-builder updates skip Eloquent
        // events, so dispatch the per-row transition events ourselves.
        $flow->tasks()->whereKey($rows->modelKeys())->update(['status' => FlowTaskStatus::Abandoned]);

        $this->emitTaskTransitions($rows, FlowTaskStatus::Abandoned);
    }

    /**
     * Mark every not-yet-run task (any level) as Cancelled. Covers seeded previews
     * and promoted rows still pending, plus anything in flight or waiting on a
     * signal. Completed / failed / skipped / reverted rows are left untouched.
     */
    private function cancelRemainingTasks(FlowModel $flow): void
    {
        $rows = $flow->tasks()
            ->whereIn('status', [
                FlowTaskStatus::Pending->value,
                FlowTaskStatus::Running->value,
                FlowTaskStatus::Waiting->value,
            ])
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        // Bulk-update for a single query; query-builder updates skip Eloquent
        // events, so dispatch the per-row transition events ourselves.
        $flow->tasks()->whereKey($rows->modelKeys())->update(['status' => FlowTaskStatus::Cancelled]);

        $this->emitTaskTransitions($rows, FlowTaskStatus::Cancelled);
    }

    /**
     * Dispatch a FlowTaskStateChanged for each row whose status was forced to
     * $to by a bulk update, using each row's own prior status as `from`. Gated
     * on the same config flag as the model-event observers so toggling events
     * off silences every path uniformly.
     *
     * @param  \Illuminate\Support\Collection<int, FlowTask>  $rows
     */
    private function emitTaskTransitions($rows, FlowTaskStatus $to): void
    {
        if (! config('flow.events.enabled', true)) {
            return;
        }

        foreach ($rows as $row) {
            $from = $row->status;

            if ($from === $to) {
                continue;
            }

            $row->setAttribute('status', $to);
            event(new FlowTaskStateChanged($row, $from, $to));
        }
    }

    private function dispatchTask(FlowTask $row, Task $task): void
    {
        $row->update([
            'status' => FlowTaskStatus::Running,
            'payload' => $task->payload(),
            'job_dispatched_at' => now(),
        ]);

        FlowTaskJob::dispatch($row->public_id, taskClass: $row->task_class);
    }

    private function dispatchChild(FlowTask $child): void
    {
        $child->update([
            'status' => FlowTaskStatus::Running,
            'job_dispatched_at' => now(),
        ]);

        FlowTaskJob::dispatch($child->public_id, taskClass: $child->task_class);
    }

    private function buildTask(FlowTask $row): Task
    {
        /** @var Task $task */
        $task = $row->task_class::init($row->payload ?? []);

        return $task;
    }

    private function afterTaskCompleted(FlowTask $row): void
    {
        if ($row->parent_id !== null) {
            $this->handleSubtaskFinished($row);

            return;
        }

        FlowOrchestratorJob::dispatch($row->flow->public_id, $row->flow->flow_class);
    }

    private function handleSubtasksReturned(FlowTask $parent, SubTaskCollection $collection): void
    {
        // Subtasks cannot spawn subtasks (max depth is two). A subtask that
        // returns a collection is treated as a no-op completion.
        if ($parent->parent_id !== null) {
            $parent->update([
                'status' => FlowTaskStatus::Completed,
                'output' => [],
                'job_finished_at' => now(),
            ]);
            $this->afterTaskCompleted($parent);

            return;
        }

        // Drop any seeded preview children (index=null) before creating the real
        // fan-out; otherwise their Pending status would block completeParent's
        // all-done check and the parent would never complete.
        $parent->children()->delete();

        $parent->update([
            'subtask_mode' => $collection->mode,
            'status' => FlowTaskStatus::Running,
            'job_finished_at' => null, // parent stays running until children finish
        ]);

        $children = [];
        foreach (array_values($collection->tasks) as $i => $childTask) {
            $children[] = $this->persistTaskRow($parent->flow, $parent->id, $i, $childTask, FlowTaskStatus::Pending);
        }

        if ($children === []) {
            $this->completeParent($parent);

            return;
        }

        if ($collection->mode === SubTaskCollection::MODE_PARALLEL) {
            foreach ($children as $child) {
                $this->dispatchChild($child);
            }

            return;
        }

        $this->dispatchChild($children[0]);
    }

    private function handleSubtaskFinished(FlowTask $child): void
    {
        $parent = $child->parent;

        if ($parent->subtask_mode === SubTaskCollection::MODE_SEQUENTIAL) {
            $next = $parent->children()
                ->where('index', '>', $child->index)
                ->where('status', FlowTaskStatus::Pending->value)
                ->orderBy('index')
                ->first();

            if ($next !== null) {
                $this->dispatchChild($next);

                return;
            }
        }

        // Atomic "are all siblings done?" check. Under a real (async) queue two
        // children may finish concurrently; locking the parent row prevents a
        // double-complete. Under the sync driver this is trivially serialized.
        $allDone = DB::transaction(function () use ($parent): bool {
            $locked = FlowModels::task()::whereKey($parent->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== FlowTaskStatus::Running) {
                return false;
            }

            $remaining = $locked->children()
                ->whereNotIn('status', [FlowTaskStatus::Completed->value, FlowTaskStatus::Skipped->value])
                ->count();

            return $remaining === 0;
        });

        if ($allDone) {
            $this->completeParent($parent->fresh());
        }
    }

    private function completeParent(FlowTask $parent): void
    {
        $output = $parent->children()
            ->orderBy('index')
            ->get()
            ->map(fn (FlowTask $child) => $child->output)
            ->all();

        $parent->update([
            'status' => FlowTaskStatus::Completed,
            'output' => $output,
            'job_finished_at' => now(),
        ]);

        FlowOrchestratorJob::dispatch($parent->flow->public_id, $parent->flow->flow_class);
    }

    private function handleTaskException(FlowTask $row, Task $task, \Throwable $e): void
    {
        if ($row->attempts < $task->tries()) {
            // Retry: re-dispatch the same job. attempts was already incremented.
            $row->update(['status' => FlowTaskStatus::Pending, 'job_dispatched_at' => now()]);
            FlowTaskJob::dispatch($row->public_id, taskClass: $row->task_class);

            return;
        }

        $row->update(['status' => FlowTaskStatus::Failed]);

        // Subtask failure → fail the parent and pause the flow (coarse-grained).
        if ($row->parent_id !== null) {
            $parent = $row->parent;
            $parent->update(['status' => FlowTaskStatus::Failed, 'job_finished_at' => now()]);
            $row->flow->update(['status' => FlowStatus::Paused, 'current_task_id' => $parent->id]);

            return;
        }

        $this->routeFailure($row);
    }

    private function routeFailure(FlowTask $row): void
    {
        $flow = $row->flow;
        $mode = $row->on_failure ?? OnFailure::PAUSE;

        match ($mode) {
            OnFailure::PAUSE => $flow->update([
                'status' => FlowStatus::Paused,
                'current_task_id' => $row->id,
            ]),
            OnFailure::SKIP => $this->skipAndAdvance($row),
            OnFailure::REVERT => $this->startRevert($flow),
        };
    }

    private function skipAndAdvance(FlowTask $row): void
    {
        $row->update(['status' => FlowTaskStatus::Skipped]);
        FlowOrchestratorJob::dispatch($row->flow->public_id, $row->flow->flow_class);
    }

    /**
     * Create a seeded preview row (index=null). A top-level seed is promoted to
     * an execution row by reconcileTaskRow when the generator reaches it; a
     * subtask preview child ($parentId set) is replaced by the real fan-out when
     * the parent's handle() runs, or abandoned if its branch is never taken.
     */
    private function persistSeededTaskRow(FlowModel $flow, int $sourceOrder, Task $task, ?int $parentId = null, ?int $branchPointSourceOrder = null): FlowTask
    {
        return $flow->tasks()->create([
            'public_id' => 'task_'.Str::ulid(),
            'parent_id' => $parentId,
            'index' => null,
            'source_order' => $sourceOrder,
            'branch_point_source_order' => $branchPointSourceOrder,
            'task_class' => $task::class,
            'revert_class' => $task->revertClass(),
            'on_failure' => $task->failureMode(),
            'status' => FlowTaskStatus::Pending,
            'payload' => $task->payload(),
        ]);
    }

    /**
     * Create a seeded signal preview row (index=null).
     */
    private function persistSeededSignalRow(FlowModel $flow, int $sourceOrder, string $name, ?int $branchPointSourceOrder = null): FlowTask
    {
        return $flow->tasks()->create([
            'public_id' => 'task_'.Str::ulid(),
            'parent_id' => null,
            'index' => null,
            'source_order' => $sourceOrder,
            'branch_point_source_order' => $branchPointSourceOrder,
            'task_class' => null,
            'signal_name' => $name,
            'status' => FlowTaskStatus::Pending,
        ]);
    }

    private function persistTaskRow(FlowModel $flow, ?int $parentId, int $index, Task $task, FlowTaskStatus $status): FlowTask
    {
        return $flow->tasks()->create([
            'public_id' => 'task_'.Str::ulid(),
            'parent_id' => $parentId,
            'index' => $index,
            'task_class' => $task::class,
            'revert_class' => $task->revertClass(),
            'on_failure' => $task->failureMode(),
            'status' => $status,
            'payload' => $task->payload(),
        ]);
    }

    private function persistSignalRow(FlowModel $flow, ?int $parentId, int $index, string $name, FlowTaskStatus $status): FlowTask
    {
        return $flow->tasks()->create([
            'public_id' => 'task_'.Str::ulid(),
            'parent_id' => $parentId,
            'index' => $index,
            'task_class' => null,
            'signal_name' => $name,
            'status' => $status,
            'signal_waited_at' => $status === FlowTaskStatus::Waiting ? now() : null,
        ]);
    }
}
