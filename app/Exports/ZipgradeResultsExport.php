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
        $sheets = [
            'Resultados Completos' => new CompleteResultsSheet($this->exam, $this->groupFilter, $this->piarFilter),
            'Formato Planilla' => new PlanillaSheet($this->exam, $this->groupFilter, $this->piarFilter),
            'Resultados Anonimizados' => new AnonymizedResultsSheet($this->exam, $this->groupFilter, $this->piarFilter),
        ];

        // Solo agregar hojas de análisis si hay estadísticas importadas
        // Orden: Lectura → Matemáticas → Sociales → Naturales → Inglés
        if ($this->hasQuestionStats()) {
            $sheets['Análisis por Pregunta'] = new QuestionAnalysisSheet($this->exam);
            $sheets['Promedios y desviaciones'] = new StatisticsSheet($this->exam);
            $sheets['Lectura Crítica'] = new AreaAnalysisSheet($this->exam, 'lectura', 'Lectura Crítica');
            $sheets['Matemáticas'] = new AreaAnalysisSheet($this->exam, 'matematicas', 'Matemáticas');
            $sheets['Ciencias Sociales'] = new AreaAnalysisSheet($this->exam, 'sociales', 'Ciencias Sociales');
            $sheets['Ciencias Naturales'] = new AreaAnalysisSheet($this->exam, 'naturales', 'Ciencias Naturales');
            $sheets['Inglés'] = new AreaAnalysisSheet($this->exam, 'ingles', 'Inglés');
        }

        return $sheets;
    }

    /**
     * Verifica si el examen tiene estadísticas de preguntas importadas.
     */
    private function hasQuestionStats(): bool
    {
        $sessionIds = \App\Models\ExamSession::where('exam_id', $this->exam->id)->pluck('id');

        return \App\Models\ExamQuestion::whereIn('exam_session_id', $sessionIds)
            ->whereNotNull('correct_answer')
            ->exists();
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

        return $query->get();
    }

    /**
     * Obtiene todas las dimensiones/tags únicos para todas las áreas del examen.
     * Esto asegura que todas las filas tengan las mismas columnas.
     */
    private function getAllDimensions(): array
    {
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

        return $allDimensions;
    }

    private function getDimensionLabel(string $area, string $tagName): string
    {
        $prefix = match ($area) {
            'lectura' => 'Lec',
            'matematicas' => 'Mat',
            'sociales' => 'Soc',
            'naturales' => 'Nat',
            'ingles' => 'Ing',
            default => substr($area, 0, 3),
        };

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

        $lectura = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'lectura');
        $matematicas = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'matematicas');
        $sociales = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'sociales');
        $naturales = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'naturales');
        $ingles = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'ingles');
        $global = $this->metricsService->getStudentGlobalScore($enrollment, $this->exam);

        $baseRow = [
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
        $startCol = 'K';
        $colIndex = ord($startCol);
        foreach ($dimensions as $dim) {
            $col = chr($colIndex);
            if ($colIndex > ord('Z')) {
                // Para columnas AA, AB, etc.
                $col = 'A'.chr($colIndex - 26);
            }
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
            ->whereHas('studentAnswers.question.session', function ($query) {
                $query->where('exam_id', $this->exam->id);
            })
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
                return match ($tag->tag_name) {
                    'Ciencias', 'Naturales', 'Ciencias Naturales' => 'Naturales',
                    'Matemáticas', 'Matemática', 'Mat' => 'Matemáticas',
                    'Sociales', 'Ciencias Sociales', 'Social' => 'Sociales',
                    'Lectura', 'Lectura Crítica', 'lectura' => 'Lectura',
                    'Inglés', 'Ingles', 'English' => 'Inglés',
                    default => $tag->tag_name,
                };
            }
        }

        // Inferir desde parent_area
        foreach ($question->tags as $tag) {
            if ($tag->parent_area) {
                return match ($tag->parent_area) {
                    'Ciencias', 'Naturales', 'Ciencias Naturales' => 'Naturales',
                    'Matemáticas', 'Matemática', 'Mat' => 'Matemáticas',
                    'Sociales', 'Ciencias Sociales', 'Social' => 'Sociales',
                    'Lectura', 'Lectura Crítica', 'lectura' => 'Lectura',
                    'Inglés', 'Ingles', 'English' => 'Inglés',
                    default => $tag->parent_area,
                };
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

        $groupLabels = \App\Models\Enrollment::where('academic_year_id', $this->exam->academic_year_id)
            ->where('status', 'ACTIVE')
            ->whereHas('studentAnswers.question', function ($query) use ($sessionIds) {
                $query->whereIn('exam_session_id', $sessionIds);
            })
            ->select('group')
            ->distinct()
            ->orderBy('group')
            ->get()
            ->pluck('group')
            ->values();

        $dim1Data = $this->metricsService->getDimensionAnalysisByGroup($this->exam, $this->areaKey, 1);
        $dim2Data = $this->metricsService->getDimensionAnalysisByGroup($this->exam, $this->areaKey, 2);

        // Para Lectura, también obtener Dimensión 3 (Niveles de Lectura)
        $dim3Data = $this->areaKey === 'lectura'
            ? $this->metricsService->getDimensionAnalysisByGroup($this->exam, $this->areaKey, 3)
            : null;

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

        // DIMENSIÓN 1: Dos tablas completas (CON PIAR y SIN PIAR)
        $rows->push(['DIMENSIÓN 1'] + array_fill(0, $numGroups + 1, ''));
        $header1 = array_merge([$this->getDim1Label(), 'Promedio'], $groupArray);
        $rows->push($header1);

        // CON PIAR
        $rows->push(array_merge(['CON PIAR'], array_fill(0, $numGroups + 1, '')));
        foreach ($dim1PiarData as $itemName => $piarData) {
            $row = [$itemName, $piarData['con_piar']['promedio']];
            foreach ($groupArray as $groupLabel) {
                $row[] = $piarData['con_piar'][$groupLabel] ?? 0;
            }
            $rows->push($row);
        }

        // SIN PIAR
        $rows->push(array_merge(['SIN PIAR'], array_fill(0, $numGroups + 1, '')));
        foreach ($dim1PiarData as $itemName => $piarData) {
            $row = [$itemName, $piarData['sin_piar']['promedio']];
            foreach ($groupArray as $groupLabel) {
                $row[] = $piarData['sin_piar'][$groupLabel] ?? 0;
            }
            $rows->push($row);
        }

        // DIMENSIÓN 2: Dos tablas completas (CON PIAR y SIN PIAR)
        if ($this->areaKey !== 'ingles' && ! empty($dim2PiarData)) {
            $rows->push(array_merge([''], array_fill(0, $numGroups + 1, '')));
            $rows->push(['DIMENSIÓN 2'] + array_fill(0, $numGroups + 1, ''));
            $header2 = array_merge([$this->getDim2Label(), 'Promedio'], $groupArray);
            $rows->push($header2);

            // CON PIAR
            $rows->push(array_merge(['CON PIAR'], array_fill(0, $numGroups + 1, '')));
            foreach ($dim2PiarData as $itemName => $piarData) {
                $row = [$itemName, $piarData['con_piar']['promedio']];
                foreach ($groupArray as $groupLabel) {
                    $row[] = $piarData['con_piar'][$groupLabel] ?? 0;
                }
                $rows->push($row);
            }

            // SIN PIAR
            $rows->push(array_merge(['SIN PIAR'], array_fill(0, $numGroups + 1, '')));
            foreach ($dim2PiarData as $itemName => $piarData) {
                $row = [$itemName, $piarData['sin_piar']['promedio']];
                foreach ($groupArray as $groupLabel) {
                    $row[] = $piarData['sin_piar'][$groupLabel] ?? 0;
                }
                $rows->push($row);
            }
        }

        // DIMENSIÓN 3: Dos tablas completas (CON PIAR y SIN PIAR) - Solo Lectura
        if ($this->areaKey === 'lectura' && ! empty($dim3PiarData)) {
            $rows->push(array_merge([''], array_fill(0, $numGroups + 1, '')));
            $rows->push(['DIMENSIÓN 3'] + array_fill(0, $numGroups + 1, ''));
            $header3 = array_merge([$this->getDim3Label(), 'Promedio'], $groupArray);
            $rows->push($header3);

            // CON PIAR
            $rows->push(array_merge(['CON PIAR'], array_fill(0, $numGroups + 1, '')));
            foreach ($dim3PiarData as $itemName => $piarData) {
                $row = [$itemName, $piarData['con_piar']['promedio']];
                foreach ($groupArray as $groupLabel) {
                    $row[] = $piarData['con_piar'][$groupLabel] ?? 0;
                }
                $rows->push($row);
            }

            // SIN PIAR
            $rows->push(array_merge(['SIN PIAR'], array_fill(0, $numGroups + 1, '')));
            foreach ($dim3PiarData as $itemName => $piarData) {
                $row = [$itemName, $piarData['sin_piar']['promedio']];
                foreach ($groupArray as $groupLabel) {
                    $row[] = $piarData['sin_piar'][$groupLabel] ?? 0;
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
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];

        // Estilo para encabezados de comparación PIAR
        $piarHeaderStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => '92400E'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];

        // Estilo para filas de datos
        $dataStyle = [
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
        ];

        // Aplicar estilos
        for ($row = 1; $row <= $lastRow; $row++) {
            $value = $sheet->getCell("A{$row}")->getValue();

            if ($value === 'DIMENSIÓN 1' || $value === 'DIMENSIÓN 2' || $value === 'DIMENSIÓN 3') {
                $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray($sectionStyle);
            } elseif ($row === $dim1Row || $row === $dim2Row || $row === $dim3Row) {
                $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray($headerStyle);
            } elseif ($value === 'CON PIAR' || $value === 'SIN PIAR') {
                // Encabezado de comparación PIAR (tanto CON como SIN)
                $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray($piarHeaderStyle);
            } elseif (! empty($value) && $value !== $this->getDim1Label() && $value !== $this->getDim2Label() && $value !== $this->getDim3Label()) {
                $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray($dataStyle);
                // Negrita en primera columna
                $sheet->getStyle("A{$row}")->applyFromArray(['font' => ['bold' => true]]);
            }
        }

        return [];
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT, // Competencia/Componente/Parte como texto
            'B' => NumberFormat::FORMAT_NUMBER_00, // Promedio/CON PIAR
            'C' => NumberFormat::FORMAT_NUMBER_00, // SIN PIAR
            'D' => NumberFormat::FORMAT_TEXT, // Grupo 1
            'E' => NumberFormat::FORMAT_TEXT, // Grupo 2
            'F' => NumberFormat::FORMAT_TEXT, // Grupo 3
        ];
    }
}

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

        return $query->get();
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

        $lectura = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'lectura');
        $matematicas = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'matematicas');
        $sociales = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'sociales');
        $naturales = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'naturales');
        $ingles = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, 'ingles');
        $global = $this->metricsService->getStudentGlobalScore($enrollment, $this->exam);

        // Convertir a escala 0-5
        // Áreas: (score / 100) * 5
        // Global: (score / 500) * 5
        return [
            $student->first_name.' '.$student->last_name,
            $enrollment->group,
            round(($lectura / 100) * 5, 2),
            round(($matematicas / 100) * 5, 2),
            round(($sociales / 100) * 5, 2),
            round(($naturales / 100) * 5, 2),
            round(($ingles / 100) * 5, 2),
            round(($global / 500) * 5, 2),
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
            'C' => NumberFormat::FORMAT_NUMBER_00,
            'D' => NumberFormat::FORMAT_NUMBER_00,
            'E' => NumberFormat::FORMAT_NUMBER_00,
            'F' => NumberFormat::FORMAT_NUMBER_00,
            'G' => NumberFormat::FORMAT_NUMBER_00,
            'H' => NumberFormat::FORMAT_NUMBER_00,
        ];
    }
}

