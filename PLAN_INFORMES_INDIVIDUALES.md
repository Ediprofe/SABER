# PLAN DE IMPLEMENTACIÓN: Informes Individuales PDF por Estudiante

## Resumen

Implementar sistema de generación masiva de informes PDF individuales por estudiante, procesados en cola (background), que genera un ZIP descargable con todos los PDFs.

---

## PASO 1: Migración y Modelo para Tracking

### 1.1 Crear migración

**Archivo:** `database/migrations/2024_02_03_create_report_generations_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['individual_pdfs', 'consolidated_pdf', 'excel'])->default('individual_pdfs');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->unsignedInteger('total_students')->default(0);
            $table->unsignedInteger('processed_students')->default(0);
            $table->string('file_path')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_generations');
    }
};
```

### 1.2 Crear modelo

**Archivo:** `app/Models/ReportGeneration.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportGeneration extends Model
{
    protected $fillable = [
        'exam_id',
        'type',
        'status',
        'total_students',
        'processed_students',
        'file_path',
        'error_message',
    ];

    protected $casts = [
        'total_students' => 'integer',
        'processed_students' => 'integer',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function getProgressPercentAttribute(): int
    {
        if ($this->total_students === 0) {
            return 0;
        }
        return (int) round(($this->processed_students / $this->total_students) * 100);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }
}
```

---

## PASO 2: Agregar métodos a ZipgradeMetricsService

**Archivo:** `app/Services/ZipgradeMetricsService.php`

Agregar estos 2 métodos al final de la clase (antes del cierre `}`):

