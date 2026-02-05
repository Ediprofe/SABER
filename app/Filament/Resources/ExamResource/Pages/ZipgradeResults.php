<?php

namespace App\Filament\Resources\ExamResource\Pages;

use App\Exports\ZipgradeResultsExport;
use App\Filament\Resources\ExamResource;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Services\ZipgradeMetricsService;
use App\Services\ZipgradePdfService;
use App\Services\ZipgradeReportGenerator;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Maatwebsite\Excel\Facades\Excel;
use App\Jobs\GenerateStudentReportsJob;
use App\Models\ReportGeneration;
use Filament\Notifications\Notification;
use App\Jobs\SendStudentReportsEmailJob;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;


class ZipgradeResults extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ExamResource::class;

    protected static string $view = 'filament.resources.exam-resource.pages.zipgrade-results';

    public Exam $record;

    public function mount(Exam $record): void
    {
        $this->record = $record;
    }

    public function getHeading(): string
    {
        return "Resultados Zipgrade - {$this->record->name}";
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                return Enrollment::query()
                    ->where('academic_year_id', $this->record->academic_year_id)
                    ->where('status', 'ACTIVE')
                    ->whereHas('studentAnswers.question.session', function ($query) {
                        $query->where('exam_id', $this->record->id);
                    })
                    ->with('student');
            })
            ->columns([
                TextColumn::make('student.document_id')
                    ->label('Documento')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('student.full_name')
                    ->label('Nombre')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('group')
                    ->label('Grupo')
                    ->sortable(),
                BadgeColumn::make('is_piar')
                    ->label('PIAR')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'SI' : 'NO')
                    ->color(fn (bool $state): string => $state ? 'warning' : 'success'),
                TextColumn::make('lectura_score')
                    ->label('Lectura')
                    ->numeric(2)
                    ->state(function (Enrollment $record): float {
                        $service = app(ZipgradeMetricsService::class);

                        return $service->getStudentAreaScore($record, $this->record, 'lectura');
                    }),
                TextColumn::make('matematicas_score')
                    ->label('Matemáticas')
                    ->numeric(2)
                    ->state(function (Enrollment $record): float {
                        $service = app(ZipgradeMetricsService::class);

                        return $service->getStudentAreaScore($record, $this->record, 'matematicas');
                    }),
                TextColumn::make('sociales_score')
                    ->label('Sociales')
                    ->numeric(2)
                    ->state(function (Enrollment $record): float {
                        $service = app(ZipgradeMetricsService::class);

                        return $service->getStudentAreaScore($record, $this->record, 'sociales');
                    }),
                TextColumn::make('naturales_score')
                    ->label('Naturales')
                    ->numeric(2)
                    ->state(function (Enrollment $record): float {
                        $service = app(ZipgradeMetricsService::class);

                        return $service->getStudentAreaScore($record, $this->record, 'naturales');
                    }),
                TextColumn::make('ingles_score')
                    ->label('Inglés')
                    ->numeric(2)
                    ->state(function (Enrollment $record): float {
                        $service = app(ZipgradeMetricsService::class);

                        return $service->getStudentAreaScore($record, $this->record, 'ingles');
                    }),
                TextColumn::make('global_score')
                    ->label('Global')
                    ->numeric(0)
                    ->weight('bold')
                    ->state(function (Enrollment $record): int {
                        $service = app(ZipgradeMetricsService::class);

                        return $service->getStudentGlobalScore($record, $this->record);
                    }),
            ])
            ->filters([
                SelectFilter::make('group')
                    ->label('Grupo')
                    ->options(function () {
                        return Enrollment::where('academic_year_id', $this->record->academic_year_id)
                            ->where('status', 'ACTIVE')
                            ->distinct()
                            ->pluck('group', 'group')
                            ->toArray();
                    }),
                Filter::make('piar_only')
                    ->label('Solo PIAR')
                    ->query(fn ($query) => $query->where('is_piar', true)),
            ])
            ->defaultSort('student.document_id', 'asc')
            ->poll(null)
            ->emptyStateHeading('No hay estudiantes con resultados')
            ->emptyStateDescription('Importe datos de Zipgrade para ver los resultados.')
            ->actions([
                \Filament\Tables\Actions\Action::make('send_email_single')
                    ->label('Enviar Email')
                    ->icon('heroicon-o-envelope')
                    ->color('success')
                    ->size('sm')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Enrollment $record) => "Enviar reporte a {$record->student->full_name}")
                    ->modalDescription(fn (Enrollment $record) => $record->student->email
                        ? "Se enviará el reporte a: **{$record->student->email}**"
                        : "⚠️ Este estudiante no tiene email registrado.")
                    ->modalSubmitActionLabel('Enviar')
                    ->visible(fn (Enrollment $record) => !empty($record->student->email))
                    ->action(function (Enrollment $record) {
                        // Verificar que hay PDFs generados
                        $reportGeneration = ReportGeneration::where('exam_id', $this->record->id)
                            ->where('type', 'individual_pdfs')
                            ->where('status', 'completed')
                            ->latest()
                            ->first();

                        if (!$reportGeneration || !$reportGeneration->file_path) {
                            Notification::make()
                                ->title('Error')
                                ->body('Primero debe generar los reportes individuales.')
                                ->danger()
                                ->send();
                            return;
                        }

                        $zipPath = storage_path('app/' . $reportGeneration->file_path);

                        if (!file_exists($zipPath)) {
                            Notification::make()
                                ->title('Error')
                                ->body('No se encontró el archivo de reportes.')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Extraer PDF específico del ZIP
                        $pdfService = app(\App\Services\IndividualStudentPdfService::class);
                        $metricsService = app(\App\Services\ZipgradeMetricsService::class);

                        $tempDir = storage_path('app/temp-single-email-' . uniqid());
                        mkdir($tempDir, 0755, true);

                        $zip = new \ZipArchive();
                        if ($zip->open($zipPath) !== true) {
                            Notification::make()
                                ->title('Error')
                                ->body('No se pudo abrir el archivo de reportes.')
                                ->danger()
                                ->send();
                            return;
                        }

                        $pdfRelativePath = $pdfService->getRelativePath($record);
                        $zip->extractTo($tempDir, $pdfRelativePath);
                        $zip->close();

                        $pdfFullPath = $tempDir . '/' . $pdfRelativePath;

                        if (!file_exists($pdfFullPath)) {
                            // Limpiar
                            $this->deleteDirectory($tempDir);
                            Notification::make()
                                ->title('Error')
                                ->body("No se encontró el PDF de {$record->student->full_name}")
                                ->danger()
                                ->send();
                            return;
                        }

                        try {
                            $globalScore = $metricsService->getStudentGlobalScore($record, $this->record);

                            Mail::to($record->student->email)->send(
                                new \App\Mail\StudentReportMail(
                                    enrollment: $record,
                                    exam: $this->record,
                                    pdfPath: $pdfFullPath,
                                    globalScore: $globalScore
                                )
                            );

                            Notification::make()
                                ->title('Email enviado')
                                ->body("Reporte enviado a {$record->student->email}")
                                ->success()
                                ->send();

                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Error al enviar')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }

                        // Limpiar directorio temporal
                        $this->deleteDirectory($tempDir);
                    }),
            ])
            ->bulkActions([
                \Filament\Tables\Actions\BulkAction::make('send_email_selected')
                    ->label('Enviar Email a Seleccionados')
                    ->icon('heroicon-o-envelope')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Enviar reportes a estudiantes seleccionados')
                    ->modalDescription(function ($records) {
                        $withEmail = $records->filter(fn ($r) => !empty($r->student->email))->count();
                        $withoutEmail = $records->count() - $withEmail;

                        return "Se enviarán emails a **{$withEmail}** estudiantes.\n\n" .
                               ($withoutEmail > 0 ? "⚠️ {$withoutEmail} estudiantes no tienen email y serán omitidos." : "");
                    })
                    ->modalSubmitActionLabel('Enviar Emails')
                    ->deselectRecordsAfterCompletion()
                    ->action(function ($records) {
                        // Verificar PDFs
                        $reportGeneration = ReportGeneration::where('exam_id', $this->record->id)
                            ->where('type', 'individual_pdfs')
                            ->where('status', 'completed')
                            ->latest()
                            ->first();

                        if (!$reportGeneration || !$reportGeneration->file_path) {
                            Notification::make()
                                ->title('Error')
                                ->body('Primero debe generar los reportes individuales.')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Filtrar solo los que tienen email
                        $enrollmentsWithEmail = $records->filter(fn ($r) => !empty($r->student->email));

                        if ($enrollmentsWithEmail->isEmpty()) {
                            Notification::make()
                                ->title('Sin destinatarios')
                                ->body('Ninguno de los estudiantes seleccionados tiene email.')
                                ->warning()
                                ->send();
                            return;
                        }

                        // Despachar Job con los IDs seleccionados
                        $enrollmentIds = $enrollmentsWithEmail->pluck('id')->toArray();
                        SendStudentReportsEmailJob::dispatch(
                            exam: $this->record,
                            groupFilter: null,
                            piarFilter: null,
                            enrollmentIds: $enrollmentIds
                        );

                        $count = count($enrollmentIds);
                        Notification::make()
                            ->title('Enviando emails')
                            ->body("Se están enviando {$count} emails en segundo plano. Revise los logs para el progreso.")
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('export_excel')
                ->label('Excel')
                ->icon('heroicon-o-document-chart-bar')
                ->color('success')
                ->action(function () {
                    // Obtener valores de filtros desde las propiedades de Livewire
                    $groupFilter = $this->tableFilters['group']['value'] ?? null;
                    $piarFilter = $this->tableFilters['piar_only']['isActive'] ?? false;

                    $export = new ZipgradeResultsExport(
                        $this->record,
                        $groupFilter,
                        $piarFilter ? true : null
                    );

                    $filename = 'resultados_zipgrade_'.str_replace(' ', '_', strtolower($this->record->name)).'_'.now()->format('Y-m-d').'.xlsx';

                    return Excel::download($export, $filename);
                }),

            Action::make('export_pdf')
                ->label('PDF')
                ->icon('heroicon-o-document-text')
                ->color('warning')
                ->action(function () {
                    // Obtener valores de filtros desde las propiedades de Livewire
                    $groupFilter = $this->tableFilters['group']['value'] ?? null;
                    $piarFilter = $this->tableFilters['piar_only']['isActive'] ?? false;

                    $pdfService = app(ZipgradePdfService::class);
                    $filename = $pdfService->getFilename($this->record);

                    return response()->streamDownload(function () use ($pdfService, $groupFilter, $piarFilter) {
                        echo $pdfService->generate(
                            $this->record,
                            $groupFilter,
                            $piarFilter ? true : null
                        );
                    }, $filename, [
                        'Content-Type' => 'application/pdf',
                    ]);
                }),

            Action::make('export_html')
                ->label('Informe HTML')
                ->icon('heroicon-o-code-bracket')
                ->color('primary')
                ->action(function () {
                    // Obtener valores de filtros desde las propiedades de Livewire
                    $groupFilter = $this->tableFilters['group']['value'] ?? null;

                    $generator = app(ZipgradeReportGenerator::class);
                    $filename = $generator->getReportFilename($this->record, $groupFilter);

                    return response()->streamDownload(function () use ($generator, $groupFilter) {
                        echo $generator->generateHtmlReport(
                            $this->record,
                            $groupFilter
                        );
                    }, $filename, [
                        'Content-Type' => 'text/html; charset=utf-8',
                    ]);
                }),

            Action::make('generate_individual_reports')
                ->label('Generar Informes Individuales (PDF)')
                ->icon('heroicon-o-document-arrow-down')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Generar Informes PDF Individuales')
                ->modalDescription(function () {
                    $exam = $this->record;
                    $count = app(ZipgradeMetricsService::class)->getEnrollmentsForExam($exam)->count();
                    return "Se generará un PDF individual por cada uno de los {$count} estudiantes. El proceso se ejecutará en segundo plano y podrás descargar el ZIP cuando esté listo.";
                })
                ->modalSubmitActionLabel('Iniciar Generación')
                ->action(function () {
                    $exam = $this->record;
                    $enrollments = app(ZipgradeMetricsService::class)->getEnrollmentsForExam($exam);

                    // Verificar si ya hay una generación en proceso
                    $existingGeneration = ReportGeneration::where('exam_id', $exam->id)
                        ->where('type', 'individual_pdfs')
                        ->whereIn('status', ['pending', 'processing'])
                        ->first();

                    if ($existingGeneration) {
                        Notification::make()
                            ->title('Generación en proceso')
                            ->body('Ya hay una generación de informes en curso. Por favor espera a que termine.')
                            ->warning()
                            ->send();
                        return;
                    }

                    // Crear registro de generación
                    $generation = ReportGeneration::create([
                        'exam_id' => $exam->id,
                        'type' => 'individual_pdfs',
                        'status' => 'pending',
                        'total_students' => $enrollments->count(),
                    ]);

                    // Despachar job
                    GenerateStudentReportsJob::dispatch($generation);

                    Notification::make()
                        ->title('Generación iniciada')
                        ->body("Se están generando {$enrollments->count()} informes individuales. Actualiza la página para ver el progreso.")
                        ->success()
                        ->send();
                }),

            Action::make('download_individual_reports')
                ->label(function () {
                    $exam = $this->record;
                    $generation = ReportGeneration::where('exam_id', $exam->id)
                        ->where('type', 'individual_pdfs')
                        ->latest()
                        ->first();

                    if (!$generation) {
                        return 'Descargar ZIP (no disponible)';
                    }

                    if ($generation->status === 'processing') {
                        return "Generando... ({$generation->progress_percent}%)";
                    }

                    if ($generation->status === 'completed') {
                        return 'Descargar ZIP de Informes';
                    }

                    if ($generation->status === 'failed') {
                        return 'Error en generación';
                    }

                    return 'Descargar ZIP';
                })
                ->icon('heroicon-o-arrow-down-tray')
                ->color(function () {
                    $exam = $this->record;
                    $generation = ReportGeneration::where('exam_id', $exam->id)
                        ->where('type', 'individual_pdfs')
                        ->latest()
                        ->first();

                    if ($generation?->status === 'completed') {
                        return 'success';
                    }
                    if ($generation?->status === 'failed') {
                        return 'danger';
                    }
                    if ($generation?->status === 'processing') {
                        return 'warning';
                    }
                    return 'gray';
                })
                ->disabled(function () {
                    $exam = $this->record;
                    $generation = ReportGeneration::where('exam_id', $exam->id)
                        ->where('type', 'individual_pdfs')
                        ->latest()
                        ->first();

                    return !$generation || $generation->status !== 'completed';
                })
                ->action(function () {
                    $exam = $this->record;
                    $generation = ReportGeneration::where('exam_id', $exam->id)
                        ->where('type', 'individual_pdfs')
                        ->where('status', 'completed')
                        ->latest()
                        ->first();

                    if (!$generation || !$generation->file_path) {
                        Notification::make()
                            ->title('Archivo no disponible')
                            ->body('El archivo ZIP no está disponible. Genera los informes primero.')
                            ->danger()
                            ->send();
                        return;
                    }

                    $fullPath = storage_path('app/' . $generation->file_path);

                    if (!file_exists($fullPath)) {
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
                ->label('Enviar por Email')
                ->icon('heroicon-o-envelope')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Enviar Reportes por Email')
                ->modalDescription(function () {
                    // Contar estudiantes con email
                    $withEmail = Enrollment::query()
                        ->where('academic_year_id', $this->record->academic_year_id)
                        ->where('status', 'ACTIVE')
                        ->whereHas('student', fn($q) => $q->whereNotNull('email')->where('email', '!=', ''))
                        ->whereHas('studentAnswers.question.session', fn($q) => $q->where('exam_id', $this->record->id))
                        ->count();

                    $withoutEmail = Enrollment::query()
                        ->where('academic_year_id', $this->record->academic_year_id)
                        ->where('status', 'ACTIVE')
                        ->whereHas('studentAnswers.question.session', fn($q) => $q->where('exam_id', $this->record->id))
                        ->whereDoesntHave('student', fn($q) => $q->whereNotNull('email')->where('email', '!=', ''))
                        ->count();

                    // Verificar si hay PDFs generados
                    $hasReports = ReportGeneration::where('exam_id', $this->record->id)
                        ->where('type', 'individual_pdfs')
                        ->where('status', 'completed')
                        ->exists();

                    $message = "Se enviarán los reportes individuales por email.\n\n";
                    $message .= "📧 Estudiantes con email: **{$withEmail}**\n";
                    $message .= "⚠️ Estudiantes sin email: **{$withoutEmail}**\n\n";

                    if (!$hasReports) {
                        $message .= "❌ **ATENCIÓN:** No hay reportes PDF generados. Primero debe generar los reportes individuales.";
                    }

                    return $message;
                })
                ->modalSubmitActionLabel('Enviar Emails')
                ->visible(function () {
                    // Solo visible si hay reportes generados
                    return ReportGeneration::where('exam_id', $this->record->id)
                        ->where('type', 'individual_pdfs')
                        ->where('status', 'completed')
                        ->exists();
                })
                ->action(function () {
                    // Verificar que hay PDFs
                    $reportGeneration = ReportGeneration::where('exam_id', $this->record->id)
                        ->where('type', 'individual_pdfs')
                        ->where('status', 'completed')
                        ->latest()
                        ->first();

                    if (!$reportGeneration) {
                        Notification::make()
                            ->title('Error')
                            ->body('Primero debe generar los reportes individuales.')
                            ->danger()
                            ->send();
                        return;
                    }

                    $withEmail = Enrollment::query()
                        ->where('academic_year_id', $this->record->academic_year_id)
                        ->where('status', 'ACTIVE')
                        ->whereHas('student', fn($q) => $q->whereNotNull('email')->where('email', '!=', ''))
                        ->whereHas('studentAnswers.question.session', fn($q) => $q->where('exam_id', $this->record->id))
                        ->count();

                    if ($withEmail === 0) {
                        Notification::make()
                            ->title('Sin destinatarios')
                            ->body('No hay estudiantes con email registrado.')
                            ->warning()
                            ->send();
                        return;
                    }

                    // Despachar job
                    SendStudentReportsEmailJob::dispatch($this->record);

                    Notification::make()
                        ->title('Enviando emails')
                        ->body("Se están enviando {$withEmail} emails en segundo plano. Esto puede tomar varios minutos.")
                        ->success()
                        ->send();
                }),

            Action::make('back')
                ->label('Volver')
                ->url(fn () => ExamResource::getUrl('index'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
        ];
    }

    public function getFooterWidgets(): array
    {
        return [
            \App\Filament\Widgets\ZipgradeStatsWidget::class,
        ];
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
