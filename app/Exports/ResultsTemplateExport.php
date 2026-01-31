<?php

namespace App\Exports;

use App\Models\Exam;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ResultsTemplateExport implements FromCollection, WithHeadings
{
    public function __construct(
        private Exam $exam,
        private int $grade,
        private ?string $group = null,
    ) {}

    public function collection(): Collection
    {
        $query = $this->exam->academicYear->enrollments()
            ->with('student')
            ->where('grade', $this->grade);

        if ($this->group) {
            $query->where('group', $this->group);
        }

        return $query->get()->map(fn ($enrollment) => [
            'code' => $enrollment->student->code,
            'first_name' => $enrollment->student->first_name,
            'last_name' => $enrollment->student->last_name,
            'group' => $enrollment->group,
            'is_piar' => $enrollment->is_piar ? 'SI' : 'NO',
            'lectura' => '',
            'matematicas' => '',
            'sociales' => '',
            'naturales' => '',
            'ingles' => '',
        ]);
    }

    public function headings(): array
    {
        return [
            'code',
            'first_name',
            'last_name',
            'group',
            'is_piar',
            'lectura',
            'matematicas',
            'sociales',
            'naturales',
            'ingles',
        ];
    }
}
