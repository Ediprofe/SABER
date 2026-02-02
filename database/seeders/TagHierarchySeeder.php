<?php

namespace Database\Seeders;

use App\Models\TagHierarchy;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TagHierarchySeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->command->info('Creando jerarquía de tags...');

        // 1. ÁREAS PRINCIPALES (sin parent_area)
        $areas = [
            ['tag_name' => 'Ciencias Naturales', 'tag_type' => 'area', 'parent_area' => null],
            ['tag_name' => 'Ciencias Sociales', 'tag_type' => 'area', 'parent_area' => null],
            ['tag_name' => 'Inglés', 'tag_type' => 'area', 'parent_area' => null],
            ['tag_name' => 'Lectura Crítica', 'tag_type' => 'area', 'parent_area' => null],
            ['tag_name' => 'Matemáticas', 'tag_type' => 'area', 'parent_area' => null],
        ];

        // 2. CIENCIAS NATURALES
        $cienciasCompetencias = [
            ['tag_name' => 'Explicación', 'tag_type' => 'competencia', 'parent_area' => 'Ciencias Naturales'],
            ['tag_name' => 'Indagación', 'tag_type' => 'competencia', 'parent_area' => 'Ciencias Naturales'],
            ['tag_name' => 'Uso Comprensivo', 'tag_type' => 'competencia', 'parent_area' => 'Ciencias Naturales'],
        ];

        $cienciasComponentes = [
            ['tag_name' => 'Biológico', 'tag_type' => 'componente', 'parent_area' => 'Ciencias Naturales'],
            ['tag_name' => 'CTS', 'tag_type' => 'componente', 'parent_area' => 'Ciencias Naturales'],
            ['tag_name' => 'Entorno Fisico', 'tag_type' => 'componente', 'parent_area' => 'Ciencias Naturales'],
            ['tag_name' => 'Entorno Químico', 'tag_type' => 'componente', 'parent_area' => 'Ciencias Naturales'],
        ];

        // 3. MATEMÁTICAS
        $matematicasCompetencias = [
            ['tag_name' => 'Argumentación', 'tag_type' => 'competencia', 'parent_area' => 'Matemáticas'],
            ['tag_name' => 'Formulación', 'tag_type' => 'competencia', 'parent_area' => 'Matemáticas'],
            ['tag_name' => 'Interpretación', 'tag_type' => 'competencia', 'parent_area' => 'Matemáticas'],
        ];

        $matematicasComponentes = [
            ['tag_name' => 'Aleatorio', 'tag_type' => 'componente', 'parent_area' => 'Matemáticas'],
            ['tag_name' => 'Geométrico - Métrico', 'tag_type' => 'componente', 'parent_area' => 'Matemáticas'],
            ['tag_name' => 'Numerico', 'tag_type' => 'componente', 'parent_area' => 'Matemáticas'],
        ];

        // 4. CIENCIAS SOCIALES
        $socialesCompetencias = [
            ['tag_name' => 'Interpretación y análisis de perspectivas', 'tag_type' => 'competencia', 'parent_area' => 'Ciencias Sociales'],
            ['tag_name' => 'Pensamiento Sistémico', 'tag_type' => 'competencia', 'parent_area' => 'Ciencias Sociales'],
            ['tag_name' => 'Pensamiento Social', 'tag_type' => 'competencia', 'parent_area' => 'Ciencias Sociales'],
        ];

        $socialesComponentes = [
            ['tag_name' => 'Ciudadanía', 'tag_type' => 'componente', 'parent_area' => 'Ciencias Sociales'],
            ['tag_name' => 'Sociales', 'tag_type' => 'componente', 'parent_area' => 'Ciencias Sociales'],
        ];

        // 5. LECTURA CRÍTICA - Competencias
        $lecturaCompetencias = [
            ['tag_name' => 'COMUNICATIVA', 'tag_type' => 'competencia', 'parent_area' => 'Lectura Crítica'],
            ['tag_name' => 'GRAMATICA LEXICAL', 'tag_type' => 'competencia', 'parent_area' => 'Lectura Crítica'],
            ['tag_name' => 'GRAMATICAL', 'tag_type' => 'competencia', 'parent_area' => 'Lectura Crítica'],
            ['tag_name' => 'Lexical', 'tag_type' => 'competencia', 'parent_area' => 'Lectura Crítica'],
            ['tag_name' => 'PRAGMATICA', 'tag_type' => 'competencia', 'parent_area' => 'Lectura Crítica'],
        ];

        // 6. LECTURA CRÍTICA - Nivel de Lectura
        $lecturaNivel = [
            ['tag_name' => 'COMPRENSION DE TEXTO INFERENCIAL', 'tag_type' => 'nivel_lectura', 'parent_area' => 'Lectura Crítica'],
            ['tag_name' => 'COMPRENSION DE TEXTO LITERAL', 'tag_type' => 'nivel_lectura', 'parent_area' => 'Lectura Crítica'],
        ];

        // 7. LECTURA CRÍTICA - Tipos de Texto
        $lecturaTipoTexto = [
            ['tag_name' => 'Filosofia', 'tag_type' => 'tipo_texto', 'parent_area' => 'Lectura Crítica'],
            ['tag_name' => 'Informativo', 'tag_type' => 'tipo_texto', 'parent_area' => 'Lectura Crítica'],
            ['tag_name' => 'Literario', 'tag_type' => 'tipo_texto', 'parent_area' => 'Lectura Crítica'],
            ['tag_name' => 'Texto Discontinuo', 'tag_type' => 'tipo_texto', 'parent_area' => 'Lectura Crítica'],
        ];

        // 8. INGLÉS - Competencias (PARTE 1-7)
        $inglesCompetencias = [];
        for ($i = 1; $i <= 7; $i++) {
            $inglesCompetencias[] = [
                'tag_name' => "PARTE {$i}",
                'tag_type' => 'competencia',
                'parent_area' => 'Inglés',
            ];
        }

        // Combinar todos
        $allTags = array_merge(
            $areas,
            $cienciasCompetencias,
            $cienciasComponentes,
            $matematicasCompetencias,
            $matematicasComponentes,
            $socialesCompetencias,
            $socialesComponentes,
            $lecturaCompetencias,
            $lecturaNivel,
            $lecturaTipoTexto,
            $inglesCompetencias
        );

        // Insertar usando firstOrCreate para evitar duplicados
        $count = 0;
        foreach ($allTags as $tagData) {
            TagHierarchy::firstOrCreate(
                ['tag_name' => $tagData['tag_name']],
                $tagData
            );
            $count++;
        }

        $this->command->info("✅ {$count} tags creados exitosamente!");
    }
}
