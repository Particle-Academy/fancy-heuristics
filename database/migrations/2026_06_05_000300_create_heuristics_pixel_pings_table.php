<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pixel liveness + visibility beacons ingested via POST {prefix}/pixel.
 */
return new class extends Migration
{
    protected function tableName(): string
    {
        return config('heuristics.table_prefix', 'heuristics_').'pixel_pings';
    }

    public function up(): void
    {
        Schema::create($this->tableName(), function (Blueprint $table) {
            $table->id();
            $table->string('site_key')->index();
            $table->string('style');
            $table->string('mode');
            $table->boolean('visible')->default(false);
            $table->string('path');
            $table->string('ua')->nullable();
            $table->string('ip_hash')->nullable();
            $table->timestamp('pinged_at')->index();
            $table->timestamps();

            $table->index(['site_key', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tableName());
    }
};
