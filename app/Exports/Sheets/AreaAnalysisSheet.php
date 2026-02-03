<?php

namespace App\Exports\Sheets;

use App\Models\Enrollment;
use App\Models\Exam;
use App\Services\ZipgradeMetricsService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Hojas 4-8: Análisis por Área
 */
class AreaAnalysisSheet implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithEvents, WithStyles, WithTitle
{
    private ZipgradeMetricsService $metricsService;

    public function __construct(
        private Exam $exam,
        private string $areaKey,
        private string $areaLabel,
    ) {
        $this->metricsService = app(ZipgradeMetricsService::class);
    }

    public function collection(): Collection
    {
        // Obtener grupos donde hay estudiantes con respuestas en este examen
        $sessionIds = \App\Models\ExamSession::where('exam_id', $this->exam->id)->pluck('id');

        // CORREGIDO: Solo grupos donde hay estudiantes CON respuestas reales
        $groupLabels = \App\Models\Enrollment::where('academic_year_id', $this->exam->academic_year_id)
            ->where('status', 'ACTIVE')
            ->whereHas('studentAnswers', function ($query) use ($sessionIds) {
                // Solo estudiantes que realmente tienen respuestas
                $query->whereHas('question', function ($q) use ($sessionIds) {
                    $q->whereIn('exam_session_id', $sessionIds);
                });
            })
            ->select('group')
            ->distinct()
            ->orderBy('group')
            ->get()
            ->pluck('group')
            ->map(fn ($group) => (string) $group) // Asegurar que sean strings
            ->values();

        $rows = collect();

        // Obtener datos CON PIAR y SIN PIAR
        $dim1PiarData = $this->metricsService->getDimensionPiarComparison($this->exam, $this->areaKey, 1);
        $dim2PiarData = $this->areaKey !== 'ingles'
            ? $this->metricsService->getDimensionPiarComparison($this->exam, $this->areaKey, 2)
            : [];
        $dim3PiarData = $this->areaKey === 'lectura'
            ? $this->metricsService->getDimensionPiarComparison($this->exam, $this->areaKey, 3)
            : [];

        // Tabla 1: Dimensión 1
        $rows->push(['', '', '', '', '', '']); // Fila vacía
        // Convertir groupLabels a array simple
        $groupArray = $groupLabels->toArray();
        $numGroups = count($groupArray);

        // DIMENSIÓN 1: Tablas CON PIAR, SIN PIAR, y DIFERENCIA
        $rows->push(['DIMENSIÓN 1'] + array_fill(0, $numGroups + 1, ''));
        $header1 = array_merge([$this->getDim1Label(), 'Promedio'], $groupArray);
        $rows->push($header1);

        // CON PIAR (todos)
        $rows->push(array_merge(['CON PIAR'], array_fill(0, $numGroups + 1, '')));
        foreach ($dim1PiarData as $itemName => $piarData) {
            $row = [$itemName, round($piarData['con_piar']['promedio'], 1)];
            foreach ($groupArray as $groupLabel) {
                $row[] = round($piarData['con_piar'][$groupLabel] ?? 0, 1);
            }
            $rows->push($row);
        }

        // SIN PIAR (solo no-PIAR) - FOCO
        $rows->push(array_merge(['SIN PIAR'], array_fill(0, $numGroups + 1, '')));
        foreach ($dim1PiarData as $itemName => $piarData) {
            $row = [$itemName, round($piarData['sin_piar']['promedio'], 1)];
            foreach ($groupArray as $groupLabel) {
                $row[] = round($piarData['sin_piar'][$groupLabel] ?? 0, 1);
            }
            $rows->push($row);
        }
        
        // DIFERENCIA (SP - CP)
        $rows->push(array_merge(['DIFERENCIA (SP-CP)'], array_fill(0, $numGroups + 1, '')));
        foreach ($dim1PiarData as $itemName => $piarData) {
            $spProm = $piarData['sin_piar']['promedio'];
            $cpProm = $piarData['con_piar']['promedio'];
            $row = [$itemName, round($spProm - $cpProm, 1)];
            foreach ($groupArray as $groupLabel) {
                $spVal = $piarData['sin_piar'][$groupLabel] ?? 0;
                $cpVal = $piarData['con_piar'][$groupLabel] ?? 0;
                $row[] = round($spVal - $cpVal, 1);
            }
            $rows->push($row);
        }

        // DIMENSIÓN 2: Tablas CON PIAR, SIN PIAR, y DIFERENCIA
        if ($this->areaKey !== 'ingles' && ! empty($dim2PiarData)) {
            $rows->push(array_merge([''], array_fill(0, $numGroups + 1, '')));
            $rows->push(['DIMENSIÓN 2'] + array_fill(0, $numGroups + 1, ''));
            $header2 = array_merge([$this->getDim2Label(), 'Promedio'], $groupArray);
            $rows->push($header2);

            // CON PIAR
            $rows->push(array_merge(['CON PIAR'], array_fill(0, $numGroups + 1, '')));
            foreach ($dim2PiarData as $itemName => $piarData) {
                $row = [$itemName, round($piarData['con_piar']['promedio'], 1)];
                foreach ($groupArray as $groupLabel) {
                    $row[] = round($piarData['con_piar'][$groupLabel] ?? 0, 1);
                }
                $rows->push($row);
            }

            // SIN PIAR - FOCO
            $rows->push(array_merge(['SIN PIAR'], array_fill(0, $numGroups + 1, '')));
            foreach ($dim2PiarData as $itemName => $piarData) {
                $row = [$itemName, round($piarData['sin_piar']['promedio'], 1)];
                foreach ($groupArray as $groupLabel) {
                    $row[] = round($piarData['sin_piar'][$groupLabel] ?? 0, 1);
                }
                $rows->push($row);
            }
            
            // DIFERENCIA (SP - CP)
            $rows->push(array_merge(['DIFERENCIA (SP-CP)'], array_fill(0, $numGroups + 1, '')));
            foreach ($dim2PiarData as $itemName => $piarData) {
                $spProm = $piarData['sin_piar']['promedio'];
                $cpProm = $piarData['con_piar']['promedio'];
                $row = [$itemName, round($spProm - $cpProm, 1)];
                foreach ($groupArray as $groupLabel) {
                    $spVal = $piarData['sin_piar'][$groupLabel] ?? 0;
                    $cpVal = $piarData['con_piar'][$groupLabel] ?? 0;
                    $row[] = round($spVal - $cpVal, 1);
                }
                $rows->push($row);
            }
        }

        // DIMENSIÓN 3: Tablas CON PIAR, SIN PIAR, y DIFERENCIA - Solo Lectura
        if ($this->areaKey === 'lectura' && ! empty($dim3PiarData)) {
            $rows->push(array_merge([''], array_fill(0, $numGroups + 1, '')));
            $rows->push(['DIMENSIÓN 3'] + array_fill(0, $numGroups + 1, ''));
            $header3 = array_merge([$this->getDim3Label(), 'Promedio'], $groupArray);
            $rows->push($header3);

            // CON PIAR
            $rows->push(array_merge(['CON PIAR'], array_fill(0, $numGroups + 1, '')));
            foreach ($dim3PiarData as $itemName => $piarData) {
                $row = [$itemName, round($piarData['con_piar']['promedio'], 1)];
                foreach ($groupArray as $groupLabel) {
                    $row[] = round($piarData['con_piar'][$groupLabel] ?? 0, 1);
                }
                $rows->push($row);
            }

            // SIN PIAR - FOCO
            $rows->push(array_merge(['SIN PIAR'], array_fill(0, $numGroups + 1, '')));
            foreach ($dim3PiarData as $itemName => $piarData) {
                $row = [$itemName, round($piarData['sin_piar']['promedio'], 1)];
                foreach ($groupArray as $groupLabel) {
                    $row[] = round($piarData['sin_piar'][$groupLabel] ?? 0, 1);
                }
                $rows->push($row);
            }
            
            // DIFERENCIA (SP - CP)
            $rows->push(array_merge(['DIFERENCIA (SP-CP)'], array_fill(0, $numGroups + 1, '')));
            foreach ($dim3PiarData as $itemName => $piarData) {
                $spProm = $piarData['sin_piar']['promedio'];
                $cpProm = $piarData['con_piar']['promedio'];
                $row = [$itemName, round($spProm - $cpProm, 1)];
                foreach ($groupArray as $groupLabel) {
                    $spVal = $piarData['sin_piar'][$groupLabel] ?? 0;
                    $cpVal = $piarData['con_piar'][$groupLabel] ?? 0;
                    $row[] = round($spVal - $cpVal, 1);
                }
                $rows->push($row);
            }
        }

        return $rows;
    }

