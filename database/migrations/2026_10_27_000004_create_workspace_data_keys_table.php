<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only the Transit-wrapped data key is stored; the key that unwraps it
        // lives in OpenBao, outside this database and its backups (ADR-013).
        Schema::create('workspace_data_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('transit_key', 128);
            $table->text('wrapped_key');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_data_keys');
    }
};
