<?php

namespace App\Imports;

use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamDetailResult;
use App\Models\ExamResult;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class DetailResultsImport implements ToCollection, WithHeadingRow, WithMultipleSheets
{
    private array $errors = [];

    private array $warnings = [];

    private array $columnMapping = [];

    private bool $hasDetailConfig = false;

    public function __construct(
        private Exam $exam,
    ) {
        $this->initializeColumnMapping();
    }

    /**
     * Initialize column mapping for detail items.
     */
    private function initializeColumnMapping(): void
    {
        $this->hasDetailConfig = $this->exam->areaConfigs()->exists();

        if (! $this->hasDetailConfig) {
            return;
        }

        // Build mapping of column names to exam_area_item_id
        $configs = $this->exam->areaConfigs()->with('items')->get();

        foreach ($configs as $config) {
            foreach ($config->items as $item) {
                $columnName = $item->column_name;
                $this->columnMapping[$columnName] = $item->id;
            }
        }
    }

    /**
     * Define which sheets to import.
     * We import all sheets and validate group names during processing.
     */
    public function sheets(): array
    {
        return [
            0 => $this,
            1 => $this,
            2 => $this,
            3 => $this,
            4 => $this,
            5 => $this,
            6 => $this,
            7 => $this,
            8 => $this,
            9 => $this,
        ];
    }

    public function collection(Collection $rows)
    {
        // This will be called for each sheet
        // We process each sheet independently
        DB::beginTransaction();

        try {
            $sheetName = $this->getSheetName();
            $currentGroup = $sheetName;

            // Validate if this sheet name corresponds to a valid group
            $validGroups = Enrollment::where('academic_year_id', $this->exam->academic_year_id)
                ->distinct()
                ->pluck('group')
                ->toArray();

            if (! in_array($currentGroup, $validGroups)) {
                $this->warnings[] = "Hoja '{$currentGroup}': No corresponde a un grupo válido. Se ignora la hoja.";
                DB::rollBack();

                return;
            }

            foreach ($rows as $index => $row) {
                $rowNum = $index + 2;

                // Skip empty rows - try both 'codigo' and 'code' for backwards compatibility
                $code = $row['codigo'] ?? $row['code'] ?? null;
                if (empty($code)) {
                    continue;
                }

                // Validate student code exists
                $student = Student::where('code', $code)->first();
                if (! $student) {
                    $this->errors[] = "Hoja {$currentGroup}, Fila {$rowNum}: El código '{$code}' no existe en el sistema.";

                    continue;
                }

                // Find enrollment for this student and exam's academic year
                $enrollment = Enrollment::where('student_id', $student->id)
                    ->where('academic_year_id', $this->exam->academic_year_id)
                    ->first();

                if (! $enrollment) {
                    $this->errors[] = "Hoja {$currentGroup}, Fila {$rowNum}: El estudiante '{$code}' no tiene matrícula en el año {$this->exam->academicYear->year}.";

                    continue;
                }

                // Validate enrollment group matches sheet name
                if ($enrollment->group !== $currentGroup) {
                    $this->warnings[] = "Hoja {$currentGroup}, Fila {$rowNum}: El estudiante '{$code}' pertenece al grupo '{$enrollment->group}' pero está en la hoja '{$currentGroup}'.";
                }

                // Validate and process main area scores
                $areas = ['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'];
                $scores = [];
                $hasAnyScore = false;

                foreach ($areas as $area) {
                    $value = $row[$area] ?? null;

                    if ($value === null || $value === '') {
                        $scores[$area] = null;

                        continue;
                    }

                    $score = (int) $value;

                    if ($score < 0 || $score > 100) {
                        $this->errors[] = "Hoja {$currentGroup}, Fila {$rowNum}: El puntaje de {$area} ({$score}) está fuera del rango permitido (0-100).";

                        continue 2; // Skip to next row
                    }

                    $scores[$area] = $score;
                    $hasAnyScore = true;
                }

                // Skip if no scores provided
                if (! $hasAnyScore) {
                    $this->warnings[] = "Hoja {$currentGroup}, Fila {$rowNum}: No se proporcionaron puntajes para el estudiante '{$code}'. Se ignora la fila.";

                    continue;
                }

                // Find or create exam result
                $examResult = ExamResult::firstOrNew([
                    'exam_id' => $this->exam->id,
                    'enrollment_id' => $enrollment->id,
                ]);

                // Update main area scores
                $examResult->lectura = $scores['lectura'];
                $examResult->matematicas = $scores['matematicas'];
                $examResult->sociales = $scores['sociales'];
                $examResult->naturales = $scores['naturales'];
                $examResult->ingles = $scores['ingles'];

                // Global score is calculated automatically by the model
                $examResult->save();

                // Process detail results if configuration exists
                if ($this->hasDetailConfig) {
                    $this->processDetailResults($examResult, $row, $currentGroup, $rowNum);
                }
            }

            if (! empty($this->errors)) {
                DB::rollBack();
                throw new \Exception($this->getErrorMessage());
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Process detail results for a row.
     */
    private function processDetailResults(ExamResult $examResult, $row, string $sheetName, int $rowNum): void
    {
        foreach ($this->columnMapping as $columnName => $itemId) {
            $value = $row[$columnName] ?? null;

            // Skip if no value
            if ($value === null || $value === '') {
                continue;
            }

            $score = (int) $value;

            // Validate score range
            if ($score < 0 || $score > 100) {
                $this->errors[] = "Hoja {$sheetName}, Fila {$rowNum}: El puntaje de {$columnName} ({$score}) está fuera del rango permitido (0-100).";

                continue;
            }

            // Find or create detail result
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

    /**
     * Get current sheet name.
     * This is a workaround since we don't have direct access to sheet name in collection().
     */
    private function getSheetName(): string
    {
        // For now, we'll use a simple approach - the group should be in the data
        // or we can get it from the first row
        return 'default';
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    private function getErrorMessage(): string
    {
        $message = "Error en la importación:\n";
        foreach ($this->errors as $error) {
            $message .= "- {$error}\n";
        }
        $message .= "\nNo se importó ningún registro. Corrija los errores e intente nuevamente.";

        return $message;
    }
}
