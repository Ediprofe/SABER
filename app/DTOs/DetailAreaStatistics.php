<?php

namespace App\DTOs;

class DetailAreaStatistics
{
    public function __construct(
        public string $area,
        public string $areaLabel,
        public ?array $dimension1,
        public ?array $dimension2,
    ) {}
}
