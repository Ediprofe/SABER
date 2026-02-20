<?php

namespace App\Console\Commands;

use App\Exports\ZipgradeResultsExport;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\QuestionTag;
use App\Models\StudentAnswer;
use App\Services\ZipgradeMetricsService;
use App\Services\ZipgradePdfService;
use App\Services\ZipgradePipelineStatusService;
use App\Services\ZipgradeReportGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;

class VerifyZipgradeExam extends Command
{
    protected $signature = 'zipgrade:verify-exam
                            {exam_id : ID del examen a verificar}
                            {--bench : Ejecuta benchmarks de HTML/PDF/XLSX}
                            {--max-metrics-ms=500 : Umbral de tiempo para métricas}
                            {--max-html-ms=3000 : Umbral de tiempo para HTML}
                            {--max-pdf-ms=3000 : Umbral de tiempo para PDF}
                            {--max-xlsx-ms=10000 : Umbral de tiempo para Excel}
                            {--output= : Ruta opcional para guardar reporte JSON}';

    protected $description = 'Verifica integridad, persistencia y rendimiento de un examen Zipgrade';

    public function handle(
        ZipgradePipelineStatusService $pipelineStatusService,
        ZipgradeMetricsService $metricsService,
        ZipgradeReportGenerator $reportGenerator,
        ZipgradePdfService $pdfService
    ): int {
        $exam = Exam::query()->find($this->argument('exam_id'));
        if (! $exam) {
            $this->error('Examen no encontrado.');

            return self::FAILURE;
        }

        $checks = [];
        $benchmarks = [];

        $checks[] = $this->checkSessions($exam);
        $checks[] = $this->checkPipelineReady($exam, $pipelineStatusService);
        $checks[] = $this->checkQuestionCounters($exam);
        $checks[] = $this->checkDataConsistency($exam);
        $checks[] = $this->checkPersistence($exam, $metricsService);

        if ((bool) $this->option('bench')) {
            $benchmarks = $this->runBenchmarks($exam, $metricsService, $reportGenerator, $pdfService);
            $checks = array_merge($checks, $this->evaluateBenchmarks($benchmarks));
        }

        $this->renderChecksTable($checks);
        $this->renderSessionTable($exam);

        if ($benchmarks !== []) {
            $this->renderBenchmarksTable($benchmarks);
        }

        $reportPath = (string) ($this->option('output') ?? '');
        if ($reportPath !== '') {
            $report = [
                'timestamp' => now()->toIso8601String(),
                'exam' => [
                    'id' => $exam->id,
                    'name' => $exam->name,
                ],
                'checks' => $checks,
                'benchmarks' => $benchmarks,
            ];

            File::ensureDirectoryExists(dirname($reportPath));
            File::put($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Reporte guardado en {$reportPath}");
        }

        $hasFailure = collect($checks)->contains(fn (array $check): bool => $check['status'] === 'FAIL');

        if ($hasFailure) {
            $this->error('Verificación completada con FALLAS.');

            return self::FAILURE;
        }

        $hasWarnings = collect($checks)->contains(fn (array $check): bool => $check['status'] === 'WARN');
        if ($hasWarnings) {
            $this->warn('Verificación completada con advertencias.');
        } else {
            $this->info('Verificación completada: todo OK.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{status:string,check:string,details:string}
     */
    private function checkSessions(Exam $exam): array
    {
        $count = $exam->sessions()->count();
        if ($count < 2) {
            return [
                'status' => 'FAIL',
                'check' => 'Sesiones mínimas',
                'details' => "El examen tiene {$count} sesión(es); se esperaban al menos 2.",
            ];
        }

        return [
            'status' => 'OK',
            'check' => 'Sesiones mínimas',
            'details' => "Sesiones detectadas: {$count}.",
        ];
    }

    /**
     * @return array{status:string,check:string,details:string}
     */
    private function checkPipelineReady(Exam $exam, ZipgradePipelineStatusService $service): array
    {
        $pipeline = $service->getPipelineStatus($exam);

        if (! $pipeline['ready']) {
            return [
                'status' => 'WARN',
                'check' => 'Pipeline ready',
                'details' => 'No está listo (faltan tags y/o stats en alguna sesión).',
            ];
        }

        return [
            'status' => 'OK',
            'check' => 'Pipeline ready',
            'details' => 'Tags y stats completos en ambas sesiones.',
        ];
    }

    /**
     * @return array{status:string,check:string,details:string}
     */
    private function checkQuestionCounters(Exam $exam): array
    {
        $mismatches = [];

        foreach ($exam->sessions as $session) {
            $actual = (int) $session->questions()->count();
            $stored = (int) $session->total_questions;
            if ($actual !== $stored) {
                $mismatches[] = "S{$session->session_number}: stored={$stored}, actual={$actual}";
            }
        }

        if ($mismatches !== []) {
            return [
                'status' => 'WARN',
                'check' => 'Contadores de preguntas',
                'details' => 'Diferencias detectadas: '.implode(' | ', $mismatches),
            ];
        }

        return [
            'status' => 'OK',
            'check' => 'Contadores de preguntas',
            'details' => 'Los contadores por sesión coinciden con el número real de preguntas.',
        ];
    }

    /**
     * @return array{status:string,check:string,details:string}
     */
    private function checkDataConsistency(Exam $exam): array
    {
        $sessionIds = $exam->sessions()->pluck('id');
        $questionIds = ExamQuestion::query()->whereIn('exam_session_id', $sessionIds)->pluck('id');

        if ($questionIds->isEmpty()) {
            return [
                'status' => 'FAIL',
                'check' => 'Consistencia de datos',
                'details' => 'No hay preguntas asociadas al examen.',
            ];
        }

        $duplicateAnswers = StudentAnswer::query()
            ->whereIn('exam_question_id', $questionIds)
            ->select('exam_question_id', 'enrollment_id', DB::raw('COUNT(*) as c'))
            ->groupBy('exam_question_id', 'enrollment_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $duplicateQuestionTags = QuestionTag::query()
            ->whereIn('exam_question_id', $questionIds)
            ->select('exam_question_id', 'tag_hierarchy_id', DB::raw('COUNT(*) as c'))
            ->groupBy('exam_question_id', 'tag_hierarchy_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicateAnswers > 0 || $duplicateQuestionTags > 0) {
            return [
                'status' => 'FAIL',
                'check' => 'Consistencia de datos',
                'details' => "Duplicados detectados: answers={$duplicateAnswers}, question_tags={$duplicateQuestionTags}",
            ];
        }

        return [
            'status' => 'OK',
            'check' => 'Consistencia de datos',
            'details' => 'No se detectaron duplicados en respuestas ni tags por pregunta.',
        ];
    }

    /**
     * @return array{status:string,check:string,details:string}
     */
    private function checkPersistence(Exam $exam, ZipgradeMetricsService $metricsService): array
    {
        $enrollment = Enrollment::query()
            ->where('academic_year_id', $exam->academic_year_id)
            ->where('status', 'ACTIVE')
            ->whereHas('studentAnswers.question.session', fn ($query) => $query->where('exam_id', $exam->id))
            ->first();

        if (! $enrollment) {
            return [
                'status' => 'WARN',
                'check' => 'Persistencia de cálculo',
                'details' => 'No hay matrícula activa con respuestas para validar estabilidad de cálculo.',
            ];
        }

        $first = $metricsService->getStudentGlobalScore($enrollment, $exam);
        $second = $metricsService->getStudentGlobalScore($enrollment, $exam);

        if ($first !== $second) {
            return [
                'status' => 'FAIL',
                'check' => 'Persistencia de cálculo',
                'details' => "El cálculo global no fue estable: first={$first}, second={$second}.",
            ];
        }

        return [
            'status' => 'OK',
            'check' => 'Persistencia de cálculo',
            'details' => "Cálculo estable para enrollment_id={$enrollment->id}, global={$first}.",
        ];
    }

    /**
     * @return array<string,float|int>
     */
    private function runBenchmarks(
        Exam $exam,
        ZipgradeMetricsService $metricsService,
        ZipgradeReportGenerator $reportGenerator,
        ZipgradePdfService $pdfService
    ): array {
        $bench = [];

        $start = microtime(true);
        $stats = $metricsService->getExamStatistics($exam);
        $bench['metrics_ms'] = round((microtime(true) - $start) * 1000, 2);
        $bench['students'] = (int) ($stats['total_students'] ?? 0);

        $start = microtime(true);
        $html = $reportGenerator->generateHtmlReport($exam, null);
        $bench['html_ms'] = round((microtime(true) - $start) * 1000, 2);
        $bench['html_bytes'] = strlen($html);

        $start = microtime(true);
        $pdf = $pdfService->generate($exam, null, null);
        $bench['pdf_ms'] = round((microtime(true) - $start) * 1000, 2);
        $bench['pdf_bytes'] = strlen($pdf);

        $start = microtime(true);
        $xlsx = ExcelFacade::raw(new ZipgradeResultsExport($exam, null, null), ExcelWriter::XLSX);
        $bench['xlsx_ms'] = round((microtime(true) - $start) * 1000, 2);
        $bench['xlsx_bytes'] = strlen($xlsx);

        return $bench;
    }

    /**
     * @param  array<string,float|int>  $benchmarks
     * @return list<array{status:string,check:string,details:string}>
     */
    private function evaluateBenchmarks(array $benchmarks): array
    {
        $checks = [];

        $thresholds = [
            'metrics_ms' => (float) $this->option('max-metrics-ms'),
            'html_ms' => (float) $this->option('max-html-ms'),
            'pdf_ms' => (float) $this->option('max-pdf-ms'),
            'xlsx_ms' => (float) $this->option('max-xlsx-ms'),
        ];

        foreach ($thresholds as $metric => $threshold) {
            $value = (float) ($benchmarks[$metric] ?? 0);

            if ($value > $threshold) {
                $checks[] = [
                    'status' => 'WARN',
                    'check' => "Rendimiento {$metric}",
                    'details' => "Tiempo {$value}ms supera umbral {$threshold}ms.",
                ];
                continue;
            }

            $checks[] = [
                'status' => 'OK',
                'check' => "Rendimiento {$metric}",
                'details' => "Tiempo {$value}ms dentro del umbral {$threshold}ms.",
            ];
        }

        return $checks;
    }

    /**
     * @param  list<array{status:string,check:string,details:string}>  $checks
     */
    private function renderChecksTable(array $checks): void
    {
        $rows = array_map(
            fn (array $check): array => [$check['status'], $check['check'], $check['details']],
            $checks
        );

        $this->table(['Estado', 'Chequeo', 'Detalle'], $rows);
    }

    private function renderSessionTable(Exam $exam): void
    {
        $rows = [];

        foreach ($exam->sessions()->orderBy('session_number')->get() as $session) {
            $questionCount = ExamQuestion::query()
                ->where('exam_session_id', $session->id)
                ->count();

            $questionWithTags = ExamQuestion::query()
                ->where('exam_session_id', $session->id)
                ->whereHas('questionTags')
                ->count();

            $questionWithStats = ExamQuestion::query()
                ->where('exam_session_id', $session->id)
                ->whereNotNull('correct_answer')
                ->count();

            $rows[] = [
                $session->session_number,
                $questionCount,
                $questionWithTags,
                $questionWithStats,
                $session->imports()->where('status', 'completed')->count(),
                $session->imports()->where('status', 'error')->count(),
            ];
        }

        $this->table(
            ['Sesión', 'Preguntas', 'Con tags', 'Con stats', 'Imports OK', 'Imports error'],
            $rows
        );
    }

    /**
     * @param  array<string,float|int>  $benchmarks
     */
    private function renderBenchmarksTable(array $benchmarks): void
    {
        $rows = [
            ['metrics_ms', (string) $benchmarks['metrics_ms']],
            ['html_ms', (string) $benchmarks['html_ms']],
            ['pdf_ms', (string) $benchmarks['pdf_ms']],
            ['xlsx_ms', (string) $benchmarks['xlsx_ms']],
            ['students', (string) $benchmarks['students']],
            ['html_bytes', (string) $benchmarks['html_bytes']],
            ['pdf_bytes', (string) $benchmarks['pdf_bytes']],
            ['xlsx_bytes', (string) $benchmarks['xlsx_bytes']],
        ];

        $this->table(['Métrica', 'Valor'], $rows);
    }
}

