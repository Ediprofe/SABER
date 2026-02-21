<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\ExamResource;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Services\ZipgradeSessionImportService;
use App\Support\TagClassificationConfig;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ExamPipelineUploadController extends Controller
{
    public function analyze(Request $request, Exam $exam, int $sessionNumber): RedirectResponse
    {
        try {
            $this->assertValidSession($exam, $sessionNumber);

            $validated = $request->validate([
                'blueprint_file' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
                'responses_file' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
            ]);

            $blueprintPath = $validated['blueprint_file']->store('zipgrade_imports', 'local');
            $responsesPath = $validated['responses_file']->store('zipgrade_imports', 'local');

            $analysis = app(ZipgradeSessionImportService::class)
                ->analyzeSessionUpload($exam, $sessionNumber, $blueprintPath, $responsesPath);

            return redirect()->to(ExamResource::getUrl('pipeline-preview', [
                'record' => $exam,
                'sessionNumber' => $sessionNumber,
                'token' => $analysis['token'],
            ]));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Error inesperado al analizar archivos')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return redirect()->to(ExamResource::getUrl('upload', [
                'record' => $exam,
                'sessionNumber' => $sessionNumber,
            ]))->with('upload_error', $exception->getMessage());
        }
    }

    public function import(Request $request, Exam $exam, int $sessionNumber, string $token): RedirectResponse
    {
        try {
            $this->assertValidSession($exam, $sessionNumber);

            $service = app(ZipgradeSessionImportService::class);
            $previewPayload = $service->getPreview($exam, $sessionNumber, $token);
            if ($previewPayload === null) {
                throw new \RuntimeException('La vista previa expiró o no es válida. Sube los archivos nuevamente.');
            }

            $summary = is_array($previewPayload['summary'] ?? null) ? $previewPayload['summary'] : [];
            $classifications = $this->resolveTagClassifications($request, $summary);
            $saveNormalizations = $request->boolean('save_normalizations', true);

            $result = $service
                ->importFromPreviewToken($exam, $sessionNumber, $token, $classifications, $saveNormalizations);

            Notification::make()
                ->title('Importación completada')
                ->body(
                    "Sesión {$sessionNumber}: {$result['questions_imported']} preguntas, "
                    ."{$result['answers_imported']} respuestas, "
                    ."{$result['students_matched']} estudiantes vinculados."
                )
                ->success()
                ->send();

            return redirect()->to(ExamResource::getUrl('pipeline', ['record' => $exam]));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Error al importar la sesión')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return redirect()->to(ExamResource::getUrl('upload', [
                'record' => $exam,
                'sessionNumber' => $sessionNumber,
            ]))->with('upload_error', $exception->getMessage());
        }
    }

    private function assertValidSession(Exam $exam, int $sessionNumber): void
    {
        abort_unless(in_array($sessionNumber, $exam->getConfiguredSessionNumbers(), true), 404);
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,array{area:string,type:string}>
     */
    private function resolveTagClassifications(Request $request, array $summary): array
    {
        $allowedTags = [];
        $fallback = [];

        foreach (($summary['tag_suggestions'] ?? []) as $item) {
            if (! is_array($item) || ! isset($item['tag'])) {
                continue;
            }

            $tagName = (string) $item['tag'];
            if ($tagName === '') {
                continue;
            }

            $allowedTags[$tagName] = true;
            $area = TagClassificationConfig::normalizeAreaKey((string) ($item['suggested_area'] ?? ''));
            $type = (string) ($item['suggested_type'] ?? TagClassificationConfig::defaultTypeForArea($area));

            if (! TagClassificationConfig::isValidTypeForArea($area, $type)) {
                $type = TagClassificationConfig::defaultTypeForArea($area);
            }

            $fallback[$tagName] = [
                'area' => $area,
                'type' => $type,
            ];
        }

        $rawJson = (string) $request->input('classification_json', '');
        if ($rawJson === '') {
            return $fallback;
        }

        $decoded = json_decode($rawJson, true);
        if (! is_array($decoded)) {
            return $fallback;
        }

        $resolved = [];
        foreach ($decoded as $tagName => $entry) {
            if (! is_string($tagName) || ! isset($allowedTags[$tagName]) || ! is_array($entry)) {
                continue;
            }

            $area = TagClassificationConfig::normalizeAreaKey((string) ($entry['area'] ?? ''));
            if ($area === '__unclassified' && isset($fallback[$tagName])) {
                $area = $fallback[$tagName]['area'];
            }

            $type = (string) ($entry['type'] ?? TagClassificationConfig::defaultTypeForArea($area));
            if (! TagClassificationConfig::isValidTypeForArea($area, $type)) {
                $type = TagClassificationConfig::defaultTypeForArea($area);
            }

            $resolved[$tagName] = [
                'area' => $area,
                'type' => $type,
            ];
        }

        foreach ($fallback as $tagName => $classification) {
            if (! isset($resolved[$tagName])) {
                $resolved[$tagName] = $classification;
            }
        }

        $unclassifiedTags = collect($resolved)
            ->filter(fn (array $classification): bool => ($classification['area'] ?? '__unclassified') === '__unclassified')
            ->keys()
            ->values()
            ->all();

        if ($unclassifiedTags !== []) {
            $listedTags = implode(', ', array_slice($unclassifiedTags, 0, 12));
            if (count($unclassifiedTags) > 12) {
                $listedTags .= ', ...';
            }

            throw ValidationException::withMessages([
                'classification_json' => "Todos los tags deben quedar asociados a un área antes de importar. Revisa: {$listedTags}",
            ]);
        }

        return $resolved;
    }
}
