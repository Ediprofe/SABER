<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TagHierarchyResource\Pages;
use App\Models\TagHierarchy;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TagHierarchyResource extends Resource
{
    protected static ?string $model = TagHierarchy::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Jerarquía de Tags';

    protected static ?string $modelLabel = 'Tag';

    protected static ?string $pluralModelLabel = 'Jerarquía de Tags';

    protected static ?int $navigationSort = 50;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('tag_name')
                    ->label('Nombre del Tag')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true)
                    ->placeholder('Ej: Ciencias, Químico, Uso comprensivo'),
                Forms\Components\Select::make('tag_type')
                    ->label('Tipo')
                    ->required()
                    ->options([
                        'area' => 'Área',
                        'competencia' => 'Competencia',
                        'componente' => 'Componente',
                        'tipo_texto' => 'Tipo de Texto',
                        'nivel_lectura' => 'Nivel de Lectura (Lectura Crítica)',
                        'parte' => 'Parte',
                    ])
                    ->native(false)
                    ->helperText('Nivel de Lectura es específico para el área de Lectura Crítica (Literal, Inferencial, Crítico)'),
                Forms\Components\Select::make('parent_area')
                    ->label('Área Padre')
                    ->options(function () {
                        return TagHierarchy::where('tag_type', 'area')
                            ->pluck('tag_name', 'tag_name');
                    })
                    ->native(false)
                    ->placeholder('Seleccione si aplica')
                    ->helperText('Solo para tags que pertenecen a un área específica'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('tag_name')
                    ->label('Nombre')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\BadgeColumn::make('tag_type')
                    ->label('Tipo')
                    ->sortable()
                    ->colors([
                        'primary' => 'area',
                        'success' => 'competencia',
                        'warning' => 'componente',
                        'info' => 'tipo_texto',
                        'danger' => 'parte',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'area' => 'Área',
                        'competencia' => 'Competencia',
                        'componente' => 'Componente',
                        'tipo_texto' => 'Tipo de Texto',
                        'parte' => 'Parte',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('parent_area')
                    ->label('Área Padre')
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tag_type')
                    ->label('Tipo')
                    ->options([
                        'area' => 'Área',
                        'competencia' => 'Competencia',
                        'componente' => 'Componente',
                        'tipo_texto' => 'Tipo de Texto',
                        'parte' => 'Parte',
                    ])
                    ->native(false),
                Tables\Filters\SelectFilter::make('parent_area')
                    ->label('Área Padre')
                    ->options(function () {
                        return TagHierarchy::where('tag_type', 'area')
                            ->pluck('tag_name', 'tag_name');
                    })
                    ->native(false),
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
            ->defaultSort('tag_name', 'asc');
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
            'index' => Pages\ListTagHierarchies::route('/'),
            'create' => Pages\CreateTagHierarchy::route('/create'),
            'edit' => Pages\EditTagHierarchy::route('/{record}/edit'),
        ];
    }
}