```php
/**
 * Obtiene las preguntas incorrectas de un estudiante con detalles completos.
 */
public function getStudentIncorrectAnswers(Enrollment $enrollment, Exam $exam): Collection
{
    $sessionIds = ExamSession::where('exam_id', $exam->id)->pluck('id');

    return StudentAnswer::where('enrollment_id', $enrollment->id)
        ->where('is_correct', false)
        ->whereHas('question', fn($q) => $q->whereIn('exam_session_id', $sessionIds))
        ->with(['question.session', 'question.tags'])
        ->get()
        ->map(function ($answer) {
            $question = $answer->question;
            $area = $this->getAreaLabelFromQuestion($question);

            return [
                'session' => $question->session?->session_number ?? 1,
                'question_number' => $question->question_number,
                'area' => $area,
                'correct_answer' => $question->correct_answer ?? '—',
                'dimension1_name' => $this->getDimension1Name($area),
                'dimension1_value' => $this->getDimension1FromQuestion($question, $area),
                'dimension2_name' => $this->getDimension2Name($area),
                'dimension2_value' => $this->getDimension2FromQuestion($question, $area),
                'dimension3_name' => $this->getDimension3Name($area),
                'dimension3_value' => $this->getDimension3FromQuestion($question, $area),
            ];
        })
        ->sortBy(['session', 'question_number'])
        ->values();
}

/**
 * Calcula el percentil de un estudiante en el examen.
 * Retorna el porcentaje de estudiantes que tienen puntaje menor o igual.
 */
public function getStudentPercentile(Enrollment $enrollment, Exam $exam): int
{
    $studentScore = $this->getStudentGlobalScore($enrollment, $exam);
    $allEnrollments = $this->getEnrollmentsForExam($exam);

    if ($allEnrollments->count() <= 1) {
        return 100;
    }

    $scoresBelow = 0;
    foreach ($allEnrollments as $e) {
        $score = $this->getStudentGlobalScore($e, $exam);
        if ($score < $studentScore) {
            $scoresBelow++;
        }
    }

    return (int) round(($scoresBelow / $allEnrollments->count()) * 100);
}

/**
 * Obtiene el label del área desde una pregunta.
 */
private function getAreaLabelFromQuestion($question): ?string
{
    foreach ($question->tags as $tag) {
        if ($tag->tag_type === 'area') {
            $normalized = AreaConfig::normalizeAreaName($tag->tag_name);
            return $normalized ? AreaConfig::getLabel($normalized) : $tag->tag_name;
        }
    }

    foreach ($question->tags as $tag) {
        if ($tag->parent_area) {
            $normalized = AreaConfig::normalizeAreaName($tag->parent_area);
            return $normalized ? AreaConfig::getLabel($normalized) : $tag->parent_area;
        }
    }

    return null;
}

/**
 * Obtiene la dimensión 1 de una pregunta.
 */
private function getDimension1FromQuestion($question, ?string $area): ?string
{
    if ($area === 'Inglés') {
        $parte = $question->tags->firstWhere('tag_type', 'parte');
        return $parte?->tag_name;
    }

    $competencia = $question->tags->firstWhere('tag_type', 'competencia');
    return $competencia?->tag_name;
}

/**
 * Obtiene la dimensión 2 de una pregunta.
 */
private function getDimension2FromQuestion($question, ?string $area): ?string
{
    if ($area === 'Inglés') {
        return null;
    }

    if (in_array($area, ['Lectura', 'Lectura Crítica'])) {
        $tipo = $question->tags->firstWhere('tag_type', 'tipo_texto');
        return $tipo?->tag_name;
    }

    $componente = $question->tags->firstWhere('tag_type', 'componente');
    return $componente?->tag_name;
}

/**
 * Obtiene la dimensión 3 de una pregunta.
 */
private function getDimension3FromQuestion($question, ?string $area): ?string
{
    if (in_array($area, ['Lectura', 'Lectura Crítica'])) {
        $nivel = $question->tags->firstWhere('tag_type', 'nivel_lectura');
        return $nivel?->tag_name;
    }

    return null;
}

/**
 * Obtiene el nombre de la dimensión 1 según el área.
 */
private function getDimension1Name(?string $area): string
{
    return $area === 'Inglés' ? 'Parte' : 'Competencia';
}

/**
 * Obtiene el nombre de la dimensión 2 según el área.
 */
private function getDimension2Name(?string $area): ?string
{
    if ($area === 'Inglés') {
        return null;
    }
    return in_array($area, ['Lectura', 'Lectura Crítica']) ? 'Tipo de Texto' : 'Componente';
}

/**
 * Obtiene el nombre de la dimensión 3 según el área.
 */
private function getDimension3Name(?string $area): ?string
{
    return in_array($area, ['Lectura', 'Lectura Crítica']) ? 'Nivel de Lectura' : null;
}

/**
 * Obtiene resumen de incorrectas por área para un estudiante.
 */
public function getStudentIncorrectSummary(Enrollment $enrollment, Exam $exam): array
{
    $sessionIds = ExamSession::where('exam_id', $exam->id)->pluck('id');

    $areas = ['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'];
    $summary = [];

    foreach ($areas as $area) {
        $areaTag = $this->findAreaTag($area);
        if (!$areaTag) {
            continue;
        }

        // Total de preguntas del área
        $questionIds = DB::table('exam_questions')
            ->join('question_tags', 'exam_questions.id', '=', 'question_tags.exam_question_id')
            ->whereIn('exam_questions.exam_session_id', $sessionIds)
            ->where(function ($query) use ($areaTag, $area) {
                $query->where('question_tags.tag_hierarchy_id', $areaTag->id)
                    ->orWhere('question_tags.inferred_area', $area);
            })
            ->distinct()
            ->pluck('exam_questions.id');

        $total = $questionIds->count();

        // Incorrectas del estudiante en este área
        $incorrect = StudentAnswer::where('enrollment_id', $enrollment->id)
            ->whereIn('exam_question_id', $questionIds)
            ->where('is_correct', false)
            ->count();

        $summary[$area] = [
            'label' => AreaConfig::getLabel($area),
            'total' => $total,
            'incorrect' => $incorrect,
            'correct' => $total - $incorrect,
            'error_rate' => $total > 0 ? round(($incorrect / $total) * 100, 1) : 0,
        ];
    }

    return $summary;
}
```

---

## PASO 3: Crear IndividualStudentPdfService

**Archivo:** `app/Services/IndividualStudentPdfService.php`

