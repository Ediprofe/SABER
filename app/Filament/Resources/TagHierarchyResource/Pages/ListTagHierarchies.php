<?php

namespace App\Filament\Resources\TagHierarchyResource\Pages;

use App\Filament\Resources\TagHierarchyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTagHierarchies extends ListRecords
{
    protected static string $resource = TagHierarchyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
