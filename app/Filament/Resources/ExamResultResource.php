<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExamResultResource\Pages;
use App\Models\ExamResult;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ExamResultResource extends Resource
{
    protected static ?string $model = ExamResult::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Resultados';

    protected static ?string $modelLabel = 'Resultado';

    protected static ?string $pluralModelLabel = 'Resultados';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('exam_id')
                    ->label('Examen')
                    ->relationship('exam', 'name')
                    ->disabled(),
                Forms\Components\Select::make('enrollment_id')
                    ->label('Matrícula')
                    ->relationship('enrollment.student', 'first_name')
                    ->disabled(),
                Forms\Components\TextInput::make('lectura')
                    ->label('Lectura Crítica')
                    ->disabled()
                    ->suffix('/ 100'),
                Forms\Components\TextInput::make('matematicas')
                    ->label('Matemáticas')
                    ->disabled()
                    ->suffix('/ 100'),
                Forms\Components\TextInput::make('sociales')
                    ->label('Ciencias Sociales')
                    ->disabled()
                    ->suffix('/ 100'),
                Forms\Components\TextInput::make('naturales')
                    ->label('Ciencias Naturales')
                    ->disabled()
                    ->suffix('/ 100'),
                Forms\Components\TextInput::make('ingles')
                    ->label('Inglés')
                    ->disabled()
                    ->suffix('/ 100'),
                Forms\Components\TextInput::make('global_score')
                    ->label('Puntaje Global')
                    ->disabled()
                    ->suffix('/ 500'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('exam.name')
                    ->label('Examen')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('enrollment.student.code')
                    ->label('Código Est.')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('enrollment.student.first_name')
                    ->label('Nombre')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('enrollment.student.last_name')
                    ->label('Apellido')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('enrollment.academicYear.year')
                    ->label('Año')
                    ->sortable(),
                Tables\Columns\TextColumn::make('enrollment.group')
                    ->label('Grupo')
                    ->sortable(),
                Tables\Columns\IconColumn::make('enrollment.is_piar')
                    ->label('PIAR')
                    ->boolean(),
                Tables\Columns\TextColumn::make('global_score')
                    ->label('Global')
                    ->sortable()
                    ->suffix('/ 500'),
                Tables\Columns\TextColumn::make('lectura')
                    ->label('Lect.')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('matematicas')
                    ->label('Mat.')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('sociales')
                    ->label('Soc.')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('naturales')
                    ->label('Nat.')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('ingles')
                    ->label('Ing.')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('exam_id')
                    ->label('Examen')
                    ->relationship('exam', 'name'),
                Tables\Filters\SelectFilter::make('academic_year')
                    ->label('Año Académico')
                    ->relationship('enrollment.academicYear', 'year'),
                Tables\Filters\Filter::make('is_piar')
                    ->label('Solo PIAR')
                    ->query(fn ($query) => $query->whereHas('enrollment', fn ($q) => $q->where('is_piar', true))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExamResults::route('/'),
            'view' => Pages\ViewExamResult::route('/{record}'),
        ];
    }
}