```php
<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Exam;
use App\Support\AreaConfig;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

class IndividualStudentPdfService
{
    public function __construct(
        private ZipgradeMetricsService $metricsService
    ) {}

    /**
     * Genera el PDF de un estudiante individual.
     */
    public function generatePdf(Enrollment $enrollment, Exam $exam): string
    {
        $data = $this->collectReportData($enrollment, $exam);

        $html = view('reports.student-individual-pdf', $data)->render();

        $pdf = Pdf::loadHTML($html)
            ->setPaper('letter', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => false,
                'defaultFont' => 'sans-serif',
                'dpi' => 96,
            ]);

        return $pdf->output();
    }

    /**
     * Recopila todos los datos necesarios para el informe.
     * REUTILIZA métodos existentes de ZipgradeMetricsService.
     */
    private function collectReportData(Enrollment $enrollment, Exam $exam): array
    {
        // Datos del estudiante
        $student = $enrollment->student;

        // Puntajes por área (REUTILIZA)
        $areaScores = $this->metricsService->getStudentAllAreaScores($enrollment, $exam);

        // Estadísticas del examen para comparación (REUTILIZA)
        $examStats = $this->metricsService->getExamStatistics($exam);

        // Percentil (NUEVO)
        $percentile = $this->metricsService->getStudentPercentile($enrollment, $exam);

        // Puntajes por dimensión para cada área (REUTILIZA)
        $dimensions = [];
        foreach (['naturales', 'matematicas', 'sociales', 'lectura', 'ingles'] as $area) {
            $dimScores = $this->metricsService->getStudentDimensionScores($enrollment, $exam, $area);
            if (!empty($dimScores)) {
                $dimensions[$area] = [
                    'label' => AreaConfig::getLabel($area),
                    'area_score' => $areaScores[$area],
                    'area_average' => $examStats['areas'][$area]['average'] ?? 0,
                    'dimensions' => $dimScores,
                ];
            }
        }

        // Preguntas incorrectas (NUEVO)
        $incorrectAnswers = $this->metricsService->getStudentIncorrectAnswers($enrollment, $exam);

        // Resumen de incorrectas por área (NUEVO)
        $incorrectSummary = $this->metricsService->getStudentIncorrectSummary($enrollment, $exam);

        // Construir comparaciones por área
        $areaComparisons = [];
        foreach (['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'] as $area) {
            $score = $areaScores[$area];
            $average = $examStats['areas'][$area]['average'] ?? 0;
            $areaComparisons[$area] = [
                'label' => AreaConfig::getLabel($area),
                'score' => $score,
                'average' => $average,
                'comparison' => $score >= $average ? 'above' : 'below',
                'difference' => round($score - $average, 2),
            ];
        }

        return [
            'student' => [
                'document_id' => $student->document_id ?? $student->zipgrade_id ?? '—',
                'full_name' => trim($student->first_name . ' ' . $student->last_name),
                'group' => $enrollment->group,
                'is_piar' => $enrollment->is_piar,
            ],
            'exam' => [
                'name' => $exam->name,
                'date' => $exam->date?->format('Y-m-d') ?? '—',
            ],
            'scores' => [
                'global' => $areaScores['global'],
                'percentile' => $percentile,
                'areas' => $areaComparisons,
            ],
            'dimensions' => $dimensions,
            'incorrectAnswers' => $incorrectAnswers,
            'incorrectSummary' => $incorrectSummary,
            'totalQuestions' => $this->getTotalQuestions($exam),
            'totalIncorrect' => $incorrectAnswers->count(),
            'generatedAt' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Obtiene el total de preguntas del examen.
     */
    private function getTotalQuestions(Exam $exam): int
    {
        return \App\Models\ExamQuestion::whereHas('session', function ($q) use ($exam) {
            $q->where('exam_id', $exam->id);
        })->count();
    }

    /**
     * Genera el nombre del archivo PDF para un estudiante.
     */
    public function getFileName(Enrollment $enrollment): string
    {
        $student = $enrollment->student;
        $lastName = Str::slug($student->last_name ?? 'sin-apellido');
        $firstName = Str::slug($student->first_name ?? 'sin-nombre');
        $doc = $student->document_id ?? $student->zipgrade_id ?? $enrollment->id;

        return "informe_{$doc}_{$lastName}_{$firstName}.pdf";
    }
}
```

---

## PASO 4: Crear el Job de Cola

**Archivo:** `app/Jobs/GenerateStudentReportsJob.php`

