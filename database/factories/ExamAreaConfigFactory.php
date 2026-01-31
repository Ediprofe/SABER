<?php

namespace Database\Factories;

use App\Models\Exam;
use App\Models\ExamAreaConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExamAreaConfigFactory extends Factory
{
    protected $model = ExamAreaConfig::class;

    public function definition(): array
    {
        $areas = ['lectura', 'matematicas', 'sociales', 'naturales', 'ingles'];
        $area = $this->faker->randomElement($areas);

        $dimensionNames = $this->getDimensionNamesForArea($area);

        return [
            'exam_id' => Exam::factory(),
            'area' => $area,
            'dimension1_name' => $dimensionNames['dimension1'],
            'dimension2_name' => $dimensionNames['dimension2'],
        ];
    }

    private function getDimensionNamesForArea(string $area): array
    {
        return match ($area) {
            'lectura' => [
                'dimension1' => 'Competencias',
                'dimension2' => 'Tipos de Texto',
            ],
            'matematicas', 'sociales', 'naturales' => [
                'dimension1' => 'Competencias',
                'dimension2' => 'Componentes',
            ],
            'ingles' => [
                'dimension1' => 'Partes',
                'dimension2' => null,
            ],
            default => [
                'dimension1' => 'Competencias',
                'dimension2' => 'Componentes',
            ],
        };
    }

    public function forExam(Exam $exam): static
    {
        return $this->state(fn (array $attributes) => [
            'exam_id' => $exam->id,
        ]);
    }

    public function forArea(string $area): static
    {
        return $this->state(fn (array $attributes) => [
            'area' => $area,
            'dimension1_name' => match ($area) {
                'lectura', 'matematicas', 'sociales', 'naturales' => 'Competencias',
                'ingles' => 'Partes',
                default => 'Competencias',
            },
            'dimension2_name' => match ($area) {
                'lectura' => 'Tipos de Texto',
                'matematicas', 'sociales', 'naturales' => 'Componentes',
                'ingles' => null,
                default => 'Componentes',
            },
        ]);
    }
}
