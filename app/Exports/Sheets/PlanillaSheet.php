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
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Hoja Formato Planilla: Para profesores, escala 0-5, sin Documento ni PIAR
 */
class PlanillaSheet implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithMapping, WithStyles, WithTitle
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
        $sessionIds = \App\Models\ExamSession::where('exam_id', $this->exam->id)->pluck('id');

        $query = Enrollment::query()
            ->where('academic_year_id', $this->exam->academic_year_id)
            ->where('status', 'ACTIVE')
            ->whereHas('studentAnswers.question', function ($query) use ($sessionIds) {
                $query->whereIn('exam_session_id', $sessionIds);
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

    public function headings(): array
    {
        return [
            'Nombre',
            'Grupo',
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

        $scores = $this->metricsService->getStudentAllAreaScores($enrollment, $this->exam);

        // Convertir a escala 0-5
        // Áreas: (score / 100) * 5
        // Global: (score / 500) * 5
        return [
            $student->last_name.' '.$student->first_name,
            $enrollment->group,
            round(($scores['lectura'] / 100) * 5, 1),
            round(($scores['matematicas'] / 100) * 5, 1),
            round(($scores['sociales'] / 100) * 5, 1),
            round(($scores['naturales'] / 100) * 5, 1),
            round(($scores['ingles'] / 100) * 5, 1),
            round(($scores['global'] / 500) * 5, 1),
        ];
    }

    public function title(): string
    {
        return 'Formato Planilla';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $lastRow = $event->sheet->getHighestRow();
                $lastColumn = $event->sheet->getHighestColumn();
                $event->sheet->getDelegate()->setAutoFilter("A1:{$lastColumn}{$lastRow}");
                $event->sheet->getDelegate()->setSelectedCells('A1');
            },
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $sheet->getHighestRow();

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E40AF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '1E3A8A']]],
        ];

        $sheet->getStyle('A1:H1')->applyFromArray($headerStyle);

        for ($row = 2; $row <= $lastRow; $row++) {
            $fillColor = ($row % 2 == 0) ? 'F0F9FF' : 'FFFFFF';
            $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fillColor]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
            ]);
        }

        $sheet->getStyle("H2:H{$lastRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '1E40AF']],
        ]);

        $sheet->getRowDimension(1)->setRowHeight(25);
        $sheet->freezePane('A2');

        return [];
    }

    public function columnFormats(): array
    {
        return [
            'C' => '0.0',
            'D' => '0.0',
            'E' => '0.0',
            'F' => '0.0',
            'G' => '0.0',
            'H' => '0.0',
        ];
    }
}
