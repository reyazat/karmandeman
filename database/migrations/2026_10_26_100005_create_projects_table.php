<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('state', 40)->default('new')->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['workspace_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
