<?php

namespace App\Filament\Resources\ExamResource\Pages;

use App\Filament\Resources\ExamResource;
use App\Models\Exam;
use Filament\Resources\Pages\Page;

class Upload extends Page
{
    protected static string $resource = ExamResource::class;

    protected static string $view = 'filament.resources.exam-resource.pages.upload';

    public Exam $record;

    public int $sessionNumber;

    public function mount(Exam $record, int $sessionNumber): void
    {
        abort_unless(in_array($sessionNumber, $record->getConfiguredSessionNumbers(), true), 404);

        $this->record = $record;
        $this->sessionNumber = $sessionNumber;
    }

    public function getHeading(): string
    {
        return sprintf('Cargar Sesión %d', $this->sessionNumber);
    }

    public function getSubheading(): ?string
    {
        return 'Sube el Blueprint CSV y el CSV de Respuestas para validar antes de importar.';
    }

    public function getAnalyzeUrl(): string
    {
        return route('admin.exams.pipeline.upload.analyze', [
            'exam' => $this->record,
            'sessionNumber' => $this->sessionNumber,
        ]);
    }

    public function getPipelineUrl(): string
    {
        return ExamResource::getUrl('pipeline', ['record' => $this->record]);
    }
}
