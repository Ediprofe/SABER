<?php

namespace App\Services;

use App\DTOs\AreaStatistics;
use App\DTOs\DetailAreaStatistics;
use App\DTOs\DetailItemStatistics;
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

    /**
     * Verifica si un examen tiene configuración de análisis detallado.
     */
    public function hasDetailConfig(Exam $exam, ?string $area = null): bool
    {
        return $exam->hasDetailConfig($area);
    }

    /**
     * Obtiene la configuración de análisis detallado de un examen.
     */
    public function getDetailConfig(Exam $exam): Collection
    {
        return $exam->areaConfigs()->with('items')->get();
    }

    /**
     * Obtiene estadísticas detalladas por item de un área.
     */
    public function getDetailStatistics(
        Exam $exam,
        string $area,
        ?array $filters = null
    ): ?DetailAreaStatistics {
        $config = $exam->getDetailConfig($area);

        if (! $config) {
            return null;
        }

        $items = $config->items;
        $dimension1Items = $config->itemsDimension1;
        $dimension2Items = $config->hasDimension2() ? $config->itemsDimension2 : collect();

        // Get exam results query with filters
        $resultsQuery = $exam->examResults()
            ->with(['detailResults', 'enrollment']);

        if ($filters) {
            if (isset($filters['grade'])) {
                $resultsQuery->whereHas('enrollment', function ($q) use ($filters) {
                    $q->where('grade', $filters['grade']);
                });
            }
            if (isset($filters['group'])) {
                $resultsQuery->whereHas('enrollment', function ($q) use ($filters) {
                    $q->where('group', $filters['group']);
                });
            }
            if (isset($filters['include_piar']) && ! $filters['include_piar']) {
                $resultsQuery->whereHas('enrollment', function ($q) {
                    $q->where('is_piar', false);
                });
            }
        }

        $examResults = $resultsQuery->get();

        // Calculate statistics for dimension 1
        $dimension1Stats = [];
        foreach ($dimension1Items as $item) {
            $scores = $examResults
                ->flatMap(fn ($result) => $result->detailResults->where('exam_area_item_id', $item->id))
                ->map(fn ($dr) => $dr->score)
                ->filter(fn ($score) => $score !== null);

            $dimension1Stats[] = $this->createDetailItemStatistics(
                $area,
                1,
                $config->dimension1_name,
                $item->name,
                $scores
            );
        }

        // Calculate statistics for dimension 2 (if exists)
        $dimension2Stats = null;
        if ($config->hasDimension2()) {
            $dimension2Stats = [];
            foreach ($dimension2Items as $item) {
                $scores = $examResults
                    ->flatMap(fn ($result) => $result->detailResults->where('exam_area_item_id', $item->id))
                    ->map(fn ($dr) => $dr->score)
                    ->filter(fn ($score) => $score !== null);

                $dimension2Stats[] = $this->createDetailItemStatistics(
                    $area,
                    2,
                    $config->dimension2_name,
                    $item->name,
                    $scores
                );
            }
        }

        return new DetailAreaStatistics(
            area: $area,
            areaLabel: $config->area_label,
            dimension1: $dimension1Stats,
            dimension2: $dimension2Stats,
        );
    }

    /**
     * Create DetailItemStatistics from a collection of scores.
     */
    private function createDetailItemStatistics(
        string $area,
        int $dimension,
        string $dimensionName,
        string $itemName,
        $scores
    ): DetailItemStatistics {
        $count = $scores->count();

        if ($count === 0) {
            return new DetailItemStatistics(
                area: $area,
                dimension: $dimension,
                dimensionName: $dimensionName,
                itemName: $itemName,
                average: 0,
                stdDev: 0,
                min: 0,
                max: 0,
                count: 0,
            );
        }

        return new DetailItemStatistics(
            area: $area,
            dimension: $dimension,
            dimensionName: $dimensionName,
            itemName: $itemName,
            average: $scores->avg(),
            stdDev: $this->calculateStdDev($scores),
            min: $scores->min(),
            max: $scores->max(),
            count: $count,
        );
    }

    /**
     * Comparativo PIAR vs No-PIAR por items detallados.
     */
    public function getDetailPiarComparison(
        Exam $exam,
        string $area,
        ?array $filters = null
    ): ?array {
        $config = $exam->getDetailConfig($area);

        if (! $config) {
            return null;
        }

        $items = $config->items;

        // Get PIAR and non-PIAR results
        $resultsQuery = $exam->examResults()
            ->with(['detailResults', 'enrollment']);

        if ($filters) {
            if (isset($filters['grade'])) {
                $resultsQuery->whereHas('enrollment', function ($q) use ($filters) {
                    $q->where('grade', $filters['grade']);
                });
            }
            if (isset($filters['group'])) {
                $resultsQuery->whereHas('enrollment', function ($q) use ($filters) {
                    $q->where('group', $filters['group']);
                });
            }
        }

        $allResults = $resultsQuery->get();
        $piarResults = $allResults->filter(fn ($r) => $r->enrollment->is_piar);
        $nonPiarResults = $allResults->reject(fn ($r) => $r->enrollment->is_piar);

        $comparison = [];

        foreach ($items as $item) {
            $piarScores = $piarResults
                ->flatMap(fn ($result) => $result->detailResults->where('exam_area_item_id', $item->id))
                ->map(fn ($dr) => $dr->score)
                ->filter(fn ($score) => $score !== null);

            $nonPiarScores = $nonPiarResults
                ->flatMap(fn ($result) => $result->detailResults->where('exam_area_item_id', $item->id))
                ->map(fn ($dr) => $dr->score)
                ->filter(fn ($score) => $score !== null);

            $comparison[] = [
                'name' => $item->name,
                'dimension' => $item->dimension,
                'dimension_name' => $item->dimension === 1 ? $config->dimension1_name : $config->dimension2_name,
                'piar_average' => $piarScores->avg() ?? 0,
                'piar_count' => $piarScores->count(),
                'non_piar_average' => $nonPiarScores->avg() ?? 0,
                'non_piar_count' => $nonPiarScores->count(),
                'difference' => ($nonPiarScores->avg() ?? 0) - ($piarScores->avg() ?? 0),
            ];
        }

        return [
            'items' => $comparison,
            'piar_count' => $piarResults->count(),
            'non_piar_count' => $nonPiarResults->count(),
        ];
    }

    /**
     * Desglose por grupo para items detallados.
     */
    public function getDetailGroupComparison(
        Exam $exam,
        string $area,
        ?array $filters = null
    ): ?array {
        $config = $exam->getDetailConfig($area);

        if (! $config) {
            return null;
        }

        $items = $config->items;

        // Get results grouped by group
        $resultsQuery = $exam->examResults()
            ->with(['detailResults', 'enrollment']);

        if ($filters) {
            if (isset($filters['grade'])) {
                $resultsQuery->whereHas('enrollment', function ($q) use ($filters) {
                    $q->where('grade', $filters['grade']);
                });
            }
        }

        $results = $resultsQuery->get()->groupBy(fn ($r) => $r->enrollment->group);

        $groupComparison = [];

        foreach ($results as $group => $groupResults) {
            $groupData = [
                'group' => $group,
                'count' => $groupResults->count(),
                'items' => [],
            ];

            foreach ($items as $item) {
                $scores = $groupResults
                    ->flatMap(fn ($result) => $result->detailResults->where('exam_area_item_id', $item->id))
                    ->map(fn ($dr) => $dr->score)
                    ->filter(fn ($score) => $score !== null);

                $groupData['items'][$item->name] = [
                    'name' => $item->name,
                    'dimension' => $item->dimension,
                    'dimension_name' => $item->dimension === 1 ? $config->dimension1_name : $config->dimension2_name,
                    'average' => $scores->avg() ?? 0,
                    'count' => $scores->count(),
                ];
            }

            $groupComparison[$group] = $groupData;
        }

        return $groupComparison;
    }
}
