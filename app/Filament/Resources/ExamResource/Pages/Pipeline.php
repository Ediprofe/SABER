<?php

namespace App\Filament\Resources\ExamResource\Pages;

use App\Exports\ZipgradeResultsExport;
use App\Filament\Resources\ExamResource;
use App\Jobs\GenerateStudentReportsJob;
use App\Jobs\SendStudentReportsEmailJob;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\ReportGeneration;
use App\Services\ZipgradeMetricsService;
use App\Services\ZipgradePipelineStatusService;
use App\Services\ZipgradePdfService;
use App\Services\ZipgradeReportGenerator;
use App\Support\AreaConfig;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Maatwebsite\Excel\Facades\Excel;

class Pipeline extends Page
{
    protected static string $resource = ExamResource::class;

    protected static string $view = 'filament.resources.exam-resource.pages.pipeline';

    public Exam $record;

    public function mount(Exam $record): void
    {
        $this->record = $record;
    }

    public function getHeading(): string
    {
        return "Pipeline de Carga - {$this->record->name}";
    }

    public function getSubheading(): ?string
    {
        return 'Carga por sesión: primero Blueprint + Respuestas, luego revisa resultados y exportaciones.';
    }

    /**
     * @return array{session:int, has_data:bool, has_completed_import:bool, has_tagged_questions:bool, has_stats:bool, total_questions:int}
     */
    public function getSessionStatus(int $sessionNumber): array
    {
        return app(ZipgradePipelineStatusService::class)
            ->getSessionStatus($this->record, $sessionNumber);
    }

    /**
     * @return array{ready:bool, tags_done:bool, stats_done:bool}
     */
    public function getPipelineStatus(): array
    {
        return app(ZipgradePipelineStatusService::class)
            ->getPipelineStatus($this->record);
    }

    /**
     * @return array<int,array{
     *   area:string,
     *   total:int,
     *   dim1_name:string,
     *   dim1_with:int,
     *   dim1_missing:int,
     *   dim2_name:?string,
     *   dim2_with:?int,
     *   dim2_missing:?int,
     *   dim3_name:?string,
     *   dim3_with:?int,
     *   dim3_missing:?int
     * }>
     */
    public function getDimensionCoverageSummary(): array
    {
        $sessionIds = ExamSession::query()
            ->where('exam_id', $this->record->id)
            ->pluck('id');

        if ($sessionIds->isEmpty()) {
            return [];
        }

        $questions = ExamQuestion::query()
            ->whereIn('exam_session_id', $sessionIds)
            ->with('tags')
            ->get();

        $summary = [];

        foreach ($questions as $question) {
            $areaKey = $this->resolveAreaKeyFromQuestion($question);
            if ($areaKey === null) {
                continue;
            }

            if (! isset($summary[$areaKey])) {
                $summary[$areaKey] = [
                    'area' => AreaConfig::getLabel($areaKey),
                    'total' => 0,
                    'dim1_name' => $areaKey === 'ingles' ? 'Parte' : 'Competencia',
                    'dim1_with' => 0,
                    'dim2_name' => $areaKey === 'ingles' ? 'Competencia' : ($areaKey === 'lectura' ? 'Tipo de Texto' : 'Componente'),
                    'dim2_with' => 0,
                    'dim3_name' => $areaKey === 'lectura' ? 'Nivel de Lectura' : null,
                    'dim3_with' => $areaKey === 'lectura' ? 0 : null,
                ];
            }

            $summary[$areaKey]['total']++;

            $hasCompetencia = $question->tags->contains(fn ($tag): bool => $tag->tag_type === 'competencia');
            $hasParte = $question->tags->contains(fn ($tag): bool => $tag->tag_type === 'parte');
            $hasComponente = $question->tags->contains(fn ($tag): bool => $tag->tag_type === 'componente');
            $hasTipoTexto = $question->tags->contains(fn ($tag): bool => $tag->tag_type === 'tipo_texto');
            $hasNivelLectura = $question->tags->contains(fn ($tag): bool => $tag->tag_type === 'nivel_lectura');

            if ($areaKey === 'ingles') {
                if ($hasParte) {
                    $summary[$areaKey]['dim1_with']++;
                }
                if ($hasCompetencia) {
                    $summary[$areaKey]['dim2_with']++;
                }
                continue;
            }

            if ($hasCompetencia) {
                $summary[$areaKey]['dim1_with']++;
            }

            if ($areaKey === 'lectura') {
                if ($hasTipoTexto) {
                    $summary[$areaKey]['dim2_with']++;
                }
                if ($hasNivelLectura) {
                    $summary[$areaKey]['dim3_with']++;
                }
            } else {
                if ($hasComponente) {
                    $summary[$areaKey]['dim2_with']++;
                }
            }
        }

        foreach ($summary as $areaKey => $data) {
            $summary[$areaKey]['dim1_missing'] = max(0, $data['total'] - $data['dim1_with']);
            $summary[$areaKey]['dim2_missing'] = $data['dim2_with'] === null ? null : max(0, $data['total'] - $data['dim2_with']);
            $summary[$areaKey]['dim3_missing'] = $data['dim3_with'] === null ? null : max(0, $data['total'] - $data['dim3_with']);
        }

        return array_values($summary);
    }

