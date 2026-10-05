<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->smallInteger('priority')->default(0);

            // Snapshot of what the automated pipeline decided, to measure agreement later.
            $table->string('automated_outcome')->nullable();

            // A reviewer who may not take this task (e.g. the person whose decision is being appealed).
            $table->foreignId('excluded_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();

            // Claim lease
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();

            // Resolution
            $table->string('decision')->nullable();
            $table->string('reason')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();
            $table->index(['resolved_at', 'kind', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_tasks');
    }
};
