<?php

namespace App\Imports;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class StudentsImport implements ToCollection, WithHeadingRow
{
    private array $errors = [];

    private array $columnMap = [];

    public function collection(Collection $rows)
    {
        if ($rows->isEmpty()) {
            throw new \Exception('El archivo está vacío');
        }

        // Detectar columnas de la primera fila
        $firstRow = $rows->first();
        $this->detectColumns($firstRow);

        Log::info('Columnas detectadas: '.json_encode($this->columnMap));

        DB::beginTransaction();

        try {
            foreach ($rows as $index => $row) {
                $rowNum = $index + 2;

                // Get values using detected column indices
                $firstName = $this->getValueFromRow($row, ['nombre', 'name', 'first_name', 'FirstName']);
                $lastName = $this->getValueFromRow($row, ['apellido', 'lastname', 'last_name', 'LastName']);
                $documentId = $this->getValueFromRow($row, ['documento', 'document', 'document_id', 'Documento']);
                $academicYear = $this->getValueFromRow($row, ['año', 'ano', 'year', 'academic_year', 'Año']);
                $grade = $this->getValueFromRow($row, ['grado', 'grade', 'Grado']);
                $group = $this->getValueFromRow($row, ['grupo', 'group', 'Grupo']);

                // Para PIAR, buscar específicamente la columna que contiene 'PIAR'
                $isPiar = $this->getPiarValue($row);

                $status = $this->getValueFromRow($row, ['estado', 'status', 'Estado']) ?? 'ACTIVE';

                Log::info("Row {$rowNum}: {$firstName} {$lastName} - PIAR='{$isPiar}'");

                // Validate required fields
                if (empty($firstName)) {
                    $this->errors[] = "Fila {$rowNum}: El campo 'Nombre' es requerido.";

                    continue;
                }
                if (empty($lastName)) {
                    $this->errors[] = "Fila {$rowNum}: El campo 'Apellido' es requerido.";

                    continue;
                }
                if (empty($academicYear)) {
                    $this->errors[] = "Fila {$rowNum}: El campo 'Año' es requerido.";

                    continue;
                }
                if (empty($grade)) {
                    $this->errors[] = "Fila {$rowNum}: El campo 'Grado' es requerido.";

                    continue;
                }
                if (empty($group)) {
                    $this->errors[] = "Fila {$rowNum}: El campo 'Grupo' es requerido.";

                    continue;
                }

                // Validate grade
                if (! in_array((int) $grade, [10, 11])) {
                    $this->errors[] = "Fila {$rowNum}: El grado debe ser 10 u 11.";

                    continue;
                }

                // Find or create academic year
                $academicYearModel = AcademicYear::firstOrCreate(
                    ['year' => (int) $academicYear],
                    ['year' => (int) $academicYear]
                );

                // Check if student exists
                $student = null;
                if (! empty($documentId)) {
                    $student = Student::where('document_id', $documentId)->first();
                }
                if (! $student) {
                    $student = Student::where('first_name', $firstName)
                        ->where('last_name', $lastName)
                        ->first();
                }

                // Create or update student
                if (! $student) {
                    $code = ! empty($documentId) ? $documentId : $this->generateStudentCode((int) $academicYear + (11 - (int) $grade));

                    $studentData = [
                        'code' => $code,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                    ];

                    if (! empty($documentId)) {
                        $studentData['document_id'] = $documentId;
                    }

                    $student = Student::create($studentData);
                } elseif (! empty($documentId) && empty($student->document_id)) {
                    $student->document_id = $documentId;
                    $student->save();
                }

                // Check if enrollment already exists
                $existingEnrollment = Enrollment::where('student_id', $student->id)
                    ->where('academic_year_id', $academicYearModel->id)
                    ->first();

                if ($existingEnrollment) {
                    $this->errors[] = "Fila {$rowNum}: El estudiante {$firstName} {$lastName} ya tiene una matrícula en el año {$academicYear}.";

                    continue;
                }

                // Procesar PIAR
                $piarValue = strtoupper(trim($isPiar ?? ''));
                $isPiarBoolean = ($piarValue === 'SI');

                Log::info("Row {$rowNum}: PIAR processed='{$piarValue}', isPIAR=".($isPiarBoolean ? 'true' : 'false'));

                // Create enrollment
                Enrollment::create([
                    'student_id' => $student->id,
                    'academic_year_id' => $academicYearModel->id,
                    'grade' => (int) $grade,
                    'group' => (string) $group,
                    'is_piar' => $isPiarBoolean,
                    'status' => strtoupper(trim($status ?? 'ACTIVE')),
                ]);
            }

            if (! empty($this->errors)) {
                DB::rollBack();
                throw new \Exception($this->getErrorMessage());
            }

            DB::commit();
            Log::info('Import completed successfully. Total rows: '.$rows->count());
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Import failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Detecta las columnas del Excel basándose en la primera fila
     */
    private function detectColumns($firstRow): void
    {
        foreach ($firstRow as $key => $value) {
            $header = strtoupper(trim($value));

            // Mapear por nombre de columna común
            if (strpos($header, 'NOMBRE') !== false && strpos($header, 'APELLIDO') === false) {
                $this->columnMap['nombre'] = $key;
            } elseif (strpos($header, 'APELLIDO') !== false) {
                $this->columnMap['apellido'] = $key;
            } elseif (strpos($header, 'DOCUMENTO') !== false || strpos($header, 'ID') !== false) {
                $this->columnMap['documento'] = $key;
            } elseif (strpos($header, 'AÑO') !== false || strpos($header, 'ANO') !== false || strpos($header, 'YEAR') !== false) {
                $this->columnMap['año'] = $key;
            } elseif (strpos($header, 'GRADO') !== false || strpos($header, 'GRADE') !== false) {
                $this->columnMap['grado'] = $key;
            } elseif (strpos($header, 'GRUPO') !== false || strpos($header, 'GROUP') !== false) {
                $this->columnMap['grupo'] = $key;
            } elseif (strpos($header, 'PIAR') !== false) {
                $this->columnMap['piar'] = $key;
                Log::info("Columna PIAR detectada: key='{$key}', header='{$value}'");
            } elseif (strpos($header, 'ESTADO') !== false || strpos($header, 'STATUS') !== false) {
                $this->columnMap['estado'] = $key;
            }
        }
    }

    /**
     * Obtiene el valor de PIAR buscando específicamente la columna PIAR
     */
    private function getPiarValue($row): ?string
    {
        // Si detectamos la columna PIAR, usar esa key específica
        if (isset($this->columnMap['piar'])) {
            $piarKey = $this->columnMap['piar'];
            $value = $row[$piarKey] ?? null;
            if ($value !== null && $value !== '') {
                return trim($value);
            }
        }

        // Fallback: buscar en todas las keys que contengan 'piar'
        foreach ($row as $key => $value) {
            if (stripos($key, 'piar') !== false) {
                if ($value !== null && $value !== '') {
                    return trim($value);
                }
            }
        }

        return null;
    }

    /**
     * Obtiene valor de la fila buscando en múltiples posibles nombres de columna
     */
    private function getValueFromRow($row, array $possibleNames): ?string
    {
        // Primero intentar con las columnas mapeadas
        foreach ($possibleNames as $name) {
            if (isset($this->columnMap[$name])) {
                $key = $this->columnMap[$name];
                $value = $row[$key] ?? null;
                if ($value !== null && $value !== '') {
                    return trim($value);
                }
            }
        }

        // Fallback: buscar en todas las keys de la fila
        foreach ($possibleNames as $name) {
            foreach ($row as $key => $value) {
                if (stripos($key, $name) !== false) {
                    if ($value !== null && $value !== '') {
                        return trim($value);
                    }
                }
            }
        }

        return null;
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
