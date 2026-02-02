<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Exam;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;

class ZipgradeReportGenerator
{
    public function __construct(
        private ZipgradeMetricsService $metricsService,
    ) {}

    /**
     * Genera un reporte HTML completo con los datos de Zipgrade.
     * Similar al reporte de Features 1 y 2, pero usando datos calculados desde Zipgrade.
     */
    public function generateHtmlReport(Exam $exam, ?string $group = null): string
    {
        $filters = [];
        if ($group) {
            $filters['group'] = $group;
        }

        // Gather all necessary data
        $statistics = $this->getExamStatistics($exam, $filters);

        // Get all results with student info
        $results = $this->getExamResults($exam, $group);

        // Get top performers for each area
        $areas = ['lectura', 'matematicas', 'sociales', 'naturales', 'ingles', 'global'];
        $topPerformers = [];
        foreach ($areas as $area) {
            $topPerformers[$area] = $this->getTopPerformers($exam, $area, 5, $filters);
        }

        // Get distributions
        $distributions = [];
        foreach (['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'] as $area) {
            $distributions[$area] = $this->getDistribution($exam, $area, 10, $filters);
        }
        // Add global distribution
        $distributions['global'] = $this->getGlobalDistribution($exam, 10, $filters);

        // Group comparison
        $groupComparison = $this->getGroupComparison($exam, $filters);

        // PIAR comparison
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
     * Obtiene estadísticas globales del examen para el reporte.
     */
    private function getExamStatistics(Exam $exam, array $filters): object
    {
        $enrollments = $this->getEnrollmentsForExam($exam, $filters);

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
        $areaLabels = [
            'lectura' => 'Lectura',
            'matematicas' => 'Matemáticas',
            'sociales' => 'Sociales',
            'naturales' => 'Naturales',
            'ingles' => 'Inglés',
        ];

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
     * Obtiene todos los resultados con información de estudiante.
     * Solo incluye estudiantes que tienen respuestas en el examen.
     */
    private function getExamResults(Exam $exam, ?string $group = null): Collection
    {
        $sessionIds = \App\Models\ExamSession::where('exam_id', $exam->id)->pluck('id');

        $query = Enrollment::query()
            ->where('academic_year_id', $exam->academic_year_id)
            ->where('status', 'ACTIVE')
            ->whereHas('studentAnswers.question', function ($query) use ($sessionIds) {
                $query->whereIn('exam_session_id', $sessionIds);
            })
            ->with('student');

        if ($group) {
            $query->where('group', $group);
        }

        $enrollments = $query->get();

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
    private function getTopPerformers(Exam $exam, string $area, int $limit, array $filters): Collection
    {
        $results = $this->getExamResults($exam, $filters['group'] ?? null);

        $sorted = $results->sortByDesc(function ($result) use ($area) {
            return $area === 'global' ? $result->global_score : $result->{$area};
        });

        return $sorted->take($limit)->values();
    }

    /**
     * Obtiene la distribución de puntajes para un área.
     */
    private function getDistribution(Exam $exam, string $area, int $bins, array $filters): array
    {
        $results = $this->getExamResults($exam, $filters['group'] ?? null);

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
    private function getGlobalDistribution(Exam $exam, int $bins, array $filters): array
    {
        $results = $this->getExamResults($exam, $filters['group'] ?? null);

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
     * Obtiene comparación por grupos.
     * Solo incluye grupos donde hay estudiantes con respuestas en el examen.
     */
    private function getGroupComparison(Exam $exam, array $filters): array
    {
        $sessionIds = \App\Models\ExamSession::where('exam_id', $exam->id)->pluck('id');

        $groups = Enrollment::query()
            ->where('academic_year_id', $exam->academic_year_id)
            ->where('status', 'ACTIVE')
            ->whereHas('studentAnswers.question', function ($query) use ($sessionIds) {
                $query->whereIn('exam_session_id', $sessionIds);
            })
            ->distinct()
            ->pluck('group');

        $result = [];
        foreach ($groups as $group) {
            $groupFilters = array_merge($filters, ['group' => $group]);
            $groupEnrollments = $this->getEnrollmentsForExam($exam, $groupFilters);

            if ($groupEnrollments->isEmpty()) {
                continue;
            }

            $areaScores = [
                'lectura' => [],
                'matematicas' => [],
                'sociales' => [],
                'naturales' => [],
                'ingles' => [],
            ];

            foreach ($groupEnrollments as $enrollment) {
                $scores = $this->metricsService->getStudentAllAreaScores($enrollment, $exam);
                foreach ($areaScores as $area => &$values) {
                    $values[] = $scores[$area];
                }
            }

            $result[$group] = [
                'group' => $group,
                'count' => $groupEnrollments->count(),
                'lectura' => ! empty($areaScores['lectura']) ? array_sum($areaScores['lectura']) / count($areaScores['lectura']) : 0,
                'matematicas' => ! empty($areaScores['matematicas']) ? array_sum($areaScores['matematicas']) / count($areaScores['matematicas']) : 0,
                'sociales' => ! empty($areaScores['sociales']) ? array_sum($areaScores['sociales']) / count($areaScores['sociales']) : 0,
                'naturales' => ! empty($areaScores['naturales']) ? array_sum($areaScores['naturales']) / count($areaScores['naturales']) : 0,
                'ingles' => ! empty($areaScores['ingles']) ? array_sum($areaScores['ingles']) / count($areaScores['ingles']) : 0,
            ];
        }

        return $result;
    }

    /**
     * Obtiene comparativo PIAR vs No-PIAR.
     */
    private function getPiarComparison(Exam $exam, array $filters): array
    {
        $piarFilters = array_merge($filters, ['piar_only' => true]);
        $nonPiarFilters = array_merge($filters, ['exclude_piar' => true]);

        $piarStats = $this->getAreaAveragesForComparison($exam, $piarFilters);
        $nonPiarStats = $this->getAreaAveragesForComparison($exam, $nonPiarFilters);

        $piarCount = $this->getEnrollmentsForExam($exam, $piarFilters)->count();
        $nonPiarCount = $this->getEnrollmentsForExam($exam, $nonPiarFilters)->count();

        return [
            'piar' => $piarStats,
            'non_piar' => $nonPiarStats,
            'piar_count' => $piarCount,
            'non_piar_count' => $nonPiarCount,
        ];
    }

    /**
     * Obtiene promedios por área para comparación.
     */
    private function getAreaAveragesForComparison(Exam $exam, array $filters): array
    {
        $enrollments = $this->getEnrollmentsForExam($exam, $filters);

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
        }

        $result = [];
        foreach ($areaScores as $area => $values) {
            $result[$area] = (object) [
                'average' => ! empty($values) ? array_sum($values) / count($values) : 0,
            ];
        }

        return $result;
    }

    /**
     * Obtiene matrículas para un examen con filtros opcionales.
     * Solo incluye estudiantes que tienen respuestas en el examen.
     */
    private function getEnrollmentsForExam(Exam $exam, array $filters): Collection
    {
        $sessionIds = \App\Models\ExamSession::where('exam_id', $exam->id)->pluck('id');

        $query = Enrollment::query()
            ->where('academic_year_id', $exam->academic_year_id)
            ->where('status', 'ACTIVE')
            ->whereHas('studentAnswers.question', function ($query) use ($sessionIds) {
                $query->whereIn('exam_session_id', $sessionIds);
            });

        if (! empty($filters['group'])) {
            $query->where('group', $filters['group']);
        }

        if (! empty($filters['piar_only'])) {
            $query->where('is_piar', true);
        }

        if (! empty($filters['exclude_piar'])) {
            $query->where('is_piar', false);
        }

        return $query->get();
    }

    /**
     * Calcula la desviación estándar.
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
