<?php

namespace App\Filament\Resources;

use App\Exports\ResultsTemplateExport;
use App\Filament\Resources\ExamResource\Pages;
use App\Imports\ResultsImport;
use App\Imports\ZipgradeTagsImport;
use App\Models\Exam;
use App\Models\ExamAreaConfig;
use App\Models\ExamSession;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExamResource extends Resource
{
    protected static ?string $model = Exam::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Exámenes';

    protected static ?string $modelLabel = 'Examen';

    protected static ?string $pluralModelLabel = 'Exámenes';

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
                Tables\Columns\TextColumn::make('examResults_count')
                    ->label('Resultados')
                    ->counts('examResults'),
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
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\Action::make('configure_areas')
                    ->label('Configurar Análisis Detallado')
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
                            $filePath = \Illuminate\Support\Facades\Storage::disk('public')->path($data['file']);

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
                    ->label('Importar Sesión 1')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Importar Sesión 1')
                    ->modalDescription('Esta acción importará los datos de la sesión 1 desde el archivo pre-generado.')
                    ->modalSubmitActionLabel('Importar')
                    ->action(function (Exam $record) {
                        $filePath = storage_path('app/zipgrade_test/zipgrade_sesion1_prueba.csv');
                        if (! file_exists($filePath)) {
                            Notification::make()
                                ->title('Archivo no encontrado')
                                ->body('El archivo zipgrade_sesion1_prueba.csv no existe. Genere los datos de prueba primero.')
                                ->danger()
                                ->send();

                            return;
                        }

                        return static::processZipgradeImport($record, 1, $filePath);
                    }),

                Tables\Actions\Action::make('import_session2')
                    ->label('Importar Sesión 2')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Importar Sesión 2')
                    ->modalDescription('Esta acción importará los datos de la sesión 2 desde el archivo pre-generado.')
                    ->modalSubmitActionLabel('Importar')
                    ->action(function (Exam $record) {
                        $filePath = storage_path('app/zipgrade_test/zipgrade_sesion2_prueba.csv');
                        if (! file_exists($filePath)) {
                            Notification::make()
                                ->title('Archivo no encontrado')
                                ->body('El archivo zipgrade_sesion2_prueba.csv no existe. Genere los datos de prueba primero.')
                                ->danger()
                                ->send();

                            return;
                        }

                        return static::processZipgradeImport($record, 2, $filePath);
                    }),

                Tables\Actions\Action::make('view_zipgrade_results')
                    ->label('Ver Resultados Zipgrade')
                    ->icon('heroicon-o-table-cells')
                    ->color('primary')
                    ->visible(fn (Exam $record) => $record->hasSessions())
                    ->url(fn (Exam $record) => route('filament.admin.resources.exams.zipgrade-results', ['record' => $record])),

                Tables\Actions\Action::make('generate_report')
                    ->label('Generar Informe')
                    ->icon('heroicon-o-document-chart-bar')
                    ->color('info')
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
            'zipgrade-results' => Pages\ZipgradeResults::route('/{record}/zipgrade-results'),
        ];
    }

    /**
     * Procesa la importación de datos de Zipgrade.
     */
    private static function processZipgradeImport(Exam $exam, int $sessionNumber, string $filePath): void
    {
        // Increase execution time for large files
        ini_set('max_execution_time', 300); // 5 minutes
        set_time_limit(300);

        $session = ExamSession::firstOrCreate(
            ['exam_id' => $exam->id, 'session_number' => $sessionNumber],
            ['name' => "Sesión {$sessionNumber}"]
        );

        // Create import record
        $import = \App\Models\ZipgradeImport::create([
            'exam_session_id' => $session->id,
            'filename' => basename($filePath),
            'total_rows' => 0,
            'status' => 'processing',
        ]);

        try {
            if (! file_exists($filePath)) {
                throw new \Exception('No se pudo encontrar el archivo: '.$filePath);
            }

            // Process the import
            $importClass = new ZipgradeTagsImport($session->id, []);
            Excel::import($importClass, $filePath);

            // Update session stats
            $session->refresh();
            $session->total_questions = $session->questions()->count();
            $session->save();

            // Mark import as completed
            $import->update([
                'status' => 'completed',
                'total_rows' => $importClass->getRowCount(),
            ]);

            // Check for new tags
            if ($importClass->hasNewTags()) {
                $newTags = $importClass->getNewTags();
                $tagList = implode(', ', array_slice($newTags, 0, 5));
                if (count($newTags) > 5) {
                    $tagList .= ' y '.(count($newTags) - 5).' más...';
                }

                Notification::make()
                    ->title('Importación completada con tags nuevos')
                    ->body("Se importaron {$importClass->getStudentsCount()} estudiantes y {$session->total_questions} preguntas.\n\nTags nuevos detectados: {$tagList}\n\nPor favor, configure estos tags en el menú 'Jerarquía de Tags' antes de calcular resultados.")
                    ->warning()
                    ->persistent()
                    ->send();
            } else {
                Notification::make()
                    ->title('Importación exitosa')
                    ->body("Se importaron {$importClass->getStudentsCount()} estudiantes y {$session->total_questions} preguntas correctamente.")
                    ->success()
                    ->send();
            }

        } catch (\Exception $e) {
            $import->markAsError($e->getMessage());

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
