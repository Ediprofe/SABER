<?php

namespace App\Exports;

use App\Exports\Sheets\AreaAnalysisSheet;
use App\Exports\Sheets\AnonymizedResultsSheet;
use App\Exports\Sheets\CompleteResultsSheet;
use App\Exports\Sheets\PlanillaSheet;
use App\Exports\Sheets\QuestionAnalysisSheet;
use App\Exports\Sheets\StatisticsSheet;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ZipgradeResultsExport implements WithMultipleSheets
{
    public function __construct(
        private Exam $exam,
        private ?string $groupFilter = null,
        private ?bool $piarFilter = null,
    ) {}

    public function sheets(): array
    {
        $sheets = [
            'Resultados Completos' => new CompleteResultsSheet($this->exam, $this->groupFilter, $this->piarFilter),
            'Formato Planilla' => new PlanillaSheet($this->exam, $this->groupFilter, $this->piarFilter),
            'Resultados Anonimizados' => new AnonymizedResultsSheet($this->exam, $this->groupFilter, $this->piarFilter),
        ];

        if ($this->hasQuestionStats()) {
            $sheets['Análisis por Pregunta'] = new QuestionAnalysisSheet($this->exam);
            $sheets['Promedios y desviaciones'] = new StatisticsSheet($this->exam);
            $sheets['Lectura Crítica'] = new AreaAnalysisSheet($this->exam, 'lectura', 'Lectura Crítica');
            $sheets['Matemáticas'] = new AreaAnalysisSheet($this->exam, 'matematicas', 'Matemáticas');
            $sheets['Ciencias Sociales'] = new AreaAnalysisSheet($this->exam, 'sociales', 'Ciencias Sociales');
            $sheets['Ciencias Naturales'] = new AreaAnalysisSheet($this->exam, 'naturales', 'Ciencias Naturales');
            $sheets['Inglés'] = new AreaAnalysisSheet($this->exam, 'ingles', 'Inglés');
        }

        return $sheets;
    }

    /**
     * Verifica si el examen tiene estadísticas de preguntas importadas.
     */
    private function hasQuestionStats(): bool
    {
        $sessionIds = ExamSession::where('exam_id', $this->exam->id)->pluck('id');

        return ExamQuestion::whereIn('exam_session_id', $sessionIds)
            ->whereNotNull('correct_answer')
            ->exists();
    }
}
