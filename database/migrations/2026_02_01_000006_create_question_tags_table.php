<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_question_id')->constrained('exam_questions')->cascadeOnDelete();
            $table->foreignId('tag_hierarchy_id')->constrained('tag_hierarchy')->cascadeOnDelete();
            $table->string('inferred_area', 50)->nullable();
            $table->timestamps();

            $table->unique(['exam_question_id', 'tag_hierarchy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_tags');
    }
};
