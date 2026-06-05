<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw interaction events ingested via POST {prefix}/collect. Shape mirrors
 * the frozen wire contract (see docs/fancy-pixel-and-heuristics-plan.md).
 */
return new class extends Migration
{
    protected function tableName(): string
    {
        return config('heuristics.table_prefix', 'heuristics_').'events';
    }

    public function up(): void
    {
        Schema::create($this->tableName(), function (Blueprint $table) {
            $table->id();
            $table->string('site_key')->index();
            $table->string('session_id')->nullable();
            $table->enum('actor', ['human', 'agent'])->default('human');
            $table->string('kind');
            $table->string('path');
            $table->integer('x')->nullable();
            $table->integer('y')->nullable();
            $table->integer('vw')->nullable();
            $table->integer('vh')->nullable();
            $table->float('scroll_pct')->nullable();
            $table->unsignedBigInteger('dwell_ms')->nullable();
            $table->string('target_id')->nullable();
            $table->string('label')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['site_key', 'path']);
            $table->index(['site_key', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tableName());
    }
};
