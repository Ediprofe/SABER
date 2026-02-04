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
            ->emptyStateDescription('Importe datos de Zipgrade para ver los resultados.');
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
}
