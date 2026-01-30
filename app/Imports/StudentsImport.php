<?php

namespace App\Imports;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class StudentsImport implements ToCollection, WithHeadingRow
{
    private array $errors = [];

    public function collection(Collection $rows)
    {
        DB::beginTransaction();

        try {
            foreach ($rows as $index => $row) {
                $rowNum = $index + 2; // Excel row number (1-based + header)

                // Validate required fields
                if (empty($row['first_name'])) {
                    $this->errors[] = "Fila {$rowNum}: El campo 'first_name' es requerido.";

                    continue;
                }
                if (empty($row['last_name'])) {
                    $this->errors[] = "Fila {$rowNum}: El campo 'last_name' es requerido.";

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

                // Find or create academic year
                $academicYear = AcademicYear::firstOrCreate(
                    ['year' => $row['academic_year']],
                    ['year' => $row['academic_year']]
                );

                // Check if student exists (match by first_name + last_name)
                $student = Student::where('first_name', $row['first_name'])
                    ->where('last_name', $row['last_name'])
                    ->first();

                if (! $student) {
                    // Generate student code based on graduation year
                    $graduationYear = $row['academic_year'] + (11 - $row['grade']);
                    $code = $this->generateStudentCode($graduationYear);

                    $student = Student::create([
                        'code' => $code,
                        'first_name' => $row['first_name'],
                        'last_name' => $row['last_name'],
                    ]);
                }

                // Check if enrollment already exists for this year
                $existingEnrollment = Enrollment::where('student_id', $student->id)
                    ->where('academic_year_id', $academicYear->id)
                    ->first();

                if ($existingEnrollment) {
                    $this->errors[] = "Fila {$rowNum}: El estudiante ya tiene una matrícula en el año {$row['academic_year']}.";

                    continue;
                }

                // Create enrollment
                Enrollment::create([
                    'student_id' => $student->id,
                    'academic_year_id' => $academicYear->id,
                    'grade' => $row['grade'],
                    'group' => $row['group'],
                    'is_piar' => strtoupper($row['is_piar'] ?? '') === 'SI',
                    'status' => strtoupper($row['status'] ?? 'ACTIVE'),
                ]);
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

    private function generateStudentCode(int $graduationYear): string
    {
        $prefix = "STU-{$graduationYear}-";
        $lastStudent = Student::where('code', 'like', $prefix.'%')
            ->orderBy('code', 'desc')
            ->first();

        if ($lastStudent) {
            $lastNumber = (int) substr($lastStudent->code, -5);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix.str_pad($newNumber, 5, '0', STR_PAD_LEFT);
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
