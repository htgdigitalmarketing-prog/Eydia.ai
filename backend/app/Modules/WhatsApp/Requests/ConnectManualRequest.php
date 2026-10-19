<?php

namespace App\Modules\WhatsApp\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConnectManualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission checked in controller via Gate
    }

    public function rules(): array
    {
        return [
            'waba_id' => ['required', 'string', 'max:64'],
            'phone_number_id' => ['required', 'string', 'max:64'],
            'display_phone_number' => ['required', 'string', 'max:32'],
            'access_token' => ['required', 'string', 'min:20'],
            'name' => ['nullable', 'string', 'max:255'],
            'verified_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
