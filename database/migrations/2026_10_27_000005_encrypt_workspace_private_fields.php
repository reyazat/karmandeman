<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NFR-11 stage S2 (ADR-013): these columns now hold `kmv1:` ciphertext under
 * the workspace data key, which is neither JSON nor bounded by 255 chars.
 *
 * Encrypting needs OpenBao and an active workspace, so this migration never
 * rewrites data. It refuses to run while legacy plaintext or APP_KEY values
 * exist: back up, then purge or re-enter them (no pilot data exists yet).
 * Rollback refuses while ciphertext exists; restore the backup instead.
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> table => column => previous type */
    private const COLUMNS = [
        'brands' => [
            'name' => 'string', 'category' => 'string', 'tone' => 'string', 'target_audience' => 'string',
            'brand_description' => 'text', 'color_palette' => 'json', 'contact_channels' => 'json', 'default_outputs' => 'json',
        ],
        'projects' => ['name' => 'string'],
        'memory_items' => ['value_json' => 'json'],
        'memory_item_revisions' => ['value_json' => 'json'],
        'telegram_onboardings' => ['draft' => 'text'],
        'service_requests' => ['details' => 'text'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $type) {
                // A json column cannot hold ciphertext yet, so any value there is legacy.
                $legacy = DB::table($table)->whereNotNull($column)
                    ->when($type !== 'json', fn ($query) => $query->where($column, 'not like', 'kmv1:%'))->count();
                if ($legacy > 0) {
                    throw new RuntimeException("{$table}.{$column} has {$legacy} plaintext or APP_KEY value(s); back up and purge them before encrypting (ADR-013 S2).");
                }
            }
        }
        // Old snapshots copy brand/project fields in plaintext and HMACs use APP_KEY.
        if (DB::table('api_idempotency_records')->whereIn('operation', ['brand.update', 'project.create'])->exists()
            || DB::table('service_requests')->whereNotNull('details_hash')->exists()) {
            throw new RuntimeException('Legacy idempotency snapshots or APP_KEY detail hashes exist; back up and purge them before encrypting (ADR-013 S2).');
        }

        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $column => $type) {
                    if ($type !== 'text') {
                        $definition = $blueprint->text($column);
                        // memory value_json stays NOT NULL; every other column was nullable.
                        if ($column !== 'value_json') {
                            $definition->nullable();
                        }
                        $definition->change();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                if (DB::table($table)->where($column, 'like', 'kmv1:%')->exists()) {
                    throw new RuntimeException("{$table}.{$column} holds workspace ciphertext; restore the pre-migration backup instead of rolling back.");
                }
            }
        }
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $type) {
                if ($type === 'json' && DB::getDriverName() === 'pgsql') {
                    $null = $column === 'value_json' ? 'SET NOT NULL' : 'DROP NOT NULL';
                    DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE json USING {$column}::json, ALTER COLUMN {$column} {$null}");
                } elseif ($type !== 'text') {
                    Schema::table($table, function (Blueprint $blueprint) use ($column, $type): void {
                        $definition = $type === 'json' ? $blueprint->json($column) : $blueprint->string($column);
                        if ($column !== 'value_json') {
                            $definition->nullable();
                        }
                        $definition->change();
                    });
                }
            }
        }
    }
};
