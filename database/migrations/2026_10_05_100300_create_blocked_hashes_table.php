<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_hashes', function (Blueprint $table) {
            $table->id();
            $table->char('sha256', 64)->unique();
            $table->char('dhash', 16);
            $table->string('category')->nullable();
            $table->foreignId('source_submission_id')->nullable()->constrained('submissions')->nullOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_hashes');
    }
};
