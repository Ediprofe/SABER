<?php

namespace App\Filament\Resources\ExamResource\Pages;

use App\Filament\Resources\ExamResource;
use App\Models\Exam;
use App\Services\ZipgradeSessionImportService;
use Filament\Resources\Pages\Page;

class PipelinePreview extends Page
{
    protected static string $resource = ExamResource::class;

    protected static string $view = 'filament.resources.exam-resource.pages.pipeline-preview';

    public Exam $record;

    public int $sessionNumber;

    public string $token;

    /**
     * @var array<string,mixed>
     */
    public array $preview = [];

    public function mount(Exam $record, int $sessionNumber, string $token): void
    {
        $this->record = $record;
        $this->sessionNumber = $sessionNumber;
        $this->token = $token;

        $payload = app(ZipgradeSessionImportService::class)
            ->getPreview($record, $sessionNumber, $token);

        abort_unless(is_array($payload), 404);

        $this->preview = $payload['summary'] ?? [];
    }

    public function getHeading(): string
    {
        return sprintf('Vista previa - Sesión %d', $this->sessionNumber);
    }

    public function getSubheading(): ?string
    {
        return 'Revisa el consolidado detectado antes de guardar datos en la base de datos.';
    }

    public function getImportUrl(): string
    {
        return route('admin.exams.pipeline.upload.import', [
            'exam' => $this->record,
            'sessionNumber' => $this->sessionNumber,
            'token' => $this->token,
        ]);
    }

    public function getUploadUrl(): string
    {
        return ExamResource::getUrl('upload', [
            'record' => $this->record,
            'sessionNumber' => $this->sessionNumber,
        ]);
    }

    public function getPipelineUrl(): string
    {
        return ExamResource::getUrl('pipeline', ['record' => $this->record]);
    }
}
