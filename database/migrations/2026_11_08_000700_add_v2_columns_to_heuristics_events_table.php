<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wire v2 columns. Additive and nullable: v1 rows keep every column they had
 * and are marked schema_version 1. `name` is the `<domain>.<verb>` event
 * name; route, title and the structured target are separate fields so no
 * report ever has to read meaning out of a free-text label again.
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
            $table->unsignedTinyInteger('schema_version')->default(1);
            $table->string('event_id', 128)->nullable();
            $table->string('name', 64)->nullable();
            $table->string('route_name', 128)->nullable();
            $table->string('route_pattern', 255)->nullable();
            $table->string('title', 120)->nullable();
            $table->string('component', 64)->nullable();
            $table->string('part', 64)->nullable();
            $table->string('instance', 128)->nullable();
            $table->string('role', 32)->nullable();
            $table->string('target_name', 60)->nullable();
            $table->json('props')->nullable();

            $table->index(['site_key', 'name', 'occurred_at'], 'heuristics_events_site_name_time_index');
            $table->unique(['site_key', 'event_id'], 'heuristics_events_site_event_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table($this->tableName(), function (Blueprint $table) {
            $table->dropUnique('heuristics_events_site_event_id_unique');
            $table->dropIndex('heuristics_events_site_name_time_index');
            $table->dropColumn([
                'schema_version', 'event_id', 'name', 'route_name', 'route_pattern', 'title',
                'component', 'part', 'instance', 'role', 'target_name', 'props',
            ]);
        });
    }
};
