<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('layers', function (Blueprint $table) {
            $table->json('schema')->nullable()->after('style');
        });
    }

    public function down(): void
    {
        Schema::table('layers', function (Blueprint $table) {
            $table->dropColumn('schema');
        });
    }
};
