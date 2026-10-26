<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 64);
            $table->string('key', 128);
            $table->string('request_hash', 64);
            $table->json('result_attributes');
            $table->timestamp('created_at');
            $table->unique(['workspace_id', 'user_id', 'operation', 'key'], 'api_idempotency_scope_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_records');
    }
};
