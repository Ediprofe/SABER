<?php

namespace App\Services;

use App\DTOs\AreaStatistics;
use App\DTOs\ExamStatistics;
use App\Models\Exam;
use Illuminate\Database\Eloquent\Collection;

class MetricsService
{
    /**
     * Calculate global score from individual area scores.
     */
    public function calculateGlobalScore(array $results): int
    {
        $lectura = $results['lectura'] ?? 0;
        $matematicas = $results['matematicas'] ?? 0;
        $sociales = $results['sociales'] ?? 0;
        $naturales = $results['naturales'] ?? 0;
        $ingles = $results['ingles'] ?? 0;

        return (int) round((($lectura + $matematicas + $sociales + $naturales) * 3 + $ingles) / 13 * 5);
    }

    /**
     * Get comprehensive statistics for an exam.
     */
    public function getExamStatistics(Exam $exam, ?array $filters = null): ExamStatistics
    {
        $query = $exam->examResults()
            ->with(['enrollment.student', 'enrollment']);

        if ($filters) {
            if (isset($filters['grade'])) {
                $query->whereHas('enrollment', function ($q) use ($filters) {
                    $q->where('grade', $filters['grade']);
                });
            }
            if (isset($filters['group'])) {
                $query->whereHas('enrollment', function ($q) use ($filters) {
                    $q->where('group', $filters['group']);
                });
            }
        }

        $results = $query->get();

        $totalStudents = $results->count();
        $piarCount = $results->filter(fn ($r) => $r->enrollment->is_piar)->count();
        $nonPiarCount = $totalStudents - $piarCount;

        // Global statistics
        $globalScores = $results->pluck('global_score')->filter();
        $globalAverage = $globalScores->avg() ?? 0;
        $globalStdDev = $this->calculateStdDev($globalScores);

        // Area statistics
        $areas = ['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'];
        $areaStatistics = [];

        foreach ($areas as $area) {
            $areaStatistics[] = $this->calculateAreaStatistics($results, $area);
        }

        return new ExamStatistics(
            totalStudents: $totalStudents,
            piarCount: $piarCount,
            nonPiarCount: $nonPiarCount,
            globalAverage: $globalAverage,
            globalStdDev: $globalStdDev,
            areaStatistics: $areaStatistics,
        );
    }

    /**
     * Get statistics for a specific area.
     */
    public function getAreaStatistics(Exam $exam, string $area, ?array $filters = null): AreaStatistics
    {
        $query = $exam->examResults()->with('enrollment');

        if ($filters) {
            if (isset($filters['grade'])) {
                $query->whereHas('enrollment', function ($q) use ($filters) {
                    $q->where('grade', $filters['grade']);
                });
            }
            if (isset($filters['group'])) {
                $query->whereHas('enrollment', function ($q) use ($filters) {
                    $q->where('group', $filters['group']);
                });
            }
        }

        $results = $query->get();

        return $this->calculateAreaStatistics($results, $area);
    }

    /**
     * Get top performers for a specific area.
     */
    public function getTopPerformers(Exam $exam, string $area, int $limit = 5, ?array $filters = null): Collection
    {
        $query = $exam->examResults()
            ->with(['enrollment.student'])
            ->whereNotNull($area)
            ->orderBy($area, 'desc')
            ->limit($limit);

        if ($filters) {
            if (isset($filters['grade'])) {
                $query->whereHas('enrollment', function ($q) use ($filters) {
                    $q->where('grade', $filters['grade']);
                });
            }
            if (isset($filters['group'])) {
                $query->whereHas('enrollment', function ($q) use ($filters) {
                    $q->where('group', $filters['group']);
                });
            }
        }

        return $query->get();
    }

    /**
     * Get comparison statistics by group.
     */
    public function getGroupComparison(Exam $exam, ?array $filters = null): array
    {
        $query = $exam->examResults()->with('enrollment');

        // Apply filters
        if ($filters) {
            if (isset($filters['grade'])) {
                $query->whereHas('enrollment', function ($q) use ($filters) {
                    $q->where('grade', $filters['grade']);
                });
            }
            if (isset($filters['group'])) {
                $query->whereHas('enrollment', function ($q) use ($filters) {
                    $q->where('group', $filters['group']);
                });
            }
        }

        $results = $query->get()->groupBy(fn ($r) => $r->enrollment->group);

        $comparison = [];
        $areas = ['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'];

        foreach ($results as $group => $groupResults) {
            $groupStats = [
                'group' => $group,
                'count' => $groupResults->count(),
            ];

            foreach ($areas as $area) {
                $groupStats[$area] = $this->calculateAreaStatistics($groupResults, $area);
            }

            $comparison[] = $groupStats;
        }

        return $comparison;
    }

