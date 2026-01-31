<?php

namespace Database\Factories;

use App\Models\ExamAreaConfig;
use App\Models\ExamAreaItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExamAreaItemFactory extends Factory
{
    protected $model = ExamAreaItem::class;

    public function definition(): array
    {
        $dimension = $this->faker->randomElement([1, 2]);
        $name = $this->faker->words(3, true);

        return [
            'exam_area_config_id' => ExamAreaConfig::factory(),
            'dimension' => $dimension,
            'name' => $name,
            'order' => $this->faker->numberBetween(0, 10),
        ];
    }

    public function forConfig(ExamAreaConfig $config): static
    {
        return $this->state(fn (array $attributes) => [
            'exam_area_config_id' => $config->id,
        ]);
    }

    public function dimension(int $dimension): static
    {
        return $this->state(fn (array $attributes) => [
            'dimension' => $dimension,
        ]);
    }

    public function order(int $order): static
    {
        return $this->state(fn (array $attributes) => [
            'order' => $order,
        ]);
    }
}
