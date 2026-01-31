<?php

namespace App\Imports;

use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAreaItem;
use App\Models\ExamDetailResult;
use App\Models\ExamResult;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Import results from a single sheet.
 * Used for importing both single-sheet and multi-sheet Excel files.
 * When used with multi-sheet files, each sheet gets its own instance.
 */
class ResultsImport implements ToCollection, WithHeadingRow
{
    private array $errors = [];

    private array $warnings = [];

    private int $totalRows = 0;

    private int $importedCount = 0;

    private bool $hasErrors = false;

    private ?string $sheetName = null;

    /**
     * Mapping of column names (e.g., 'nat_comp_uso_conocimiento') to exam_area_item_id.
     *
     * @var array<string, int>
     */
    private array $detailColumnMap = [];

    /**
     * Area prefix mapping for reverse lookup of column names.
     */
    private const AREA_PREFIXES = [
        'lectura' => 'lec',
        'matematicas' => 'mat',
        'sociales' => 'soc',
        'naturales' => 'nat',
        'ingles' => 'ing',
    ];

    public function __construct(
        private Exam $exam,
    ) {
        $this->loadDetailColumnMapping();
    }

    /**
     * Load the mapping of Excel column names to exam_area_item_ids.
     */
    private function loadDetailColumnMapping(): void
    {
        $areaConfigs = $this->exam->areaConfigs()->with('items')->get();

        foreach ($areaConfigs as $config) {
            $areaPrefix = self::AREA_PREFIXES[$config->area] ?? 'unk';

            foreach ($config->items as $item) {
                // Generate column name following the same pattern as ExamAreaItem::getColumnNameAttribute
                $columnName = $this->generateColumnName($item, $areaPrefix, $config);
                $this->detailColumnMap[$columnName] = $item->id;
            }
        }
    }

    /**
     * Generate column name for a detail item.
     */
    private function generateColumnName(ExamAreaItem $item, string $areaPrefix, $config): string
    {
        $slug = str_replace(' ', '_', strtolower($item->name));

        $dimensionPrefix = match ($config->dimension1_name) {
            'Competencias' => 'comp',
            'Partes' => 'part',
            default => 'dim1',
        };

        if ($item->dimension === 2) {
            $dimensionPrefix = match ($config->dimension2_name) {
                'Componentes' => 'cmpn',
                'Tipos de Texto' => 'txt',
                default => 'dim2',
            };
        }

        return "{$areaPrefix}_{$dimensionPrefix}_{$slug}";
    }

    /**
     * Check if we have any detail configurations configured for this exam.
     */
    private function hasDetailConfigurations(): bool
    {
        return ! empty($this->detailColumnMap);
    }

