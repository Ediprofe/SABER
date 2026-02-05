<?php

namespace App\Jobs;

use App\Mail\StudentReportMail;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ReportGeneration;
use App\Services\IndividualStudentPdfService;
use App\Services\ZipgradeMetricsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class SendStudentReportsEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // 1 hora
    public int $tries = 1;

    public function __construct(
        public Exam $exam,
        public ?string $groupFilter = null,
        public ?bool $piarFilter = null,
        public ?array $enrollmentIds = null // Para envío selectivo
    ) {}

    public function handle(
        IndividualStudentPdfService $pdfService,
        ZipgradeMetricsService $metricsService
    ): void {
        Log::info("Iniciando envío de reportes por email para examen: {$this->exam->name}");

        // Buscar el ZIP de reportes generados
        $reportGeneration = ReportGeneration::where('exam_id', $this->exam->id)
            ->where('type', 'individual_pdfs')
            ->where('status', 'completed')
            ->latest()
            ->first();

        if (!$reportGeneration || !$reportGeneration->file_path) {
            Log::error("No se encontró ZIP de reportes para el examen {$this->exam->id}");
            return;
        }

        $zipPath = storage_path('app/' . $reportGeneration->file_path);

        if (!file_exists($zipPath)) {
            Log::error("Archivo ZIP no encontrado: {$zipPath}");
            return;
        }

        // Obtener estudiantes con email
        $query = Enrollment::query()
            ->where('academic_year_id', $this->exam->academic_year_id)
            ->where('status', 'ACTIVE')
            ->whereHas('student', fn($q) => $q->whereNotNull('email')->where('email', '!=', ''))
            ->whereHas('studentAnswers.question.session', fn($q) => $q->where('exam_id', $this->exam->id))
            ->with('student');

        // Si hay IDs específicos, filtrar por ellos
        if ($this->enrollmentIds !== null && count($this->enrollmentIds) > 0) {
            $query->whereIn('id', $this->enrollmentIds);
        }

        if ($this->groupFilter) {
            $query->where('group', $this->groupFilter);
        }

        if ($this->piarFilter !== null) {
            $query->where('is_piar', $this->piarFilter);
        }

        $enrollments = $query->get();

        Log::info("Estudiantes con email para enviar: {$enrollments->count()}");

        // Extraer ZIP a directorio temporal
        $tempDir = storage_path('app/temp-email-pdfs-' . uniqid());
        mkdir($tempDir, 0755, true);

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            Log::error("No se pudo abrir el ZIP: {$zipPath}");
            return;
        }
        $zip->extractTo($tempDir);
        $zip->close();

        $sent = 0;
        $failed = 0;
        $noEmail = 0;

        foreach ($enrollments as $enrollment) {
            $student = $enrollment->student;

            if (empty($student->email)) {
                $noEmail++;
                continue;
            }

            // Buscar el PDF del estudiante
            $pdfRelativePath = $pdfService->getRelativePath($enrollment);
            $pdfFullPath = $tempDir . '/' . $pdfRelativePath;

            if (!file_exists($pdfFullPath)) {
                Log::warning("PDF no encontrado para {$student->full_name}: {$pdfFullPath}");
                $failed++;
                continue;
            }

            try {
                // Obtener puntaje global
                $globalScore = $metricsService->getStudentGlobalScore($enrollment, $this->exam);

                // Enviar email
                Mail::to($student->email)->send(
                    new StudentReportMail(
                        enrollment: $enrollment,
                        exam: $this->exam,
                        pdfPath: $pdfFullPath,
                        globalScore: $globalScore
                    )
                );

                $sent++;
                Log::info("Email enviado a {$student->full_name} ({$student->email})");

                // Pequeña pausa para no saturar el servidor SMTP
                usleep(500000); // 0.5 segundos

            } catch (\Exception $e) {
                $failed++;
                Log::error("Error enviando email a {$student->email}: " . $e->getMessage());
            }
        }

        // Limpiar directorio temporal
        $this->deleteDirectory($tempDir);

        Log::info("Envío completado: {$sent} enviados, {$failed} fallidos, {$noEmail} sin email");
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
