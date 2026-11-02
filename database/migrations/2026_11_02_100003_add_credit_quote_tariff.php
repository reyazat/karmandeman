<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The usage tariff a quote was made with (ADR-042): rates and class
        // minimums, so the charge on success uses them and not later ones.
        // Null on the fixed-price quotes of ADR-039, which are spent whole.
        Schema::table('credit_quotes', function (Blueprint $table): void {
            $table->json('tariff')->nullable()->after('tariff_version');
        });
    }

    public function down(): void
    {
        Schema::table('credit_quotes', function (Blueprint $table): void {
            $table->dropColumn('tariff');
        });
    }
};
