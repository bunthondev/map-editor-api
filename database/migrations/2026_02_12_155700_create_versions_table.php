<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feature_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->integer('version_number');
            $table->json('geometry');
            $table->json('properties');
            $table->text('change_description')->nullable();
            $table->timestamp('created_at');
            
            $table->unique(['feature_id', 'version_number']);
            $table->index(['feature_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versions');
    }
};
