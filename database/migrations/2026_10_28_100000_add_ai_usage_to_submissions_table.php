<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            // Token usage of the classification, for cost reporting. Null for the offline classifier.
            $table->unsignedInteger('ai_input_tokens')->nullable()->after('ai_error');
            $table->unsignedInteger('ai_cache_read_tokens')->nullable()->after('ai_input_tokens');
            $table->unsignedInteger('ai_cache_write_tokens')->nullable()->after('ai_cache_read_tokens');
            $table->unsignedInteger('ai_output_tokens')->nullable()->after('ai_cache_write_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn(['ai_input_tokens', 'ai_cache_read_tokens', 'ai_cache_write_tokens', 'ai_output_tokens']);
        });
    }
};
