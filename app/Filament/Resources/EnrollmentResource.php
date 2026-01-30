<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EnrollmentResource\Pages;
use App\Imports\EnrollmentsImport;
use App\Models\Enrollment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Maatwebsite\Excel\Facades\Excel;

class EnrollmentResource extends Resource
{
    protected static ?string $model = Enrollment::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Matrículas';

    protected static ?string $modelLabel = 'Matrícula';

    protected static ?string $pluralModelLabel = 'Matrículas';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('student_id')
                    ->label('Estudiante')
                    ->relationship('student', 'full_name')
                    ->searchable(['first_name', 'last_name'])
                    ->required(),
                Forms\Components\Select::make('academic_year_id')
                    ->label('Año Académico')
                    ->relationship('academicYear', 'year')
                    ->required(),
                Forms\Components\Select::make('grade')
                    ->label('Grado')
                    ->options([
                        10 => '10°',
                        11 => '11°',
                    ])
                    ->required(),
                Forms\Components\TextInput::make('group')
                    ->label('Grupo')
                    ->required()
                    ->maxLength(10)
                    ->placeholder('Ej: 10-1, 11-2'),
                Forms\Components\Toggle::make('is_piar')
                    ->label('PIAR')
                    ->helperText('¿El estudiante pertenece al programa PIAR?')
                    ->default(false),
                Forms\Components\Select::make('status')
                    ->label('Estado')
                    ->options([
                        'ACTIVE' => 'Activo',
                        'GRADUATED' => 'Graduado',
                    ])
                    ->default('ACTIVE')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('student.code')
                    ->label('Código Est.')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('student.full_name')
                    ->label('Estudiante')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('academicYear.year')
                    ->label('Año Académico')
                    ->sortable(),
                Tables\Columns\TextColumn::make('grade')
                    ->label('Grado')
                    ->sortable(),
                Tables\Columns\TextColumn::make('group')
                    ->label('Grupo')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_piar')
                    ->label('PIAR')
                    ->boolean(),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Estado')
                    ->colors([
                        'success' => 'ACTIVE',
                        'warning' => 'GRADUATED',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'ACTIVE' => 'Activo',
                        'GRADUATED' => 'Graduado',
                        default => $state,
                    }),
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
                Tables\Filters\SelectFilter::make('grade')
                    ->label('Grado')
                    ->options([
                        10 => '10°',
                        11 => '11°',
                    ]),
                Tables\Filters\Filter::make('is_piar')
                    ->label('Solo PIAR')
                    ->query(fn ($query) => $query->where('is_piar', true)),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'ACTIVE' => 'Activo',
                        'GRADUATED' => 'Graduado',
                    ]),
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
                Tables\Actions\Action::make('import')
                    ->label('Importar Matrículas')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('success')
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label('Archivo Excel')
                            ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'])
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            Excel::import(new EnrollmentsImport, $data['file']);

                            Notification::make()
                                ->title('Importación exitosa')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Error en la importación')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEnrollments::route('/'),
            'create' => Pages\CreateEnrollment::route('/create'),
            'edit' => Pages\EditEnrollment::route('/{record}/edit'),
        ];
    }
}
