<?php

namespace Tests\Unit;

use App\Modules\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Modules\WhatsApp\Providers\FakeWhatsAppProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 smoke test: the Fake provider honours the interface and records
 * sends without touching the network. Proves the abstraction seam is intact
 * before any real Meta calls exist.
 */
class FakeWhatsAppProviderTest extends TestCase
{
    public function test_fake_provider_implements_interface_and_returns_wamid(): void
    {
        $provider = new FakeWhatsAppProvider();

        $this->assertInstanceOf(WhatsAppProviderInterface::class, $provider);

        $result = $provider->sendMessage('PN_ID', '15551234567', [
            'type' => 'text',
            'text' => ['body' => 'hi'],
        ]);

        $this->assertArrayHasKey('messages', $result);
        $this->assertStringStartsWith('wamid.FAKE', $result['messages'][0]['id']);
        $this->assertCount(1, $provider->sent);
        $this->assertSame('15551234567', $provider->sent[0]['to']);
    }
}
