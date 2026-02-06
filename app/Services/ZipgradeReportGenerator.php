<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamSession;
use App\Support\AreaConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;

/**
 * Genera reportes HTML para exámenes Zipgrade.
 * 
 * REFACTORIZADO: Ahora usa ZipgradeMetricsService como fuente única de datos,
 * eliminando duplicación de código y asegurando consistencia con Excel.
 */
class ZipgradeReportGenerator
{
    public function __construct(
        private ZipgradeMetricsService $metricsService,
    ) {}

    /**
     * Genera un reporte HTML completo con los datos de Zipgrade.
     * Usa las mismas fuentes de datos que el Excel para garantizar consistencia.
     */
    public function generateHtmlReport(Exam $exam, ?string $group = null): string
    {
        $filters = [];
        if ($group) {
            $filters['group'] = $group;
        }

        // Usar MetricsService como fuente única de datos
        $enrollments = $this->metricsService->getEnrollmentsForExam($exam, $filters);
        
        // Gather all necessary data using centralized methods
        $statistics = $this->buildStatisticsFromMetrics($exam, $enrollments);

        // Get all results with student info
        $results = $this->buildResultsFromEnrollments($exam, $enrollments);

        // Get top performers for each area
        $areas = ['lectura', 'matematicas', 'sociales', 'naturales', 'ingles', 'global'];
        $topPerformers = [];
        foreach ($areas as $area) {
            $topPerformers[$area] = $this->getTopPerformers($results, $area, 5);
        }

        // Get distributions
        $distributions = [];
        foreach (['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'] as $area) {
            $distributions[$area] = $this->getDistribution($results, $area, 10);
        }
        // Add global distribution
        $distributions['global'] = $this->getGlobalDistribution($results, 10);

        // Group comparison - usando MetricsService
        $groupComparison = $this->getGroupComparison($exam, $filters);

        // PIAR comparison - usando MetricsService
        $piarComparison = $this->getPiarComparison($exam, $filters);

        // Dimension analysis by area (for charts)
        $dimensionChartData = $this->getDimensionChartData($exam);

        // Question analysis data
        $questionAnalysisData = $this->getQuestionAnalysisData($exam);

        // Prepare data for the view
        $reportData = [
            'exam' => $exam,
            'filters' => $filters,
            'statistics' => $statistics,
            'results' => $results,
            'topPerformers' => $topPerformers,
            'distributions' => $distributions,
            'groupComparison' => $groupComparison,
            'piarComparison' => $piarComparison,
            'dimensionChartData' => $dimensionChartData,
            'questionAnalysisData' => $questionAnalysisData,
            'generatedAt' => now()->format('Y-m-d H:i:s'),
        ];

        // Render the view
        $html = View::make('reports.zipgrade-exam', $reportData)->render();

        return $html;
    }