```php
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
                    $fileName = $pdfService->getFileName($enrollment);

                    // Guardar en carpeta temporal
                    File::put($tempDir . '/' . $fileName, $pdfContent);

                    // Actualizar progreso
                    $this->reportGeneration->increment('processed_students');

                    Log::debug("PDF generado: {$fileName}");

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

        $files = File::files($sourceDir);

        foreach ($files as $file) {
            $zip->addFile($file->getPathname(), $file->getFilename());
        }

        $zip->close();

        Log::info("ZIP creado con " . count($files) . " archivos");

        return $zipPath;
    }
}
```

---

## PASO 5: Crear la Vista Blade del PDF

**Archivo:** `resources/views/reports/student-individual-pdf.blade.php`

```blade
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Informe Individual - {{ $student['full_name'] }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            line-height: 1.4;
            color: #333;
            padding: 20px;
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .header h1 {
            font-size: 16px;
            color: #1e40af;
            margin-bottom: 5px;
        }

        .header .exam-name {
            font-size: 12px;
            color: #6b7280;
        }

        .student-info {
            background: #f3f4f6;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .student-info table {
            width: 100%;
        }

        .student-info td {
            padding: 3px 10px;
        }

        .student-info .label {
            font-weight: bold;
            color: #374151;
            width: 120px;
        }

        .global-score-box {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 20px;
        }

        .global-score-box .score {
            font-size: 36px;
            font-weight: bold;
        }

        .global-score-box .score-label {
            font-size: 12px;
            opacity: 0.9;
        }

        .global-score-box .percentile {
            margin-top: 10px;
            font-size: 11px;
            opacity: 0.9;
        }

        .section-title {
            font-size: 12px;
            font-weight: bold;
            color: #1e40af;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 5px;
            margin: 20px 0 10px 0;
        }

        .area-scores-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        .area-scores-table th,
        .area-scores-table td {
            border: 1px solid #d1d5db;
            padding: 8px;
            text-align: center;
        }

        .area-scores-table th {
            background: #f3f4f6;
            font-weight: bold;
            color: #374151;
        }

        .area-scores-table .area-name {
            text-align: left;
            font-weight: 600;
        }

        .above {
            color: #059669;
            font-weight: bold;
        }

        .below {
            color: #dc2626;
            font-weight: bold;
        }

        .page-break {
            page-break-after: always;
        }

        .dimension-section {
            margin-bottom: 20px;
            border: 1px solid #e5e7eb;
            border-radius: 5px;
            overflow: hidden;
        }

        .dimension-header {
            background: #f3f4f6;
            padding: 10px;
            font-weight: bold;
            color: #1e40af;
            border-bottom: 1px solid #e5e7eb;
        }

        .dimension-content {
            padding: 10px;
        }

        .dimension-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }

        .dimension-table th,
        .dimension-table td {
            border: 1px solid #e5e7eb;
            padding: 5px;
            text-align: center;
        }

        .dimension-table th {
            background: #f9fafb;
            font-weight: 600;
        }

        .dimension-table .item-name {
            text-align: left;
        }

        .incorrect-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8px;
        }

        .incorrect-table th,
        .incorrect-table td {
            border: 1px solid #d1d5db;
            padding: 4px;
            text-align: center;
        }

        .incorrect-table th {
            background: #fef2f2;
            color: #991b1b;
            font-weight: bold;
        }

        .incorrect-table .area-cell {
            text-align: left;
        }

        .summary-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 15px;
        }

        .summary-box h4 {
            color: #991b1b;
            margin-bottom: 8px;
            font-size: 11px;
        }

        .summary-grid {
            display: table;
            width: 100%;
        }

        .summary-item {
            display: table-cell;
            text-align: center;
            padding: 5px;
        }

        .summary-item .value {
            font-size: 14px;
            font-weight: bold;
            color: #991b1b;
        }

        .summary-item .label {
            font-size: 8px;
            color: #6b7280;
        }

        .footer {
            position: fixed;
            bottom: 10px;
            left: 20px;
            right: 20px;
            text-align: center;
            font-size: 8px;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
            padding-top: 5px;
        }

        .piar-badge {
            display: inline-block;
            background: #fef3c7;
            color: #92400e;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    {{-- PÁGINA 1: RESUMEN GENERAL --}}
    <div class="header">
        <h1>INFORME INDIVIDUAL DE RESULTADOS</h1>
        <div class="exam-name">{{ $exam['name'] }} - {{ $exam['date'] }}</div>
    </div>

    <div class="student-info">
        <table>
            <tr>
                <td class="label">Estudiante:</td>
                <td><strong>{{ $student['full_name'] }}</strong></td>
                <td class="label">Documento:</td>
                <td>{{ $student['document_id'] }}</td>
            </tr>
            <tr>
                <td class="label">Grupo:</td>
                <td>{{ $student['group'] }}</td>
                <td class="label">PIAR:</td>
                <td>
                    @if($student['is_piar'])
                        <span class="piar-badge">SÍ</span>
                    @else
                        NO
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <div class="global-score-box">
        <div class="score-label">PUNTAJE GLOBAL</div>
        <div class="score">{{ $scores['global'] }} / 500</div>
        <div class="percentile">Superas al {{ $scores['percentile'] }}% de los estudiantes</div>
    </div>

    <div class="section-title">PUNTAJES POR ÁREA</div>

    <table class="area-scores-table">
        <thead>
            <tr>
                <th>Área</th>
                <th>Tu Puntaje</th>
                <th>Promedio Examen</th>
                <th>Diferencia</th>
                <th>Desempeño</th>
            </tr>
        </thead>
        <tbody>
            @foreach($scores['areas'] as $key => $area)
            <tr>
                <td class="area-name">{{ $area['label'] }}</td>
                <td>{{ number_format($area['score'], 2) }}</td>
                <td>{{ number_format($area['average'], 2) }}</td>
                <td class="{{ $area['comparison'] }}">
                    {{ $area['difference'] > 0 ? '+' : '' }}{{ number_format($area['difference'], 2) }}
                </td>
                <td class="{{ $area['comparison'] }}">
                    @if($area['comparison'] === 'above')
                        ▲ Sobre promedio
                    @else
                        ▼ Bajo promedio
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="page-break"></div>

    {{-- PÁGINAS 2+: DESGLOSE POR ÁREA --}}
    <div class="section-title">ANÁLISIS DETALLADO POR ÁREA</div>

    @foreach($dimensions as $areaKey => $areaData)
    <div class="dimension-section">
        <div class="dimension-header">
            {{ $areaData['label'] }} — Puntaje: {{ number_format($areaData['area_score'], 2) }}
            (Promedio: {{ number_format($areaData['area_average'], 2) }})
        </div>
        <div class="dimension-content">
            @foreach($areaData['dimensions'] as $dimType => $items)
                @php
                    $dimLabel = match($dimType) {
                        'competencia' => 'Competencias',
                        'componente' => 'Componentes',
                        'parte' => 'Partes',
                        'tipo_texto' => 'Tipos de Texto',
                        'nivel_lectura' => 'Niveles de Lectura',
                        default => ucfirst($dimType)
                    };
                @endphp
                <p style="font-weight: bold; margin: 8px 0 4px 0; color: #4b5563;">{{ $dimLabel }}:</p>
                <table class="dimension-table">
                    <thead>
                        <tr>
                            <th style="width: 60%;">{{ $dimLabel }}</th>
                            <th>Tu Puntaje</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($items as $itemName => $itemScore)
                        <tr>
                            <td class="item-name">{{ $itemName }}</td>
                            <td>{{ number_format($itemScore, 2) }}%</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach
        </div>
    </div>
    @endforeach

    <div class="page-break"></div>

    {{-- ÚLTIMA PÁGINA: PREGUNTAS INCORRECTAS --}}
    <div class="section-title">ANÁLISIS DE PREGUNTAS INCORRECTAS</div>

    <div class="summary-box">
        <h4>Resumen de Errores</h4>
        <table style="width: 100%; font-size: 9px;">
            <tr>
                <td><strong>Total de preguntas:</strong> {{ $totalQuestions }}</td>
                <td><strong>Incorrectas:</strong> {{ $totalIncorrect }}</td>
                <td><strong>Correctas:</strong> {{ $totalQuestions - $totalIncorrect }}</td>
                <td><strong>% de acierto:</strong> {{ number_format((($totalQuestions - $totalIncorrect) / max($totalQuestions, 1)) * 100, 1) }}%</td>
            </tr>
        </table>

        <p style="margin-top: 10px; font-size: 9px;"><strong>Por área:</strong></p>
        <table style="width: 100%; font-size: 8px; margin-top: 5px;">
            <tr>
                @foreach($incorrectSummary as $area => $data)
                <td style="text-align: center; padding: 3px;">
                    <strong>{{ $data['label'] }}</strong><br>
                    {{ $data['incorrect'] }}/{{ $data['total'] }} ({{ $data['error_rate'] }}% error)
                </td>
                @endforeach
            </tr>
        </table>
    </div>

    @if($incorrectAnswers->count() > 0)
    <table class="incorrect-table">
        <thead>
            <tr>
                <th>Ses.</th>
                <th>#</th>
                <th>Área</th>
                <th>Correcta</th>
                <th>{{ $incorrectAnswers->first()['dimension1_name'] ?? 'Dim 1' }}</th>
                @if($incorrectAnswers->first()['dimension2_name'] ?? null)
                <th>{{ $incorrectAnswers->first()['dimension2_name'] }}</th>
                @endif
                @if($incorrectAnswers->first()['dimension3_name'] ?? null)
                <th>{{ $incorrectAnswers->first()['dimension3_name'] }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($incorrectAnswers as $answer)
            <tr>
                <td>{{ $answer['session'] }}</td>
                <td>{{ $answer['question_number'] }}</td>
                <td class="area-cell">{{ $answer['area'] ?? '—' }}</td>
                <td><strong>{{ $answer['correct_answer'] }}</strong></td>
                <td>{{ $answer['dimension1_value'] ?? '—' }}</td>
                @if($answer['dimension2_name'] ?? null)
                <td>{{ $answer['dimension2_value'] ?? '—' }}</td>
                @endif
                @if($answer['dimension3_name'] ?? null)
                <td>{{ $answer['dimension3_value'] ?? '—' }}</td>
                @endif
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p style="text-align: center; color: #059669; padding: 20px;">
        ¡Felicitaciones! No tienes preguntas incorrectas.
    </p>
    @endif

    <div class="footer">
        Generado el {{ $generatedAt }} — Sistema SABER
    </div>
</body>
</html>
```

