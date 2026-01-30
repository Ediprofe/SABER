<?php

namespace App\Imports;

use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ResultsImport implements ToCollection, WithHeadingRow
{
    private array $errors = [];

    private array $warnings = [];

    public function __construct(
        private Exam $exam,
    ) {}

    public function collection(Collection $rows)
    {
        DB::beginTransaction();

        try {
            foreach ($rows as $index => $row) {
                $rowNum = $index + 2;

                // Skip empty rows
                if (empty($row['code'])) {
                    continue;
                }

                // Validate student code exists
                $student = Student::where('code', $row['code'])->first();
                if (! $student) {
                    $this->errors[] = "Fila {$rowNum}: El código '{$row['code']}' no existe en el sistema.";

                    continue;
                }

                // Find enrollment for this student and exam's academic year
                $enrollment = Enrollment::where('student_id', $student->id)
                    ->where('academic_year_id', $this->exam->academic_year_id)
                    ->first();

                if (! $enrollment) {
                    $this->errors[] = "Fila {$rowNum}: El estudiante '{$row['code']}' no tiene matrícula en el año {$this->exam->academicYear->year}.";

                    continue;
                }

                // Validate scores (0-100 range)
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
                        $this->errors[] = "Fila {$rowNum}: El puntaje de {$area} ({$score}) está fuera del rango permitido (0-100).";

                        continue 2; // Skip to next row
                    }

                    $scores[$area] = $score;
                    $hasAnyScore = true;
                }

                // Skip if no scores provided
                if (! $hasAnyScore) {
                    $this->warnings[] = "Fila {$rowNum}: No se proporcionaron puntajes para el estudiante '{$row['code']}'. Se ignora la fila.";

                    continue;
                }

                // Find or create exam result
                $examResult = ExamResult::firstOrNew([
                    'exam_id' => $this->exam->id,
                    'enrollment_id' => $enrollment->id,
                ]);

                // Update scores
                $examResult->lectura = $scores['lectura'];
                $examResult->matematicas = $scores['matematicas'];
                $examResult->sociales = $scores['sociales'];
                $examResult->naturales = $scores['naturales'];
                $examResult->ingles = $scores['ingles'];

                // Global score is calculated automatically by the model
                $examResult->save();
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