    /**
     * Set the sheet name (called from ExamResource before importing each sheet)
     */
    public function setSheetName(string $name): void
    {
        $this->sheetName = $name;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            $this->totalRows++;
            $rowNum = $index + 2; // +2 because: +1 for header, +1 for 0-index

            $prefix = $this->sheetName ? "Hoja '{$this->sheetName}'" : '';
            $location = $prefix ? "{$prefix}, fila {$rowNum}" : "Fila {$rowNum}";

            // Skip empty rows (no code) - Support both 'codigo' (Spanish) and 'code' (English)
            $code = $row['codigo'] ?? $row['code'] ?? null;
            if (empty($code)) {
                continue;
            }

            // Validate student code exists
            $student = Student::where('code', $code)->first();
            if (! $student) {
                $this->errors[] = "{$location}: El código '{$code}' no existe en el sistema.";

                continue;
            }

            // Find enrollment for this student and exam's academic year
            $enrollment = Enrollment::where('student_id', $student->id)
                ->where('academic_year_id', $this->exam->academic_year_id)
                ->first();

            if (! $enrollment) {
                $this->errors[] = "{$location}: El estudiante '{$code}' no tiene matrícula en el año {$this->exam->academicYear->year}.";

                continue;
            }

            // Validate scores (0-100 range)
            $areas = ['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'];
            $scores = [];
            $hasAnyScore = false;
            $rowHasErrors = false;

            foreach ($areas as $area) {
                $value = $row[$area] ?? null;

                if ($value === null || $value === '') {
                    $scores[$area] = null;

                    continue;
                }

                $score = (int) $value;

                if ($score < 0 || $score > 100) {
                    $this->errors[] = "{$location}: El puntaje de {$area} ({$score}) está fuera del rango permitido (0-100).";
                    $rowHasErrors = true;
                    $this->hasErrors = true;

                    continue;
                }

                $scores[$area] = $score;
                $hasAnyScore = true;
            }

            // Skip if row has validation errors
            if ($rowHasErrors) {
                continue;
            }

            // Skip if no scores provided
            if (! $hasAnyScore) {
                $this->warnings[] = "{$location}: No se proporcionaron puntajes para el estudiante '{$code}'. Se ignora la fila.";

                continue;
            }

            // Save to database immediately
            try {
                $examResult = ExamResult::firstOrNew([
                    'exam_id' => $this->exam->id,
                    'enrollment_id' => $enrollment->id,
                ]);

                $examResult->lectura = $scores['lectura'];
                $examResult->matematicas = $scores['matematicas'];
                $examResult->sociales = $scores['sociales'];
                $examResult->naturales = $scores['naturales'];
                $examResult->ingles = $scores['ingles'];
                $examResult->save();

                // Import detail results if configured
                if ($this->hasDetailConfigurations()) {
                    $this->importDetailResults($examResult, $row, $location);
                }

                $this->importedCount++;
            } catch (\Exception $e) {
                $this->errors[] = "{$location}: Error al guardar - ".$e->getMessage();
                $this->hasErrors = true;
            }
        }
    }

    public function headingRow(): int
    {
        return 1;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function getTotalRows(): int
    {
        return $this->totalRows;
    }

    public function getImportedCount(): int
    {
        return $this->importedCount;
    }

    public function hasErrors(): bool
    {
        return $this->hasErrors || ! empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getErrorMessage(): string
    {
        $message = "Error en la importación:\n";
        foreach ($this->errors as $error) {
            $message .= "- {$error}\n";
        }
        $message .= "\nNo se importó ningún registro. Corrija los errores e intente nuevamente.";

        return $message;
    }

    /**
     * Import detail results (competencias, componentes, etc.) for a student.
     */
    private function importDetailResults(ExamResult $examResult, $row, string $location): void
    {
        // Convert row to array if it's a Collection or object, otherwise use as-is
        $rowArray = is_array($row) ? $row : (is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array) $row);

        foreach ($this->detailColumnMap as $columnName => $itemId) {
            // Try to find the column in the row (case-insensitive matching)
            $value = null;

            // Exact match
            if (array_key_exists($columnName, $rowArray)) {
                $value = $rowArray[$columnName];
            } else {
                // Case-insensitive search
                $rowKeys = array_keys($rowArray);
                foreach ($rowKeys as $key) {
                    if (Str::lower($key) === Str::lower($columnName)) {
                        $value = $rowArray[$key];
                        break;
                    }
                }
            }

            // Skip if no value provided
            if ($value === null || $value === '') {
                // Delete any existing detail result for this item
                ExamDetailResult::where('exam_result_id', $examResult->id)
                    ->where('exam_area_item_id', $itemId)
                    ->delete();

                continue;
            }

            // Validate score range
            $score = (int) $value;

            if ($score < 0 || $score > 100) {
                $this->warnings[] = "{$location}: El puntaje detallado '{$columnName}' ({$score}) está fuera del rango permitido (0-100). Se ignora.";

                continue;
            }

            // Save or update detail result
            ExamDetailResult::updateOrCreate(
                [
                    'exam_result_id' => $examResult->id,
                    'exam_area_item_id' => $itemId,
                ],
                [
                    'score' => $score,
                ]
            );
        }
    }
}
