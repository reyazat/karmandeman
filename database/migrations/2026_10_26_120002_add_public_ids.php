<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const TABLES = ['users', 'workspaces', 'brands', 'projects', 'credit_ledger'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->uuid('public_id')->nullable()->unique();
            });

            DB::table($table)->select('id')->orderBy('id')->chunkById(500, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update(['public_id' => (string) Str::uuid()]);
                }
            });

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->uuid('public_id')->nullable(false)->change();
            });
        }

        // Preserve the original result of requests recorded before public IDs existed.
        DB::table('api_idempotency_records')->select('id', 'workspace_id', 'operation', 'result_attributes')
            ->orderBy('id')->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $table = match ($row->operation) {
                        'project.create' => 'projects',
                        'brand.update' => 'brands',
                        default => null,
                    };
                    if ($table === null) {
                        continue;
                    }

                    $attributes = json_decode($row->result_attributes, true, flags: JSON_THROW_ON_ERROR);
                    $publicId = DB::table($table)->where('workspace_id', $row->workspace_id)
                        ->where('id', $attributes['id'])->value('public_id');
                    if ($publicId === null) {
                        throw new RuntimeException('Cannot backfill idempotency snapshot without its resource.');
                    }

                    $attributes['public_id'] = $publicId;
                    DB::table('api_idempotency_records')->where('id', $row->id)
                        ->update(['result_attributes' => json_encode($attributes, JSON_THROW_ON_ERROR)]);
                }
            });
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('public_id');
            });
        }
        // Snapshot JSON keeps the extra public_id, which is harmless on rollback.
    }
};