/**
 * Hoja Promedios y Desviaciones: Estadísticas CON PIAR y SIN PIAR
 */
class StatisticsSheet implements FromCollection, ShouldAutoSize, WithStyles, WithTitle
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
        $baseQuery = function ($includePiar) use ($sessionIds) {
            return Enrollment::query()
                ->where('academic_year_id', $this->exam->academic_year_id)
                ->where('status', 'ACTIVE')
                ->where('is_piar', $includePiar)
                ->whereHas('studentAnswers.question', function ($query) use ($sessionIds) {
                    $query->whereIn('exam_session_id', $sessionIds);
                });
        };

        $areas = ['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'];
        $areaLabels = [
            'lectura' => 'Lectura Crítica',
            'matematicas' => 'Matemáticas',
            'sociales' => 'Ciencias Sociales',
            'naturales' => 'Ciencias Naturales',
            'ingles' => 'Inglés',
        ];

        $rows = collect();

        // Encabezados
        $rows->push(['', '', '', '', '']);
        $rows->push(['MÉTRICA', 'ÁREA', 'CON PIAR', 'SIN PIAR', 'DIFERENCIA']);

        // Promedios por área
        foreach ($areas as $area) {
            $withPiar = $this->calculateAreaStats($baseQuery(true), $area);
            $withoutPiar = $this->calculateAreaStats($baseQuery(false), $area);
            $diff = $withPiar['avg'] - $withoutPiar['avg'];

            $rows->push([
                'Promedio',
                $areaLabels[$area],
                round($withPiar['avg'], 2),
                round($withoutPiar['avg'], 2),
                round($diff, 2),
            ]);
        }

        // Desviaciones estándar por área
        foreach ($areas as $area) {
            $withPiar = $this->calculateAreaStats($baseQuery(true), $area);
            $withoutPiar = $this->calculateAreaStats($baseQuery(false), $area);
            $diff = $withPiar['stdDev'] - $withoutPiar['stdDev'];

            $rows->push([
                'Desv. Estándar',
                $areaLabels[$area],
                round($withPiar['stdDev'], 2),
                round($withoutPiar['stdDev'], 2),
                round($diff, 2),
            ]);
        }

        // Fila vacía
        $rows->push(['', '', '', '', '']);

        // Promedio Global
        $globalWithPiar = $this->calculateGlobalStats($baseQuery(true));
        $globalWithoutPiar = $this->calculateGlobalStats($baseQuery(false));
        $globalDiff = $globalWithPiar['avg'] - $globalWithoutPiar['avg'];

        $rows->push([
            'Promedio Global',
            '',
            round($globalWithPiar['avg'], 2),
            round($globalWithoutPiar['avg'], 2),
            round($globalDiff, 2),
        ]);

        // Desviación Estándar Global
        $rows->push([
            'Desv. Estándar Global',
            '',
            round($globalWithPiar['stdDev'], 2),
            round($globalWithoutPiar['stdDev'], 2),
            round($globalWithPiar['stdDev'] - $globalWithoutPiar['stdDev'], 2),
        ]);

        // Total estudiantes
        $rows->push([
            'Total Estudiantes',
            '',
            $globalWithPiar['count'],
            $globalWithoutPiar['count'],
            $globalWithPiar['count'] + $globalWithoutPiar['count'],
        ]);

        return $rows;
    }

    private function calculateAreaStats($query, string $area): array
    {
        $enrollments = $query->get();
        $scores = [];

        foreach ($enrollments as $enrollment) {
            $scores[] = $this->metricsService->getStudentAreaScore($enrollment, $this->exam, $area);
        }

        if (empty($scores)) {
            return ['avg' => 0, 'stdDev' => 0, 'count' => 0];
        }

        $avg = array_sum($scores) / count($scores);
        $stdDev = $this->calculateStdDev($scores, $avg);

        return [
            'avg' => $avg,
            'stdDev' => $stdDev,
            'count' => count($scores),
        ];
    }

    private function calculateGlobalStats($query): array
    {
        $enrollments = $query->get();
        $scores = [];

        foreach ($enrollments as $enrollment) {
            $scores[] = $this->metricsService->getStudentGlobalScore($enrollment, $this->exam);
        }

        if (empty($scores)) {
            return ['avg' => 0, 'stdDev' => 0, 'count' => 0];
        }

        $avg = array_sum($scores) / count($scores);
        $stdDev = $this->calculateStdDev($scores, $avg);

        return [
            'avg' => $avg,
            'stdDev' => $stdDev,
            'count' => count($scores),
        ];
    }

    private function calculateStdDev(array $values, float $mean): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0;
        }

        $sum = 0;
        foreach ($values as $value) {
            $sum += pow($value - $mean, 2);
        }

        return sqrt($sum / ($count - 1));
    }

    public function title(): string
    {
        return 'Promedios y desviaciones';
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $sheet->getHighestRow();

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E40AF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];

        $sectionStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => '1E40AF'], 'size' => 12],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DBEAFE']],
        ];

        // Aplicar estilo al encabezado de la tabla (fila 2)
        $sheet->getStyle('A2:E2')->applyFromArray($headerStyle);

        // Estilo para filas de datos
        for ($row = 3; $row <= $lastRow; $row++) {
            $metric = $sheet->getCell("A{$row}")->getValue();
            if ($metric === 'Promedio Global' || $metric === 'Desv. Estándar Global') {
                $sheet->getStyle("A{$row}:E{$row}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0F9FF']],
                ]);
            }
        }

        return [];
    }
}
