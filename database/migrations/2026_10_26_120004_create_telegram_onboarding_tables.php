<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('policy_version', 64);
            $table->timestamp('accepted_at');
            $table->unique(['workspace_id', 'policy_version']);
        });

        Schema::create('telegram_onboardings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('step', 32)->default('consent');
            $table->text('draft')->nullable();
            $table->unsignedInteger('expected_brand_version')->nullable();
            $table->timestamps();
        });

        Schema::create('memory_item_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('memory_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->json('value_json')->nullable();
            $table->string('status', 32);
            $table->string('verification_status', 32);
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('created_at');
            $table->unique(['memory_item_id', 'revision']);
        });

        // Prior overwritten values cannot be recovered; preserve the last known revision.
        DB::table('memory_items')->orderBy('id')->chunkById(500, function ($items): void {
            foreach ($items as $item) {
                DB::table('memory_item_revisions')->insert([
                    'memory_item_id' => $item->id,
                    'workspace_id' => $item->workspace_id,
                    'revision' => $item->revision,
                    'value_json' => $item->value_json,
                    'status' => $item->status,
                    'verification_status' => $item->verification_status,
                    'confirmed_by' => $item->confirmed_by,
                    'source_type' => $item->source_type,
                    'source_id' => $item->source_id,
                    'created_at' => $item->updated_at ?? $item->created_at,
                ]);
            }
        });

        Schema::create('service_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('status', 32)->default('received');
            $table->text('details')->nullable();
            $table->string('external_key', 64)->unique();
            $table->timestamps();
            $table->index(['workspace_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
        Schema::dropIfExists('memory_item_revisions');
        Schema::dropIfExists('telegram_onboardings');
        Schema::dropIfExists('workspace_consents');
    }
};
