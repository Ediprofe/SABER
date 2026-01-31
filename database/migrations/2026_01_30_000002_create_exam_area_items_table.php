<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_area_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_area_config_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('dimension');  // 1 o 2
            $table->string('name', 100);  // "Uso del conocimiento", "Vivo", etc.
            $table->unsignedTinyInteger('order')->default(0);
            $table->timestamps();

            $table->unique(['exam_area_config_id', 'dimension', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_area_items');
    }
};
