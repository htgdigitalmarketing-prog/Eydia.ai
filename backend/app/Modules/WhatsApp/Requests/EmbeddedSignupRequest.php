<?php

namespace App\Modules\WhatsApp\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmbeddedSignupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string'],
            'waba_id' => ['required', 'string', 'max:64'],
            'phone_number_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
