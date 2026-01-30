<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('lectura')->nullable();
            $table->unsignedTinyInteger('matematicas')->nullable();
            $table->unsignedTinyInteger('sociales')->nullable();
            $table->unsignedTinyInteger('naturales')->nullable();
            $table->unsignedTinyInteger('ingles')->nullable();
            $table->unsignedSmallInteger('global_score')->nullable();
            $table->timestamps();

            $table->unique(['exam_id', 'enrollment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_results');
    }
};
