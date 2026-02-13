<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rasters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('layer_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('file_path');
            $table->string('file_type'); // geotiff, png, jpg
            $table->bigInteger('file_size')->default(0);
            $table->json('bounds'); // [minX, minY, maxX, maxY] in EPSG:4326
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->integer('bands')->default(3);
            $table->string('color_interpretation')->nullable(); // RGB, RGBA, Gray, etc.
            $table->json('metadata')->nullable(); // GDAL metadata
            $table->boolean('is_tiled')->default(false);
            $table->string('tile_path')->nullable();
            $table->timestamps();
            
            $table->index('layer_id');
        });

        // Add raster support to layers
        Schema::table('layers', function (Blueprint $table) {
            $table->enum('raster_type', ['single', 'mosaic', 'timeseries'])->nullable();
            $table->json('raster_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rasters');
        Schema::table('layers', function (Blueprint $table) {
            $table->dropColumn(['raster_type', 'raster_settings']);
        });
    }
};
