<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('layer_id')->constrained()->cascadeOnDelete();
            $table->jsonb('properties')->nullable();
            $table->timestamps();
        });

        DB::statement("SELECT AddGeometryColumn('public', 'features', 'geometry', 4326, 'GEOMETRY', 2)");
        DB::statement('CREATE INDEX features_geometry_idx ON features USING GIST (geometry)');
    }

    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
