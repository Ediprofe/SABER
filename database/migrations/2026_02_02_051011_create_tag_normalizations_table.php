<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tag_normalizations', function (Blueprint $table) {
            $table->id();
            $table->string('tag_csv_name', 150)->unique();  // Nombre como viene en CSV de Zipgrade
            $table->string('tag_system_name', 150);         // Nombre estandarizado en el sistema
            $table->enum('tag_type', [
                'area',
                'competencia',
                'componente',
                'tipo_texto',
                'nivel_lectura',
                'parte',
            ]);
            $table->string('parent_area', 50)->nullable();  // Área padre (ej: "Lectura", "Naturales")
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('tag_csv_name');
            $table->index(['tag_type', 'parent_area']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tag_normalizations');
    }
};
