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
        $areaScores = [
            'lectura' => [],
            'matematicas' => [],
            'sociales' => [],
            'naturales' => [],
            'ingles' => [],
        ];

        $piarCount = 0;
        $nonPiarCount = 0;

        foreach ($enrollments as $enrollment) {
            $scores = $this->metricsService->getStudentAllAreaScores($enrollment, $exam);
            $globalScores[] = $scores['global'];

            foreach ($areaScores as $area => &$values) {
                $values[] = $scores[$area];
            }
            unset($values); // IMPORTANTE: romper la referencia

            if ($enrollment->is_piar) {
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
                'globalStdDev' => 0,
                'areaStatistics' => [],
            ];
        }

        $globalAvg = array_sum($globalScores) / $totalStudents;
        $globalStdDev = $this->calculateStdDev($globalScores, $globalAvg);

        // Calculate area statistics
        $areaStatistics = [];
        $areaLabels = AreaConfig::AREA_LABELS;

        foreach ($areaScores as $area => $values) {
            if (! empty($values)) {
                $avg = array_sum($values) / count($values);
                $stdDev = $this->calculateStdDev($values, $avg);
                $areaStatistics[] = (object) [
                    'area' => $areaLabels[$area],
                    'average' => $avg,
                    'stdDev' => $stdDev,
                    'min' => min($values),
                    'max' => max($values),
                    'count' => count($values),
                ];
            }
        }

        return (object) [
            'totalStudents' => $totalStudents,
            'piarCount' => $piarCount,
            'nonPiarCount' => $nonPiarCount,
            'globalAverage' => $globalAvg,
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
     * Obtiene los top performers para un área específica.
     */
    private function getTopPerformers(Collection $results, string $area, int $limit): Collection
    {
        $sorted = $results->sortByDesc(function ($result) use ($area) {
            return $area === 'global' ? $result->global_score : $result->{$area};
        });

        return $sorted->take($limit)->values();
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
}
