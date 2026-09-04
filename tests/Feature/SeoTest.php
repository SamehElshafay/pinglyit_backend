<?php

namespace Tests\Feature;

use Tests\TestCase;

class SeoTest extends TestCase
{
    public function test_sitemap_lists_only_the_public_frontend_pages(): void
    {
        config(['pingly.frontend_url' => 'https://app.pingly.test']);

        $response = $this->get('/sitemap.xml');

        $response->assertOk()->assertHeader('Content-Type', 'application/xml');
        $response->assertSee('https://app.pingly.test/welcome', false);
        $response->assertSee('https://app.pingly.test/login', false);
        $response->assertSee('https://app.pingly.test/signup', false);
        // Never an authenticated route — nothing there is public content.
        $response->assertDontSee('/wallet', false);
        $response->assertDontSee('/dashboard', false);
    }

    public function test_robots_txt_is_permissive_and_points_at_the_sitemap(): void
    {
        config(['pingly.frontend_url' => 'https://app.pingly.test']);

        $response = $this->get('/robots.txt');

        $response->assertOk();
        $response->assertSee('User-agent: *', false);
        $response->assertSee('Allow: /', false);
        $response->assertSee('Sitemap: https://app.pingly.test/sitemap.xml', false);
    }
}
