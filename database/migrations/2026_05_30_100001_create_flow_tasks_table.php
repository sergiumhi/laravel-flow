<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Sergiumhi\LaravelFlow\FlowTaskStatus;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('flow_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();                 // e.g. "task_01j3k..."
            $table->foreignId('flow_id')->constrained('flows')->cascadeOnDelete();
            $table->foreignId('parent_id')                         // null = top-level task, set = subtask
                ->nullable()
                ->constrained('flow_tasks')
                ->cascadeOnDelete();
            $table->unsignedInteger('index')->nullable();          // runtime 0-based position; null until the seeded row is promoted
            $table->unsignedInteger('source_order')->nullable();  // position in run() source order; set on seeded rows, null for subtasks
            $table->string('task_class')->nullable();              // App\Tasks\ProcessPaymentTask (null for signal rows)
            $table->string('revert_class')->nullable();            // App\Tasks\RefundPaymentTask — revert task for this step
            $table->string('subtask_mode')->nullable();            // null, 'parallel', 'sequential' — set on parent
            $table->string('signal_name')->nullable();             // set when this row represents a Signal yield
            $table->json('signal_payload')->nullable();            // payload delivered when the signal was sent
            $table->string('on_failure')->nullable();              // pause (default), skip, revert
            $table->enum('status', array_column(FlowTaskStatus::cases(), 'value')); // pending, running, completed, failed, skipped, waiting, reverting, reverted, abandoned
            $table->json('payload')->nullable();                   // input passed to this task
            $table->json('output')->nullable();                    // result returned by this task
            $table->unsignedInteger('attempts')->default(0);

            // Job timing — tracks the lifecycle of the underlying queued job
            $table->timestamp('job_dispatched_at', 3)->nullable();    // moment job was pushed to queue
            $table->timestamp('job_started_at', 3)->nullable();       // moment a worker picked it up
            $table->timestamp('job_finished_at', 3)->nullable();      // moment handle() returned (success or fail)

            // Signal timing — only set when this row is a Signal yield
            $table->timestamp('signal_waited_at', 3)->nullable();     // moment the flow started waiting for this signal
            $table->timestamp('signal_received_at', 3)->nullable();   // moment the signal was sent from outside

            $table->timestamps(3);

            $table->index(['flow_id', 'parent_id', 'index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flow_tasks');
    }
};
