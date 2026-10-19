<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        $wa = (string) $this->faker->unique()->numerify('9199########');

        return [
            'tenant_id' => Tenant::factory(),
            'wa_id' => $wa,
            'phone' => '+' . $wa,
            'name' => $this->faker->name(),
            'email' => $this->faker->optional()->safeEmail(),
            'source' => 'manual',
            'is_blocked' => false,
            'opted_out' => false,
        ];
    }
}
