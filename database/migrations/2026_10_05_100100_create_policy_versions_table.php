<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: every decision points at the exact thresholds it was made under.
        Schema::create('policy_versions', function (Blueprint $table) {
            $table->id();
            $table->json('thresholds');
            $table->decimal('audit_sample_rate', 4, 3);
            $table->unsignedTinyInteger('blocklist_distance');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_versions');
    }
};
