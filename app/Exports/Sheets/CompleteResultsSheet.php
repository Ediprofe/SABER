<?php

namespace App\Exports\Sheets;

use App\Models\Enrollment;
use App\Models\Exam;
use App\Services\ZipgradeMetricsService;
use App\Support\AreaConfig;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Hoja con resultados completos (incluye Nombre, Grupo, PIAR)
 */
class CompleteResultsSheet implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithMapping, WithStyles, WithTitle
{
    private ZipgradeMetricsService $metricsService;
    private ?array $cachedDimensions = null;

    public function __construct(
        private Exam $exam,
        private ?string $groupFilter = null,
        private ?bool $piarFilter = null,
    ) {
        $this->metricsService = app(ZipgradeMetricsService::class);
    }

    public function collection(): Collection
    {
        $query = Enrollment::query()
            ->where('academic_year_id', $this->exam->academic_year_id)
            ->where('status', 'ACTIVE')
            ->whereHas('studentAnswers.question.session', function ($query) {
                $query->where('exam_id', $this->exam->id);
            })
            ->with('student');

        if ($this->groupFilter) {
            $query->where('group', $this->groupFilter);
        }

        if ($this->piarFilter !== null) {
            $query->where('is_piar', $this->piarFilter);
        }

        return $query->join('students', 'enrollments.student_id', '=', 'students.id')
            ->orderBy('students.last_name')
            ->orderBy('students.first_name')
            ->select('enrollments.*')
            ->get();
    }

    /**
     * Obtiene todas las dimensiones/tags únicos para todas las áreas del examen.
     * Esto asegura que todas las filas tengan las mismas columnas.
     */
    private function getAllDimensions(): array
    {
        if ($this->cachedDimensions !== null) {
            return $this->cachedDimensions;
        }

        $areas = ['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'];
        $allDimensions = [];

        // Obtener un enrollment de ejemplo para consultar dimensiones
        $sessionIds = \App\Models\ExamSession::where('exam_id', $this->exam->id)->pluck('id');
        $sampleEnrollment = \App\Models\Enrollment::where('academic_year_id', $this->exam->academic_year_id)
            ->where('status', 'ACTIVE')
            ->whereHas('studentAnswers.question', function ($query) use ($sessionIds) {
                $query->whereIn('exam_session_id', $sessionIds);
            })
            ->first();

        if (! $sampleEnrollment) {
            return [];
        }

        foreach ($areas as $area) {
            $dimensionScores = $this->metricsService->getStudentDimensionScores($sampleEnrollment, $this->exam, $area);
            foreach ($dimensionScores as $type => $scores) {
                foreach ($scores as $tagName => $score) {
                    $key = $area.'_'.$type.'_'.str_replace(' ', '_', $tagName);
                    $allDimensions[$key] = [
                        'area' => $area,
                        'type' => $type,
                        'name' => $tagName,
                        'label' => $this->getDimensionLabel($area, $tagName),
                    ];
                }
            }
        }

        // Ordenar por área y tipo
        uasort($allDimensions, function ($a, $b) {
            $areaOrder = ['lectura' => 1, 'matematicas' => 2, 'sociales' => 3, 'naturales' => 4, 'ingles' => 5];
            $typeOrder = ['competencia' => 1, 'tipo_texto' => 2, 'nivel_lectura' => 3, 'componente' => 4, 'parte' => 5];

            if ($areaOrder[$a['area']] !== $areaOrder[$b['area']]) {
                return $areaOrder[$a['area']] - $areaOrder[$b['area']];
            }

            return $typeOrder[$a['type']] - $typeOrder[$b['type']];
        });

        $this->cachedDimensions = $allDimensions;

        return $allDimensions;
    }

    private function getDimensionLabel(string $area, string $tagName): string
    {
        $prefix = AreaConfig::getPrefix($area);
        return $prefix.'_'.$tagName;
    }

    public function headings(): array
    {
        $baseHeadings = [
            'Documento',
            'Nombre',
            'Grupo',
            'PIAR',
            'Lectura',
            'Matemáticas',
            'Sociales',
            'Naturales',
            'Inglés',
            'Global',
        ];

        // Agregar encabezados de dimensiones
        $dimensions = $this->getAllDimensions();
        $dimensionHeadings = array_map(fn ($d) => $d['label'], $dimensions);

        return array_merge($baseHeadings, $dimensionHeadings);
    }

