<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\Student;
use App\Models\TagHierarchy;
use Illuminate\Console\Command;

class GenerateZipgradeTestData extends Command
{
    protected $signature = 'generate:zipgrade-test-data
                            {--year=2026 : Año académico}
                            {--grade=11 : Grado (10 u 11)}
                            {--questions=150 : Número de preguntas por sesión}';

    protected $description = 'Genera datos de prueba tipo Zipgrade usando estudiantes reales del sistema';

    private array $areas = [
        'Ciencias' => [
            'competencias' => ['Explicación de fenómenos', 'Indagación', 'Uso comprensivo del conocimiento'],
            'componentes' => ['Químico', 'Biológico', 'Físico', 'CTS'],
        ],
        'Matemáticas' => [
            'competencias' => ['Interpretación', 'Formulación', 'Argumentación'],
            'componentes' => ['Numérico variacional', 'Geométrico métrico', 'Aleatorio'],
        ],
        'Sociales' => [
            'competencias' => ['Pensamiento social', 'Interpretación de perspectivas', 'Pensamiento reflexivo'],
            'componentes' => ['Historia', 'Geografía', 'Político'],
        ],
        'Lectura' => [
            'competencias' => ['Identificar y ubicar', 'Relacionar e interpretar', 'Evaluar y reflexionar'],
            'componentes' => ['Texto continuo', 'Texto discontinuo', 'Texto literario'],
        ],
        'Inglés' => [
            'competencias' => ['Comprensión', 'Expresión', 'Interacción'],
            'componentes' => ['Parte 1', 'Parte 2', 'Parte 3', 'Parte 4'],
        ],
    ];

    public function handle()
    {
        $year = $this->option('year');
        $grade = $this->option('grade');
        $questionsPerSession = $this->option('questions');

        $this->info('Generando datos de prueba Zipgrade...');
        $this->info("Año: {$year}, Grado: {$grade}, Preguntas por sesión: {$questionsPerSession}");

        // 1. Verificar estudiantes reales
        $students = $this->getRealStudents($year, $grade);
        if ($students->isEmpty()) {
            $this->error("No hay estudiantes en grado {$grade} para el año {$year}");

            return 1;
        }

        $this->info("Encontrados {$students->count()} estudiantes reales");

        // 2. Crear tags si no existen
        $this->createTagsIfNotExist();

        // 3. Generar sesiones
        $quizName = "Simulacro Prueba {$year}-{$grade}";

        for ($session = 1; $session <= 2; $session++) {
            $this->info("Generando Sesión {$session}...");
            $this->generateSessionCsv($students, $session, $questionsPerSession, $quizName);
        }

        $this->info('✅ Archivos generados exitosamente en: storage/app/zipgrade_test/');
        $this->info('Archivos:');
        $this->info('  - zipgrade_sesion1_prueba.csv');
        $this->info('  - zipgrade_sesion2_prueba.csv');
        $this->info('');
        $this->info('Instrucciones:');
        $this->info('1. Ve a Exámenes → Crear Examen');
        $this->info('2. Configura el examen (nombre, tipo, fecha)');
        $this->info("3. Click en 'Sesiones Zipgrade'");
        $this->info('4. Importa primero sesion1, luego sesion2');
        $this->info("5. Ve 'Ver Resultados Zipgrade' para ver los cálculos");

        return 0;
    }

    private function getRealStudents(int $year, int $grade)
    {
        $academicYear = AcademicYear::where('year', $year)->first();

        if (! $academicYear) {
            return collect();
        }

        return Student::whereHas('enrollments', function ($query) use ($academicYear, $grade) {
            $query->where('academic_year_id', $academicYear->id)
                ->where('grade', $grade)
                ->where('status', 'ACTIVE');
        })->with(['enrollments' => function ($query) use ($academicYear) {
            $query->where('academic_year_id', $academicYear->id);
        }])->get();
    }

