<?php

namespace App\Services;

use App\Models\Exam;
use Illuminate\Support\Facades\View;

class ReportGenerator
{
    public function __construct(
        private MetricsService $metricsService,
    ) {}

    public function generateHtmlReport(Exam $exam, ?int $grade = null, ?string $group = null): string
    {
        $filters = [];
        if ($grade) {
            $filters['grade'] = $grade;
        }
        if ($group) {
            $filters['group'] = $group;
        }

        // Gather all necessary data
        $statistics = $this->metricsService->getExamStatistics($exam, $filters);

        // Transform group comparison from array to object with group names as keys
        // And flatten area statistics to just the average values
        $groupComparisonArray = $this->metricsService->getGroupComparison($exam, $filters);
        $groupComparison = [];
        foreach ($groupComparisonArray as $groupData) {
            $groupName = $groupData['group'];
            $flattenedData = [
                'group' => $groupName,
                'count' => $groupData['count'],
            ];

            // Flatten area statistics to simple values for JS consumption
            foreach (['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'] as $area) {
                if (isset($groupData[$area]) && is_object($groupData[$area])) {
                    $flattenedData[$area] = $groupData[$area]->average;
                } else {
                    $flattenedData[$area] = 0;
                }
            }

            $groupComparison[$groupName] = $flattenedData;
        }

        $piarComparison = $this->metricsService->getPiarComparison($exam, $filters);

        // Get top performers for each area
        $areas = ['lectura', 'matematicas', 'sociales', 'naturales', 'ingles', 'global_score'];
        $topPerformers = [];
        foreach ($areas as $area) {
            $topPerformers[$area] = $this->metricsService->getTopPerformers($exam, $area, 5, $filters);
        }

        // Get distributions
        $distributions = [];
        foreach (['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'] as $area) {
            $distributions[$area] = $this->metricsService->getDistribution($exam, $area, 10, $filters);
        }

        // Add global distribution
        $distributions['global'] = $this->metricsService->getDistribution($exam, 'global_score', 10, $filters);

        // Get all results with student info
        $results = $exam->examResults()
            ->with(['enrollment.student', 'enrollment'])
            ->when($grade, fn ($q) => $q->whereHas('enrollment', fn ($sq) => $sq->where('grade', $grade)))
            ->when($group, fn ($q) => $q->whereHas('enrollment', fn ($sq) => $sq->where('group', $group)))
            ->get();

        // Prepare data for the view
        $reportData = [
            'exam' => $exam,
            'filters' => $filters,
            'statistics' => $statistics,
            'groupComparison' => $groupComparison,
            'piarComparison' => $piarComparison,
            'topPerformers' => $topPerformers,
            'distributions' => $distributions,
            'results' => $results,
            'generatedAt' => now()->format('Y-m-d H:i:s'),
        ];

        // Add detailed analysis data for all areas
        $detailAnalysis = [];
        $areas = ['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'];
        $areaLabels = [
            'lectura' => 'Lectura Crítica',
            'matematicas' => 'Matemáticas',
            'sociales' => 'Ciencias Sociales',
            'naturales' => 'Ciencias Naturales',
            'ingles' => 'Inglés',
        ];

        foreach ($areas as $area) {
            if ($exam->hasDetailConfig($area)) {
                $detailStatistics = $this->metricsService->getDetailStatistics($exam, $area, $filters);
                $detailPiarComparison = $this->metricsService->getDetailPiarComparison($exam, $area, $filters);
                $detailGroupComparison = $this->metricsService->getDetailGroupComparison($exam, $area, $filters);

                $detailAnalysis[$area] = [
                    'hasConfig' => true,
                    'config' => $exam->getDetailConfig($area),
                    'statistics' => $detailStatistics,
                    'piarComparison' => $detailPiarComparison,
                    'groupComparison' => $detailGroupComparison,
                ];
            } else {
                $detailAnalysis[$area] = [
                    'hasConfig' => false,
                    'area_label' => $areaLabels[$area] ?? ucfirst($area),
                ];
            }
        }

        $reportData['detailAnalysis'] = $detailAnalysis;

        // Render the view
        $html = View::make('reports.exam', $reportData)->render();

        return $html;
    }

    public function getReportFilename(Exam $exam, ?int $grade = null, ?string $group = null): string
    {
        $parts = ['informe', $exam->name];

        if ($grade) {
            $parts[] = "grado{$grade}";
        }
        if ($group) {
            $parts[] = "grupo{$group}";
        }

        $parts[] = now()->format('Ymd_His');

        return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '_', implode('_', $parts))).'.html';
    }
}