    /**
     * Genera el nombre del archivo HTML.
     */
    public function getReportFilename(Exam $exam, ?string $group = null): string
    {
        $parts = ['informe', str_replace(' ', '_', $exam->name)];

        if ($group) {
            $parts[] = "grupo{$group}";
        }

        $parts[] = now()->format('Ymd');

        return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '_', implode('_', $parts))).'.html';
    }

    /**
     * Construye estadísticas usando enrollments pre-obtenidos del MetricsService.
     */
    private function buildStatisticsFromMetrics(Exam $exam, Collection $enrollments): object
    {
        $globalScores = [];
        $globalScoresNonPiar = [];
        $areaScores = [
            'lectura' => ['all' => [], 'non_piar' => []],
            'matematicas' => ['all' => [], 'non_piar' => []],
            'sociales' => ['all' => [], 'non_piar' => []],
            'naturales' => ['all' => [], 'non_piar' => []],
            'ingles' => ['all' => [], 'non_piar' => []],
        ];

        $piarCount = 0;
        $nonPiarCount = 0;

        foreach ($enrollments as $enrollment) {
            $scores = $this->metricsService->getStudentAllAreaScores($enrollment, $exam);
            $globalScores[] = $scores['global'];
            
            $isPiar = $enrollment->is_piar;
            if (!$isPiar) {
                $globalScoresNonPiar[] = $scores['global'];
            }

            foreach ($areaScores as $area => &$data) {
                if (isset($scores[$area]) && $scores[$area] !== null) {
                    $data['all'][] = $scores[$area];
                    if (!$isPiar) {
                        $data['non_piar'][] = $scores[$area];
                    }
                }
            }
            unset($data);

            if ($isPiar) {
                $piarCount++;
            } else {
                $nonPiarCount++;
            }
        }

        $totalStudents = count($globalScores);

        if ($totalStudents === 0) {
            return (object) [
                'totalStudents' => 0,
                'piarCount' => 0,
                'nonPiarCount' => 0,
                'globalAverage' => 0,
                'globalAverageNonPiar' => 0,
                'globalStdDev' => 0,
                'areaStatistics' => [],
            ];
        }

        $globalAvg = array_sum($globalScores) / $totalStudents;
        $globalAvgNonPiar = count($globalScoresNonPiar) > 0 ? array_sum($globalScoresNonPiar) / count($globalScoresNonPiar) : 0;
        $globalStdDev = $this->calculateStdDev($globalScores, $globalAvg);

        // Calculate area statistics
        $areaStatistics = [];
        $areaLabels = AreaConfig::AREA_LABELS;

        foreach ($areaScores as $area => $data) {
            $allValues = $data['all'];
            $nonPiarValues = $data['non_piar'];
            
            if (! empty($allValues)) {
                $avgAll = array_sum($allValues) / count($allValues);
                $avgNonPiar = count($nonPiarValues) > 0 ? array_sum($nonPiarValues) / count($nonPiarValues) : 0;
                $stdDev = $this->calculateStdDev($allValues, $avgAll);
                
                $areaStatistics[] = (object) [
                    'area' => $areaLabels[$area],
                    'average' => $avgAll,
                    'averageNonPiar' => $avgNonPiar,
                    'stdDev' => $stdDev,
                    'min' => min($allValues),
                    'max' => max($allValues),
                    'count' => count($allValues),
                ];
            }
        }

        return (object) [
            'totalStudents' => $totalStudents,
            'piarCount' => $piarCount,
            'nonPiarCount' => $nonPiarCount,
            'globalAverage' => $globalAvg,
            'globalAverageNonPiar' => $globalAvgNonPiar,
            'globalStdDev' => $globalStdDev,
            'areaStatistics' => $areaStatistics,
        ];
    }

    /**
     * Construye resultados usando enrollments pre-obtenidos.
     */
    private function buildResultsFromEnrollments(Exam $exam, Collection $enrollments): Collection
    {
        return $enrollments->map(function ($enrollment) use ($exam) {
            $scores = $this->metricsService->getStudentAllAreaScores($enrollment, $exam);

            return (object) [
                'enrollment' => $enrollment,
                'student' => $enrollment->student,
                'lectura' => $scores['lectura'],
                'matematicas' => $scores['matematicas'],
                'sociales' => $scores['sociales'],
                'naturales' => $scores['naturales'],
                'ingles' => $scores['ingles'],
                'global_score' => $scores['global'],
            ];
        });
    }

    /**
     * Obtiene los top performers para un área específica, con ranking que considera empates.
     */
    private function getTopPerformers(Collection $results, string $area, int $limit): Collection
    {
        $sorted = $results->sortByDesc(function ($result) use ($area) {
            return $area === 'global' ? $result->global_score : $result->{$area};
        })->take($limit)->values();

        // Calcular ranking con empates
        $rank = 1;
        $previousScore = null;

        return $sorted->map(function ($result, $index) use ($area, &$rank, &$previousScore) {
            $currentScore = $area === 'global' ? $result->global_score : $result->{$area};

            if ($previousScore !== null && $currentScore < $previousScore) {
                // Puntaje diferente (menor), avanzar el rank
                $rank = $index + 1; // El rank salta a la posición real
            }
            // Si es igual al anterior, mantiene el mismo rank

            $previousScore = $currentScore;

            // IMPORTANTE: Clonar el objeto para evitar que el rank de un área 
            // sobrescriba el de otra si el estudiante aparece en varios tops.
            $newResult = clone $result;
            $newResult->rank = $rank;

            return $newResult;
        });
    }

    /**
     * Obtiene la distribución de puntajes para un área.
     */
    private function getDistribution(Collection $results, string $area, int $bins): array
    {
        $scores = $results->map(function ($result) use ($area) {
            return $area === 'global' ? $result->global_score : $result->{$area};
        })->toArray();

        if (empty($scores)) {
            return [];
        }

        $min = 0;
        $max = 100;
        $binWidth = ($max - $min) / $bins;

        $distribution = [];
        for ($i = 0; $i < $bins; $i++) {
            $binMin = $min + ($i * $binWidth);
            $binMax = $binMin + $binWidth;
            $count = 0;

            foreach ($scores as $score) {
                if ($score >= $binMin && $score < $binMax) {
                    $count++;
                }
            }

            // Include max value in last bin
            if ($i === $bins - 1) {
                foreach ($scores as $score) {
                    if ($score == $max) {
                        $count++;
                    }
                }
            }

            $distribution[] = [
                'range' => sprintf('%d-%d', $binMin, $binMax),
                'min' => $binMin,
                'max' => $binMax,
                'count' => $count,
            ];
        }

        return $distribution;
    }

    /**
     * Obtiene la distribución de puntajes globales.
     */
    private function getGlobalDistribution(Collection $results, int $bins): array
    {
        $scores = $results->map(function ($result) {
            return $result->global_score;
        })->toArray();

        if (empty($scores)) {
            return [];
        }

        $min = 0;
        $max = 500;
        $binWidth = ($max - $min) / $bins;

        $distribution = [];
        for ($i = 0; $i < $bins; $i++) {
            $binMin = $min + ($i * $binWidth);
            $binMax = $binMin + $binWidth;
            $count = 0;

            foreach ($scores as $score) {
                if ($score >= $binMin && $score < $binMax) {
                    $count++;
                }
            }

            // Include max value in last bin
            if ($i === $bins - 1) {
                foreach ($scores as $score) {
                    if ($score == $max) {
                        $count++;
                    }
                }
            }

            $distribution[] = [
                'range' => sprintf('%d-%d', $binMin, $binMax),
                'min' => $binMin,
                'max' => $binMax,
                'count' => $count,
            ];
        }

        return $distribution;
    }

    /**
     * Obtiene comparación por grupos usando MetricsService.
     * Devuelve promedios segregados por CON PIAR (todos) vs SIN PIAR (solo no-piar).
     * 
     * SEMÁNTICA CORRECTA (igual que Excel):
     * - CON PIAR: Todos los estudiantes (incluyendo los que tienen PIAR)
     * - SIN PIAR: Todos los estudiantes EXCEPTO los que tienen PIAR
     */
    private function getGroupComparison(Exam $exam, array $filters): array
    {
        $sessionIds = ExamSession::where('exam_id', $exam->id)->pluck('id');

        $groups = Enrollment::query()
            ->where('academic_year_id', $exam->academic_year_id)
            ->where('status', 'ACTIVE')
            ->whereHas('studentAnswers.question', function ($query) use ($sessionIds) {
                $query->whereIn('exam_session_id', $sessionIds);
            })
            ->distinct()
            ->pluck('group')
            ->sort()
            ->values();

        $result = [];
        $areas = ['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'];

        foreach ($groups as $group) {
            $groupFilters = array_merge($filters, ['group' => $group]);
            $groupEnrollments = $this->metricsService->getEnrollmentsForExam($exam, $groupFilters);

            if ($groupEnrollments->isEmpty()) {
                continue;
            }

            // Inicializar acumuladores
            // con_piar = TODOS los estudiantes
            // sin_piar = solo estudiantes SIN PIAR
            $scoresByArea = [];
            foreach ($areas as $area) {
                $scoresByArea[$area] = [
                    'all' => [],      // Todos (CON PIAR)
                    'non_piar' => []  // Solo no-PIAR (SIN PIAR)
                ];
            }

            foreach ($groupEnrollments as $enrollment) {
                $scores = $this->metricsService->getStudentAllAreaScores($enrollment, $exam);
                $isPiar = $enrollment->is_piar;
                
                foreach ($areas as $area) {
                    // TODOS van al grupo "all" (CON PIAR)
                    $scoresByArea[$area]['all'][] = $scores[$area];
                    
                    // Solo los NO-PIAR van al grupo "non_piar" (SIN PIAR)
                    if (!$isPiar) {
                        $scoresByArea[$area]['non_piar'][] = $scores[$area];
                    }
                }
            }

            // Calcular promedios
            $groupAverages = ['group' => $group, 'count' => $groupEnrollments->count()];
            
            foreach ($areas as $area) {
                $allValues = $scoresByArea[$area]['all'];
                $nonPiarValues = $scoresByArea[$area]['non_piar'];
                
                $groupAverages[$area] = [
                    // CON PIAR = promedio de TODOS
                    'piar' => !empty($allValues) ? array_sum($allValues) / count($allValues) : 0,
                    // SIN PIAR = promedio solo de NO-PIAR
                    'non_piar' => !empty($nonPiarValues) ? array_sum($nonPiarValues) / count($nonPiarValues) : 0,
                    'all_count' => count($allValues),
                    'non_piar_count' => count($nonPiarValues)
                ];
            }
            
            $result[$group] = $groupAverages;
        }

        return $result;
    }

    /**
     * Obtiene comparativo CON PIAR vs SIN PIAR usando MetricsService.
     * 
     * SEMÁNTICA CORRECTA (igual que Excel):
     * - CON PIAR: Todos los estudiantes (incluyendo los que tienen PIAR)
     * - SIN PIAR: Todos los estudiantes EXCEPTO los que tienen PIAR
     */
    private function getPiarComparison(Exam $exam, array $filters): array
    {
        // CON PIAR: todos los estudiantes (sin filtro de PIAR)
        $conPiarFilters = $filters; // Sin filtro adicional = todos
        // SIN PIAR: excluir estudiantes con PIAR
        $sinPiarFilters = array_merge($filters, ['exclude_piar' => true]);

        $conPiarStats = $this->getAreaAveragesForComparison($exam, $conPiarFilters);
        $sinPiarStats = $this->getAreaAveragesForComparison($exam, $sinPiarFilters);

        $conPiarCount = $this->metricsService->getEnrollmentsForExam($exam, $conPiarFilters)->count();
        $sinPiarCount = $this->metricsService->getEnrollmentsForExam($exam, $sinPiarFilters)->count();

        return [
            'piar' => $conPiarStats,       // CON PIAR = todos
            'non_piar' => $sinPiarStats,   // SIN PIAR = excluyendo PIAR
            'piar_count' => $conPiarCount,
            'non_piar_count' => $sinPiarCount,
        ];
    }

    /**
     * Obtiene promedios por área para comparación usando MetricsService.
     */
    private function getAreaAveragesForComparison(Exam $exam, array $filters): array
    {
        $enrollments = $this->metricsService->getEnrollmentsForExam($exam, $filters);

        $areaScores = [
            'lectura' => [],
            'matematicas' => [],
            'sociales' => [],
            'naturales' => [],
            'ingles' => [],
        ];

        foreach ($enrollments as $enrollment) {
            $scores = $this->metricsService->getStudentAllAreaScores($enrollment, $exam);
            foreach ($areaScores as $area => &$values) {
                $values[] = $scores[$area];
            }
            unset($values); // IMPORTANTE: romper la referencia después del loop
        }

        $result = [];
        foreach ($areaScores as $area => $values) {
            $avg = ! empty($values) ? array_sum($values) / count($values) : 0;
            $result[$area] = (object) [
                'average' => $avg,
                'stdDev' => $this->calculateStdDev($values, $avg),
            ];
        }

        return $result;
    }

    /**
     * Calcula la desviación estándar.
     * Nota: Este cálculo se mantiene aquí para formateo específico del reporte,
     * pero los datos provienen del MetricsService.
     */
    private function calculateStdDev(array $values, float $mean): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0;
        }

        $sum = 0;
        foreach ($values as $value) {
            $sum += pow($value - $mean, 2);
        }

        return sqrt($sum / ($count - 1));
    }

    /**
     * Obtiene datos de dimensiones por área para gráficos.
     * Solo promedios globales (CON PIAR vs SIN PIAR).
     */
    private function getDimensionChartData(Exam $exam): array
    {
        $areas = [
            'lectura' => [
                'label' => 'Lectura Crítica',
                'dimensions' => [
                    1 => 'Competencias',
                    2 => 'Tipos de Texto',
                    3 => 'Niveles de Lectura',
                ],
            ],
            'matematicas' => [
                'label' => 'Matemáticas',
                'dimensions' => [
                    1 => 'Competencias',
                    2 => 'Componentes',
                ],
            ],
            'sociales' => [
                'label' => 'Ciencias Sociales',
                'dimensions' => [
                    1 => 'Competencias',
                    2 => 'Componentes',
                ],
            ],
            'naturales' => [
                'label' => 'Ciencias Naturales',
                'dimensions' => [
                    1 => 'Competencias',
                    2 => 'Componentes',
                ],
            ],
            'ingles' => [
                'label' => 'Inglés',
                'dimensions' => [
                    1 => 'Partes',
                ],
            ],
        ];

        $result = [];

        foreach ($areas as $areaKey => $areaConfig) {
            $result[$areaKey] = [
                'label' => $areaConfig['label'],
                'dimensions' => [],
            ];

            foreach ($areaConfig['dimensions'] as $dimNumber => $dimLabel) {
                $piarData = $this->metricsService->getDimensionPiarComparison($exam, $areaKey, $dimNumber);

                if (empty($piarData)) {
                    continue;
                }

                // Extraer solo los promedios globales
                $dimensionItems = [];
                foreach ($piarData as $itemName => $itemData) {
                    $dimensionItems[] = [
                        'name' => $itemName,
                        'con_piar' => $itemData['con_piar']['promedio'] ?? 0,
                        'sin_piar' => $itemData['sin_piar']['promedio'] ?? 0,
                    ];
                }

                if (!empty($dimensionItems)) {
                    $result[$areaKey]['dimensions'][$dimNumber] = [
                        'label' => $dimLabel,
                        'items' => $dimensionItems,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Obtiene datos de análisis por pregunta para la tabla HTML.
     */
    private function getQuestionAnalysisData(Exam $exam): array
    {
        $sessionIds = ExamSession::where('exam_id', $exam->id)->pluck('id');

        $questions = \App\Models\ExamQuestion::whereIn('exam_session_id', $sessionIds)
            ->with(['session', 'tags', 'questionTags.tag'])
            ->orderBy('exam_session_id')
            ->orderBy('question_number')
            ->get();

        return $questions->map(function ($question) {
            $session = $question->session;
            $sessionNumber = $session?->session_number ?? 1;

            // Determinar área y dimensiones desde tags
            $area = $this->getAreaFromQuestion($question);
            $dimension1 = $this->getDimension1FromQuestion($question, $area);
            $dimension2 = $this->getDimension2FromQuestion($question, $area);
            $dimension3 = $this->getDimension3FromQuestion($question, $area);

            // Calcular % de acierto desde respuestas
            $totalAnswers = $question->studentAnswers()->count();
            $correctAnswers = $question->studentAnswers()->where('is_correct', true)->count();
            $pctCorrect = $totalAnswers > 0 ? round(($correctAnswers / $totalAnswers) * 100, 2) : 0;

            return [
                'sesion' => $sessionNumber,
                'numero' => $question->question_number,
                'correcta' => $question->correct_answer ?? '—',
                'area' => $area ?? '—',
                'pct_acierto' => $pctCorrect,
                'respuesta_1' => $question->response_1 ?? '—',
                'pct_1' => $question->response_1_pct ?? 0,
                'respuesta_2' => $question->response_2 ?? '—',
                'pct_2' => $question->response_2_pct ?? 0,
                'dim1' => $dimension1 ?? '—',
                'dim2' => $dimension2 ?? '—',
                'dim3' => $dimension3 ?? '—',
            ];
        })->toArray();
    }

    /**
     * Obtiene el área de una pregunta.
     */
    private function getAreaFromQuestion($question): ?string
    {
        foreach ($question->tags as $tag) {
            if ($tag->tag_type === 'area') {
                $normalized = \App\Support\AreaConfig::normalizeAreaName($tag->tag_name);
                return $normalized ? \App\Support\AreaConfig::getLabel($normalized) : $tag->tag_name;
            }
        }

        foreach ($question->tags as $tag) {
            if ($tag->parent_area) {
                $normalized = \App\Support\AreaConfig::normalizeAreaName($tag->parent_area);
                return $normalized ? \App\Support\AreaConfig::getLabel($normalized) : $tag->parent_area;
            }
        }

        return null;
    }

    /**
     * Obtiene la dimensión 1 de una pregunta.
     */
    private function getDimension1FromQuestion($question, ?string $area): ?string
    {
        if ($area === 'Inglés') {
            $parte = $question->tags->firstWhere('tag_type', 'parte');
            return $parte?->tag_name;
        }

        $competencia = $question->tags->firstWhere('tag_type', 'competencia');
        return $competencia?->tag_name;
    }

    /**
     * Obtiene la dimensión 2 de una pregunta.
     */
    private function getDimension2FromQuestion($question, ?string $area): ?string
    {
        if ($area === 'Inglés') {
            return null;
        }

        if (in_array($area, ['Lectura', 'Lectura Crítica'])) {
            $tipo = $question->tags->firstWhere('tag_type', 'tipo_texto');
            return $tipo?->tag_name;
        }

        $componente = $question->tags->firstWhere('tag_type', 'componente');
        return $componente?->tag_name;
    }

    /**
     * Obtiene la dimensión 3 de una pregunta.
     */
    private function getDimension3FromQuestion($question, ?string $area): ?string
    {
        if (in_array($area, ['Lectura', 'Lectura Crítica'])) {
            $nivel = $question->tags->firstWhere('tag_type', 'nivel_lectura');
            return $nivel?->tag_name;
        }

        return null;
    }
}
