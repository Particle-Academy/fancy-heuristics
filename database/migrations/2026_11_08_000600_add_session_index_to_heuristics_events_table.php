<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index the per-session event lookup. Session drill-downs and "recent
 * sessions" group events by session_id within a site; without this index
 * those queries scan every event the site has.
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
            $table->index(['site_key', 'session_id', 'occurred_at'], 'heuristics_events_site_session_time_index');
        });
    }

    public function down(): void
    {
        Schema::table($this->tableName(), function (Blueprint $table) {
            $table->dropIndex('heuristics_events_site_session_time_index');
        });
    }
};
