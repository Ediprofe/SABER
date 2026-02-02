<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Exam;
use Illuminate\Support\Collection;

class ZipgradePdfService
{
    public function __construct(
        private ZipgradeMetricsService $metricsService,
    ) {}

    /**
     * Genera un PDF anonimizado con los resultados Zipgrade.
     */
    public function generate(
        Exam $exam,
        ?string $groupFilter = null,
        ?bool $piarFilter = null
    ): string {
        $results = $this->getResults($exam, $groupFilter, $piarFilter);
        $html = $this->buildSimpleHtml($exam, $results);

        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
            ->setPaper('letter', 'landscape')
            ->output();
    }

    /**
     * Construye HTML simple sin caracteres especiales problematicos.
     */
    private function buildSimpleHtml(Exam $exam, Collection $results): string
    {
        $examName = $this->sanitizeText($exam->name ?? 'Sin nombre');
        $examDate = $exam->date ? $exam->date->format('d/m/Y') : '-';
        $generatedAt = $this->sanitizeText(now()->format('Y-m-d H:i:s'));

        $html = '<!DOCTYPE html><html><head>';
        $html .= '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>';
        $html .= '<title>Resultados</title>';
        $html .= '<style>';
        $html .= 'body{font-family:sans-serif;font-size:10px;color:#333}';
        $html .= '.header{background:#1e40af;color:white;padding:15px;margin-bottom:20px}';
        $html .= '.header h1{font-size:18px;margin-bottom:5px}';
        $html .= '.header p{font-size:11px}';
        $html .= 'table{width:100%;border-collapse:collapse;margin:20px;width:calc(100%-40px)}';
        $html .= 'th,td{padding:8px 10px;text-align:left;border-bottom:1px solid #ddd}';
        $html .= 'th{background:#f8fafc;font-weight:bold;font-size:9px;text-transform:uppercase;color:#64748b}';
        $html .= 'td{font-size:10px}';
        $html .= 'tr:nth-child(even){background:#f8fafc}';
        $html .= '.doc{font-weight:bold;color:#1e40af}';
        $html .= '.score{text-align:right}';
        $html .= '.global{font-weight:bold;color:#1e40af}';
        $html .= '.footer{position:fixed;bottom:10px;left:20px;right:20px;font-size:8px;color:#94a3b8;border-top:1px solid #e2e8f0;padding-top:5px}';
        $html .= '</style></head><body>';

        $html .= '<div class="header">';
        $html .= '<h1>RESULTADOS ZIPGRADE</h1>';
        $html .= '<p>Examen: '.$examName.' | Fecha: '.$examDate.' | Generado: '.$generatedAt.'</p>';
        $html .= '</div>';

        $html .= '<table><thead><tr>';
        $html .= '<th>Documento</th>';
        $html .= '<th style="text-align:right">Lectura</th>';
        $html .= '<th style="text-align:right">Matematicas</th>';
        $html .= '<th style="text-align:right">Sociales</th>';
        $html .= '<th style="text-align:right">Naturales</th>';
        $html .= '<th style="text-align:right">Ingles</th>';
        $html .= '<th style="text-align:right">Global</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($results as $result) {
            $doc = $this->sanitizeText($result['document_id'] ?? '');
            $lectura = (int) round($result['lectura'] ?? 0);
            $matematicas = (int) round($result['matematicas'] ?? 0);
            $sociales = (int) round($result['sociales'] ?? 0);
            $naturales = (int) round($result['naturales'] ?? 0);
            $ingles = (int) round($result['ingles'] ?? 0);
            $global = (int) round($result['global'] ?? 0);

            $html .= '<tr>';
            $html .= '<td class="doc">'.$doc.'</td>';
            $html .= '<td class="score">'.$lectura.'</td>';
            $html .= '<td class="score">'.$matematicas.'</td>';
            $html .= '<td class="score">'.$sociales.'</td>';
            $html .= '<td class="score">'.$naturales.'</td>';
            $html .= '<td class="score">'.$ingles.'</td>';
            $html .= '<td class="score global">'.$global.'</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '<div class="footer">Sistema SABER - Analisis ICFES</div>';
        $html .= '</body></html>';

        return $html;
    }

    /**
     * Sanitiza texto para evitar problemas de codificacion.
     */
    private function sanitizeText(string $text): string
    {
        // Eliminar caracteres no imprimibles
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Asegurar UTF-8 valido
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Escapar HTML
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Obtiene los resultados para el PDF.
     */
    private function getResults(
        Exam $exam,
        ?string $groupFilter = null,
        ?bool $piarFilter = null
    ): Collection {
        $query = Enrollment::query()
            ->where('academic_year_id', $exam->academic_year_id)
            ->where('status', 'ACTIVE')
            ->with('student')
            ->join('students', 'enrollments.student_id', '=', 'students.id')
            ->orderBy('students.document_id', 'asc')
            ->select('enrollments.*');

        if ($groupFilter) {
            $query->where('enrollments.group', $groupFilter);
        }

        if ($piarFilter !== null) {
            $query->where('enrollments.is_piar', $piarFilter);
        }

        $enrollments = $query->get();

        return $enrollments->map(function ($enrollment) use ($exam) {
            $student = $enrollment->student;

            return [
                'document_id' => $student->document_id ?? $student->code,
                'lectura' => $this->metricsService->getStudentAreaScore($enrollment, $exam, 'lectura'),
                'matematicas' => $this->metricsService->getStudentAreaScore($enrollment, $exam, 'matematicas'),
                'sociales' => $this->metricsService->getStudentAreaScore($enrollment, $exam, 'sociales'),
                'naturales' => $this->metricsService->getStudentAreaScore($enrollment, $exam, 'naturales'),
                'ingles' => $this->metricsService->getStudentAreaScore($enrollment, $exam, 'ingles'),
                'global' => $this->metricsService->getStudentGlobalScore($enrollment, $exam),
            ];
        });
    }

    /**
     * Genera el nombre del archivo PDF.
     */
    public function getFilename(Exam $exam): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '_', $exam->name));
        $date = now()->format('Y-m-d');

        return "resultados_zipgrade_{$slug}_{$date}.pdf";
    }
}
