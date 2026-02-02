<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StudentResource\Pages;
use App\Imports\StudentsImport;
use App\Models\Student;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class StudentResource extends Resource
{
    protected static ?string $model = Student::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Estudiantes';

    protected static ?string $modelLabel = 'Estudiante';

    protected static ?string $pluralModelLabel = 'Estudiantes';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->label('Código')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('El código se genera automáticamente al crear el estudiante'),
                Forms\Components\TextInput::make('first_name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(100),
                Forms\Components\TextInput::make('last_name')
                    ->label('Apellido')
                    ->required()
                    ->maxLength(100),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('first_name')
                    ->label('Nombre')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('last_name')
                    ->label('Apellido')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Nombre Completo'),
                Tables\Columns\TextColumn::make('enrollments_count')
                    ->label('Matrículas')
                    ->counts('enrollments'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('has_enrollments')
                    ->label('Con matrículas')
                    ->query(fn ($query) => $query->has('enrollments')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('download_template')
                    ->label('Descargar Plantilla Excel')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('primary')
                    ->action(function () {
                        $spreadsheet = new Spreadsheet;
                        $sheet = $spreadsheet->getActiveSheet();

                        // Headers in Spanish (matching the import expectations)
                        // INCLUYE ZipgradeID - campo nuevo para vinculación con Zipgrade
                        $headers = [
                            'Nombre',
                            'Apellido',
                            'Documento',
                            'ZipgradeID',
                            'Año',
                            'Grado',
                            'Grupo',
                            'PIAR (SI/NO)',
                            'Estado (ACTIVE/INACTIVE)',
                        ];

                        // Set headers in first row
                        $col = 1;
                        foreach ($headers as $label) {
                            $sheet->setCellValueByColumnAndRow($col, 1, $label);
                            $col++;
                        }

                        // Style headers (bold)
                        $sheet->getStyle('A1:I1')->getFont()->setBold(true);

                        // Example data (in the same order as headers)
                        // ZipgradeID = ID interno que asigna Zipgrade a cada estudiante
                        $exampleData = [
                            ['SALOMÉ', 'ACEVEDO OCAMPO', '1234567890', '1', 2026, 11, '1', 'NO', 'ACTIVE'],
                            ['JUAN', 'PÉREZ GÓMEZ', '1098765432', '2', 2026, 11, '2', 'SI', 'ACTIVE'],
                        ];

                        // Add data rows
                        $row = 2;
                        foreach ($exampleData as $data) {
                            $col = 1;
                            foreach ($data as $value) {
                                $sheet->setCellValueByColumnAndRow($col, $row, $value);
                                $col++;
                            }
                            $row++;
                        }

                        // Auto-size columns
                        foreach (range('A', 'I') as $col) {
                            $sheet->getColumnDimension($col)->setAutoSize(true);
                        }

                        // Add helper comment to ZipgradeID column
                        $sheet->getComment('D1')->getText()->createTextRun(
                            'ZipgradeID: Es el número de identificación interno que Zipgrade asigna a cada estudiante. '.
                            'Se encuentra en la columna "StudentID" del CSV exportado desde Zipgrade.'
                        );

                        // Create temporary file
                        $tempFile = tempnam(sys_get_temp_dir(), 'plantilla_estudiantes_').'.xlsx';
                        $writer = new Xlsx($spreadsheet);
                        $writer->save($tempFile);

                        return response()->download($tempFile, 'plantilla_estudiantes.xlsx', [
                            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ])->deleteFileAfterSend();
                    }),
                Tables\Actions\Action::make('import_with_preview')
                    ->label('Importar Estudiantes (Con Verificación)')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('success')
                    ->modalHeading('Importar Estudiantes - Verificación')
                    ->modalSubmitActionLabel('Confirmar Importación')
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label('Archivo Excel o CSV')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'text/csv',
                                'text/plain',
                            ])
                            ->disk('public')
                            ->directory('imports')
                            ->visibility('private')
                            ->required()
                            ->helperText('Sube tu archivo y verifica que el sistema detecte correctamente los campos, especialmente PIAR (SI/NO)')
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if (! $state) {
                                    return;
                                }

                                $filePath = \Illuminate\Support\Facades\Storage::disk('public')->path($state);

                                // Read first 5 rows for preview
                                try {
                                    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
                                    $reader->setReadDataOnly(true);
                                    $spreadsheet = $reader->load($filePath);
                                    $sheet = $spreadsheet->getActiveSheet();
                                    $data = $sheet->toArray(null, true, true, true);

                                    // Get headers from first row
                                    $headers = array_shift($data);
                                    $preview = [];
                                    $rowNum = 2;

                                    foreach (array_slice($data, 0, 5) as $row) {
                                        if (empty($row['A'])) {
                                            continue;
                                        }

                                        // Try to find PIAR column
                                        $piarValue = 'NO ENCONTRADO';
                                        foreach ($headers as $col => $header) {
                                            $headerUpper = strtoupper(trim($header));
                                            if (strpos($headerUpper, 'PIAR') !== false) {
                                                $piarValue = trim($row[$col] ?? '');
                                                break;
                                            }
                                        }

                                        // Detectar ZipgradeID (columna D)
                                        $zipgradeId = '';
                                        foreach ($headers as $col => $header) {
                                            $headerUpper = strtoupper(trim($header));
                                            if (strpos($headerUpper, 'ZIPGRADE') !== false || strpos($headerUpper, 'ZIPGRADEID') !== false) {
                                                $zipgradeId = trim($row[$col] ?? '');
                                                break;
                                            }
                                        }

                                        $preview[] = [
                                            'row' => $rowNum,
                                            'nombre' => $row['A'] ?? '',
                                            'apellido' => $row['B'] ?? '',
                                            'documento' => $row['C'] ?? '',
                                            'zipgrade_id' => $zipgradeId ?: '—',
                                            'piar_raw' => $piarValue,
                                            'piar_detected' => strtoupper(trim($piarValue)) === 'SI' ? '✅ SI (PIAR)' : '❌ NO (No PIAR)',
                                        ];
                                        $rowNum++;
                                    }

                                    $set('preview_data', $preview);
                                    $set('headers_found', implode(', ', array_values($headers)));

                                } catch (\Exception $e) {
                                    $set('preview_error', $e->getMessage());
                                }
                            }),

                        Forms\Components\Section::make('Vista Previa (Primeras 5 filas)')
                            ->schema([
                                Forms\Components\Placeholder::make('headers_info')
                                    ->label('Columnas detectadas')
                                    ->content(fn ($get) => $get('headers_found') ?: 'Sube un archivo para ver las columnas'),

                                Forms\Components\Repeater::make('preview_data')
                                    ->label('Datos detectados')
                                    ->schema([
                                        Forms\Components\TextInput::make('row')->label('Fila')->disabled(),
                                        Forms\Components\TextInput::make('nombre')->label('Nombre')->disabled(),
                                        Forms\Components\TextInput::make('apellido')->label('Apellido')->disabled(),
                                        Forms\Components\TextInput::make('documento')->label('Documento')->disabled(),
                                        Forms\Components\TextInput::make('zipgrade_id')->label('ZipgradeID')->disabled(),
                                        Forms\Components\TextInput::make('piar_raw')->label('PIAR (original)')->disabled(),
                                        Forms\Components\TextInput::make('piar_detected')->label('PIAR (detectado)')->disabled(),
                                    ])
                                    ->columns(7)
                                    ->addable(false)
                                    ->deletable(false)
                                    ->reorderable(false),

                                Forms\Components\Placeholder::make('preview_instructions')
                                    ->label('Instrucciones')
                                    ->content('Verifica que: (1) La columna "ZipgradeID" muestre el número asignado por Zipgrade (requerido para importar resultados), (2) "PIAR (detectado)" muestre ✅ SI para estudiantes PIAR. Si hay problemas con la detección, revisa los nombres de columnas en tu archivo.')
                                    ->columnSpanFull(),
                            ])
                            ->visible(fn ($get) => ! empty($get('preview_data'))),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $filePath = \Illuminate\Support\Facades\Storage::disk('public')->path($data['file']);
                            Excel::import(new StudentsImport, $filePath);

                            Notification::make()
                                ->title('Importación exitosa')
                                ->success()
                                ->persistent()
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
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStudents::route('/'),
            'create' => Pages\CreateStudent::route('/create'),
            'edit' => Pages\EditStudent::route('/{record}/edit'),
        ];
    }
}
