<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Sergiumhi\LaravelFlow\FlowStatus;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('flows', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();                 // e.g. "flow_01j3k..."
            $table->string('flow_class');                          // App\Flows\OrderFlow
            $table->enum('status', array_column(FlowStatus::cases(), 'value')); // pending, running, waiting, paused, failed, reverting, completed, cancelled, reverted
            $table->json('payload')->nullable();                   // input data passed when the flow was started
            $table->unsignedBigInteger('current_task_id')->nullable(); // FK to flow_tasks
            $table->string('revert_outcome')->nullable();          // set to 'reverted' during a user cancelAndRevert saga; null = failure-driven revert (ends failed)
            $table->timestamps(3);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flows');
    }
};
