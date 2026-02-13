<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add vector tile support to layers
        Schema::table('layers', function (Blueprint $table) {
            $table->boolean('vector_tile_enabled')->default(false);
            $table->integer('max_zoom')->nullable();
            $table->integer('min_zoom')->nullable();
            $table->json('tile_bounds')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('layers', function (Blueprint $table) {
            $table->dropColumn(['vector_tile_enabled', 'max_zoom', 'min_zoom', 'tile_bounds']);
        });
    }
};
