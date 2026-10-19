<?php

namespace App\Modules\Auth\DTOs;

use App\Support\DTO\BaseDTO;

/**
 * Typed payload for tenant self-signup. Never carries a tenant_id — the tenant
 * is created here, not supplied by the caller.
 */
class RegisterData extends BaseDTO
{
    public function __construct(
        public readonly string $companyName,
        public readonly string $name,
        public readonly string $email,
        public readonly string $password,
        public readonly ?string $phone = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            companyName: $data['company_name'],
            name: $data['name'],
            email: $data['email'],
            password: $data['password'],
            phone: $data['phone'] ?? null,
        );
    }
}
