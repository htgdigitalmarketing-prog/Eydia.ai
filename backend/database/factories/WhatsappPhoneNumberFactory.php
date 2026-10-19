<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\WhatsappBusinessAccount;
use App\Models\WhatsappPhoneNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

class WhatsappPhoneNumberFactory extends Factory
{
    protected $model = WhatsappPhoneNumber::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'whatsapp_business_account_id' => WhatsappBusinessAccount::factory(),
            'phone_number_id' => (string) $this->faker->unique()->numerify('###############'),
            'display_phone_number' => $this->faker->e164PhoneNumber(),
            'verified_name' => $this->faker->company(),
            'quality_rating' => 'GREEN',
            'status' => 'registered',
            'is_default' => true,
            'is_registered' => true,
        ];
    }
}
