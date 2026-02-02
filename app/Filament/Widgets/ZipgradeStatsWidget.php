<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class ZipgradeStatsWidget extends Widget
{
    protected static string $view = 'filament.widgets.zipgrade-stats-widget';

    protected int|string|array $columnSpan = 'full';

    public function render(): \Illuminate\Contracts\View\View
    {
        return view(static::$view);
    }
}
