<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('layers', function (Blueprint $table) {
            $table->string('layer_type', 20)->default('vector')->after('name');
            $table->string('source_url')->nullable()->after('layer_type');
            $table->string('wms_layers')->nullable()->after('source_url');
        });
    }

    public function down(): void
    {
        Schema::table('layers', function (Blueprint $table) {
            $table->dropColumn(['layer_type', 'source_url', 'wms_layers']);
        });
    }
};
