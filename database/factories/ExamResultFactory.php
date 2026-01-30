<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamResult;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExamResultFactory extends Factory
{
    protected $model = ExamResult::class;

    public function definition(): array
    {
        // Normal distribution around 60, std dev 15
        $score = fn () => max(0, min(100, (int) $this->faker->normalDistribution(60, 15)));

        return [
            'exam_id' => Exam::factory(),
            'enrollment_id' => Enrollment::factory(),
            'lectura' => $score(),
            'matematicas' => $score(),
            'sociales' => $score(),
            'naturales' => $score(),
            'ingles' => $score(),
            // global_score is calculated automatically
        ];
    }

    public function forExam(Exam $exam): static
    {
        return $this->state(fn (array $attributes) => [
            'exam_id' => $exam->id,
        ]);
    }

    public function forEnrollment(Enrollment $enrollment): static
    {
        return $this->state(fn (array $attributes) => [
            'enrollment_id' => $enrollment->id,
        ]);
    }

    public function withNoEnglish(): static
    {
        return $this->state(fn (array $attributes) => [
            'ingles' => null,
        ]);
    }

    public function withScores(int $lectura, int $matematicas, int $sociales, int $naturales, ?int $ingles = null): static
    {
        return $this->state(fn (array $attributes) => [
            'lectura' => $lectura,
            'matematicas' => $matematicas,
            'sociales' => $sociales,
            'naturales' => $naturales,
            'ingles' => $ingles,
        ]);
    }
}
