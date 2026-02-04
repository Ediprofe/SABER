<?php

namespace App\Jobs;

use App\Models\ReportGeneration;
use App\Services\IndividualStudentPdfService;
use App\Services\ZipgradeMetricsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZipArchive;

class GenerateStudentReportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // 1 hora máximo
    public int $tries = 1;

    public function __construct(
        public ReportGeneration $reportGeneration
    ) {}

    public function handle(
        IndividualStudentPdfService $pdfService,
        ZipgradeMetricsService $metricsService
    ): void {
        $exam = $this->reportGeneration->exam;
        $tempDir = storage_path('app/temp/reports/' . $this->reportGeneration->id);

        // Crear directorio temporal
        File::ensureDirectoryExists($tempDir);

        // Actualizar estado
        $this->reportGeneration->update(['status' => 'processing']);

        Log::info("Iniciando generación de reportes para examen {$exam->id}");

        try {
            // Obtener enrollments del examen
            $enrollments = $metricsService->getEnrollmentsForExam($exam);

            $this->reportGeneration->update(['total_students' => $enrollments->count()]);

            foreach ($enrollments as $enrollment) {
                try {
                    // Generar PDF
                    $pdfContent = $pdfService->generatePdf($enrollment, $exam);

                    // Obtener ruta relativa (incluye grupo como subcarpeta)
                    $relativePath = $pdfService->getRelativePath($enrollment);
                    $fullFilePath = $tempDir . '/' . $relativePath;

                    // Crear subcarpeta del grupo si no existe
                    File::ensureDirectoryExists(dirname($fullFilePath));

                    // Guardar PDF
                    File::put($fullFilePath, $pdfContent);

                    // Actualizar progreso
                    $this->reportGeneration->increment('processed_students');

                    Log::debug("PDF generado: {$relativePath}");

                } catch (\Exception $e) {
                    Log::error("Error generando PDF para enrollment {$enrollment->id}: " . $e->getMessage());
                    // Continuar con el siguiente estudiante
                }
            }

            // Crear ZIP
            $zipPath = $this->createZip($tempDir, $exam);

            // Actualizar registro
            $this->reportGeneration->update([
                'status' => 'completed',
                'file_path' => $zipPath,
            ]);

            Log::info("Generación completada. ZIP: {$zipPath}");

        } catch (\Exception $e) {
            Log::error("Error en generación de reportes: " . $e->getMessage());

            $this->reportGeneration->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            // Limpiar directorio temporal
            if (File::isDirectory($tempDir)) {
                File::deleteDirectory($tempDir);
            }
        }
    }

    private function createZip(string $sourceDir, $exam): string
    {
        $examSlug = Str::slug($exam->name);
        $zipName = "informes_individuales_{$examSlug}_" . now()->format('Ymd_His') . '.zip';
        $zipPath = 'reports/' . $zipName;
        $fullPath = storage_path('app/' . $zipPath);

        File::ensureDirectoryExists(dirname($fullPath));

        $zip = new ZipArchive();

        if ($zip->open($fullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("No se pudo crear el archivo ZIP");
        }

        // Obtener todos los archivos recursivamente (incluye subcarpetas)
        $files = File::allFiles($sourceDir);
        $fileCount = 0;

        foreach ($files as $file) {
            // Calcular la ruta relativa desde el directorio fuente
            $relativePath = str_replace($sourceDir . '/', '', $file->getPathname());

            // Agregar al ZIP manteniendo la estructura de carpetas
            $zip->addFile($file->getPathname(), $relativePath);
            $fileCount++;
        }

        $zip->close();

        Log::info("ZIP creado con {$fileCount} archivos en estructura de carpetas por grupo");

        return $zipPath;
    }
}
