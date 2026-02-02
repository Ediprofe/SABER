<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('zipgrade_id', 20)->nullable()->after('document_id');
            $table->index('zipgrade_id');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['zipgrade_id']);
            $table->dropColumn('zipgrade_id');
        });
    }
};
