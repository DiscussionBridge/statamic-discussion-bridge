<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discussionbridge_deliveries', function (Blueprint $table): void {
            $table->string('source_revision', 64)->nullable();
            $table->string('desired_revision', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('discussionbridge_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['source_revision', 'desired_revision']);
        });
    }
};
