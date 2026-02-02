<?php

namespace App\Filament\Resources\TagNormalizationResource\Pages;

use App\Filament\Resources\TagNormalizationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTagNormalizations extends ListRecords
{
    protected static string $resource = TagNormalizationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