    /**
     * @return array{status:string,label:string,color:string,progress:int,can_download:bool,download_label:string}
     */
    public function getIndividualReportsStatus(): array
    {
        $generation = $this->getLatestIndividualReportsGeneration();

        if (! $generation) {
            return [
                'status' => 'not_started',
                'label' => 'No generado',
                'color' => 'gray',
                'progress' => 0,
                'can_download' => false,
                'download_label' => 'Descargar ZIP (no disponible)',
            ];
        }

        if ($generation->status === 'completed') {
            return [
                'status' => 'completed',
                'label' => 'ZIP listo para descargar',
                'color' => 'success',
                'progress' => 100,
                'can_download' => true,
                'download_label' => 'Descargar ZIP Individuales',
            ];
        }

        if ($generation->status === 'failed') {
            return [
                'status' => 'failed',
                'label' => 'Error en la generación',
                'color' => 'danger',
                'progress' => 0,
                'can_download' => false,
                'download_label' => 'Descargar ZIP (error)',
            ];
        }

        $progress = (int) ($generation->progress_percent ?? 0);

        return [
            'status' => $generation->status,
            'label' => 'Generación en progreso',
            'color' => 'warning',
            'progress' => $progress,
            'can_download' => false,
            'download_label' => "Generando ZIP ({$progress}%)",
        ];
    }

    /**
     * @return array{with_email:int, without_email:int}
     */
    public function getEmailCoverage(): array
    {
        $baseQuery = $this->getEnrollmentsForExamQuery();

        $withEmail = (clone $baseQuery)
            ->whereHas('student', fn ($query) => $query->whereNotNull('email')->where('email', '!=', ''))
            ->count();

        $withoutEmail = (clone $baseQuery)
            ->whereDoesntHave('student', fn ($query) => $query->whereNotNull('email')->where('email', '!=', ''))
            ->count();

        return [
            'with_email' => $withEmail,
            'without_email' => $withoutEmail,
        ];
    }

    public function downloadExcel()
    {
        $pipeline = $this->getPipelineStatus();
        if (! $pipeline['ready']) {
            Notification::make()
                ->title('Pipeline incompleto')
                ->body('Completa la carga de todas las sesiones antes de descargar reportes.')
                ->warning()
                ->send();

            return null;
        }

        $export = new ZipgradeResultsExport($this->record, null, null);
        $filename = 'resultados_zipgrade_'.str_replace(' ', '_', strtolower($this->record->name)).'_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download($export, $filename);
    }

