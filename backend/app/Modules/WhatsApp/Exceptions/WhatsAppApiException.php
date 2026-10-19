<?php

namespace App\Modules\WhatsApp\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when Meta returns an error. Carries a user-facing message (safe to show)
 * plus the raw Meta code/response (for internal logs only). The exception handler
 * renders $userMessage; the raw detail never reaches the client in production.
 */
class WhatsAppApiException extends RuntimeException
{
    public function __construct(
        public readonly string $userMessage,
        public readonly ?int $metaCode = null,
        public readonly int $httpStatus = 422,
        public readonly array $raw = [],
        string $technicalMessage = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($technicalMessage !== '' ? $technicalMessage : $userMessage, 0, $previous);
    }
}
