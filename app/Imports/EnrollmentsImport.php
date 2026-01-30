<?php

namespace App\Imports;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class EnrollmentsImport implements ToCollection, WithHeadingRow
{
    private array $errors = [];

    public function collection(Collection $rows)
    {
        DB::beginTransaction();

        try {
            foreach ($rows as $index => $row) {
                $rowNum = $index + 2;

                // Validate required fields
                if (empty($row['student_code'])) {
                    $this->errors[] = "Fila {$rowNum}: El campo 'student_code' es requerido.";

                    continue;
                }
                if (empty($row['academic_year'])) {
                    $this->errors[] = "Fila {$rowNum}: El campo 'academic_year' es requerido.";

                    continue;
                }
                if (empty($row['grade'])) {
                    $this->errors[] = "Fila {$rowNum}: El campo 'grade' es requerido.";

                    continue;
                }
                if (empty($row['group'])) {
                    $this->errors[] = "Fila {$rowNum}: El campo 'group' es requerido.";

                    continue;
                }

                // Validate grade
                if (! in_array($row['grade'], [10, 11])) {
                    $this->errors[] = "Fila {$rowNum}: El grado debe ser 10 u 11.";

                    continue;
                }

                // Find student
                $student = Student::where('code', $row['student_code'])->first();
                if (! $student) {
                    $this->errors[] = "Fila {$rowNum}: El código '{$row['student_code']}' no existe en el sistema.";

                    continue;
                }

                // Find or create academic year
                $academicYear = AcademicYear::firstOrCreate(
                    ['year' => $row['academic_year']],
                    ['year' => $row['academic_year']]
                );

                // Check if enrollment already exists
                $existingEnrollment = Enrollment::where('student_id', $student->id)
                    ->where('academic_year_id', $academicYear->id)
                    ->first();

                if ($existingEnrollment) {
                    // Update existing enrollment
                    $existingEnrollment->update([
                        'grade' => $row['grade'],
                        'group' => $row['group'],
                        'is_piar' => strtoupper($row['is_piar'] ?? '') === 'SI',
                        'status' => strtoupper($row['status'] ?? 'ACTIVE'),
                    ]);
                } else {
                    // Create new enrollment
                    Enrollment::create([
                        'student_id' => $student->id,
                        'academic_year_id' => $academicYear->id,
                        'grade' => $row['grade'],
                        'group' => $row['group'],
                        'is_piar' => strtoupper($row['is_piar'] ?? '') === 'SI',
                        'status' => strtoupper($row['status'] ?? 'ACTIVE'),
                    ]);
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
