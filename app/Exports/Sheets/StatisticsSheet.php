<?php

namespace App\Exports\Sheets;

use App\Models\Enrollment;
use App\Models\Exam;
use App\Services\ZipgradeMetricsService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Hoja Promedios y Desviaciones: Estadísticas CON PIAR y SIN PIAR
 * Diseño horizontal: Áreas como columnas, métricas como filas
 */
class StatisticsSheet implements FromCollection, ShouldAutoSize, WithStyles, WithTitle, WithColumnFormatting
{
    private ZipgradeMetricsService $metricsService;

    public function __construct(
        private Exam $exam,
    ) {
        $this->metricsService = app(ZipgradeMetricsService::class);
    }

    public function collection(): Collection
    {
        $sessionIds = \App\Models\ExamSession::where('exam_id', $this->exam->id)->pluck('id');
        
        // Función base para crear queries
        $baseQuery = function ($excludePiar = false, $group = null) use ($sessionIds) {
            $query = Enrollment::query()
                ->where('academic_year_id', $this->exam->academic_year_id)
                ->where('status', 'ACTIVE')
                ->whereHas('studentAnswers.question', function ($query) use ($sessionIds) {
                    $query->whereIn('exam_session_id', $sessionIds);
                });

            if ($excludePiar) {
                $query->where('is_piar', false);
            }
            
            if ($group) {
                $query->where('group', $group);
            }

            return $query;
        };

        $areas = ['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'];
        $areaLabels = ['Lectura Crítica', 'Matemáticas', 'C. Sociales', 'C. Naturales', 'Inglés', 'Global'];
        
        // Obtener grupos disponibles
        $groups = Enrollment::query()
            ->where('academic_year_id', $this->exam->academic_year_id)
            ->where('status', 'ACTIVE')
            ->whereHas('studentAnswers.question', function ($query) use ($sessionIds) {
                $query->whereIn('exam_session_id', $sessionIds);
            })
            ->distinct()
            ->pluck('group')
            ->sort()
            ->values();

        $rows = collect();

        // ========================================
        // ENCABEZADO
        // ========================================
        $rows->push(['', ...$areaLabels]);
        
        // ========================================
        // SECCIÓN: PROMEDIOS GENERALES
        // ========================================
        $rows->push(['PROMEDIOS', '', '', '', '', '', '']);
        
        // Promedio CP (Con PIAR = TODOS)
        $cpStats = $this->getAllAreasStats($baseQuery(false));
        $rows->push([
            'Promedio CP (todos)',
            round($cpStats['lectura']['avg'], 0),
            round($cpStats['matematicas']['avg'], 0),
            round($cpStats['sociales']['avg'], 0),
            round($cpStats['naturales']['avg'], 0),
            round($cpStats['ingles']['avg'], 0),
            round($cpStats['global']['avg'], 0),
        ]);
        
        // Promedio SP (Sin PIAR = solo no-PIAR)
        $spStats = $this->getAllAreasStats($baseQuery(true));
        $rows->push([
            'Promedio SP',
            round($spStats['lectura']['avg'], 0),
            round($spStats['matematicas']['avg'], 0),
            round($spStats['sociales']['avg'], 0),
            round($spStats['naturales']['avg'], 0),
            round($spStats['ingles']['avg'], 0),
            round($spStats['global']['avg'], 0),
        ]);
        
        // Diferencia (SP - CP: positivo cuando SP es mayor)
        $rows->push([
            'Diferencia (SP - CP)',
            round($spStats['lectura']['avg'] - $cpStats['lectura']['avg'], 1),
            round($spStats['matematicas']['avg'] - $cpStats['matematicas']['avg'], 1),
            round($spStats['sociales']['avg'] - $cpStats['sociales']['avg'], 1),
            round($spStats['naturales']['avg'] - $cpStats['naturales']['avg'], 1),
            round($spStats['ingles']['avg'] - $cpStats['ingles']['avg'], 1),
            round($spStats['global']['avg'] - $cpStats['global']['avg'], 1),
        ]);
        
        // Fila vacía
        $rows->push(['', '', '', '', '', '', '']);
        
        // ========================================
        // SECCIÓN: PROMEDIOS POR GRUPO
        // ========================================
        $rows->push(['PROMEDIOS POR GRUPO', '', '', '', '', '', '']);
        
        foreach ($groups as $group) {
            // CP del grupo
            $groupCpStats = $this->getAllAreasStats($baseQuery(false, $group));
            $rows->push([
                "Promedio {$group} CP",
                round($groupCpStats['lectura']['avg'], 0),
                round($groupCpStats['matematicas']['avg'], 0),
                round($groupCpStats['sociales']['avg'], 0),
                round($groupCpStats['naturales']['avg'], 0),
                round($groupCpStats['ingles']['avg'], 0),
                round($groupCpStats['global']['avg'], 0),
            ]);
            
            // SP del grupo
            $groupSpStats = $this->getAllAreasStats($baseQuery(true, $group));
            $rows->push([
                "Promedio {$group} SP",
                round($groupSpStats['lectura']['avg'], 0),
                round($groupSpStats['matematicas']['avg'], 0),
                round($groupSpStats['sociales']['avg'], 0),
                round($groupSpStats['naturales']['avg'], 0),
                round($groupSpStats['ingles']['avg'], 0),
                round($groupSpStats['global']['avg'], 0),
            ]);
        }
        
        // Fila vacía
        $rows->push(['', '', '', '', '', '', '']);
        
        // ========================================
        // SECCIÓN: DESVIACIONES ESTÁNDAR
        // ========================================
        $rows->push(['DESVIACIONES ESTÁNDAR', '', '', '', '', '', '']);
        
        // Desv. Est. CP
        $rows->push([
            'Desv. Est. CP (todos)',
            round($cpStats['lectura']['stdDev'], 2),
            round($cpStats['matematicas']['stdDev'], 2),
            round($cpStats['sociales']['stdDev'], 2),
            round($cpStats['naturales']['stdDev'], 2),
            round($cpStats['ingles']['stdDev'], 2),
            round($cpStats['global']['stdDev'], 2),
        ]);
        
        // Desv. Est. SP
        $rows->push([
            'Desv. Est. SP',
            round($spStats['lectura']['stdDev'], 2),
            round($spStats['matematicas']['stdDev'], 2),
            round($spStats['sociales']['stdDev'], 2),
            round($spStats['naturales']['stdDev'], 2),
            round($spStats['ingles']['stdDev'], 2),
            round($spStats['global']['stdDev'], 2),
        ]);
        
        // Fila vacía
        $rows->push(['', '', '', '', '', '', '']);
        
        // ========================================
        // SECCIÓN: DESVIACIONES POR GRUPO
        // ========================================
        $rows->push(['DESVIACIONES POR GRUPO', '', '', '', '', '', '']);
        
        foreach ($groups as $group) {
            $groupCpStats = $this->getAllAreasStats($baseQuery(false, $group));
            $groupSpStats = $this->getAllAreasStats($baseQuery(true, $group));
            
            $rows->push([
                "Desv. Est. {$group} CP",
                round($groupCpStats['lectura']['stdDev'], 2),
                round($groupCpStats['matematicas']['stdDev'], 2),
                round($groupCpStats['sociales']['stdDev'], 2),
                round($groupCpStats['naturales']['stdDev'], 2),
                round($groupCpStats['ingles']['stdDev'], 2),
                round($groupCpStats['global']['stdDev'], 2),
            ]);
            
            $rows->push([
                "Desv. Est. {$group} SP",
                round($groupSpStats['lectura']['stdDev'], 2),
                round($groupSpStats['matematicas']['stdDev'], 2),
                round($groupSpStats['sociales']['stdDev'], 2),
                round($groupSpStats['naturales']['stdDev'], 2),
                round($groupSpStats['ingles']['stdDev'], 2),
                round($groupSpStats['global']['stdDev'], 2),
            ]);
        }
        
        // Fila vacía
        $rows->push(['', '', '', '', '', '', '']);
        
        // ========================================
        // SECCIÓN: TOTALES
        // ========================================
        $rows->push(['TOTALES', '', '', '', '', '', '']);
        $rows->push([
            'Total estudiantes CP',
            $cpStats['lectura']['count'],
            '', '', '', '', '',
        ]);
        $rows->push([
            'Total estudiantes SP',
            $spStats['lectura']['count'],
            '', '', '', '', '',
        ]);
        $rows->push([
            'Estudiantes con PIAR',
            $cpStats['lectura']['count'] - $spStats['lectura']['count'],
            '', '', '', '', '',
        ]);

        return $rows;
    }
    
