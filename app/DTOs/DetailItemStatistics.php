<?php

namespace App\DTOs;

class DetailItemStatistics
{
    public function __construct(
        public string $area,
        public int $dimension,
        public string $dimensionName,
        public string $itemName,
        public float $average,
        public float $stdDev,
        public int $min,
        public int $max,
        public int $count,
    ) {}
}
