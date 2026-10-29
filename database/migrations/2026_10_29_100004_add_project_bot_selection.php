<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The bot's current batch (ADR-028): the open project the seller created
        // or picked last. Photos, /product and /done all follow it.
        Schema::table('projects', function (Blueprint $table): void {
            // Microseconds: two selections in the same second must still order.
            $table->timestamp('bot_selected_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('bot_selected_at');
        });
    }
};
