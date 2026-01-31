<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_detail_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_area_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->nullable();  // 0-100
            $table->timestamps();

            $table->unique(['exam_result_id', 'exam_area_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_detail_results');
    }
};
