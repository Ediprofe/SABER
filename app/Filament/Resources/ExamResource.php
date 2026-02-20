<?php

namespace App\Filament\Resources;

use App\Exports\ResultsTemplateExport;
use App\Filament\Resources\ExamResource\Pages;
use App\Imports\ResultsImport;
use App\Imports\ZipgradeTagsImport;
use App\Models\Exam;
use App\Models\ExamAreaConfig;
use App\Services\ZipgradeImportPipelineService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExamResource extends Resource
{
    protected static ?string $model = Exam::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Exámenes';

    protected static ?string $modelLabel = 'Examen';

    protected static ?string $pluralModelLabel = 'Exámenes';

    protected static ?string $navigationGroup = 'Evaluaciones';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('academic_year_id')
                    ->label('Año Académico')
                    ->relationship('academicYear', 'year')
                    ->required(),
                Forms\Components\TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(150)
                    ->placeholder('Ej: Simulacro Único 2025'),
                Forms\Components\Select::make('type')
                    ->label('Tipo')
                    ->options([
                        'SIMULACRO' => 'Simulacro',
                        'ICFES' => 'ICFES',
                    ])
                    ->required(),
                Forms\Components\DatePicker::make('date')
                    ->label('Fecha')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('academicYear.year')
                    ->label('Año Académico')
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('type')
                    ->label('Tipo')
                    ->colors([
                        'primary' => 'SIMULACRO',
                        'success' => 'ICFES',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'SIMULACRO' => 'Simulacro',
                        'ICFES' => 'ICFES',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('zipgrade_pipeline')
                    ->label('Pipeline Zipgrade')
                    ->state(function (Exam $record): string {
                        $completedSessions = $record->sessions()
                            ->whereHas('imports', fn ($query) => $query->where('status', 'completed'))
                            ->count();

                        return "{$completedSessions}/2 sesiones";
                    })
                    ->badge()
                    ->color(fn (string $state): string => str_starts_with($state, '2/') ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('academic_year_id')
                    ->label('Año Académico')
                    ->relationship('academicYear', 'year'),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options([
                        'SIMULACRO' => 'Simulacro',
                        'ICFES' => 'ICFES',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('open_pipeline')
                    ->label('Pipeline de Carga')
                    ->icon('heroicon-o-queue-list')
                    ->color('primary')
                    ->url(fn (Exam $record) => static::getUrl('pipeline', ['record' => $record])),
                Tables\Actions\Action::make('open_results')
                    ->label('Resultados')
                    ->icon('heroicon-o-table-cells')
                    ->color('success')
                    ->visible(fn (Exam $record) => $record->hasSessions())
                    ->url(fn (Exam $record) => static::getUrl('zipgrade-results', ['record' => $record])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\Action::make('configure_areas')
                    ->label('Configurar Dimensiones')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->color('secondary')
                    ->modalHeading('Configurar Análisis Detallado')
                    ->modalSubmitActionLabel('Guardar Configuración')
                    ->mountUsing(function (Forms\ComponentContainer $form, Exam $record) {
                        $areas = ['naturales', 'matematicas', 'sociales', 'lectura', 'ingles'];
                        $formData = [];

                        foreach ($areas as $area) {
                            $config = $record->getDetailConfig($area);

                            if ($config) {
                                $formData[$area] = [
                                    'enabled' => true,
                                    'dimension1_name' => $config->dimension1_name,
                                    'dimension1_items' => $config->itemsDimension1->map(fn ($item) => [
                                        'name' => $item->name,
                                    ])->toArray(),
                                ];

                                if ($area !== 'ingles' && $config->hasDimension2()) {
                                    $formData[$area]['dimension2_name'] = $config->dimension2_name;
                                    $formData[$area]['dimension2_items'] = $config->itemsDimension2->map(fn ($item) => [
                                        'name' => $item->name,
                                    ])->toArray();
                                }
                            } else {
                                // Set defaults for non-configured areas
                                $formData[$area] = [
                                    'enabled' => false,
                                ];
                            }
                        }

                        $form->fill($formData);
                    })
                    ->form([
                        Forms\Components\Tabs::make('areas')
                            ->tabs([
                                self::getAreaTab('naturales', 'Ciencias Naturales'),
                                self::getAreaTab('matematicas', 'Matemáticas'),
                                self::getAreaTab('sociales', 'Ciencias Sociales'),
                                self::getAreaTab('lectura', 'Lectura Crítica'),
                                self::getAreaTab('ingles', 'Inglés'),
                            ]),
                    ])
                    ->action(function (Exam $record, array $data) {
                        DB::transaction(function () use ($record, $data) {
                            $areas = ['naturales', 'matematicas', 'sociales', 'lectura', 'ingles'];

                            foreach ($areas as $area) {
                                $areaData = $data[$area] ?? [];
                                $enabled = $areaData['enabled'] ?? false;

                                // Find existing config
                                $existingConfig = $record->getDetailConfig($area);

                                if (! $enabled) {
                                    if ($existingConfig) {
                                        $existingConfig->delete();
                                    }

                                    continue;
                                }

                                // Create or update config
                                $config = $existingConfig ?? new ExamAreaConfig([
                                    'exam_id' => $record->id,
                                    'area' => $area,
                                ]);

                                $config->dimension1_name = $areaData['dimension1_name'] ?? 'Competencias';
                                if ($area !== 'ingles') {
                                    $config->dimension2_name = $areaData['dimension2_name'] ?? 'Componentes';
                                } else {
                                    $config->dimension2_name = null;
                                }
                                $config->save();

                                // Remove existing items
                                $config->items()->delete();

                                // Add dimension 1 items
                                $dim1Items = $areaData['dimension1_items'] ?? [];
                                foreach ($dim1Items as $index => $item) {
                                    if (! empty($item['name'])) {
                                        $config->items()->create([
                                            'dimension' => 1,
                                            'name' => $item['name'],
                                            'order' => $index,
                                        ]);
                                    }
                                }

                                // Add dimension 2 items (except ingles)
                                if ($area !== 'ingles') {
                                    $dim2Items = $areaData['dimension2_items'] ?? [];
                                    foreach ($dim2Items as $index => $item) {
                                        if (! empty($item['name'])) {
                                            $config->items()->create([
                                                'dimension' => 2,
                                                'name' => $item['name'],
                                                'order' => $index,
                                            ]);
                                        }
                                    }
                                }
                            }
                        });

                        Notification::make()
                            ->title('Configuración guardada exitosamente')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('export_template')
                    ->label('Exportar Plantilla')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('warning')
                    ->hidden()
                    ->form([
                        Forms\Components\Select::make('grade')
                            ->label('Grado')
                            ->options([
                                10 => '10°',
                                11 => '11°',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('group')
                            ->label('Grupo (opcional)')
                            ->placeholder('Ej: 10-1, 11-2')
                            ->helperText('Deje en blanco para exportar todos los grupos'),
                    ])
                    ->action(function (Exam $record, array $data) {
                        // Check if there are students enrolled in the academic year for the selected grade
                        $yearId = $record->academic_year_id;
                        $grade = $data['grade'];
                        $year = $record->academicYear->year;

                        $studentCount = \App\Models\Enrollment::where('academic_year_id', $yearId)
                            ->where('grade', $grade)
                            ->where('status', 'ACTIVE')
                            ->count();

                        if ($studentCount === 0) {
                            Notification::make()
                                ->title('No hay estudiantes matriculados')
                                ->body("No hay estudiantes matriculados en el año {$year} para el grado {$grade}°. Por favor, registre estudiantes primero.")
                                ->danger()
                                ->send();

                            return;
                        }

                        $export = new ResultsTemplateExport(
                            $record,
                            $data['grade'],
                            $data['group'] ?? null
                        );

                        $filename = 'plantilla_resultados_'.str_replace(' ', '_', $record->name)."_grado{$data['grade']}.xlsx";

                        return Excel::download($export, $filename);
                    }),
                Tables\Actions\Action::make('import_results')
                    ->label('Importar Resultados')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('success')
                    ->hidden()
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label('Archivo Excel')
                            ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'])
                            ->disk('public')
                            ->directory('imports')
                            ->visibility('private')
                            ->required(),
                    ])
                    ->action(function (Exam $record, array $data): void {
                        try {
                            // Usar Storage para obtener el path correcto
                            $filePath = Storage::disk('public')->path($data['file']);

                            if (! file_exists($filePath)) {
                                Notification::make()
                                    ->title('Error en la importación')
                                    ->body('No se pudo encontrar el archivo subido. Por favor, intente subir el archivo nuevamente.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            // Use PhpSpreadsheet IOFactory to read multi-sheet Excel files
                            $reader = IOFactory::createReaderForFile($filePath);
                            $reader->setReadDataOnly(true);
                            $spreadsheet = $reader->load($filePath);

                            $totalImportedCount = 0;
                            $allErrors = [];
                            $allWarnings = [];
                            $hasErrors = false;

                            // Importar envuelto en transacción para permitir rollback en caso de errores
                            DB::transaction(function () use ($spreadsheet, $record, &$totalImportedCount, &$allErrors, &$allWarnings, &$hasErrors) {
                                $sheetCount = $spreadsheet->getSheetCount();

                                for ($sheetIndex = 0; $sheetIndex < $sheetCount; $sheetIndex++) {
                                    $sheet = $spreadsheet->getSheet($sheetIndex);
                                    $sheetName = $sheet->getTitle();

                                    // Get sheet data as array, starting from header row
                                    $sheetData = $sheet->toArray(null, true, true, true);

                                    // Skip empty sheets
                                    if (count($sheetData) < 2) {
                                        $allWarnings[] = "Hoja '{$sheetName}': La hoja está vacía o solo tiene encabezados. Se ignora.";

                                        continue;
                                    }

                                    // Convert to collection (skip header row at index 1, start from index 2)
                                    $rows = new Collection;
                                    $headerRow = $sheetData[1];

                                    for ($rowIndex = 2; $rowIndex <= count($sheetData); $rowIndex++) {
                                        if (! isset($sheetData[$rowIndex])) {
                                            continue;
                                        }

                                        $rowData = $sheetData[$rowIndex];
                                        $row = [];

                                        // Map column letters to header names
                                        foreach ($headerRow as $col => $header) {
                                            $headerKey = strtolower(trim($header));
                                            $row[$headerKey] = $rowData[$col] ?? null;
                                        }

                                        // Skip empty rows (no code)
                                        if (empty($row['codigo'] ?? $row['code'] ?? null)) {
                                            continue;
                                        }

                                        $rows->push($row);
                                    }

                                    // Skip if no data rows
                                    if ($rows->isEmpty()) {
                                        $allWarnings[] = "Hoja '{$sheetName}': No se encontraron filas con datos. Se ignora.";

                                        continue;
                                    }

                                    // Create import instance and process this sheet
                                    $import = new ResultsImport($record);
                                    $import->setSheetName($sheetName);
                                    $import->collection($rows);

                                    // Accumulate results
                                    $totalImportedCount += $import->getImportedCount();
                                    $allWarnings = array_merge($allWarnings, $import->getWarnings());

                                    if ($import->hasErrors()) {
                                        $hasErrors = true;
                                        $sheetErrors = $import->getErrors();
                                        $allErrors = array_merge($allErrors, $sheetErrors);
                                    }
                                }

                                // If any sheet has errors, throw exception to trigger rollback
                                if ($hasErrors) {
                                    $errorMessage = "Error en la importación:\n";
                                    foreach ($allErrors as $error) {
                                        $errorMessage .= "- {$error}\n";
                                    }
                                    $errorMessage .= "\nNo se importó ningún registro. Corrija los errores e intente nuevamente.";

                                    throw new \Exception($errorMessage);
                                }
                            });

                            // Show success notification with totals
                            if (! empty($allWarnings)) {
                                Notification::make()
                                    ->title('Importación completada con advertencias')
                                    ->body("Se importaron {$totalImportedCount} registros correctamente.\n\nAdvertencias:\n".implode("\n", $allWarnings))
                                    ->warning()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Importación exitosa')
                                    ->body("Se importaron {$totalImportedCount} registros correctamente desde todas las hojas.")
                                    ->success()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Error en la importación')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('import_session1')
                    ->label('Paso 1A: Importar Tags Sesión 1')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('success')
                    ->hidden()
                    ->modalHeading('Importar Sesión 1')
                    ->modalDescription('Seleccione el archivo CSV de Zipgrade con los tags de la sesión 1. Si hay tags nuevos, se mostrará una pantalla para clasificarlos antes de completar la importación.')
                    ->modalSubmitActionLabel('Analizar y Continuar')
                    ->form([
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
                            ->maxSize(10240) // 10MB
                            ->required()
                            ->helperText('Archivos CSV de hasta 10MB'),
                    ])
                    ->action(fn (Exam $record, array $data) => static::handleSessionTagsUpload($record, $data, 1)),

                Tables\Actions\Action::make('import_session2')
                    ->label('Paso 1B: Importar Tags Sesión 2')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('warning')
                    ->hidden()
                    ->modalHeading('Importar Sesión 2')
                    ->modalDescription('Seleccione el archivo CSV de Zipgrade con los tags de la sesión 2. Si hay tags nuevos, se mostrará una pantalla para clasificarlos antes de completar la importación.')
                    ->modalSubmitActionLabel('Analizar y Continuar')
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label('Archivo CSV de Zipgrade (Tags)')
                            ->acceptedFileTypes([]) // Sin validación estricta de MIME type
                            ->disk('public')
                            ->directory('zipgrade_imports')
                            ->visibility('private')
                            ->maxSize(20480) // 20MB
                            ->required()
                            ->helperText('Archivos CSV de hasta 20MB. Si tiene problemas, asegúrese de que el archivo tenga extensión .csv'),
                    ])
                    ->action(fn (Exam $record, array $data) => static::handleSessionTagsUpload($record, $data, 2)),

                Tables\Actions\Action::make('import_session1_from_storage')
                    ->label('Importar Sesión 1 (desde Storage)')
                    ->icon('heroicon-o-folder-open')
                    ->color('warning')
                    ->hidden()
                    ->form([
                        Forms\Components\TextInput::make('filename')
                            ->label('Nombre del archivo')
                            ->helperText('Coloque el archivo CSV en storage/app/zipgrade-imports/ y escriba el nombre aquí (ej: sesion1.csv)')
                            ->required()
                            ->placeholder('sesion1.csv'),
                    ])
                    ->action(function (Exam $record, array $data) {
                        set_time_limit(600); // 10 minutos para archivos grandes
                        $filePath = storage_path('app/zipgrade-imports/' . $data['filename']);

                        if (!file_exists($filePath)) {
                            Notification::make()
                                ->title('Archivo no encontrado')
                                ->body("No se encontró el archivo: {$data['filename']} en storage/app/zipgrade-imports/")
                                ->danger()
                                ->send();
                            return;
                        }

                        // Obtener o crear sesión 1
                        $session = $record->sessions()->firstOrCreate(
                            ['session_number' => 1],
                            ['name' => 'Sesión 1']
                        );

                        try {
                            // Analizar tags primero
                            $newTags = ZipgradeTagsImport::analyzeFile($filePath);

                            if (!empty($newTags)) {
                                // Si hay tags nuevos, mostrar advertencia
                                $tagNames = collect($newTags)->pluck('csv_name')->implode(', ');
                                Notification::make()
                                    ->title('Tags nuevos detectados')
                                    ->body("Hay tags que necesitan clasificación: {$tagNames}. Configure los tags primero en Jerarquía de Tags.")
                                    ->warning()
                                    ->persistent()
                                    ->send();
                                return;
                            }

                            // Importar directamente
                            $import = new ZipgradeTagsImport($session->id, []);
                            Excel::import($import, $filePath);

                            // Actualizar contador
                            $questionCount = $session->questions()->count();
                            $session->update(['total_questions' => $questionCount]);

                            // Contar estudiantes importados
                            $studentCount = \App\Models\StudentAnswer::whereHas('question', fn($q) => $q->where('exam_session_id', $session->id))
                                ->distinct('enrollment_id')
                                ->count('enrollment_id');

                            Notification::make()
                                ->title('Importación exitosa')
                                ->body("Se importaron {$studentCount} estudiantes y {$questionCount} preguntas desde storage.")
                                ->success()
                                ->send();

                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Error en la importación')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('import_session2_from_storage')
                    ->label('Importar Sesión 2 (desde Storage)')
                    ->icon('heroicon-o-folder-open')
                    ->color('warning')
                    ->hidden()
                    ->form([
                        Forms\Components\TextInput::make('filename')
                            ->label('Nombre del archivo')
                            ->helperText('Coloque el archivo CSV en storage/app/zipgrade-imports/ y escriba el nombre aquí (ej: sesion2.csv)')
                            ->required()
                            ->placeholder('sesion2.csv'),
                    ])
                    ->action(function (Exam $record, array $data) {
                        set_time_limit(600); // 10 minutos para archivos grandes
                        $filePath = storage_path('app/zipgrade-imports/' . $data['filename']);

                        if (!file_exists($filePath)) {
                            Notification::make()
                                ->title('Archivo no encontrado')
                                ->body("No se encontró el archivo: {$data['filename']} en storage/app/zipgrade-imports/")
                                ->danger()
                                ->send();
                            return;
                        }

                        // Obtener o crear sesión 2
                        $session = $record->sessions()->firstOrCreate(
                            ['session_number' => 2],
                            ['name' => 'Sesión 2']
                        );

                        try {
                            // Analizar tags primero
                            $newTags = ZipgradeTagsImport::analyzeFile($filePath);

                            if (!empty($newTags)) {
                                $tagNames = collect($newTags)->pluck('csv_name')->implode(', ');
                                Notification::make()
                                    ->title('Tags nuevos detectados')
                                    ->body("Hay tags que necesitan clasificación: {$tagNames}. Configure los tags primero en Jerarquía de Tags.")
                                    ->warning()
                                    ->persistent()
                                    ->send();
                                return;
                            }

                            // Importar directamente
                            $import = new ZipgradeTagsImport($session->id, []);
                            Excel::import($import, $filePath);

                            // Actualizar contador
                            $questionCount = $session->questions()->count();
                            $session->update(['total_questions' => $questionCount]);

                            // Contar estudiantes importados
                            $studentCount = \App\Models\StudentAnswer::whereHas('question', fn($q) => $q->where('exam_session_id', $session->id))
                                ->distinct('enrollment_id')
                                ->count('enrollment_id');

                            Notification::make()
                                ->title('Importación exitosa')
                                ->body("Se importaron {$studentCount} estudiantes y {$questionCount} preguntas desde storage.")
                                ->success()
                                ->send();

                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Error en la importación')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('import_stats_session1')
                    ->label('Paso 2A: Importar Stats Sesión 1')
                    ->icon('heroicon-o-chart-bar')
                    ->color('info')
                    ->hidden()
                    ->modalHeading('Importar Estadísticas Sesión 1')
                    ->modalDescription('Seleccione el archivo Excel con las estadísticas de Zipgrade para la sesión 1.')
                    ->modalSubmitActionLabel('Importar Stats')
                    ->visible(fn (Exam $record) => $record->hasSessions())
                    ->form([
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
                    ])
                    ->action(fn (Exam $record, array $data) => static::handleSessionStatsUpload($record, $data, 1)),

                Tables\Actions\Action::make('import_stats_session2')
                    ->label('Paso 2B: Importar Stats Sesión 2')
                    ->icon('heroicon-o-chart-bar')
                    ->color('secondary')
                    ->hidden()
                    ->modalHeading('Importar Estadísticas Sesión 2')
                    ->modalDescription('Seleccione el archivo Excel con las estadísticas de Zipgrade para la sesión 2.')
                    ->modalSubmitActionLabel('Importar Stats')
                    ->visible(fn (Exam $record) => $record->hasSessions())
                    ->form([
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
                    ])
                    ->action(fn (Exam $record, array $data) => static::handleSessionStatsUpload($record, $data, 2)),

                Tables\Actions\Action::make('clear_session1')
                    ->label('Limpiar Sesión 1')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->hidden()
                    ->requiresConfirmation()
                    ->modalHeading('¿Limpiar datos de Sesión 1?')
                    ->modalDescription('Esto eliminará todas las preguntas, tags y respuestas de la Sesión 1. Los estudiantes y sus matrículas NO se eliminarán. Esta acción no se puede deshacer.')
                    ->modalSubmitActionLabel('Sí, limpiar Sesión 1')
                    ->visible(fn (Exam $record): bool => $record->sessions()->where('session_number', 1)->exists())
                    ->action(function (Exam $record) {
                        $session = $record->sessions()->where('session_number', 1)->first();
                        if ($session) {
                            // Eliminar respuestas de estudiantes de esta sesión
                            DB::table('student_answers')
                                ->whereIn('exam_question_id', function ($query) use ($session) {
                                    $query->select('id')
                                        ->from('exam_questions')
                                        ->where('exam_session_id', $session->id);
                                })
                                ->delete();

                            // Eliminar question_tags de esta sesión
                            DB::table('question_tags')
                                ->whereIn('exam_question_id', function ($query) use ($session) {
                                    $query->select('id')
                                        ->from('exam_questions')
                                        ->where('exam_session_id', $session->id);
                                })
                                ->delete();

                            // Eliminar preguntas de esta sesión
                            $session->questions()->delete();

                            // Resetear contador de preguntas
                            $session->update(['total_questions' => 0]);

                            // Eliminar registros de importación
                            $session->imports()->delete();

                            Notification::make()
                                ->title('Sesión 1 limpiada')
                                ->body('Puede reimportar los datos de la Sesión 1.')
                                ->success()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('clear_session2')
                    ->label('Limpiar Sesión 2')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->hidden()
                    ->requiresConfirmation()
                    ->modalHeading('¿Limpiar datos de Sesión 2?')
                    ->modalDescription('Esto eliminará todas las preguntas, tags y respuestas de la Sesión 2. Los estudiantes y sus matrículas NO se eliminarán. Esta acción no se puede deshacer.')
                    ->modalSubmitActionLabel('Sí, limpiar Sesión 2')
                    ->visible(fn (Exam $record): bool => $record->sessions()->where('session_number', 2)->exists())
                    ->action(function (Exam $record) {
                        $session = $record->sessions()->where('session_number', 2)->first();
                        if ($session) {
                            // Eliminar respuestas de estudiantes de esta sesión
                            DB::table('student_answers')
                                ->whereIn('exam_question_id', function ($query) use ($session) {
                                    $query->select('id')
                                        ->from('exam_questions')
                                        ->where('exam_session_id', $session->id);
                                })
                                ->delete();

                            // Eliminar question_tags de esta sesión
                            DB::table('question_tags')
                                ->whereIn('exam_question_id', function ($query) use ($session) {
                                    $query->select('id')
                                        ->from('exam_questions')
                                        ->where('exam_session_id', $session->id);
                                })
                                ->delete();

                            // Eliminar preguntas de esta sesión
                            $session->questions()->delete();

                            // Resetear contador de preguntas
                            $session->update(['total_questions' => 0]);

                            // Eliminar registros de importación
                            $session->imports()->delete();

                            Notification::make()
                                ->title('Sesión 2 limpiada')
                                ->body('Puede reimportar los datos de la Sesión 2.')
                                ->success()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('clear_all_sessions')
                    ->label('Limpiar Todo')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->hidden()
                    ->requiresConfirmation()
                    ->modalHeading('¿Limpiar TODAS las sesiones?')
                    ->modalDescription('Esto eliminará TODAS las preguntas, tags y respuestas de AMBAS sesiones. Los estudiantes y sus matrículas NO se eliminarán. Esta acción no se puede deshacer.')
                    ->modalSubmitActionLabel('Sí, limpiar todo')
                    ->visible(fn (Exam $record): bool => $record->sessions()->exists())
                    ->action(function (Exam $record) {
                        foreach ($record->sessions as $session) {
                            // Eliminar respuestas
                            DB::table('student_answers')
                                ->whereIn('exam_question_id', function ($query) use ($session) {
                                    $query->select('id')
                                        ->from('exam_questions')
                                        ->where('exam_session_id', $session->id);
                                })
                                ->delete();

                            // Eliminar question_tags
                            DB::table('question_tags')
                                ->whereIn('exam_question_id', function ($query) use ($session) {
                                    $query->select('id')
                                        ->from('exam_questions')
                                        ->where('exam_session_id', $session->id);
                                })
                                ->delete();

                            // Eliminar preguntas
                            $session->questions()->delete();
                            $session->update(['total_questions' => 0]);
                            $session->imports()->delete();
                        }

                        Notification::make()
                            ->title('Todas las sesiones limpiadas')
                            ->body('Puede reimportar los datos de ambas sesiones.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('view_zipgrade_results')
                    ->label('Paso 3: Ver Resultados y Reportes')
                    ->icon('heroicon-o-table-cells')
                    ->color('success')
                    ->hidden()
                    ->visible(fn (Exam $record) => $record->hasSessions())
                    ->url(fn (Exam $record) => route('filament.admin.resources.exams.zipgrade-results', ['record' => $record])),

                Tables\Actions\Action::make('generate_report')
                    ->label('Generar Informe')
                    ->icon('heroicon-o-document-chart-bar')
                    ->color('info')
                    ->hidden()
                    ->form([
                        Forms\Components\Select::make('grade')
                            ->label('Grado (opcional)')
                            ->options([
                                10 => '10°',
                                11 => '11°',
                            ])
                            ->placeholder('Todos los grados'),
                        Forms\Components\TextInput::make('group')
                            ->label('Grupo (opcional)')
                            ->placeholder('Ej: 10-1, 11-2'),
                    ])
                    ->action(function (Exam $record, array $data) {
                        $generator = app(\App\Services\ReportGenerator::class);

                        $grade = $data['grade'] ?? null;
                        $group = $data['group'] ?? null;

                        $html = $generator->generateHtmlReport($record, $grade, $group);
                        $filename = $generator->getReportFilename($record, $grade, $group);

                        // Store to temp file and return download
                        $tempPath = storage_path('app/temp_'.uniqid().'.html');
                        file_put_contents($tempPath, $html);

                        return response()->download($tempPath, $filename, [
                            'Content-Type' => 'text/html',
                        ])->deleteFileAfterSend();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExams::route('/'),
            'create' => Pages\CreateExam::route('/create'),
            'edit' => Pages\EditExam::route('/{record}/edit'),
            'pipeline' => Pages\Pipeline::route('/{record}/pipeline'),
            'zipgrade-results' => Pages\ZipgradeResults::route('/{record}/zipgrade-results'),
            'classify-tags' => Pages\ClassifyTags::route('/{record}/classify-tags/{sessionNumber}/{filePath}'),
        ];
    }

    private static function handleSessionTagsUpload(Exam $record, array $data, int $sessionNumber): mixed
    {
        try {
            $result = app(ZipgradeImportPipelineService::class)
                ->importSessionTagsFromUploadedFile($record, $sessionNumber, $data['file']);

            if ($result['needs_classification'] ?? false) {
                return redirect()->to(
                    static::getUrl('classify-tags', [
                        'record' => $record,
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

    private static function handleSessionStatsUpload(Exam $record, array $data, int $sessionNumber): void
    {
        try {
            $service = app(ZipgradeImportPipelineService::class);
            $filePath = $service->getUploadedFilePath($data['file']);
            static::processZipgradeStatsImport($record, $sessionNumber, $filePath);
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error en la importación')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Procesa la importación de estadísticas de Zipgrade.
     */
    public static function processZipgradeStatsImport(Exam $exam, int $sessionNumber, string $filePath): void
    {
        try {
            $processedCount = app(ZipgradeImportPipelineService::class)
                ->processZipgradeStatsImport($exam, $sessionNumber, $filePath);

            Notification::make()
                ->title('Importación de estadísticas exitosa')
                ->body("Se importaron estadísticas para {$processedCount} preguntas de la sesión {$sessionNumber}.")
                ->success()
                ->send();

        } catch (\Exception $e) {
            Notification::make()
                ->title('Error en la importación de estadísticas')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Procesa la importación de datos de Zipgrade.
     */
    public static function processZipgradeImport(Exam $exam, int $sessionNumber, string $filePath): void
    {
        try {
            $imported = app(ZipgradeImportPipelineService::class)
                ->processZipgradeImport($exam, $sessionNumber, $filePath);

            $newTags = $imported['new_tags'] ?? [];
            $studentsCount = $imported['students_count'] ?? 0;
            $questionsCount = $imported['questions_count'] ?? 0;

            // Check for new tags
            if (! empty($newTags)) {
                $tagList = implode(', ', array_slice($newTags, 0, 5));
                if (count($newTags) > 5) {
                    $tagList .= ' y '.(count($newTags) - 5).' más...';
                }

                Notification::make()
                    ->title('Importación completada con tags nuevos')
                    ->body("Se importaron {$studentsCount} estudiantes y {$questionsCount} preguntas.\n\nTags nuevos detectados: {$tagList}\n\nPor favor, configure estos tags en el menú 'Jerarquía de Tags' antes de calcular resultados.")
                    ->warning()
                    ->persistent()
                    ->send();
            } else {
                Notification::make()
                    ->title('Importación exitosa')
                    ->body("Se importaron {$studentsCount} estudiantes y {$questionsCount} preguntas correctamente.")
                    ->success()
                    ->send();
            }

        } catch (\Exception $e) {
            Notification::make()
                ->title('Error en la importación')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    private static function getAreaTab(string $area, string $label): Forms\Components\Tabs\Tab
    {
        $defaultDim1Name = match ($area) {
            'ingles' => 'Partes',
            default => 'Competencias',
        };

        $defaultDim2Name = match ($area) {
            'lectura' => 'Tipos de Texto',
            'matematicas', 'sociales', 'naturales' => 'Componentes',
            'ingles' => null,
            default => 'Componentes',
        };

        return Forms\Components\Tabs\Tab::make($label)
            ->schema([
                Forms\Components\Toggle::make("{$area}.enabled")
                    ->label('Activar análisis detallado')
                    ->live(),

                Forms\Components\TextInput::make("{$area}.dimension1_name")
                    ->label('Nombre Dimensión 1')
                    ->default($defaultDim1Name)
                    ->visible(fn (callable $get) => $get("{$area}.enabled")),

                Forms\Components\Repeater::make("{$area}.dimension1_items")
                    ->label('Items Dimensión 1')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre')
                            ->required(),
                    ])
                    ->addable()
                    ->deletable()
                    ->reorderable()
                    ->visible(fn (callable $get) => $get("{$area}.enabled")),

                Forms\Components\TextInput::make("{$area}.dimension2_name")
                    ->label('Nombre Dimensión 2')
                    ->default($defaultDim2Name)
                    ->visible(fn (callable $get) => $get("{$area}.enabled") && $area !== 'ingles'),

                Forms\Components\Repeater::make("{$area}.dimension2_items")
                    ->label('Items Dimensión 2')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre')
                            ->required(),
                    ])
                    ->addable()
                    ->deletable()
                    ->reorderable()
                    ->visible(fn (callable $get) => $get("{$area}.enabled") && $area !== 'ingles'),
            ]);
    }
}
