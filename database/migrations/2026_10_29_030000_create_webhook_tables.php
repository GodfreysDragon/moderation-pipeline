<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            // The uploading platform's own id for the item, so retries don't create duplicates.
            $table->string('external_ref', 100)->nullable()->after('original_filename');
            $table->unique(['user_id', 'external_ref']);
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->text('secret');
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submission_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 50);
            $table->json('payload');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('error', 250)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'external_ref']);
            $table->dropColumn('external_ref');
        });
    }
};