    /**
     * Calcula estadísticas para todas las áreas de una vez
     */
    private function getAllAreasStats($query): array
    {
        $enrollments = $query->get();
        
        $areas = ['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'];
        $areaScores = array_fill_keys($areas, []);
        $globalScores = [];
        
        foreach ($enrollments as $enrollment) {
            $scores = $this->metricsService->getStudentAllAreaScores($enrollment, $this->exam);
            foreach ($areas as $area) {
                $areaScores[$area][] = $scores[$area];
            }
            $globalScores[] = $scores['global'];
        }
        
        $result = [];
        foreach ($areas as $area) {
            $result[$area] = $this->calculateStats($areaScores[$area]);
        }
        $result['global'] = $this->calculateStats($globalScores);
        
        return $result;
    }
    
    private function calculateStats(array $values): array
    {
        if (empty($values)) {
            return ['avg' => 0, 'stdDev' => 0, 'count' => 0];
        }
        
        $count = count($values);
        $avg = array_sum($values) / $count;
        
        if ($count < 2) {
            return ['avg' => $avg, 'stdDev' => 0, 'count' => $count];
        }
        
        $sum = 0;
        foreach ($values as $value) {
            $sum += pow($value - $avg, 2);
        }
        $stdDev = sqrt($sum / ($count - 1));
        
        return ['avg' => $avg, 'stdDev' => $stdDev, 'count' => $count];
    }

