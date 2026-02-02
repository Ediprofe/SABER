<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tag_hierarchy', function (Blueprint $table) {
            $table->id();
            $table->string('tag_name', 100)->unique();
            // Nuevo enum con nivel_lectura incluido
            $table->enum('tag_type', ['area', 'competencia', 'componente', 'tipo_texto', 'nivel_lectura', 'parte']);
            $table->string('parent_area', 50)->nullable();
            $table->timestamps();

            $table->index('tag_type');
            $table->index('parent_area');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_hierarchy');
    }
};
