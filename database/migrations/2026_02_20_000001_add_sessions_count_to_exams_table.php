<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->unsignedTinyInteger('sessions_count')->default(2)->after('date');
        });

        DB::table('exams')->whereNull('sessions_count')->update(['sessions_count' => 2]);
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('sessions_count');
        });
    }
};
