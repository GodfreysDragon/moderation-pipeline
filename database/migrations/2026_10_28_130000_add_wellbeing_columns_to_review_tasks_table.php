<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_tasks', function (Blueprint $table) {
            // Shows a graphic image: blurred by default and counted against the reviewer's daily exposure.
            $table->boolean('graphic')->default(false)->after('priority');
            // For a second review: the task whose decision it independently re-checks.
            $table->foreignId('reviews_task_id')->nullable()->after('excluded_reviewer_id')->constrained('review_tasks')->nullOnDelete();
            $table->index(['resolved_by', 'graphic', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::table('review_tasks', function (Blueprint $table) {
            $table->dropIndex(['resolved_by', 'graphic', 'resolved_at']);
            $table->dropConstrainedForeignId('reviews_task_id');
            $table->dropColumn('graphic');
        });
    }
};
