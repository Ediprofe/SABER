<?php

namespace App\DTOs;

class ExamStatistics
{
    /**
     * @param  AreaStatistics[]  $areaStatistics
     */
    public function __construct(
        public int $totalStudents,
        public int $piarCount,
        public int $nonPiarCount,
        public float $globalAverage,
        public float $globalStdDev,
        public array $areaStatistics,
    ) {}
}
