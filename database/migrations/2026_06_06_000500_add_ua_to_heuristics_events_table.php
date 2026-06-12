<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a truncated `ua` column to the events table. Until Phase B only pixel
 * pings stored the User-Agent; the collect path now stamps it too so the
 * session rollup can classify device/os/browser. Separate migration (not an
 * edit of the create-events migration) so hosts already on 0.1.0 pick it up.
 */
return new class extends Migration
{
    protected function tableName(): string
    {
        return config('heuristics.table_prefix', 'heuristics_').'events';
    }

    public function up(): void
    {
        Schema::table($this->tableName(), function (Blueprint $table) {
            $table->string('ua')->nullable()->after('meta');
        });
    }

    public function down(): void
    {
        Schema::table($this->tableName(), function (Blueprint $table) {
            $table->dropColumn('ua');
        });
    }
};
