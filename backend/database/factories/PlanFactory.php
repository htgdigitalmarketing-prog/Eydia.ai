<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->randomElement(['Starter', 'Business', 'Enterprise']);

        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . $this->faker->unique()->numberBetween(1, 9999),
            'billing_period' => 'monthly',
            'price' => $this->faker->randomElement([0, 999, 2999, 9999]),
            'currency' => 'INR',
            'limits' => [
                'whatsapp_numbers' => 1,
                'agents' => 3,
                'contacts' => 5000,
                'campaigns' => 10,
                'workflows' => 5,
            ],
            'trial_days' => 14,
            'is_active' => true,
            'is_public' => true,
        ];
    }
}
