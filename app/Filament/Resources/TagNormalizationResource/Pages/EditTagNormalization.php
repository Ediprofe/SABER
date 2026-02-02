<?php

namespace App\Filament\Resources\TagNormalizationResource\Pages;

use App\Filament\Resources\TagNormalizationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTagNormalization extends EditRecord
{
    protected static string $resource = TagNormalizationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