    /**
     * Get PIAR comparison statistics.
     */
    public function getPiarComparison(Exam $exam, ?array $filters = null): array
    {
        $query = $exam->examResults()->with('enrollment');

        if ($filters) {
            if (isset($filters['grade'])) {
                $query->whereHas('enrollment', function ($q) use ($filters) {
                    $q->where('grade', $filters['grade']);
                });
            }
        }

        $results = $query->get();

        $piarResults = $results->filter(fn ($r) => $r->enrollment->is_piar);
        $nonPiarResults = $results->reject(fn ($r) => $r->enrollment->is_piar);

        $areas = ['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'];
        $piarStats = [];
        $nonPiarStats = [];

        foreach ($areas as $area) {
            $piarStats[$area] = $this->calculateAreaStatistics($piarResults, $area);
            $nonPiarStats[$area] = $this->calculateAreaStatistics($nonPiarResults, $area);
        }

        return [
            'piar' => $piarStats,
            'non_piar' => $nonPiarStats,
            'piar_count' => $piarResults->count(),
            'non_piar_count' => $nonPiarResults->count(),
        ];
    }

    /**
     * Get distribution of scores for a specific area.
     */
    public function getDistribution(Exam $exam, string $area, int $bins = 10, ?array $filters = null): array
    {
        $query = $exam->examResults()->whereNotNull($area);

        if ($filters) {
            if (isset($filters['grade'])) {
                $query->whereHas('enrollment', function ($q) use ($filters) {
                    $q->where('grade', $filters['grade']);
                });
            }
            if (isset($filters['group'])) {
                $query->whereHas('enrollment', function ($q) use ($filters) {
                    $q->where('group', $filters['group']);
                });
            }
        }

        $scores = $query->pluck($area);

        $min = 0;
        // Global score has range 0-500, individual areas have 0-100
        $max = ($area === 'global_score') ? 500 : 100;
        $binSize = ($max - $min) / $bins;

        $distribution = array_fill(0, $bins, 0);

        foreach ($scores as $score) {
            $bin = (int) floor(($score - $min) / $binSize);
            if ($bin >= $bins) {
                $bin = $bins - 1;
            }
            $distribution[$bin]++;
        }

        $labels = [];
        for ($i = 0; $i < $bins; $i++) {
            $start = $min + ($i * $binSize);
            $end = $start + $binSize;
            $labels[] = sprintf('%d-%d', $start, $end);
        }

        return [
            'labels' => $labels,
            'data' => $distribution,
        ];
    }

    /**
     * Calculate statistics for a specific area from a collection of results.
     *
     * For PIAR students, if inglés is NULL, it is excluded from calculations.
     */
    private function calculateAreaStatistics(Collection $results, string $area): AreaStatistics
    {
        $values = $results->filter(function ($result) use ($area) {
            // For PIAR students with NULL inglés, exclude from calculation
            if ($area === 'ingles' && $result->enrollment->is_piar && $result->ingles === null) {
                return false;
            }

            return $result->{$area} !== null;
        })->map(fn ($r) => $r->{$area});

        $count = $values->count();

        if ($count === 0) {
            return new AreaStatistics(
                area: $area,
                average: 0,
                stdDev: 0,
                min: 0,
                max: 0,
                count: 0,
            );
        }

        return new AreaStatistics(
            area: $area,
            average: $values->avg(),
            stdDev: $this->calculateStdDev($values),
            min: $values->min(),
            max: $values->max(),
            count: $count,
        );
    }

    /**
     * Calculate standard deviation.
     */
    private function calculateStdDev($values): float
    {
        $count = $values->count();

        if ($count < 2) {
            return 0;
        }

        $mean = $values->avg();
        $variance = $values->map(fn ($v) => pow($v - $mean, 2))->avg();

        return sqrt($variance);
    }
}
