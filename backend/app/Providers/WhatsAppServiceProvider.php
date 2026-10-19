<?php

namespace App\Providers;

use App\Modules\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Modules\WhatsApp\Providers\FakeWhatsAppProvider;
use App\Modules\WhatsApp\Providers\MetaWhatsAppProvider;
use App\Modules\WhatsApp\Services\WhatsAppProviderFactory;
use Illuminate\Support\ServiceProvider;

class WhatsAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Default/global provider binding. Per-WABA resolution goes through
        // WhatsAppProviderFactory; this binding is the shared Fake in local/testing
        // (singleton so tests can assert its recorded sends) and a bootstrap Meta
        // provider elsewhere.
        $this->app->singleton(WhatsAppProviderInterface::class, function ($app) {
            if (config('services.meta.use_fake', $app->environment(['local', 'testing']))) {
                return new FakeWhatsAppProvider();
            }

            $version = config('services.meta.api_version', 'v23.0');
            $base = rtrim((string) config('services.meta.graph_base', 'https://graph.facebook.com'), '/');

            return new MetaWhatsAppProvider(
                baseUrl: "{$base}/{$version}",
                token: (string) config('services.meta.system_user_token', ''),
                timeout: (int) config('services.meta.timeout', 30),
                retries: (int) config('services.meta.retries', 2),
                retryDelayMs: (int) config('services.meta.retry_delay_ms', 300),
            );
        });

        $this->app->singleton(WhatsAppProviderFactory::class);
    }
}
