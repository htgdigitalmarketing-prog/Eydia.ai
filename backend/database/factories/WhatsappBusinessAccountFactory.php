<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\WhatsappBusinessAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class WhatsappBusinessAccountFactory extends Factory
{
    protected $model = WhatsappBusinessAccount::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'waba_id' => (string) $this->faker->unique()->numerify('##############'),
            'name' => $this->faker->company() . ' WABA',
            'currency' => 'INR',
            'access_token' => 'EAAF' . $this->faker->sha256(),
            'status' => 'connected',
            'connected_at' => now(),
        ];
    }
}
