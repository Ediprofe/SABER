<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TagNormalizationResource\Pages;
use App\Models\TagHierarchy;
use App\Models\TagNormalization;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TagNormalizationResource extends Resource
{
    protected static ?string $model = TagNormalization::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'Normalización de Tags';

    protected static ?string $modelLabel = 'Normalización de Tag';

    protected static ?string $pluralModelLabel = 'Normalizaciones de Tags';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 51;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información del Tag CSV')
                    ->schema([
                        Forms\Components\TextInput::make('tag_csv_name')
                            ->label('Nombre en CSV')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->placeholder('Ej: Ciencias, Químico, Uso comprensivo...')
                            ->helperText('Nombre exacto como aparece en el archivo Zipgrade'),
                    ])->columns(1),

                Forms\Components\Section::make('Mapeo del Sistema')
                    ->schema([
                        Forms\Components\TextInput::make('tag_system_name')
                            ->label('Nombre del Sistema')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('Ej: Naturales, Componente Químico, Competencia Interpretación...')
                            ->helperText('Nombre estandarizado para el sistema SABER'),

                        Forms\Components\Select::make('tag_type')
                            ->label('Tipo')
                            ->required()
                            ->options([
                                'area' => 'Área',
                                'competencia' => 'Competencia',
                                'componente' => 'Componente',
                                'tipo_texto' => 'Tipo de Texto',
                                'nivel_lectura' => 'Nivel de Lectura',
                                'parte' => 'Parte',
                            ])
                            ->native(false)
                            ->helperText('Categoría del tag en el sistema'),

                        Forms\Components\Select::make('parent_area')
                            ->label('Área Padre')
                            ->options(function () {
                                return TagHierarchy::where('tag_type', 'area')
                                    ->pluck('tag_name', 'tag_name');
                            })
                            ->native(false)
                            ->placeholder('Seleccione si aplica')
                            ->helperText('Área a la que pertenece este tag (solo para componentes, competencias, etc.)'),
                    ])->columns(3),

                Forms\Components\Section::make('Estado')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->label('Activo')
                            ->default(true)
                            ->helperText('Solo los tags activos se usarán en las importaciones'),
                    ])->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('tag_csv_name')
                    ->label('Nombre CSV')
                    ->sortable()
                    ->searchable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('tag_system_name')
                    ->label('Nombre Sistema')
                    ->sortable()
                    ->searchable()
                    ->limit(40),

                Tables\Columns\BadgeColumn::make('tag_type')
                    ->label('Tipo')
                    ->sortable()
                    ->colors([
                        'primary' => 'area',
                        'success' => 'competencia',
                        'warning' => 'componente',
                        'info' => 'tipo_texto',
                        'secondary' => 'nivel_lectura',
                        'danger' => 'parte',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'area' => 'Área',
                        'competencia' => 'Competencia',
                        'componente' => 'Componente',
                        'tipo_texto' => 'Tipo de Texto',
                        'nivel_lectura' => 'Nivel de Lectura',
                        'parte' => 'Parte',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('parent_area')
                    ->label('Área Padre')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->sortable(),

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
                        'nivel_lectura' => 'Nivel de Lectura',
                        'parte' => 'Parte',
                    ])
                    ->native(false),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos')
                    ->default(true),
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
            ->defaultSort('tag_csv_name', 'asc');
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
            'index' => Pages\ListTagNormalizations::route('/'),
            'create' => Pages\CreateTagNormalization::route('/create'),
            'edit' => Pages\EditTagNormalization::route('/{record}/edit'),
        ];
    }
}
