<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A seller's CSV of products in a batch (ADR-040). The file itself is
        // ciphertext under the workspace key in private storage, like photos;
        // this row holds no header, cell, file name or plain hash. The column
        // mapping is kept as field -> column position only.
        Schema::create('product_tables', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('storage_key', 255)->unique();
            // Keyed HMAC of the plaintext bytes: dedupe that dies with the key.
            $table->char('content_mac', 64);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('row_count');
            $table->string('source', 16);
            $table->char('source_message_key', 64)->nullable();
            $table->string('status', 16);
            $table->unsignedSmallInteger('product_count')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'content_mac']);
            $table->index(['workspace_id', 'project_id']);
        });

        // One intake queue for bot files: a photo or a product table (ADR-024, ADR-040).
        Schema::table('telegram_media_intakes', function (Blueprint $table): void {
            $table->string('kind', 16)->default('photo')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_media_intakes', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
        Schema::dropIfExists('product_tables');
    }
};
