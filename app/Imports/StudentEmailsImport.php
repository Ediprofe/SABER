<?php

namespace App\Imports;

use App\Models\Student;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class StudentEmailsImport implements ToCollection, WithHeadingRow
{
    public array $errors = [];
    public int $updated = 0;
    public int $notFound = 0;

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;

            // Detectar columna de documento
            $documentId = $row['documento'] ?? $row['document_id'] ?? $row['doc'] ?? null;

            // Detectar columna de email
            $email = $row['email'] ?? $row['correo'] ?? $row['e-mail'] ?? $row['correo_electronico'] ?? null;

            if (empty($documentId)) {
                $this->errors[] = "Fila {$rowNumber}: Documento vacío";
                continue;
            }

            if (empty($email)) {
                continue; // Saltar filas sin email
            }

            // Validar formato de email
            $email = trim($email);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->errors[] = "Fila {$rowNumber}: Email inválido ({$email})";
                continue;
            }

            // Buscar estudiante por documento
            $student = Student::where('document_id', trim($documentId))->first();

            if (!$student) {
                // Intentar por zipgrade_id
                $student = Student::where('zipgrade_id', trim($documentId))->first();
            }

            if ($student) {
                $student->update(['email' => $email]);
                $this->updated++;
            } else {
                $this->notFound++;
                $this->errors[] = "Fila {$rowNumber}: Estudiante con documento {$documentId} no encontrado";
            }
        }
    }
}
