<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_events', function (Blueprint $table): void {
            $table->string('message_key', 64)->nullable()->unique();
        });
        Schema::table('service_requests', function (Blueprint $table): void {
            $table->string('details_hash', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', fn (Blueprint $table) => $table->dropColumn('details_hash'));
        Schema::table('conversation_events', fn (Blueprint $table) => $table->dropColumn('message_key'));
    }
};
