<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\WhatsappPhoneNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'contact_id' => Contact::factory(),
            'whatsapp_phone_number_id' => WhatsappPhoneNumber::factory(),
            'status' => 'open',
            'unread_count' => 0,
            'is_bot_active' => false,
            'last_message_at' => now(),
            'window_expires_at' => now()->addHours(24),
        ];
    }
}
