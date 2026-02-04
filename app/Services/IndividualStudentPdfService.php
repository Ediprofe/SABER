<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Exam;
use App\Support\AreaConfig;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

class IndividualStudentPdfService
{
    public function __construct(
        private ZipgradeMetricsService $metricsService
    ) {}

    /**
     * Genera el PDF de un estudiante individual.
     */
    public function generatePdf(Enrollment $enrollment, Exam $exam): string
    {
        $data = $this->collectReportData($enrollment, $exam);

        $html = view('reports.student-individual-pdf', $data)->render();

        $pdf = Pdf::loadHTML($html)
            ->setPaper('letter', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => false,
                'defaultFont' => 'sans-serif',
                'dpi' => 96,
            ]);

        return $pdf->output();
    }

    /**
     * Recopila todos los datos necesarios para el informe.
     * REUTILIZA métodos existentes de ZipgradeMetricsService.
     */
    private function collectReportData(Enrollment $enrollment, Exam $exam): array
    {
        // Datos del estudiante
        $student = $enrollment->student;

        // Puntajes por área (REUTILIZA)
        $areaScores = $this->metricsService->getStudentAllAreaScores($enrollment, $exam);

        // Estadísticas del examen para comparación (REUTILIZA)
        $examStats = $this->metricsService->getExamStatistics($exam);

        // Percentil (NUEVO)
        $percentile = $this->metricsService->getStudentPercentile($enrollment, $exam);

        // Puntajes por dimensión para cada área (REUTILIZA)
        $dimensions = [];
        foreach (['naturales', 'matematicas', 'sociales', 'lectura', 'ingles'] as $area) {
            $dimScores = $this->metricsService->getStudentDimensionScores($enrollment, $exam, $area);
            if (!empty($dimScores)) {
                $dimensions[$area] = [
                    'label' => AreaConfig::getLabel($area),
                    'area_score' => $areaScores[$area],
                    'area_average' => $examStats['areas'][$area]['average'] ?? 0,
                    'dimensions' => $dimScores,
                ];
            }
        }

        // Preguntas incorrectas (NUEVO)
        $incorrectAnswers = $this->metricsService->getStudentIncorrectAnswers($enrollment, $exam);

        // Agrupar incorrectas por área en el orden solicitado
        $incorrectByArea = $this->groupIncorrectByArea($incorrectAnswers);

        // Resumen de incorrectas por área (NUEVO)
        $incorrectSummary = $this->metricsService->getStudentIncorrectSummary($enrollment, $exam);

        // Construir comparaciones por área
        $areaComparisons = [];
        foreach (['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'] as $area) {
            $score = $areaScores[$area];
            $average = $examStats['areas'][$area]['average'] ?? 0;
            $areaComparisons[$area] = [
                'label' => AreaConfig::getLabel($area),
                'score' => $score,
                'average' => $average,
                'comparison' => $score >= $average ? 'above' : 'below',
                'difference' => round($score - $average, 2),
            ];
        }

        return [
            'student' => [
                'document_id' => $student->document_id ?? $student->zipgrade_id ?? '—',
                'full_name' => trim($student->first_name . ' ' . $student->last_name),
                'group' => $enrollment->group,
                'is_piar' => $enrollment->is_piar,
            ],
            'exam' => [
                'name' => $exam->name,
                'date' => $exam->date?->format('Y-m-d') ?? '—',
            ],
            'scores' => [
                'global' => $areaScores['global'],
                'percentile' => $percentile,
                'areas' => $areaComparisons,
            ],
            'dimensions' => $dimensions,
            'incorrectAnswers' => $incorrectAnswers,
            'incorrectByArea' => $incorrectByArea,
            'incorrectSummary' => $incorrectSummary,
            'totalQuestions' => $this->getTotalQuestions($exam),
            'totalIncorrect' => $incorrectAnswers->count(),
            'generatedAt' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Obtiene el total de preguntas del examen.
     */
    private function getTotalQuestions(Exam $exam): int
    {
        return \App\Models\ExamQuestion::whereHas('session', function ($q) use ($exam) {
            $q->where('exam_id', $exam->id);
        })->count();
    }

    /**
     * Genera el nombre del archivo PDF para un estudiante.
     * Formato: APELLIDO_NOMBRE.pdf (en mayúsculas, sin tildes)
     */
    public function getFileName(Enrollment $enrollment): string
    {
        $student = $enrollment->student;

        // Limpiar y formatear apellido y nombre
        $lastName = $this->sanitizeName($student->last_name ?? 'SIN-APELLIDO');
        $firstName = $this->sanitizeName($student->first_name ?? 'SIN-NOMBRE');

        return "{$lastName}_{$firstName}.pdf";
    }

    /**
     * Obtiene la ruta relativa del archivo dentro del ZIP (incluye subcarpeta de grupo).
     * Formato: GRUPO/APELLIDO_NOMBRE.pdf
     */
    public function getRelativePath(Enrollment $enrollment): string
    {
        $group = $enrollment->group ?? 'SIN-GRUPO';
        $fileName = $this->getFileName($enrollment);

        return "{$group}/{$fileName}";
    }

    /**
     * Sanitiza un nombre: quita tildes, convierte a mayúsculas, reemplaza espacios por guiones.
     */
    private function sanitizeName(string $name): string
    {
        // Quitar tildes
        $name = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);

        // Convertir a mayúsculas
        $name = mb_strtoupper($name, 'UTF-8');

        // Reemplazar espacios y caracteres especiales por guion bajo
        $name = preg_replace('/[^A-Z0-9]+/', '_', $name);

        // Quitar guiones bajos al inicio y final
        $name = trim($name, '_');

        return $name ?: 'DESCONOCIDO';
    }

    /**
     * Agrupa las preguntas incorrectas por área en orden específico.
     * Orden: Lectura, Matemáticas, Sociales, Naturales, Inglés
     */
    private function groupIncorrectByArea($incorrectAnswers): array
    {
        // Definir el orden y configuración de dimensiones por área
        $areaConfig = [
            'Lectura Crítica' => [
                'key' => 'lectura',
                'label' => 'Lectura Crítica',
                'dimensions' => [
                    ['name' => 'Competencia', 'field' => 'dimension1_value'],
                    ['name' => 'Tipo de Texto', 'field' => 'dimension2_value'],
                    ['name' => 'Nivel de Lectura', 'field' => 'dimension3_value'],
                ],
            ],
            'Matemáticas' => [
                'key' => 'matematicas',
                'label' => 'Matemáticas',
                'dimensions' => [
                    ['name' => 'Competencia', 'field' => 'dimension1_value'],
                    ['name' => 'Componente', 'field' => 'dimension2_value'],
                ],
            ],
            'Ciencias Sociales' => [
                'key' => 'sociales',
                'label' => 'Ciencias Sociales',
                'dimensions' => [
                    ['name' => 'Competencia', 'field' => 'dimension1_value'],
                    ['name' => 'Componente', 'field' => 'dimension2_value'],
                ],
            ],
            'Ciencias Naturales' => [
                'key' => 'naturales',
                'label' => 'Ciencias Naturales',
                'dimensions' => [
                    ['name' => 'Competencia', 'field' => 'dimension1_value'],
                    ['name' => 'Componente', 'field' => 'dimension2_value'],
                ],
            ],
            'Inglés' => [
                'key' => 'ingles',
                'label' => 'Inglés',
                'dimensions' => [
                    ['name' => 'Parte', 'field' => 'dimension1_value'],
                ],
            ],
        ];

        $result = [];

        foreach ($areaConfig as $areaName => $config) {
            // Filtrar preguntas de esta área
            $questions = $incorrectAnswers->filter(function ($item) use ($areaName) {
                return $item['area'] === $areaName;
            })->values();

            if ($questions->isNotEmpty()) {
                $result[$config['key']] = [
                    'label' => $config['label'],
                    'dimensions' => $config['dimensions'],
                    'questions' => $questions,
                    'count' => $questions->count(),
                ];
            }
        }

        return $result;
    }
}