    public function map($enrollment): array
    {
        $student = $enrollment->student;

        $scores = $this->metricsService->getStudentAllAreaScores($enrollment, $this->exam);

        $baseRow = [
            $student->document_id ?? $student->code,
            $student->last_name.' '.$student->first_name,
            $enrollment->group,
            $enrollment->is_piar ? 'SI' : 'NO',
            round($scores['lectura'], 0),
            round($scores['matematicas'], 0),
            round($scores['sociales'], 0),
            round($scores['naturales'], 0),
            round($scores['ingles'], 0),
            round($scores['global'], 0),
        ];

        // Agregar valores de dimensiones
        $dimensions = $this->getAllDimensions();
        $dimensionValues = [];
        $studentDimensions = [];

        // Pre-calcular todas las dimensiones del estudiante
        foreach (['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'] as $area) {
            $scores = $this->metricsService->getStudentDimensionScores($enrollment, $this->exam, $area);
            foreach ($scores as $type => $typeScores) {
                foreach ($typeScores as $tagName => $score) {
                    $key = $area.'_'.$type.'_'.str_replace(' ', '_', $tagName);
                    $studentDimensions[$key] = $score;
                }
            }
        }

        // Agregar valores en el mismo orden que los encabezados
        foreach ($dimensions as $key => $dim) {
            $dimensionValues[] = $studentDimensions[$key] ?? 0;
        }

        return array_merge($baseRow, $dimensionValues);
    }

    public function title(): string
    {
        return 'Resultados Completos';
    }

    /**
     * Habilitar filtros automáticos en el encabezado
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                // Habilitar AutoFilter en el rango del encabezado
                $lastRow = $event->sheet->getHighestRow();
                $lastColumn = $event->sheet->getHighestColumn();

                // Aplicar AutoFilter a todo el rango de datos (A1 hasta la última columna y fila)
                $event->sheet->getDelegate()->setAutoFilter("A1:{$lastColumn}{$lastRow}");
                $event->sheet->getDelegate()->setSelectedCells('A1');
            },
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        // Obtener la última fila con datos
        $lastRow = $sheet->getHighestRow();
        $lastColumn = $sheet->getHighestColumn();

        // Estilo para el encabezado (fila 1)
        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 11,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1E40AF'], // Azul oscuro
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '1E3A8A'],
                ],
            ],
        ];

        // Aplicar estilo al encabezado
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray($headerStyle);

        // Estilo para las filas de datos (alternadas)
        for ($row = 2; $row <= $lastRow; $row++) {
            $fillColor = ($row % 2 == 0) ? 'F0F9FF' : 'FFFFFF'; // Azul muy claro / Blanco

            $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => $fillColor],
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'E2E8F0'],
                    ],
                ],
            ]);
        }

        // Estilo para la columna Global (negrita) - columna J
        $sheet->getStyle("J2:J{$lastRow}")->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => '1E40AF'],
            ],
        ]);

        // Altura de filas
        $sheet->getRowDimension(1)->setRowHeight(25);
        for ($row = 2; $row <= $lastRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(20);
        }

        // Congelar columnas A y B, y la primera fila
        $sheet->freezePane('C2');

        // Resetear selección
        $sheet->setSelectedCells('A1');

        return [];
    }

    public function columnFormats(): array
    {
        $formats = [
            'A' => NumberFormat::FORMAT_TEXT, // Documento como texto
            'B' => NumberFormat::FORMAT_GENERAL, // Nombre
            'C' => NumberFormat::FORMAT_GENERAL, // Grupo
            'D' => NumberFormat::FORMAT_GENERAL, // PIAR
            'E' => NumberFormat::FORMAT_NUMBER, // Lectura (sin decimales)
            'F' => NumberFormat::FORMAT_NUMBER, // Matemáticas
            'G' => NumberFormat::FORMAT_NUMBER, // Sociales
            'H' => NumberFormat::FORMAT_NUMBER, // Naturales
            'I' => NumberFormat::FORMAT_NUMBER, // Inglés
            'J' => NumberFormat::FORMAT_NUMBER, // Global
        ];

        // Agregar formato para columnas de dimensiones (K en adelante)
        $dimensions = $this->getAllDimensions();
        $colIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString('K');
        foreach ($dimensions as $dim) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
            $formats[$col] = NumberFormat::FORMAT_NUMBER_00;
            $colIndex++;
        }

        return $formats;
    }

    /**
     * Para asegurar que el documento se guarde como texto y no dé error de formato
     */
    public function bindValue($cell, $value): bool
    {
        if ($cell->getColumn() === 'A') {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return false;
    }
}
