<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_area_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->enum('area', ['lectura', 'matematicas', 'sociales', 'naturales', 'ingles']);
            $table->string('dimension1_name', 50);  // "Competencias", "Partes"
            $table->string('dimension2_name', 50)->nullable();  // "Componentes", "Tipos de Texto", NULL
            $table->timestamps();

            $table->unique(['exam_id', 'area']);  // Solo una config por área por examen
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_area_configs');
    }
};
