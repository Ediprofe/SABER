<?php

namespace App\Filament\Resources\ExamResource\Pages;

use App\Exports\ZipgradeResultsExport;
use App\Filament\Resources\ExamResource;
use App\Jobs\GenerateStudentReportsJob;
use App\Jobs\SendStudentReportsEmailJob;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ReportGeneration;
use App\Services\ZipgradeImportPipelineService;
use App\Services\ZipgradeMetricsService;
use App\Services\ZipgradePipelineStatusService;
use App\Services\ZipgradePdfService;
use App\Services\ZipgradeReportGenerator;
use Filament\Actions\Action;
use Filament\Forms;
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
        return 'Sigue los pasos en orden: Tags de sesiones, Stats y luego resultados/reportes.';
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

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('import_tags_session1')
                ->label('Paso 1A: Tags Sesión 1')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('success')
                ->form($this->getTagsUploadFormSchema())
                ->action(fn (array $data) => $this->handleTagsImport(1, $data)),

            Action::make('import_tags_session2')
                ->label('Paso 1B: Tags Sesión 2')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('warning')
                ->form($this->getTagsUploadFormSchema())
                ->action(fn (array $data) => $this->handleTagsImport(2, $data)),

            Action::make('import_stats_session1')
                ->label('Paso 2A: Stats Sesión 1')
                ->icon('heroicon-o-chart-bar')
                ->color('info')
                ->form($this->getStatsUploadFormSchema())
                ->visible(fn (): bool => $this->record->sessions()->where('session_number', 1)->exists())
                ->action(fn (array $data) => $this->handleStatsImport(1, $data)),

            Action::make('import_stats_session2')
                ->label('Paso 2B: Stats Sesión 2')
                ->icon('heroicon-o-chart-bar')
                ->color('secondary')
                ->form($this->getStatsUploadFormSchema())
                ->visible(fn (): bool => $this->record->sessions()->where('session_number', 2)->exists())
                ->action(fn (array $data) => $this->handleStatsImport(2, $data)),

            Action::make('open_results')
                ->label('Paso 3: Resultados y Reportes')
                ->icon('heroicon-o-table-cells')
                ->color('primary')
                ->visible(fn (): bool => $this->record->hasSessions())
                ->url(fn (): string => ExamResource::getUrl('zipgrade-results', ['record' => $this->record])),

            Action::make('download_excel')
                ->label('Descargar Excel')
                ->icon('heroicon-o-document-chart-bar')
                ->color('success')
                ->visible(fn (): bool => $this->record->hasSessions())
                ->action(function () {
                    $export = new ZipgradeResultsExport($this->record, null, null);
                    $filename = 'resultados_zipgrade_'.str_replace(' ', '_', strtolower($this->record->name)).'_'.now()->format('Y-m-d').'.xlsx';

                    return Excel::download($export, $filename);
                }),

            Action::make('download_pdf')
                ->label('Descargar PDF')
                ->icon('heroicon-o-document-text')
                ->color('warning')
                ->visible(fn (): bool => $this->record->hasSessions())
                ->action(function () {
                    $pdfService = app(ZipgradePdfService::class);
                    $filename = $pdfService->getFilename($this->record);

                    return response()->streamDownload(function () use ($pdfService) {
                        echo $pdfService->generate($this->record, null, null);
                    }, $filename, [
                        'Content-Type' => 'application/pdf',
                    ]);
                }),

            Action::make('download_html')
                ->label('Descargar HTML')
                ->icon('heroicon-o-code-bracket')
                ->color('info')
                ->visible(fn (): bool => $this->record->hasSessions())
                ->action(function () {
                    $generator = app(ZipgradeReportGenerator::class);
                    $filename = $generator->getReportFilename($this->record, null);

                    return response()->streamDownload(function () use ($generator) {
                        echo $generator->generateHtmlReport($this->record, null);
                    }, $filename, [
                        'Content-Type' => 'text/html; charset=utf-8',
                    ]);
                }),

            Action::make('generate_individual_reports')
                ->label('Generar ZIP Individuales')
                ->icon('heroicon-o-document-arrow-down')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->hasSessions())
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
                ->label(fn (): string => $this->getIndividualReportsStatus()['download_label'])
                ->icon('heroicon-o-arrow-down-tray')
                ->color(fn (): string => $this->getIndividualReportsStatus()['color'])
                ->visible(fn (): bool => $this->record->hasSessions())
                ->disabled(fn (): bool => ! $this->getIndividualReportsStatus()['can_download'])
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
                ->label('Enviar Reportes por Email')
                ->icon('heroicon-o-envelope')
                ->color('secondary')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->hasSessions())
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

    /**
     * @return array<Forms\Components\Component>
     */
    private function getTagsUploadFormSchema(): array
    {
        return [
            Forms\Components\FileUpload::make('file')
                ->label('Archivo CSV de Zipgrade (Tags)')
                ->acceptedFileTypes([
                    'text/csv',
                    'text/plain',
                    'application/vnd.ms-excel',
                    'application/csv',
                    'application/x-csv',
                ])
                ->disk('public')
                ->directory('zipgrade_imports')
                ->visibility('private')
                ->maxSize(20480)
                ->required(),
        ];
    }

    /**
     * @return array<Forms\Components\Component>
     */
    private function getStatsUploadFormSchema(): array
    {
        return [
            Forms\Components\FileUpload::make('file')
                ->label('Archivo Excel de Estadísticas')
                ->acceptedFileTypes([
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.ms-excel',
                ])
                ->disk('public')
                ->directory('zipgrade_imports')
                ->visibility('private')
                ->required(),
        ];
    }

    private function handleTagsImport(int $sessionNumber, array $data): mixed
    {
        try {
            $result = app(ZipgradeImportPipelineService::class)
                ->importSessionTagsFromUploadedFile($this->record, $sessionNumber, $data['file']);

            if ($result['needs_classification'] ?? false) {
                return redirect()->to(
                    ExamResource::getUrl('classify-tags', [
                        'record' => $this->record,
                        'sessionNumber' => $sessionNumber,
                        'filePath' => $result['encoded_path'],
                    ])
                );
            }

            $imported = $result['imported'] ?? ['students_count' => 0, 'questions_count' => 0];

            Notification::make()
                ->title('Importación exitosa')
                ->body("Se importaron {$imported['students_count']} estudiantes y {$imported['questions_count']} preguntas correctamente.")
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error en la importación')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        return null;
    }

    private function handleStatsImport(int $sessionNumber, array $data): void
    {
        try {
            $service = app(ZipgradeImportPipelineService::class);
            $filePath = $service->getUploadedFilePath($data['file']);
            $processedCount = $service->processZipgradeStatsImport($this->record, $sessionNumber, $filePath);

            Notification::make()
                ->title('Importación de estadísticas exitosa')
                ->body("Se importaron estadísticas para {$processedCount} preguntas de la sesión {$sessionNumber}.")
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error en la importación')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
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
