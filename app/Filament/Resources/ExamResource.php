<?php

namespace App\Filament\Resources;

use App\Exports\ResultsTemplateExport;
use App\Filament\Resources\ExamResource\Pages;
use App\Imports\ResultsImport;
use App\Models\Exam;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Maatwebsite\Excel\Facades\Excel;

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
                            ->required(),
                    ])
                    ->action(function (Exam $record, array $data): void {
                        try {
                            $import = new ResultsImport($record);
                            Excel::import($import, $data['file']);

                            $warnings = $import->getWarnings();

                            if (! empty($warnings)) {
                                Notification::make()
                                    ->title('Importación completada con advertencias')
                                    ->body(implode("\n", $warnings))
                                    ->warning()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Importación exitosa')
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
        ];
    }
}
