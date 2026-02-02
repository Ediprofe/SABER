<?php

namespace App\Filament\Resources\ExamResource\Pages;

use App\Filament\Resources\ExamResource;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Services\ZipgradeMetricsService;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ZipgradeResults extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ExamResource::class;

    protected static string $view = 'filament.resources.exam-resource.pages.zipgrade-results';

    public Exam $record;

    public function mount(Exam $record): void
    {
        $this->record = $record;
    }

    public function getHeading(): string
    {
        return "Resultados Zipgrade - {$this->record->name}";
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                return Enrollment::query()
                    ->where('academic_year_id', $this->record->academic_year_id)
                    ->where('status', 'ACTIVE')
                    ->with('student');
            })
            ->columns([
                TextColumn::make('student.document_id')
                    ->label('Documento')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('student.full_name')
                    ->label('Nombre')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('group')
                    ->label('Grupo')
                    ->sortable(),
                BadgeColumn::make('is_piar')
                    ->label('PIAR')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'SI' : 'NO')
                    ->color(fn (bool $state): string => $state ? 'warning' : 'success'),
                TextColumn::make('lectura_score')
                    ->label('Lectura')
                    ->numeric(2)
                    ->state(function (Enrollment $record): float {
                        $service = app(ZipgradeMetricsService::class);

                        return $service->getStudentAreaScore($record, $this->record, 'lectura');
                    }),
                TextColumn::make('matematicas_score')
                    ->label('Matemáticas')
                    ->numeric(2)
                    ->state(function (Enrollment $record): float {
                        $service = app(ZipgradeMetricsService::class);

                        return $service->getStudentAreaScore($record, $this->record, 'matematicas');
                    }),
                TextColumn::make('sociales_score')
                    ->label('Sociales')
                    ->numeric(2)
                    ->state(function (Enrollment $record): float {
                        $service = app(ZipgradeMetricsService::class);

                        return $service->getStudentAreaScore($record, $this->record, 'sociales');
                    }),
                TextColumn::make('naturales_score')
                    ->label('Naturales')
                    ->numeric(2)
                    ->state(function (Enrollment $record): float {
                        $service = app(ZipgradeMetricsService::class);

                        return $service->getStudentAreaScore($record, $this->record, 'naturales');
                    }),
                TextColumn::make('ingles_score')
                    ->label('Inglés')
                    ->numeric(2)
                    ->state(function (Enrollment $record): float {
                        $service = app(ZipgradeMetricsService::class);

                        return $service->getStudentAreaScore($record, $this->record, 'ingles');
                    }),
                TextColumn::make('global_score')
                    ->label('Global')
                    ->numeric(0)
                    ->weight('bold')
                    ->state(function (Enrollment $record): int {
                        $service = app(ZipgradeMetricsService::class);

                        return $service->getStudentGlobalScore($record, $this->record);
                    }),
            ])
            ->filters([
                SelectFilter::make('group')
                    ->label('Grupo')
                    ->options(function () {
                        return Enrollment::where('academic_year_id', $this->record->academic_year_id)
                            ->where('status', 'ACTIVE')
                            ->distinct()
                            ->pluck('group', 'group')
                            ->toArray();
                    }),
                Filter::make('piar_only')
                    ->label('Solo PIAR')
                    ->query(fn ($query) => $query->where('is_piar', true)),
            ])
            ->defaultSort('student.document_id', 'asc')
            ->poll(null)
            ->emptyStateHeading('No hay estudiantes con resultados')
            ->emptyStateDescription('Importe datos de Zipgrade para ver los resultados.');
    }

    public function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('back')
                ->label('Volver')
                ->url(fn () => ExamResource::getUrl('index'))
                ->icon('heroicon-o-arrow-left'),
        ];
    }

    public function getFooterWidgets(): array
    {
        return [
            \App\Filament\Widgets\ZipgradeStatsWidget::class,
        ];
    }
}
