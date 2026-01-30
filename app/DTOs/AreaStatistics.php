<?php

namespace App\DTOs;

class AreaStatistics
{
    public function __construct(
        public string $area,
        public float $average,
        public float $stdDev,
        public int $min,
        public int $max,
        public int $count,
    ) {}
}
