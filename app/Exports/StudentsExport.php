<?php

namespace App\Exports;

use App\Models\Student;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentsExport implements FromCollection, WithHeadings
{
    public function __construct(
        private ?int $academicYearId = null,
    ) {}

    public function collection(): Collection
    {
        $query = Student::query()->with('enrollments.academicYear');

        if ($this->academicYearId) {
            $query->whereHas('enrollments', function ($q) {
                $q->where('academic_year_id', $this->academicYearId);
            });
        }

        return $query->get()->map(fn ($student) => [
            'code' => $student->code,
            'document_id' => $student->document_id,
            'zipgrade_id' => $student->zipgrade_id,
            'email' => $student->email,
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
        ]);
    }

    public function headings(): array
    {
        return [
            'Código',
            'Documento',
            'ZipgradeID',
            'Email',
            'Nombre',
            'Apellido',
        ];
    }
}
