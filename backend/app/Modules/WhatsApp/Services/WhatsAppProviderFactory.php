<?php

namespace App\Modules\WhatsApp\Services;

use App\Models\ApiLog;
use App\Models\WhatsappBusinessAccount;
use App\Modules\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Modules\WhatsApp\Providers\MetaWhatsAppProvider;
use Closure;
use Illuminate\Contracts\Foundation\Application;

/**
 * Resolves the WhatsApp provider for a specific WABA.
 *
 * Production: a MetaWhatsAppProvider bound to that WABA's decrypted System User
 * token, with an api_logs writer scoped to the WABA's tenant.
 * Local/testing: the shared FakeWhatsAppProvider singleton, so tests can assert
 * against its recorded ->sent calls.
 */
class WhatsAppProviderFactory
{
    public function __construct(private readonly Application $app) {}

    public function for(WhatsappBusinessAccount $waba): WhatsAppProviderInterface
    {
        if ($this->usesFake()) {
            return $this->app->make(WhatsAppProviderInterface::class);
        }

        $version = config('services.meta.api_version', 'v23.0');
        $base = rtrim((string) config('services.meta.graph_base', 'https://graph.facebook.com'), '/');

        return new MetaWhatsAppProvider(
            baseUrl: "{$base}/{$version}",
            token: (string) $waba->access_token, // decrypted by the model cast
            logger: $this->loggerFor($waba->tenant_id),
            timeout: (int) config('services.meta.timeout', 30),
            retries: (int) config('services.meta.retries', 2),
            retryDelayMs: (int) config('services.meta.retry_delay_ms', 300),
        );
    }

    private function usesFake(): bool
    {
        return (bool) config('services.meta.use_fake', $this->app->environment(['local', 'testing']));
    }

    private function loggerFor(?int $tenantId): Closure
    {
        return function (array $log) use ($tenantId): void {
            ApiLog::create(array_merge([
                'tenant_id' => $tenantId,
                'direction' => 'outbound',
                'service' => 'meta',
            ], $log));
        };
    }
}
