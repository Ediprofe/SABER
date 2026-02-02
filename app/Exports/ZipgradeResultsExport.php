<?php

namespace App\Exports;

use App\Models\Enrollment;
use App\Models\Exam;
use App\Services\ZipgradeMetricsService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ZipgradeResultsExport implements WithMultipleSheets
{
    public function __construct(
        private Exam $exam,
        private ?string $groupFilter = null,
        private ?bool $piarFilter = null,
    ) {}

    public function sheets(): array
    {
        return [
            'Resultados Completos' => new CompleteResultsSheet($this->exam, $this->groupFilter, $this->piarFilter),
            'Resultados Anonimizados' => new AnonymizedResultsSheet($this->exam, $this->groupFilter, $this->piarFilter),
        ];
    }
}

/**
 * Hoja con resultados completos (incluye Nombre, Grupo, PIAR)
 */
class CompleteResultsSheet implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithMapping, WithStyles, WithTitle
{
    private ZipgradeMetricsService $metricsService;

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
            ->with('student');

        if ($this->groupFilter) {
            $query->where('group', $this->groupFilter);
        }

        if ($this->piarFilter !== null) {
            $query->where('is_piar', $this->piarFilter);
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
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
    }

    public function map($enrollment): array
    {
        $student = $enrollment->student;

        $lectura = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'lectura');
        $matematicas = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'matematicas');
        $sociales = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'sociales');
        $naturales = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'naturales');
        $ingles = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'ingles');
        $global = $this->metricsService->getStudentGlobalScore($enrollment, $this->exam);

        return [
            $student->document_id ?? $student->code,
            $student->first_name.' '.$student->last_name,
            $enrollment->group,
            $enrollment->is_piar ? 'SI' : 'NO',
            round($lectura),
            round($matematicas),
            round($sociales),
            round($naturales),
            round($ingles),
            $global,
        ];
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
        $sheet->getStyle('A1:J1')->applyFromArray($headerStyle);

        // Estilo para las filas de datos (alternadas)
        for ($row = 2; $row <= $lastRow; $row++) {
            $fillColor = ($row % 2 == 0) ? 'F0F9FF' : 'FFFFFF'; // Azul muy claro / Blanco

            $sheet->getStyle("A{$row}:J{$row}")->applyFromArray([
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

        // Estilo para la columna Global (negrita)
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

        // Congelar la primera fila
        $sheet->freezePane('A2');

        return [];
    }

    public function columnFormats(): array
    {
        return [
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

/**
 * Hoja con resultados anonimizados (sin Nombre, Grupo, PIAR)
 */
class AnonymizedResultsSheet implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithMapping, WithStyles, WithTitle
{
    private ZipgradeMetricsService $metricsService;

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
            ->with('student')
            ->join('students', 'enrollments.student_id', '=', 'students.id')
            ->orderBy('students.document_id', 'asc')
            ->select('enrollments.*'); // Seleccionar solo columnas de enrollments para evitar conflictos

        if ($this->groupFilter) {
            $query->where('enrollments.group', $this->groupFilter);
        }

        if ($this->piarFilter !== null) {
            $query->where('enrollments.is_piar', $this->piarFilter);
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'Documento',
            'Lectura',
            'Matemáticas',
            'Sociales',
            'Naturales',
            'Inglés',
            'Global',
        ];
    }

    public function map($enrollment): array
    {
        $student = $enrollment->student;

        $lectura = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'lectura');
        $matematicas = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'matematicas');
        $sociales = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'sociales');
        $naturales = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'naturales');
        $ingles = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'ingles');
        $global = $this->metricsService->getStudentGlobalScore($enrollment, $this->exam);

        return [
            $student->document_id ?? $student->code,
            round($lectura),
            round($matematicas),
            round($sociales),
            round($naturales),
            round($ingles),
            $global,
        ];
    }

    public function title(): string
    {
        return 'Resultados Anonimizados';
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
            },
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        // Obtener la última fila con datos
        $lastRow = $sheet->getHighestRow();

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
        $sheet->getStyle('A1:G1')->applyFromArray($headerStyle);

        // Estilo para las filas de datos (alternadas)
        for ($row = 2; $row <= $lastRow; $row++) {
            $fillColor = ($row % 2 == 0) ? 'F0F9FF' : 'FFFFFF'; // Azul muy claro / Blanco

            $sheet->getStyle("A{$row}:G{$row}")->applyFromArray([
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

        // Estilo para la columna Global (negrita)
        $sheet->getStyle("G2:G{$lastRow}")->applyFromArray([
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

        // Congelar la primera fila
        $sheet->freezePane('A2');

        return [];
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT, // Documento como texto
            'B' => NumberFormat::FORMAT_NUMBER, // Lectura (sin decimales)
            'C' => NumberFormat::FORMAT_NUMBER, // Matemáticas
            'D' => NumberFormat::FORMAT_NUMBER, // Sociales
            'E' => NumberFormat::FORMAT_NUMBER, // Naturales
            'F' => NumberFormat::FORMAT_NUMBER, // Inglés
            'G' => NumberFormat::FORMAT_NUMBER, // Global
        ];
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
