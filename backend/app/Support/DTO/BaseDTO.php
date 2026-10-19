<?php

namespace App\Support\DTO;

/**
 * Minimal DTO base. Concrete DTOs declare readonly promoted properties and a
 * static fromRequest()/fromArray() factory. Keeps typed data moving between
 * controllers, services and actions without leaking Request objects downward.
 */
abstract class BaseDTO
{
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
