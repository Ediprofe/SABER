<?php

namespace Database\Factories;

use App\Models\ExamAreaItem;
use App\Models\ExamDetailResult;
use App\Models\ExamResult;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExamDetailResultFactory extends Factory
{
    protected $model = ExamDetailResult::class;

    public function definition(): array
    {
        return [
            'exam_result_id' => ExamResult::factory(),
            'exam_area_item_id' => ExamAreaItem::factory(),
            'score' => $this->faker->numberBetween(0, 100),
        ];
    }

    public function forExamResult(ExamResult $examResult): static
    {
        return $this->state(fn (array $attributes) => [
            'exam_result_id' => $examResult->id,
        ]);
    }

    public function forItem(ExamAreaItem $item): static
    {
        return $this->state(fn (array $attributes) => [
            'exam_area_item_id' => $item->id,
        ]);
    }

    public function withScore(?int $score = null): static
    {
        return $this->state(fn (array $attributes) => [
            'score' => $score ?? $this->faker->numberBetween(0, 100),
        ]);
    }

    public function noScore(): static
    {
        return $this->state(fn (array $attributes) => [
            'score' => null,
        ]);
    }
}
