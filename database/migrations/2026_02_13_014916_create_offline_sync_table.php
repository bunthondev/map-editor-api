<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_sync', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('layer_id')->constrained()->onDelete('cascade');
            $table->enum('sync_type', ['full', 'bbox', 'selected']);
            $table->json('bbox')->nullable();
            $table->json('feature_ids')->nullable();
            $table->timestamp('synced_at');
            $table->timestamp('expires_at');
            $table->integer('feature_count')->default(0);
            $table->json('metadata')->nullable();
            
            $table->index(['user_id', 'layer_id']);
            $table->index('expires_at');
        });

        // Add offline_enabled to layers
        Schema::table('layers', function (Blueprint $table) {
            $table->boolean('offline_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_sync');
        Schema::table('layers', function (Blueprint $table) {
            $table->dropColumn('offline_enabled');
        });
    }
};
