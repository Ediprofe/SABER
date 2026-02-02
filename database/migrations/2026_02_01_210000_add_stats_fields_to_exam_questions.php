<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_questions', function (Blueprint $table) {
            $table->string('correct_answer', 1)->nullable()->after('question_number');
            $table->string('response_1', 1)->nullable();
            $table->decimal('response_1_pct', 5, 2)->nullable();
            $table->string('response_2', 1)->nullable();
            $table->decimal('response_2_pct', 5, 2)->nullable();
            $table->string('response_3', 1)->nullable();
            $table->decimal('response_3_pct', 5, 2)->nullable();
            $table->string('response_4', 1)->nullable();
            $table->decimal('response_4_pct', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exam_questions', function (Blueprint $table) {
            $table->dropColumn([
                'correct_answer',
                'response_1',
                'response_1_pct',
                'response_2',
                'response_2_pct',
                'response_3',
                'response_3_pct',
                'response_4',
                'response_4_pct',
            ]);
        });
    }
};
