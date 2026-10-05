<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('caption')->nullable();

            // Media: the upload is kept only until it has been re-encoded.
            $table->string('upload_path')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('image_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('bytes')->nullable();
            $table->boolean('had_gps')->default(false);
            $table->char('sha256', 64)->nullable()->index();
            $table->char('dhash', 16)->nullable();

            // AI classification
            $table->json('ai_scores')->nullable();
            $table->text('ai_summary')->nullable();
            $table->string('ai_model')->nullable();
            $table->string('ai_error')->nullable();

            // Decision
            $table->string('status')->default('processing')->index();
            $table->string('policy_outcome')->nullable();
            $table->foreignId('policy_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('decision_source')->nullable();
            $table->string('decision_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            // Appeal (at most one per submission)
            $table->text('appeal_message')->nullable();
            $table->timestamp('appealed_at')->nullable();

            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('pipeline_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->string('stage');
            $table->string('status');
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->json('detail')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->index(['submission_id', 'started_at']);
            $table->index(['stage', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_steps');
        Schema::dropIfExists('submissions');
    }
};