    public function downloadPdf()
    {
        $pipeline = $this->getPipelineStatus();
        if (! $pipeline['ready']) {
            Notification::make()
                ->title('Pipeline incompleto')
                ->body('Completa la carga de todas las sesiones antes de descargar reportes.')
                ->warning()
                ->send();

            return null;
        }

        $pdfService = app(ZipgradePdfService::class);
        $filename = $pdfService->getFilename($this->record);

        return response()->streamDownload(function () use ($pdfService) {
            echo $pdfService->generate($this->record, null, null);
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function downloadHtml()
    {
        $pipeline = $this->getPipelineStatus();
        if (! $pipeline['ready']) {
            Notification::make()
                ->title('Pipeline incompleto')
                ->body('Completa la carga de todas las sesiones antes de descargar reportes.')
                ->warning()
                ->send();

            return null;
        }

        $generator = app(ZipgradeReportGenerator::class);
        $filename = $generator->getReportFilename($this->record, null);

        return response()->streamDownload(function () use ($generator) {
            echo $generator->generateHtmlReport($this->record, null);
        }, $filename, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_individual_reports')
                ->hidden()
                ->requiresConfirmation()
                ->modalHeading('Generar Informes PDF Individuales')
                ->modalDescription(function () {
                    $count = app(ZipgradeMetricsService::class)->getEnrollmentsForExam($this->record)->count();
                    return "Se generará un PDF individual por cada uno de los {$count} estudiantes.";
                })
                ->modalSubmitActionLabel('Iniciar Generación')
                ->action(function () {
                    $exam = $this->record;
                    $enrollments = app(ZipgradeMetricsService::class)->getEnrollmentsForExam($exam);

                    $existingGeneration = ReportGeneration::where('exam_id', $exam->id)
                        ->where('type', 'individual_pdfs')
                        ->whereIn('status', ['pending', 'processing'])
                        ->first();

                    if ($existingGeneration) {
                        Notification::make()
                            ->title('Generación en proceso')
                            ->body('Ya hay una generación de informes en curso. Espera a que termine.')
                            ->warning()
                            ->send();
                        return;
                    }

                    $generation = ReportGeneration::create([
                        'exam_id' => $exam->id,
                        'type' => 'individual_pdfs',
                        'status' => 'pending',
                        'total_students' => $enrollments->count(),
                    ]);

                    GenerateStudentReportsJob::dispatch($generation);

                    Notification::make()
                        ->title('Generación iniciada')
                        ->body("Se están generando {$enrollments->count()} informes individuales.")
                        ->success()
                        ->send();
                }),

            Action::make('download_individual_reports')
                ->hidden()
                ->action(function () {
                    $generation = $this->getCompletedIndividualReportsGeneration();

                    if (! $generation || ! $generation->file_path) {
                        Notification::make()
                            ->title('Archivo no disponible')
                            ->body('El ZIP no está disponible. Genera los informes primero.')
                            ->danger()
                            ->send();
                        return;
                    }

                    $fullPath = storage_path('app/'.$generation->file_path);

                    if (! file_exists($fullPath)) {
                        Notification::make()
                            ->title('Archivo no encontrado')
                            ->body('El archivo ZIP ya no existe. Genera los informes nuevamente.')
                            ->danger()
                            ->send();
                        return;
                    }

                    return response()->download($fullPath);
                }),

            Action::make('send_reports_email')
                ->hidden()
                ->requiresConfirmation()
                ->modalHeading('Enviar Reportes por Email')
                ->modalDescription(function () {
                    $coverage = $this->getEmailCoverage();

                    return "Se enviarán reportes a {$coverage['with_email']} estudiantes con email. {$coverage['without_email']} estudiantes serán omitidos por no tener email.";
                })
                ->action(function () {
                    $reportGeneration = $this->getCompletedIndividualReportsGeneration();

                    if (! $reportGeneration) {
                        Notification::make()
                            ->title('Falta el ZIP de reportes')
                            ->body('Primero genera los informes individuales.')
                            ->danger()
                            ->send();
                        return;
                    }

                    $withEmail = $this->getEmailCoverage()['with_email'];

                    if ($withEmail === 0) {
                        Notification::make()
                            ->title('Sin destinatarios')
                            ->body('No hay estudiantes con email registrado.')
                            ->warning()
                            ->send();
                        return;
                    }

                    SendStudentReportsEmailJob::dispatch($this->record);

                    Notification::make()
                        ->title('Envío en progreso')
                        ->body("Se está enviando correo a {$withEmail} estudiantes en segundo plano.")
                        ->success()
                        ->send();
                }),

            Action::make('back')
                ->label('Volver a Exámenes')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => ExamResource::getUrl('index')),
        ];
    }

    private function resolveAreaKeyFromQuestion(ExamQuestion $question): ?string
    {
        foreach ($question->tags as $tag) {
            if ($tag->tag_type === 'area') {
                $normalized = AreaConfig::normalizeAreaName((string) $tag->tag_name);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        foreach ($question->tags as $tag) {
            if (! $tag->parent_area) {
                continue;
            }

            $normalized = AreaConfig::normalizeAreaName((string) $tag->parent_area);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function getEnrollmentsForExamQuery()
    {
        return Enrollment::query()
            ->where('academic_year_id', $this->record->academic_year_id)
            ->where('status', 'ACTIVE')
            ->whereHas('studentAnswers.question.session', fn ($query) => $query->where('exam_id', $this->record->id));
    }

    private function getLatestIndividualReportsGeneration(): ?ReportGeneration
    {
        return ReportGeneration::query()
            ->where('exam_id', $this->record->id)
            ->where('type', 'individual_pdfs')
            ->latest()
            ->first();
    }

    private function getCompletedIndividualReportsGeneration(): ?ReportGeneration
    {
        return ReportGeneration::query()
            ->where('exam_id', $this->record->id)
            ->where('type', 'individual_pdfs')
            ->where('status', 'completed')
            ->latest()
            ->first();
    }
}
