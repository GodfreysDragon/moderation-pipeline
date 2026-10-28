<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An offline re-classification of human-decided items with another model or
        // effort level, sent through the Message Batches API at half price.
        Schema::create('evaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('model');
            $table->string('effort');
            $table->unsignedSmallInteger('sample_size');
            $table->string('status')->default('pending');
            $table->string('batch_id')->nullable();
            $table->string('error')->nullable();
            // The thresholds both sets of scores are judged under.
            $table->foreignId('policy_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('evaluation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->json('scores')->nullable();
            $table->text('summary')->nullable();
            $table->string('error')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('cache_read_tokens')->nullable();
            $table->unsignedInteger('cache_write_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unique(['evaluation_run_id', 'submission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_results');
        Schema::dropIfExists('evaluation_runs');
    }
};
