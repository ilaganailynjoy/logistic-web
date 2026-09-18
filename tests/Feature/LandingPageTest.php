<?php

namespace Tests\Feature;

use Tests\TestCase;

class LandingPageTest extends TestCase
{
    public function test_the_landing_page_loads_successfully(): void
    {
        $this->get('/')
            ->assertStatus(200)
            ->assertSee('INVOIZ')
            ->assertSee("Life's short", false)
            ->assertSee('Shop fast');
    }

    public function test_the_landing_page_has_mobile_viewport_and_responsive_markup(): void
    {
        $response = $this->get('/');

        $response->assertSee('width=device-width, initial-scale=1', false);
        $response->assertSee('id="mobile-menu"', false);
        $response->assertSee('lg:grid-cols-5', false);
        $response->assertSee('landing-display', false);
        $response->assertSee('overflow-x:hidden', false);
    }

    public function test_the_landing_page_preserves_rider_app_deep_link_and_fallbacks(): void
    {
        $response = $this->get('/');

        $response->assertSee('invoizrider://login');
        $response->assertSee('Try Opening Rider App');
        $response->assertSee('Access Platform via Web Login');
        $response->assertSee('x-data="{ tried:false, fallback:false }"', false);
    }

    public function test_the_landing_page_is_not_a_platform_selection_page(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('What is INVOIZ?');
        $response->assertSee('How INVOIZ Works');
        $response->assertSee('One Connected Ecosystem');
        $response->assertSee('Ready to experience INVOIZ?');
        $response->assertDontSee('Choose the portal');
        $response->assertDontSee('Choose your platform');
        $response->assertDontSee('Select your role');
        $response->assertDontSee('Access for');
        $response->assertDontSee('Which platform');
        $response->assertDontSee('Shop — Coming Soon');
        $response->assertDontSee('Seller Dashboard — Coming Soon');
    }

    public function test_the_landing_page_presents_ecosystem_without_portal_buttons(): void
    {
        $response = $this->get('/');

        $response->assertSee('How the system connects');
        // Marketplace-first CTAs replaced the old ecosystem CTA.
        $response->assertSee('Shop Now');
        $response->assertSee('Explore Products');
        // Guest marketplace has no configured URL: honest coming-soon note,
        // never a fake shop.
        $response->assertSee('Public marketplace browsing - Coming soon');
        $response->assertDontSee('Browse Marketplace');
    }

    public function test_the_landing_page_links_out_when_marketplace_is_configured(): void
    {
        config(['invoiz.guest.marketplace_url' => 'https://marketplace.example.com']);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Browse Marketplace');
        $response->assertSee('https://marketplace.example.com', false);
    }
}