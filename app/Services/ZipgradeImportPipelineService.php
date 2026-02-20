<?php

namespace App\Services;

use App\Imports\ZipgradeQuestionStatsImport;
use App\Imports\ZipgradeTagsImport;
use App\Models\Exam;
use App\Models\ExamSession;
use App\Models\ZipgradeImport;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;

class ZipgradeImportPipelineService
{
    public function getUploadedFilePath(string $uploadedFileRelativePath): string
    {
        $filePath = Storage::disk('public')->path($uploadedFileRelativePath);

        if (! file_exists($filePath)) {
            throw new \RuntimeException('No se pudo acceder al archivo subido.');
        }

        return $filePath;
    }

    /**
     * @return array{
     *   needs_classification: bool,
     *   encoded_path?: string,
     *   imported?: array{students_count:int, questions_count:int, new_tags:array}
     * }
     */
    public function importSessionTagsFromUploadedFile(
        Exam $exam,
        int $sessionNumber,
        string $uploadedFileRelativePath
    ): array {
        $filePath = $this->getUploadedFilePath($uploadedFileRelativePath);

        $newTags = ZipgradeTagsImport::analyzeFile($filePath);

        if (! empty($newTags)) {
            return [
                'needs_classification' => true,
                'encoded_path' => base64_encode($uploadedFileRelativePath),
            ];
        }

        return [
            'needs_classification' => false,
            'imported' => $this->processZipgradeImport($exam, $sessionNumber, $filePath),
        ];
    }

    /**
     * @return array{students_count:int, questions_count:int, new_tags:array}
     */
    public function processZipgradeImport(Exam $exam, int $sessionNumber, string $filePath): array
    {
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', '300');
        set_time_limit(300);

        $session = ExamSession::firstOrCreate(
            ['exam_id' => $exam->id, 'session_number' => $sessionNumber],
            ['name' => "Sesión {$sessionNumber}"]
        );

        $import = ZipgradeImport::create([
            'exam_session_id' => $session->id,
            'filename' => basename($filePath),
            'total_rows' => 0,
            'status' => 'processing',
        ]);

        try {
            if (! file_exists($filePath)) {
                throw new \RuntimeException('No se pudo encontrar el archivo: '.$filePath);
            }

            $importClass = new ZipgradeTagsImport($session->id, []);
            Excel::import($importClass, $filePath);

            $session->refresh();
            $session->total_questions = $session->questions()->count();
            $session->save();

            $import->update([
                'status' => 'completed',
                'total_rows' => $importClass->getRowCount(),
            ]);

            return [
                'students_count' => $importClass->getStudentsCount(),
                'questions_count' => (int) $session->total_questions,
                'new_tags' => $importClass->getNewTags(),
            ];
        } catch (\Exception $e) {
            $import->markAsError($e->getMessage());
            throw $e;
        }
    }

    public function processZipgradeStatsImport(Exam $exam, int $sessionNumber, string $filePath): int
    {
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', '300');
        set_time_limit(300);

        $session = ExamSession::where('exam_id', $exam->id)
            ->where('session_number', $sessionNumber)
            ->first();

        if (! $session) {
            throw new \RuntimeException("La sesión {$sessionNumber} no existe. Importe los tags primero.");
        }

        if (! file_exists($filePath)) {
            throw new \RuntimeException('No se pudo encontrar el archivo: '.$filePath);
        }

        $import = ZipgradeImport::create([
            'exam_session_id' => $session->id,
            'filename' => 'stats_'.basename($filePath),
            'total_rows' => 0,
            'status' => 'processing',
        ]);

        $importClass = new ZipgradeQuestionStatsImport($session->id);

        try {
            HeadingRowFormatter::default('zipgrade');
            Excel::import($importClass, $filePath);

            $processedCount = $importClass->getProcessedCount();

            $import->update([
                'status' => 'completed',
                'total_rows' => $processedCount,
            ]);

            return $processedCount;
        } catch (\Exception $e) {
            $import->markAsError($e->getMessage());
            throw $e;
        } finally {
            HeadingRowFormatter::reset();
        }
    }
}