    private function createTagsIfNotExist(): void
    {
        $this->info('Verificando tags...');

        foreach ($this->areas as $areaName => $data) {
            // Crear tag de área
            TagHierarchy::firstOrCreate(
                ['tag_name' => $areaName],
                ['tag_type' => 'area', 'parent_area' => null]
            );

            // Crear competencias
            foreach ($data['competencias'] as $comp) {
                TagHierarchy::firstOrCreate(
                    ['tag_name' => $comp],
                    ['tag_type' => 'competencia', 'parent_area' => $areaName]
                );
            }

            // Crear componentes
            foreach ($data['componentes'] as $comp) {
                TagHierarchy::firstOrCreate(
                    ['tag_name' => $comp],
                    ['tag_type' => 'componente', 'parent_area' => $areaName]
                );
            }
        }

        $this->info('Tags verificados/');
    }

    private function generateSessionCsv($students, int $session, int $questionsPerSession, string $quizName): void
    {
        // Headers
        $headers = ['Tag', 'StudentFirstName', 'StudentLastName', 'StudentID', 'StudentExternalID',
            'QuizName', 'TagType', 'QuestionNumber', 'EarnedPoints', 'PossiblePoints'];

        // Crear directorio si no existe
        $directory = storage_path('app/zipgrade_test');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = "zipgrade_sesion{$session}_prueba.csv";
        $filepath = $directory.'/'.$filename;

        // Abrir archivo CSV
        $file = fopen($filepath, 'w');

        // Escribir encabezados con coma (formato Zipgrade original)
        fputcsv($file, $headers, ',');

        // Generar datos
        $rowCount = 0;
        $areaNames = array_keys($this->areas);
        $questionsPerArea = intdiv($questionsPerSession, count($areaNames)); // 30 preguntas por área

        foreach ($students as $student) {
            $enrollment = $student->enrollments->first();
            $documentId = $student->document_id ?? $student->code;

            // Generar preguntas para cada área
            $questionNum = 1;

            foreach ($areaNames as $areaName) {
                $areaData = $this->areas[$areaName];

                for ($q = 0; $q < $questionsPerArea; $q++) {
                    // Determinar si la respuesta es correcta (60% probabilidad de acierto)
                    $isCorrect = rand(1, 100) <= 60;
                    // Formato Zipgrade usa punto para decimales: 0.334
                    $earnedPoints = $isCorrect ? '0.334' : '0.0';

                    // Seleccionar competencia y componente aleatorios
                    $competencia = $areaData['competencias'][array_rand($areaData['competencias'])];
                    $componente = $areaData['componentes'][array_rand($areaData['componentes'])];

                    // 3 filas por pregunta (Área, Competencia, Componente)
                    $tags = [
                        ['tag' => $areaName, 'type' => 'area'],
                        ['tag' => $competencia, 'type' => 'competencia'],
                        ['tag' => $componente, 'type' => 'componente'],
                    ];

                    foreach ($tags as $tagData) {
                        $rowData = [
                            $tagData['tag'],                              // Tag
                            $student->first_name,                         // StudentFirstName
                            $student->last_name,                          // StudentLastName
                            $documentId,                                  // StudentID
                            '',                                           // StudentExternalID
                            "{$quizName} - Sesion {$session}",           // QuizName (sin acentos para evitar problemas)
                            'question',                                   // TagType
                            $questionNum,                                 // QuestionNumber
                            $earnedPoints,                                // EarnedPoints (con punto)
                            '0.334',                                      // PossiblePoints (con punto)
                        ];

                        fputcsv($file, $rowData, ',');
                        $rowCount++;
                    }

                    $questionNum++;
                }
            }
        }

        // Cerrar archivo
        fclose($file);

        $this->info("  Sesión {$session}: {$rowCount} filas generadas ({$students->count()} estudiantes × 150 preguntas × 3 tags)");
    }
}
