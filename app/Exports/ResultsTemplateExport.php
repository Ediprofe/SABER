<?php

namespace App\Exports;

use App\Models\Exam;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class ResultsTemplateExport implements WithMultipleSheets
{
    private array $detailColumns = [];

    private bool $hasDetailConfig = false;

    public function __construct(
        private Exam $exam,
        private int $grade,
        private ?string $group = null,
    ) {
        $this->initializeDetailColumns();
    }

    /**
     * Initialize detail columns if exam has detail configuration.
     */
    private function initializeDetailColumns(): void
    {
        $this->hasDetailConfig = $this->exam->areaConfigs()->exists();

        if (! $this->hasDetailConfig) {
            return;
        }

        $configs = $this->exam->areaConfigs()->with(['itemsDimension1', 'itemsDimension2'])->get();

        foreach ($configs as $config) {
            foreach ($config->itemsDimension1 as $item) {
                $this->detailColumns[] = [
                    'column_name' => $item->column_name,
                    'name' => $item->name,
                    'area' => $config->area,
                    'dimension' => 1,
                ];
            }

            foreach ($config->itemsDimension2 as $item) {
                $this->detailColumns[] = [
                    'column_name' => $item->column_name,
                    'name' => $item->name,
                    'area' => $config->area,
                    'dimension' => 2,
                ];
            }
        }
    }

    /**
     * Get sheets for the export.
     * If specific group is provided, returns one sheet.
     * Otherwise, returns one sheet per group.
     */
    public function sheets(): array
    {
        $sheets = [];

        if ($this->group) {
            // Single group export
            $sheets[] = new SingleGroupSheet(
                $this->exam,
                $this->grade,
                $this->group,
                $this->detailColumns
            );
        } else {
            // Multiple groups - one sheet per group
            $groups = $this->exam->academicYear->enrollments()
                ->where('grade', $this->grade)
                ->distinct()
                ->pluck('group')
                ->sort()
                ->values();

            foreach ($groups as $group) {
                $sheets[] = new SingleGroupSheet(
                    $this->exam,
                    $this->grade,
                    $group,
                    $this->detailColumns
                );
            }
        }

        return $sheets;
    }
}

/**
 * Single sheet for a specific group.
 */
class SingleGroupSheet implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(
        private Exam $exam,
        private int $grade,
        private string $group,
        private array $detailColumns,
    ) {}

    public function collection(): Collection
    {
        $query = $this->exam->academicYear->enrollments()
            ->with('student')
            ->where('grade', $this->grade)
            ->where('group', $this->group);

        return $query->get()->map(function ($enrollment) {
            $row = [
                'codigo' => $enrollment->student->code,
                'nombre' => $enrollment->student->first_name.' '.$enrollment->student->last_name,
                'grupo' => $enrollment->group,
                'es_piar' => $enrollment->is_piar ? 'SI' : 'NO',
                'lectura' => '',
                'matematicas' => '',
                'sociales' => '',
                'naturales' => '',
                'ingles' => '',
            ];

            // Add empty columns for detail items
            foreach ($this->detailColumns as $column) {
                $row[$column['column_name']] = '';
            }

            return $row;
        });
    }

    public function headings(): array
    {
        $headings = [
            'codigo',
            'nombre',
            'grupo',
            'es_piar',
            'lectura',
            'matematicas',
            'sociales',
            'naturales',
            'ingles',
        ];

        // Add detail column headings
        foreach ($this->detailColumns as $column) {
            $headings[] = $column['column_name'];
        }

        return $headings;
    }

    public function title(): string
    {
        return $this->group;
    }
}
