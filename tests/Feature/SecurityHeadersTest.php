<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Vite;
use Laravel\Boost\Services\BrowserLogger;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_web_responses_set_security_headers_and_nonce_inline_scripts(): void
    {
        $response = $this->get('/login');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), display-capture=()');

        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("script-src 'self' 'nonce-", $policy);
        preg_match("/nonce-([^']+)/", $policy, $nonce);
        $this->assertNotEmpty($nonce[1] ?? null);
        $response->assertSee('nonce="'.$nonce[1].'"', false);
    }

    public function test_local_filesystem_disk_cannot_serve_private_files_with_temporary_urls(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.local.root'));
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.private.root'));
    }

    public function test_hsts_is_added_to_production_responses(): void
    {
        config(['app.env' => 'production']);

        $this->get('/login')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_login_scripts_including_boost_share_the_response_nonce(): void
    {
        $response = $this->get('/login')->assertOk();
        preg_match("/nonce-([^']+)/", $response->headers->get('Content-Security-Policy'), $matches);
        $nonce = $matches[1];
        $this->assertSame($nonce, Vite::cspNonce());
        $this->assertStringContainsString('nonce="'.$nonce.'"', BrowserLogger::getScript());
        preg_match_all('/<script\b([^>]*)>/', $response->getContent(), $scripts);
        foreach ($scripts[1] as $attributes) {
            if (str_contains($attributes, 'src=')) {
                continue;
            }
            $this->assertStringContainsString('nonce="'.$nonce.'"', $attributes);
        }
    }
}
