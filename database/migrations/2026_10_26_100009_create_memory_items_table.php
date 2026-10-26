<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope', 40)->index(); // workspace_profile | employee_preferences | interaction_candidate | user_preferences
            $table->string('key');
            $table->json('value_json');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('verification_status', 32)->default('candidate')->index(); // candidate | confirmed | rejected
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->string('status', 32)->default('active')->index(); // active | deprecated
            $table->string('sensitivity', 32)->default('normal');
            $table->timestamps();

            $table->index(['workspace_id', 'scope', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_items');
    }
};
