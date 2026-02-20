<?php

namespace App\Filament\Resources\ExamResource\Pages;

use App\Filament\Resources\ExamResource;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Services\ZipgradeImportPipelineService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

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
     * @return array{session:int, has_data:bool, has_completed_import:bool, has_stats:bool, total_questions:int}
     */
    public function getSessionStatus(int $sessionNumber): array
    {
        $session = $this->record->sessions()
            ->with(['imports'])
            ->where('session_number', $sessionNumber)
            ->first();

        if (! $session) {
            return [
                'session' => $sessionNumber,
                'has_data' => false,
                'has_completed_import' => false,
                'has_stats' => false,
                'total_questions' => 0,
            ];
        }

        $hasCompletedImport = $session->imports()
            ->where('status', 'completed')
            ->exists();

        $hasStats = ExamQuestion::query()
            ->where('exam_session_id', $session->id)
            ->whereNotNull('correct_answer')
            ->exists();

        return [
            'session' => $sessionNumber,
            'has_data' => $session->total_questions > 0,
            'has_completed_import' => $hasCompletedImport,
            'has_stats' => $hasStats,
            'total_questions' => (int) $session->total_questions,
        ];
    }

    /**
     * @return array{ready:bool, tags_done:bool, stats_done:bool}
     */
    public function getPipelineStatus(): array
    {
        $s1 = $this->getSessionStatus(1);
        $s2 = $this->getSessionStatus(2);

        $tagsDone = $s1['has_completed_import'] && $s2['has_completed_import'];
        $statsDone = $s1['has_stats'] && $s2['has_stats'];

        return [
            'ready' => $tagsDone && $statsDone,
            'tags_done' => $tagsDone,
            'stats_done' => $statsDone,
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
}
