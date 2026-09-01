<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discussionbridge_publications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('resource_id')->unique();
            $table->string('entry_id', 255)->unique();
            $table->string('canonical_url', 2048)->unique();
            $table->string('source_revision', 100);
            $table->unsignedBigInteger('topic_id');
            $table->string('topic_url', 2048);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discussionbridge_publications');
    }
};
