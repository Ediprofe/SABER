<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

class EnrollmentFactory extends Factory
{
    protected $model = Enrollment::class;

    public function definition(): array
    {
        $grade = $this->faker->randomElement([10, 11]);
        $groupNumber = $this->faker->numberBetween(1, 3);

        return [
            'student_id' => Student::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'grade' => $grade,
            'group' => "{$grade}-{$groupNumber}",
            'is_piar' => $this->faker->boolean(15), // 15% PIAR
            'status' => 'ACTIVE',
        ];
    }

    public function forYear(int $year): static
    {
        return $this->state(fn (array $attributes) => [
            'academic_year_id' => AcademicYear::factory()->create(['year' => $year])->id,
        ]);
    }

    public function forGrade(int $grade): static
    {
        return $this->state(fn (array $attributes) => [
            'grade' => $grade,
            'group' => "{$grade}-".$this->faker->numberBetween(1, 3),
        ]);
    }

    public function graduated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'GRADUATED',
        ]);
    }

    public function piar(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_piar' => true,
        ]);
    }
}
