<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per wire-v2 batch, keyed by (site_key, batch_id). A replayed batch
 * returns the stored result instead of being ingested again. Kept only for
 * the retry horizon (`heuristics.retention.receipts_days`).
 */
return new class extends Migration
{
    protected function tableName(): string
    {
        return config('heuristics.table_prefix', 'heuristics_').'ingest_receipts';
    }

    public function up(): void
    {
        Schema::create($this->tableName(), function (Blueprint $table) {
            $table->id();
            $table->string('site_key');
            $table->string('batch_id', 128);
            $table->unsignedTinyInteger('schema_version')->default(2);
            $table->unsignedInteger('accepted')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('received_at')->index();

            $table->unique(['site_key', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tableName());
    }
};