    public function title(): string
    {
        return 'Promedios y desviaciones';
    }
    
    public function columnFormats(): array
    {
        return [];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $sheet->getHighestRow();

        // Estilo del encabezado (fila 1)
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E40AF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '1E3A8A']]],
        ];
        $sheet->getStyle('A1:G1')->applyFromArray($headerStyle);
        
        // Estilo para títulos de sección
        $sectionStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => '1E40AF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DBEAFE']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ];
        
        // Aplicar estilos a las filas
        for ($row = 2; $row <= $lastRow; $row++) {
            $cellValue = $sheet->getCell("A{$row}")->getValue();
            
            // Títulos de sección
            if (in_array($cellValue, ['PROMEDIOS', 'PROMEDIOS POR GRUPO', 'DESVIACIONES ESTÁNDAR', 'DESVIACIONES POR GRUPO', 'TOTALES'])) {
                $sheet->getStyle("A{$row}:G{$row}")->applyFromArray($sectionStyle);
            }
            // Filas CP (normal, fondo claro)
            elseif (str_contains($cellValue ?? '', ' CP')) {
                $sheet->getStyle("A{$row}:G{$row}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F9FAFB']],
                ]);
            }
            // Filas SP (destacadas con negrita - FOCO)
            elseif (str_contains($cellValue ?? '', ' SP')) {
                $sheet->getStyle("A{$row}:G{$row}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
                ]);
            }
            // Filas de diferencia
            elseif (str_contains($cellValue ?? '', 'Diferencia')) {
                $sheet->getStyle("A{$row}:G{$row}")->applyFromArray([
                    'font' => ['italic' => true, 'color' => ['rgb' => '6B7280']],
                ]);
            }
        }
        
        // Bordes para toda la tabla
        $sheet->getStyle("A1:G{$lastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
        ]);
        
        // Alineación central para datos numéricos
        $sheet->getStyle("B2:G{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        // Ancho de primera columna
        $sheet->getColumnDimension('A')->setWidth(25);
        
        // Congelar primera fila
        $sheet->freezePane('A2');

        // Resetear selección a A1
        $sheet->setSelectedCells('A1');

        return [];
    }
}
