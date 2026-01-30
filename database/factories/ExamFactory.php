<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Exam;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExamFactory extends Factory
{
    protected $model = Exam::class;

    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'name' => $this->faker->randomElement(['Simulacro', 'Prueba']).' '.$this->faker->date('Y'),
            'type' => $this->faker->randomElement(['SIMULACRO', 'ICFES']),
            'date' => $this->faker->date(),
        ];
    }

    public function forYear(int $year): static
    {
        return $this->state(fn (array $attributes) => [
            'academic_year_id' => AcademicYear::factory()->create(['year' => $year])->id,
        ]);
    }

    public function simulacro(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'SIMULACRO',
        ]);
    }

    public function icfes(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'ICFES',
        ]);
    }
}
