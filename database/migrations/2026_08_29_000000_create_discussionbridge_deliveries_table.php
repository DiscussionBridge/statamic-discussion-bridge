<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discussionbridge_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('entry_id', 255);
            $table->string('collection_handle', 100);
            $table->string('site_handle', 100);
            $table->string('external_id', 255);
            $table->string('canonical_url', 2048);
            $table->uuid('resource_id')->nullable();
            $table->unsignedBigInteger('topic_id')->nullable();
            $table->string('topic_url', 2048)->nullable();
            $table->string('outcome', 50)->nullable();
            $table->string('status', 40)->default('pending');
            $table->string('correlation_id', 200);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->uuid('lock_token')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['site_handle', 'collection_handle', 'entry_id'], 'discussionbridge_entry_unique');
            $table->unique('external_id', 'discussionbridge_external_id_unique');
            $table->index(['status', 'next_attempt_at'], 'discussionbridge_work_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discussionbridge_deliveries');
    }
};
