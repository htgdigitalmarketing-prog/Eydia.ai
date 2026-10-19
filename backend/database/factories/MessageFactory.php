<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\WhatsappPhoneNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'conversation_id' => Conversation::factory(),
            'contact_id' => Contact::factory(),
            'whatsapp_phone_number_id' => WhatsappPhoneNumber::factory(),
            'direction' => Message::DIRECTION_OUTBOUND,
            'type' => 'text',
            'body' => $this->faker->sentence(),
            'external_message_id' => 'wamid.' . $this->faker->unique()->sha1(),
            'status' => 'sent',
            'sent_at' => now(),
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn () => [
            'direction' => Message::DIRECTION_INBOUND,
            'status' => 'delivered',
        ]);
    }
}
