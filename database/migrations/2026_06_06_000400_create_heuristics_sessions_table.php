<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-session rollup derived from the collect stream. One row per
 * (site_key, session_id): acquisition context (referrer + utm), audience
 * (device/os/browser/lang/tz/screen, derived from the request User-Agent and
 * the wire `context`), and engagement totals (pageviews, events, duration,
 * bounce). Powers the GA-parity acquisition / audience / timeseries reports.
 */
return new class extends Migration
{
    protected function tableName(): string
    {
        return config('heuristics.table_prefix', 'heuristics_').'sessions';
    }

    public function up(): void
    {
        Schema::create($this->tableName(), function (Blueprint $table) {
            $table->id();
            $table->string('site_key');
            $table->string('session_id');
            $table->enum('actor', ['human', 'agent'])->default('human');

            // Acquisition.
            $table->string('referrer')->nullable();
            $table->string('referrer_host')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('landing_path')->nullable();
            $table->string('exit_path')->nullable();

            // Audience.
            $table->string('device')->nullable();   // mobile|tablet|desktop
            $table->string('os')->nullable();        // Windows|macOS|iOS|Android|Linux|Other
            $table->string('browser')->nullable();   // Chrome|Safari|Firefox|Edge|Other
            $table->string('lang')->nullable();
            $table->string('tz')->nullable();
            $table->integer('screen_w')->nullable();
            $table->integer('screen_h')->nullable();
            $table->string('country')->nullable();

            // Engagement.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->default(0);
            $table->unsignedInteger('pageviews')->default(0);
            $table->unsignedInteger('events')->default(0);
            $table->boolean('is_bounce')->default(true);

            $table->timestamps();

            $table->unique(['site_key', 'session_id']);
            $table->index(['site_key', 'started_at']);
            $table->index(['site_key', 'actor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tableName());
    }
};
