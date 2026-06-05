<?php

namespace Tests\Unit;

use App\Support\ReverbClientConfig;
use Tests\TestCase;

class ReverbClientConfigTest extends TestCase
{
    public function test_returns_null_when_broadcasting_is_disabled(): void
    {
        config(['broadcasting.default' => 'null']);

        $this->assertNull(ReverbClientConfig::forClient());
    }

    public function test_uses_app_url_host_when_reverb_host_is_localhost(): void
    {
        config([
            'app.url' => 'https://ai-shoppable-ecommerce-ztxhczxs.on-forge.com',
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'key' => 'giw85ggkxxcanvgrtnum',
                'options' => [
                    'host' => 'localhost',
                    'port' => 443,
                    'scheme' => 'https',
                ],
            ],
        ]);

        $config = ReverbClientConfig::forClient();

        $this->assertSame('giw85ggkxxcanvgrtnum', $config['key']);
        $this->assertSame('ai-shoppable-ecommerce-ztxhczxs.on-forge.com', $config['host']);
        $this->assertSame(443, $config['port']);
        $this->assertSame('https', $config['scheme']);
    }

    public function test_keeps_explicit_public_host(): void
    {
        config([
            'app.url' => 'https://example.com',
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'key' => 'prod-key',
                'options' => [
                    'host' => 'ws.example.com',
                    'port' => 443,
                    'scheme' => 'https',
                ],
            ],
        ]);

        $config = ReverbClientConfig::forClient();

        $this->assertSame('ws.example.com', $config['host']);
    }

    public function test_maps_daemon_port_to_https_client_port(): void
    {
        config([
            'app.url' => 'https://ai-shoppable-ecommerce-ztxhczxs.on-forge.com',
            'broadcasting.default' => 'reverb',
            'reverb.servers.reverb.port' => 8081,
            'broadcasting.connections.reverb' => [
                'key' => 'giw85ggkxxcanvgrtnum',
                'options' => [
                    'host' => 'localhost',
                    'port' => 8081,
                    'scheme' => 'http',
                ],
            ],
        ]);

        $config = ReverbClientConfig::forClient();

        $this->assertSame('https', $config['scheme']);
        $this->assertSame(443, $config['port']);
    }
}
