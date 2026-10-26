<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 32);
            $table->string('external_update_id', 64)->nullable();
            $table->string('intent')->nullable();
            $table->json('redacted_body')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['channel', 'external_update_id']);
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_events');
    }
};