    private function getDim1Label(): string
    {
        return match ($this->areaKey) {
            'ingles' => 'Parte',
            default => 'Competencia',
        };
    }

    private function getDim2Label(): string
    {
        return match ($this->areaKey) {
            'lectura' => 'Tipo de Texto',
            default => 'Componente',
        };
    }

    private function getDim3Label(): string
    {
        // Solo Lectura tiene Dimensión 3 (Niveles de Lectura)
        return 'Nivel de Lectura';
    }

    public function title(): string
    {
        return $this->areaLabel;
    }

    public function registerEvents(): array
    {
        return [];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $sheet->getHighestRow();
        $lastColumn = $sheet->getHighestColumn();

        // Encontrar filas de encabezados de tabla
        $dim1Row = null;
        $dim2Row = null;
        $dim3Row = null;
        for ($row = 1; $row <= $lastRow; $row++) {
            $value = $sheet->getCell("A{$row}")->getValue();
            if ($value === 'DIMENSIÓN 1') {
                $dim1Row = $row + 1; // La siguiente fila es el encabezado de columnas
            }
            if ($value === 'DIMENSIÓN 2') {
                $dim2Row = $row + 1;
            }
            if ($value === 'DIMENSIÓN 3') {
                $dim3Row = $row + 1;
            }
        }

        // Estilo para encabezados de sección
        $sectionStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => '1E40AF'], 'size' => 12],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DBEAFE']],
        ];

        // Estilo para encabezados de columnas
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '3B82F6']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ];

        // Estilo para encabezado CON PIAR (normal)
        $conPiarHeaderStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => '6B7280'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F9FAFB']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
        
        // Estilo para encabezado SIN PIAR (destacado - FOCO)
        $sinPiarHeaderStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => '1E40AF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DBEAFE']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
        
        // Estilo para encabezado DIFERENCIA
        $diffHeaderStyle = [
            'font' => ['bold' => true, 'italic' => true, 'color' => ['rgb' => '92400E'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];

        // Estilo para filas de datos
        $dataStyle = [
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ];
        
        // Variable para rastrear en qué sección estamos
        $currentSection = null; // 'CON PIAR', 'SIN PIAR', 'DIFERENCIA'

        // Aplicar estilos por fila
        for ($row = 1; $row <= $lastRow; $row++) {
            $value = $sheet->getCell("A{$row}")->getValue();

            if ($value === 'DIMENSIÓN 1' || $value === 'DIMENSIÓN 2' || $value === 'DIMENSIÓN 3') {
                $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray($sectionStyle);
                $currentSection = null;
            } elseif ($row === $dim1Row || $row === $dim2Row || $row === $dim3Row) {
                $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray($headerStyle);
            } elseif ($value === 'CON PIAR') {
                $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray($conPiarHeaderStyle);
                $currentSection = 'CON PIAR';
            } elseif ($value === 'SIN PIAR') {
                $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray($sinPiarHeaderStyle);
                $currentSection = 'SIN PIAR';
            } elseif (str_contains($value ?? '', 'DIFERENCIA')) {
                $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray($diffHeaderStyle);
                $currentSection = 'DIFERENCIA';
            } elseif (! empty($value) && $value !== $this->getDim1Label() && $value !== $this->getDim2Label() && $value !== $this->getDim3Label()) {
                $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray($dataStyle);
                
                // Aplicar estilo según la sección actual
                if ($currentSection === 'SIN PIAR') {
                    // Datos SIN PIAR en negrita (FOCO)
                    $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
                        'font' => ['bold' => true],
                    ]);
                } elseif ($currentSection === 'DIFERENCIA') {
                    // Datos DIFERENCIA en cursiva gris
                    $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
                        'font' => ['italic' => true, 'color' => ['rgb' => '6B7280']],
                    ]);
                }
                
                // Negrita en primera columna (nombre)
                $sheet->getStyle("A{$row}")->applyFromArray(['font' => ['bold' => true]]);
            }
        }
        
        // Ajustes globales de columnas
        $sheet->getColumnDimension('A')->setWidth(40); // Primera columna ancha
        
        // Columnas de datos (B en adelante) centradas y ancho uniforme
        $sheet->getStyle("B1:{$lastColumn}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        // Calcular número de columnas de grupos
        $colIndex = 2; // Columna B
        while ($colIndex <= \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($lastColumn)) {
            $colString = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
            $sheet->getColumnDimension($colString)->setWidth(12); // Ancho uniforme
            $colIndex++;
        }
        
        // Congelar primera columna (A) para que el nombre del ítem siempre sea visible
        $sheet->freezePane('B2'); // Congela A y la fila 1 (encabezados)

        // IMPORTANTE: Resetear la selección a A1 para evitar que aparezca todo sombreado/seleccionado
        $sheet->setSelectedCells('A1');

        return [];
    }

    public function columnFormats(): array
    {
        $formats = [
            'A' => NumberFormat::FORMAT_TEXT, // Competencia/Componente/Parte como texto
            'B' => NumberFormat::FORMAT_NUMBER_00, // Promedio
            // Las demas columnas se calculan dinámicamente
        ];

        // Obtener grupos dinámicamente para aplicar formato numérico a todas las columnas
        $sessionIds = \App\Models\ExamSession::where('exam_id', $this->exam->id)->pluck('id');
        $groupCount = \App\Models\Enrollment::where('academic_year_id', $this->exam->academic_year_id)
            ->where('status', 'ACTIVE')
            ->whereHas('studentAnswers.question', function ($query) use ($sessionIds) {
                $query->whereIn('exam_session_id', $sessionIds);
            })
            ->distinct()
            ->count('group');

        // Aplicar formato numérico a todas las columnas de grupos (C en adelante)
        $startColumn = 3; // Columna C (primera de grupos, B es promedio general)
        // Nota: B es promedio global, C, D, E... son grupos.
        // Espera, revisemos la estructura: [Item, Promedio, G1, G2, G3...]
        // Columna B = Promedio, Columnas C... = Grupos
        
        // Aplicar formato numérico a todas las columnas de datos
        // Asumiendo hasta 20 grupos para cubrir de sobra
        for ($i = 0; $i < 20; $i++) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($startColumn + $i);
            $formats[$column] = NumberFormat::FORMAT_NUMBER_00;
        }

        return $formats;
    }
}
