<?php

namespace App\Exports\Sheets;

use App\Models\Exam;
use App\Support\AreaConfig;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Hoja 3: Análisis por Pregunta
 */
class QuestionAnalysisSheet implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        private Exam $exam,
    ) {}

    public function collection(): Collection
    {
        $sessionIds = \App\Models\ExamSession::where('exam_id', $this->exam->id)->pluck('id');

        $questions = \App\Models\ExamQuestion::whereIn('exam_session_id', $sessionIds)
            ->with(['session', 'tags', 'questionTags.tag'])
            ->orderBy('exam_session_id')
            ->orderBy('question_number')
            ->get();

        return $questions->map(function ($question) {
            $session = $question->session;
            $sessionNumber = $session?->session_number ?? 1;

            // Determinar área y dimensiones desde tags
            $area = $this->getAreaFromQuestion($question);
            $dimension1 = $this->getDimension1FromQuestion($question, $area);
            $dimension2 = $this->getDimension2FromQuestion($question, $area);
            $dimension3 = $this->getDimension3FromQuestion($question, $area);

            // Calcular % de acierto desde respuestas
            $totalAnswers = $question->studentAnswers()->count();
            $correctAnswers = $question->studentAnswers()->where('is_correct', true)->count();
            $pctCorrect = $totalAnswers > 0 ? round(($correctAnswers / $totalAnswers) * 100, 2) : 0;

            // Determinar dificultad
            $dificultad = match (true) {
                $pctCorrect >= 70 => 'Fácil',
                $pctCorrect >= 40 => 'Media',
                default => 'Difícil',
            };

            return [
                'sesion' => $sessionNumber,
                'numero' => $question->question_number,
                'correcta' => $question->correct_answer ?? '—',
                'area' => $area ?? '—',
                'dim1' => $dimension1 ?? '—',
                'dim2' => $dimension2 ?? '—',
                'dim3' => $dimension3 ?? '—',
                'pct_acierto' => $pctCorrect,
                'dificultad' => $dificultad,
                'respuesta_1' => $question->response_1 ?? '—',
                'pct_1' => $question->response_1_pct ?? 0,
                'respuesta_2' => $question->response_2 ?? '—',
                'pct_2' => $question->response_2_pct ?? 0,
                'respuesta_3' => $question->response_3 ?? '—',
                'pct_3' => $question->response_3_pct ?? 0,
                'respuesta_4' => $question->response_4 ?? '—',
                'pct_4' => $question->response_4_pct ?? 0,
            ];
        });
    }

    private function getAreaFromQuestion($question): ?string
    {
        foreach ($question->tags as $tag) {
            if ($tag->tag_type === 'area') {
                return AreaConfig::normalizeAreaName($tag->tag_name)
                    ? AreaConfig::getLabel(AreaConfig::normalizeAreaName($tag->tag_name))
                    : $tag->tag_name;
            }
        }

        // Inferir desde parent_area
        foreach ($question->tags as $tag) {
            if ($tag->parent_area) {
                return AreaConfig::normalizeAreaName($tag->parent_area)
                    ? AreaConfig::getLabel(AreaConfig::normalizeAreaName($tag->parent_area))
                    : $tag->parent_area;
            }
        }

        return null;
    }

    private function getDimension1FromQuestion($question, ?string $area): ?string
    {
        // Para Inglés: buscar tags de tipo 'parte'
        if ($area === 'Inglés') {
            $parte = $question->tags->firstWhere('tag_type', 'parte');

            return $parte?->tag_name;
        }

        // Para otras áreas: buscar tags de tipo 'competencia'
        $competencia = $question->tags->firstWhere('tag_type', 'competencia');

        return $competencia?->tag_name;
    }

    private function getDimension2FromQuestion($question, ?string $area): ?string
    {
        // Para Inglés: no hay dimensión 2
        if ($area === 'Inglés') {
            return null;
        }

        // Para Lectura: buscar tipo_texto
        if (in_array($area, ['Lectura', 'Lectura Crítica'])) {
            $tipo = $question->tags->firstWhere('tag_type', 'tipo_texto');

            return $tipo?->tag_name;
        }

        // Para otras áreas: buscar componente
        $componente = $question->tags->firstWhere('tag_type', 'componente');

        return $componente?->tag_name;
    }

    private function getDimension3FromQuestion($question, ?string $area): ?string
    {
        // Solo Lectura tiene dimensión 3 (Nivel de Lectura)
        if (in_array($area, ['Lectura', 'Lectura Crítica'])) {
            $nivel = $question->tags->firstWhere('tag_type', 'nivel_lectura');

            return $nivel?->tag_name;
        }

        return null;
    }

    public function headings(): array
    {
        return [
            'Sesión',
            '#',
            'Correcta',
            'Área',
            'Dim 1',
            'Dim 2',
            'Dim 3',
            '% Acierto',
            'Dificultad',
            '1° Elegida',
            '1° %',
            '2° Elegida',
            '2° %',
            '3° Elegida',
            '3° %',
            '4° Elegida',
            '4° %',
        ];
    }

    public function title(): string
    {
        return 'Análisis por Pregunta';
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

        // Estilo encabezado
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E40AF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '1E3A8A']]],
        ];

        $sheet->getStyle('A1:Q1')->applyFromArray($headerStyle);

        // Filas alternadas
        for ($row = 2; $row <= $lastRow; $row++) {
            $fillColor = ($row % 2 == 0) ? 'F0F9FF' : 'FFFFFF';
            $sheet->getStyle("A{$row}:Q{$row}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fillColor]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
        }

        // Resaltar si 1° Elegida ≠ Correcta (columnas C=Correcta, J=1° Elegida)
        for ($row = 2; $row <= $lastRow; $row++) {
            $correcta = $sheet->getCell("C{$row}")->getValue();
            $primeraElegida = $sheet->getCell("J{$row}")->getValue();
            if ($primeraElegida && $correcta && $primeraElegida !== $correcta) {
                $sheet->getStyle("J{$row}")->applyFromArray([
                    'font' => ['color' => ['rgb' => 'DC2626'], 'bold' => true], // Rojo
                ]);
            }
        }

        $sheet->getRowDimension(1)->setRowHeight(25);
        $sheet->freezePane('A2');

        return [];
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_NUMBER, // Sesión
            'B' => NumberFormat::FORMAT_NUMBER, // #
            'H' => NumberFormat::FORMAT_NUMBER_00, // % Acierto
            'K' => NumberFormat::FORMAT_NUMBER_00, // 1° %
            'M' => NumberFormat::FORMAT_NUMBER_00, // 2° %
            'O' => NumberFormat::FORMAT_NUMBER_00, // 3° %
            'Q' => NumberFormat::FORMAT_NUMBER_00, // 4° %
        ];
    }
}