---

## PASO 6: Modificar ZipgradeResults.php

**Archivo:** `app/Filament/Resources/ExamResource/Pages/ZipgradeResults.php`

### 6.1 Agregar import al inicio del archivo:

```php
use App\Jobs\GenerateStudentReportsJob;
use App\Models\ReportGeneration;
```

### 6.2 Agregar nueva acción en `getHeaderActions()`:

Buscar el método `getHeaderActions()` y agregar esta acción junto a las existentes:

```php
Action::make('generate_individual_reports')
    ->label('Generar Informes Individuales (PDF)')
    ->icon('heroicon-o-document-arrow-down')
    ->color('success')
    ->requiresConfirmation()
    ->modalHeading('Generar Informes PDF Individuales')
    ->modalDescription(function () {
        $exam = $this->getExam();
        $count = app(ZipgradeMetricsService::class)->getEnrollmentsForExam($exam)->count();
        return "Se generará un PDF individual por cada uno de los {$count} estudiantes. El proceso se ejecutará en segundo plano y podrás descargar el ZIP cuando esté listo.";
    })
    ->modalSubmitActionLabel('Iniciar Generación')
    ->action(function () {
        $exam = $this->getExam();
        $enrollments = app(ZipgradeMetricsService::class)->getEnrollmentsForExam($exam);

        // Verificar si ya hay una generación en proceso
        $existingGeneration = ReportGeneration::where('exam_id', $exam->id)
            ->where('type', 'individual_pdfs')
            ->whereIn('status', ['pending', 'processing'])
            ->first();

        if ($existingGeneration) {
            Notification::make()
                ->title('Generación en proceso')
                ->body('Ya hay una generación de informes en curso. Por favor espera a que termine.')
                ->warning()
                ->send();
            return;
        }

        // Crear registro de generación
        $generation = ReportGeneration::create([
            'exam_id' => $exam->id,
            'type' => 'individual_pdfs',
            'status' => 'pending',
            'total_students' => $enrollments->count(),
        ]);

        // Despachar job
        GenerateStudentReportsJob::dispatch($generation);

        Notification::make()
            ->title('Generación iniciada')
            ->body("Se están generando {$enrollments->count()} informes individuales. Actualiza la página para ver el progreso.")
            ->success()
            ->send();
    }),

Action::make('download_individual_reports')
    ->label(function () {
        $exam = $this->getExam();
        $generation = ReportGeneration::where('exam_id', $exam->id)
            ->where('type', 'individual_pdfs')
            ->latest()
            ->first();

        if (!$generation) {
            return 'Descargar ZIP (no disponible)';
        }

        if ($generation->status === 'processing') {
            return "Generando... ({$generation->progress_percent}%)";
        }

        if ($generation->status === 'completed') {
            return 'Descargar ZIP de Informes';
        }

        if ($generation->status === 'failed') {
            return 'Error en generación';
        }

        return 'Descargar ZIP';
    })
    ->icon('heroicon-o-arrow-down-tray')
    ->color(function () {
        $exam = $this->getExam();
        $generation = ReportGeneration::where('exam_id', $exam->id)
            ->where('type', 'individual_pdfs')
            ->latest()
            ->first();

        if ($generation?->status === 'completed') {
            return 'success';
        }
        if ($generation?->status === 'failed') {
            return 'danger';
        }
        if ($generation?->status === 'processing') {
            return 'warning';
        }
        return 'gray';
    })
    ->disabled(function () {
        $exam = $this->getExam();
        $generation = ReportGeneration::where('exam_id', $exam->id)
            ->where('type', 'individual_pdfs')
            ->latest()
            ->first();

        return !$generation || $generation->status !== 'completed';
    })
    ->action(function () {
        $exam = $this->getExam();
        $generation = ReportGeneration::where('exam_id', $exam->id)
            ->where('type', 'individual_pdfs')
            ->where('status', 'completed')
            ->latest()
            ->first();

        if (!$generation || !$generation->file_path) {
            Notification::make()
                ->title('Archivo no disponible')
                ->body('El archivo ZIP no está disponible. Genera los informes primero.')
                ->danger()
                ->send();
            return;
        }

        $fullPath = storage_path('app/' . $generation->file_path);

        if (!file_exists($fullPath)) {
            Notification::make()
                ->title('Archivo no encontrado')
                ->body('El archivo ZIP ya no existe. Genera los informes nuevamente.')
                ->danger()
                ->send();
            return;
        }

        return response()->download($fullPath);
    }),
```

