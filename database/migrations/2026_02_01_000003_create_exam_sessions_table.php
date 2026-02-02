<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('session_number'); // 1 o 2
            $table->string('name', 50); // "Sesión 1"
            $table->string('zipgrade_quiz_name', 150)->nullable();
            $table->unsignedSmallInteger('total_questions')->default(0);
            $table->timestamps();

            $table->unique(['exam_id', 'session_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_sessions');
    }
};
