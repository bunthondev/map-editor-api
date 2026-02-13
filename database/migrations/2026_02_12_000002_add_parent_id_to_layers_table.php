<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('layers', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable()->after('user_id');
            $table->foreign('parent_id')->references('id')->on('layers')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('layers', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};