### 6.3 Agregar import de Notification si no existe:

```php
use Filament\Notifications\Notification;
```

---

## PASO 7: Configurar Cola (si no está configurada)

### 7.1 Verificar configuración de cola en `.env`:

```
QUEUE_CONNECTION=database
```

### 7.2 Si usa `database`, crear tabla de jobs:

```bash
php artisan queue:table
php artisan migrate
```

### 7.3 Para procesar jobs en desarrollo:

```bash
php artisan queue:work
```

### 7.4 Para producción, configurar supervisor o similar.

---

## PASO 8: Ejecutar Migración

```bash
php artisan migrate
```

---

## Resumen de Archivos

| Acción | Archivo |
|--------|---------|
| CREAR | `database/migrations/xxxx_create_report_generations_table.php` |
| CREAR | `app/Models/ReportGeneration.php` |
| MODIFICAR | `app/Services/ZipgradeMetricsService.php` (agregar métodos) |
| CREAR | `app/Services/IndividualStudentPdfService.php` |
| CREAR | `app/Jobs/GenerateStudentReportsJob.php` |
| CREAR | `resources/views/reports/student-individual-pdf.blade.php` |
| MODIFICAR | `app/Filament/Resources/ExamResource/Pages/ZipgradeResults.php` |

---

## Pruebas

1. Ejecutar migración
2. Iniciar worker de cola: `php artisan queue:work`
3. Ir a la página de resultados Zipgrade de un examen
4. Hacer clic en "Generar Informes Individuales (PDF)"
5. Actualizar la página para ver el progreso
6. Cuando termine, descargar el ZIP
7. Verificar que cada PDF contenga los datos correctos
