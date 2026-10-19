<?php

namespace App\Support\Services;

/**
 * Marker base for services. Business logic lives here (and in Actions),
 * never in controllers. Services depend on repositories and the
 * WhatsAppProviderInterface via constructor injection.
 */
abstract class BaseService
{
}
