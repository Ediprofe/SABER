<?php

namespace App\Filament\Actions;

use App\Imports\ZipgradeTagsImport;
use App\Models\Exam;
use App\Models\ExamSession;
use App\Models\TagHierarchy;
use App\Models\ZipgradeImport;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportZipgradeAction
{
    /**
     * Crea la acción para importar datos de Zipgrade en una sesión específica.
     */
    public static function make(int $sessionNumber): Action
    {
        return Action::make("import_zipgrade_session_{$sessionNumber}")
            ->label('Importar Excel')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('success')
            ->modalHeading("Importar Excel Zipgrade - Sesión {$sessionNumber}")
            ->modalSubmitActionLabel('Continuar')
            ->form([
                FileUpload::make('file')
                    ->label('Archivo Excel de Zipgrade (formato Tags)')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'])
                    ->disk('public')
                    ->directory('zipgrade_imports')
                    ->visibility('private')
                    ->required()
                    ->helperText('El archivo debe tener las columnas: Tag, StudentFirstName, StudentLastName, StudentID, QuizName, TagType, QuestionNum, EarnedPoints, PossiblePoints'),
            ])
            ->action(function (Exam $record, array $data) use ($sessionNumber) {
                // Store file info in session for next step
                session()->put('zipgrade_import', [
                    'exam_id' => $record->id,
                    'session_number' => $sessionNumber,
                    'file_path' => $data['file'],
                ]);

                return redirect()->route('filament.admin.resources.exams.zipgrade-tags', [
                    'record' => $record,
                    'session' => $sessionNumber,
                ]);
            });
    }

    /**
     * Crea la acción de clasificación de tags nuevos.
     */
    public static function classifyTagsAction(): Action
    {
        return Action::make('classify_tags')
            ->label('Clasificar Tags')
            ->icon('heroicon-o-tag')
            ->color('warning')
            ->modalHeading('Clasificar Tags Nuevos')
            ->modalSubmitActionLabel('Guardar y Continuar')
            ->form(fn () => [
                Repeater::make('tags')
                    ->label('Tags detectados')
                    ->schema([
                        TextInput::make('tag_name')
                            ->label('Nombre del Tag')
                            ->disabled(),
                        Select::make('tag_type')
                            ->label('Tipo')
                            ->options([
                                'area' => 'Área',
                                'competencia' => 'Competencia',
                                'componente' => 'Componente',
                                'tipo_texto' => 'Tipo de Texto',
                                'parte' => 'Parte',
                            ])
                            ->native(false)
                            ->required(),
                        Select::make('parent_area')
                            ->label('Área Padre')
                            ->options(function () {
                                return TagHierarchy::where('tag_type', 'area')
                                    ->pluck('tag_name', 'tag_name');
                            })
                            ->native(false)
                            ->placeholder('Seleccione si aplica'),
                    ])
                    ->columns(3),
            ]);
    }

    /**
     * Crea la acción para gestionar sesiones del examen.
     */
    public static function manageSessionsAction(): Action
    {
        return Action::make('manage_sessions')
            ->label('Gestionar Sesiones')
            ->icon('heroicon-o-clock')
            ->color('primary')
            ->modalHeading('Sesiones del Examen')
            ->modalSubmitActionLabel('Cerrar')
            ->modalCancelActionLabel(false)
            ->form(fn (Exam $record) => [
                Repeater::make('sessions')
                    ->label('Sesiones Configuradas')
                    ->default(fn () => $record->sessions->map(fn ($session) => [
                        'id' => $session->id,
                        'name' => $session->name,
                        'session_number' => $session->session_number,
                        'zipgrade_quiz_name' => $session->zipgrade_quiz_name,
                        'total_questions' => $session->total_questions,
                        'has_import' => $session->zipgradeImport !== null,
                    ])->toArray())
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->disabled(),
                        TextInput::make('session_number')
                            ->label('Número')
                            ->disabled(),
                        TextInput::make('zipgrade_quiz_name')
                            ->label('Quiz en Zipgrade')
                            ->disabled()
                            ->placeholder('Sin importar'),
                        TextInput::make('total_questions')
                            ->label('Total Preguntas')
                            ->disabled()
                            ->placeholder('—'),
                        Toggle::make('has_import')
                            ->label('Importado')
                            ->disabled(),
                    ])
                    ->columns(5)
                    ->addable(false)
                    ->deletable(false),
            ])
            ->action(function () {
                // Just close the modal
            });
    }

    /**
     * Procesa la importación del archivo Zipgrade.
     */
    public static function processImport(
        Exam $exam,
        int $sessionNumber,
        string $filePath,
        array $tagMappings = []
    ): array {
        $session = ExamSession::firstOrCreate(
            ['exam_id' => $exam->id, 'session_number' => $sessionNumber],
            ['name' => "Sesión {$sessionNumber}", 'zipgrade_quiz_name' => null, 'total_questions' => 0]
        );

        // Create import record
        $import = ZipgradeImport::create([
            'exam_session_id' => $session->id,
            'filename' => basename($filePath),
            'total_rows' => 0,
            'status' => 'processing',
        ]);

        try {
            // Get full file path
            $fullPath = Storage::disk('public')->path($filePath);

            if (! file_exists($fullPath)) {
                throw new \Exception("No se pudo encontrar el archivo: {$filePath}");
            }

            // Process the import
            $importClass = new ZipgradeTagsImport($session->id, $tagMappings);
            Excel::import($importClass, $fullPath);

            // Update session stats
            $session->refresh();
            $session->total_questions = $session->questions()->count();
            $session->save();

            // Mark import as completed
            $import->update([
                'status' => 'completed',
                'total_rows' => $importClass->getRowCount(),
            ]);

            return [
                'success' => true,
                'message' => 'Importación completada exitosamente',
                'total_rows' => $importClass->getRowCount(),
                'students_count' => $importClass->getStudentsCount(),
                'questions_count' => $session->total_questions,
            ];

        } catch (\Exception $e) {
            $import->markAsError($e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
