<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\StudentResource;
use App\Models\Enrollment;
use Filament\Resources\Pages\CreateRecord;

class CreateStudent extends CreateRecord
{
    protected static string $resource = StudentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Asegurar que code = document_id
        if (!empty($data['document_id']) && empty($data['code'])) {
            $data['code'] = $data['document_id'];
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        // Crear la matrícula si se proporcionaron los datos
        $data = $this->form->getState();

        if (!empty($data['enrollment_year']) && !empty($data['enrollment_grade']) && !empty($data['enrollment_group'])) {
            Enrollment::create([
                'student_id' => $this->record->id,
                'academic_year_id' => $data['enrollment_year'],
                'grade' => $data['enrollment_grade'],
                'group' => $data['enrollment_group'],
                'is_piar' => $data['enrollment_is_piar'] ?? false,
                'status' => $data['enrollment_status'] ?? 'ACTIVE',
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
