<?php

namespace Tests\Unit;

use App\Support\ReverbAllowedOrigins;
use Tests\TestCase;

class ReverbAllowedOriginsTest extends TestCase
{
    public function test_wildcard_is_preserved(): void
    {
        putenv('REVERB_ALLOWED_ORIGINS=*');
        putenv('APP_URL=https://example.com');

        $this->assertSame(['*'], ReverbAllowedOrigins::resolve());
    }

    public function test_app_url_is_merged_with_configured_origins(): void
    {
        putenv('REVERB_ALLOWED_ORIGINS=http://localhost');
        putenv('APP_URL=https://ai-shoppable-ecommerce-ztxhczxs.on-forge.com');

        $origins = ReverbAllowedOrigins::resolve();

        $this->assertContains('http://localhost', $origins);
        $this->assertContains('https://ai-shoppable-ecommerce-ztxhczxs.on-forge.com', $origins);
    }
}
