<?php

namespace App\Filament\Resources\ExamResource\Pages;

use App\Filament\Resources\ExamResource;
use App\Imports\ZipgradeTagsImport;
use App\Models\Exam;
use App\Models\ExamSession;
use App\Models\ZipgradeImport;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ClassifyTags extends Page
{
    use InteractsWithForms;

    protected static string $resource = ExamResource::class;

    protected static string $view = 'filament.resources.exam-resource.pages.classify-tags';

    public Exam $exam;

    public int $sessionNumber;

    public string $filePath;

    public array $newTags = [];

    public array $tagClassifications = [];

    public bool $saveNormalizations = true;

    public function mount(Exam $record, int $sessionNumber, string $filePath): void
    {
        // Aumentar tiempo de ejecución para análisis de archivos grandes
        ini_set('max_execution_time', 300); // 5 minutos
        set_time_limit(300);

        $this->exam = $record;
        $this->sessionNumber = $sessionNumber;

        // Decodificar el path del archivo (viene codificado en base64 desde la URL)
        $decodedPath = base64_decode($filePath);
        $this->filePath = Storage::disk('public')->path($decodedPath);

        // Analizar el archivo para obtener tags nuevos
        // IMPORTANTE: Usar $this->filePath (decodificado), NO $filePath (codificado en base64)
        try {
            $this->newTags = ZipgradeTagsImport::analyzeFile($this->filePath);

            if (empty($this->newTags)) {
                // No hay tags nuevos, proceder directamente a la importación
                $this->proceedWithImport();

                return;
            }

            // Inicializar las clasificaciones
            foreach ($this->newTags as $index => $tag) {
                $this->tagClassifications[$index] = [
                    'csv_name' => $tag['csv_name'],
                    'tag_type' => $tag['suggested_type'] ?? 'componente',
                    'parent_area' => $tag['suggested_area'] ?? '',
                ];
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error al analizar archivo')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->redirect(ExamResource::getUrl('edit', ['record' => $record]));
        }
    }

    public function form(Form $form): Form
    {
        $schema = [];

        // Agregar checkbox para guardar normalizaciones
        $schema[] = Checkbox::make('saveNormalizations')
            ->label('Guardar esta configuración para futuras importaciones (crear normalizaciones)')
            ->default(true)
            ->live();

        // Agregar campos para cada tag nuevo
        foreach ($this->newTags as $index => $tag) {
            $schema[] = \Filament\Forms\Components\Section::make($tag['csv_name'])
                ->schema([
                    Select::make("tagClassifications.{$index}.tag_type")
                        ->label('Tipo de Tag')
                        ->options([
                            'area' => 'Área',
                            'competencia' => 'Competencia',
                            'componente' => 'Componente',
                            'tipo_texto' => 'Tipo de Texto',
                            'nivel_lectura' => 'Nivel de Lectura (Lectura Crítica)',
                            'parte' => 'Parte',
                        ])
                        ->default($tag['suggested_type'] ?? 'componente')
                        ->required()
                        ->helperText('Use "Nivel de Lectura" solo para tags de Lectura Crítica: Literal, Inferencial, Crítico'),

                    Select::make("tagClassifications.{$index}.parent_area")
                        ->label('Área Padre')
                        ->options([
                            'Ciencias' => 'Ciencias (Naturales)',
                            'Matemáticas' => 'Matemáticas',
                            'Sociales' => 'Ciencias Sociales',
                            'Lectura' => 'Lectura Crítica',
                            'Inglés' => 'Inglés',
                        ])
                        ->default($tag['suggested_area'] ?? '')
                        ->placeholder('Seleccione área padre (si aplica)')
                        ->helperText('Solo necesario si el tipo NO es "Área"'),
                ])
                ->columns(2);
        }

        return $form->schema($schema);
    }

    public function proceedWithImport(): void
    {
        // Aumentar tiempo de ejecución para archivos grandes
        ini_set('max_execution_time', 300); // 5 minutos
        set_time_limit(300);

        try {
            $session = ExamSession::firstOrCreate(
                ['exam_id' => $this->exam->id, 'session_number' => $this->sessionNumber],
                ['name' => "Sesión {$this->sessionNumber}"]
            );

            // Crear record de importación
            $import = ZipgradeImport::create([
                'exam_session_id' => $session->id,
                'filename' => basename($this->filePath),
                'total_rows' => 0,
                'status' => 'processing',
            ]);

            // Preparar los mapeos de tags para el import
            $tagMappings = [];
            foreach ($this->tagClassifications as $classification) {
                $tagMappings[$classification['csv_name']] = [
                    'tag_type' => $classification['tag_type'],
                    'parent_area' => $classification['parent_area'] ?: null,
                ];
            }

            // Procesar la importación
            $importClass = new ZipgradeTagsImport($session->id, $tagMappings);
            Excel::import($importClass, $this->filePath);

            // Guardar normalizaciones si el usuario lo solicitó
            if ($this->saveNormalizations) {
                foreach ($this->tagClassifications as $classification) {
                    \App\Models\TagNormalization::create([
                        'tag_csv_name' => $classification['csv_name'],
                        'tag_system_name' => $classification['csv_name'],
                        'tag_type' => $classification['tag_type'],
                        'parent_area' => $classification['parent_area'] ?: null,
                    ]);
                }
            }

            // Actualizar estadísticas de la sesión
            $session->refresh();
            $session->total_questions = $session->questions()->count();
            $session->save();

            // Marcar importación como completada
            $import->update([
                'status' => 'completed',
                'total_rows' => $importClass->getRowCount(),
            ]);

            Notification::make()
                ->title('Importación exitosa')
                ->body("Se importaron {$importClass->getStudentsCount()} estudiantes y {$session->total_questions} preguntas correctamente.")
                ->success()
                ->send();

            // Redirigir al listado de exámenes (página más ligera)
            $this->redirect(ExamResource::getUrl('index'));

        } catch (\Exception $e) {
            Notification::make()
                ->title('Error en la importación')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function cancel(): void
    {
        // Eliminar el archivo temporal si existe
        if (file_exists($this->filePath)) {
            unlink($this->filePath);
        }

        Notification::make()
            ->title('Importación cancelada')
            ->body('La importación fue cancelada por el usuario.')
            ->warning()
            ->send();

        $this->redirect(ExamResource::getUrl('edit', ['record' => $this->exam]));
    }

    public function getTitle(): string
    {
        return "⚠️ Tags Nuevos Detectados - Sesión {$this->sessionNumber}";
    }
}
