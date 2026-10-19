<?php

namespace App\Modules\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'company_name' => $this->company_name,
            'status' => $this->status,
            'timezone' => $this->timezone,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
        ];
    }
}
