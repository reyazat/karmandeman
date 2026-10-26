<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fail on existing duplicates. Reconciliation must be reviewed, never silently delete data.
        Schema::table('brands', fn (Blueprint $table) => $table->unique('workspace_id'));
        Schema::table('employees', fn (Blueprint $table) => $table->unique(['workspace_id', 'template_id']));
        DB::statement("CREATE UNIQUE INDEX memory_items_active_key_unique ON memory_items (workspace_id, scope, key) WHERE status = 'active' AND employee_id IS NULL");
        DB::statement("CREATE UNIQUE INDEX memory_items_employee_active_key_unique ON memory_items (workspace_id, employee_id, scope, key) WHERE status = 'active' AND employee_id IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX memory_items_employee_active_key_unique');
        DB::statement('DROP INDEX memory_items_active_key_unique');
        Schema::table('employees', fn (Blueprint $table) => $table->dropUnique(['workspace_id', 'template_id']));
        Schema::table('brands', fn (Blueprint $table) => $table->dropUnique(['workspace_id']));
    }
};
