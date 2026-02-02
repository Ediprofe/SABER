<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_session_id')->constrained('exam_sessions')->cascadeOnDelete();
            $table->unsignedSmallInteger('question_number');
            $table->timestamps();

            $table->unique(['exam_session_id', 'question_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_questions');
    }
};
