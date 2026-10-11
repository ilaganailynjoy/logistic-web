<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * Top-bar account controls: My Profile, Settings, and Log out live inside
 * a single profile dropdown in the upper-right corner of the app header,
 * immediately reachable without scrolling the sidebar.
 *
 * - One profile trigger (photo or initial avatar, name on wider screens,
 *   chevron) opens the menu on every viewport (never hover-only).
 * - The menu carries an account header (name + role label), My Profile
 *   (profile.show), Settings (settings.index), and a visually separated
 *   Log out POST form with CSRF protection.
 * - Notifications and Messages stay visible as separate controls.
 * - The sidebar keeps primary navigation only: no account footer, no
 *   Settings item, no Logout control.
 */
class TopBarAccountControlsTest extends TestCase
{
    private function dashboardHtml(?string $url = null): string
    {
        $user = User::factory()->create(['role' => 'admin']);

        return (string) $this->actingAs($user)
            ->get($url ?? route('dashboard'))
            ->assertOk()
            ->getContent();
    }

    private function headerHtml(string $html): string
    {
        $start = strpos($html, '<header class="sticky top-0');
        $end = strpos($html, '</header>', $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    private function sidebarHtml(string $html): string
    {
        $start = strpos($html, '<aside id="app-sidebar"');
        $end = strpos($html, '</aside>', $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    public function test_profile_trigger_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'name' => 'Ava Manager']);
        $html = (string) $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();
        $header = $this->headerHtml($html);

        // Single trigger: avatar/initial, name, chevron, accessible label.
        $this->assertStringContainsString('aria-label="Account menu"', $header);
        $this->assertStringContainsString('aria-haspopup="menu"', $header);
        $this->assertStringContainsString('aria-controls="profile-menu"', $header);
        $this->assertStringContainsString(':aria-expanded="accountOpen ? \'true\' : \'false\'"', $header);
        $this->assertStringContainsString('Ava Manager', $header);
        $this->assertStringContainsString('M19 9l-7 7-7-7', $header); // chevron

        // Account header shows the display name and the existing role label.
        $this->assertStringContainsString('id="profile-menu"', $header);
        $this->assertStringContainsString('Logistics Manager', $header);
    }

    public function test_dropdown_header_shows_avatar_name_and_role(): void
    {
        $header = $this->headerHtml($this->dashboardHtml());

        // Header pairs a 44px circular avatar with the name/role block,
        // vertically centered with consistent padding.
        $this->assertStringContainsString('flex items-center gap-3 px-4 py-3 border-b border-gray-100', $header);
        $this->assertStringContainsString('h-11 w-11 rounded-full', $header);
        $this->assertStringContainsString('Logistics Manager', $header);
    }

    public function test_dropdown_header_falls_back_to_initials_without_a_photo(): void
    {
        $header = $this->headerHtml($this->dashboardHtml());

        // Users without a photo get the existing initial-avatar fallback
        // in the header (same handling as the trigger).
        $this->assertStringContainsString('bg-teal-light', $header);
    }

    public function test_dropdown_alignment_and_width(): void
    {
        $header = $this->headerHtml($this->dashboardHtml());

        // Right edge aligned under the trigger with a small gap, content
        // width in the 260–290px range, and a viewport-safe cap.
        $this->assertStringContainsString('absolute right-0 mt-2 w-[280px]', $header);
        $this->assertStringContainsString('max-w-[calc(100vw-2rem)]', $header);
    }

    public function test_dropdown_uses_existing_profile_and_settings_urls(): void
    {
        $header = $this->headerHtml($this->dashboardHtml());

        $this->assertStringContainsString(
            '<a href="' . route('profile.show') . '" title="Open profile"',
            $header
        );
        $this->assertStringContainsString('My Profile', $header);
        $this->assertStringContainsString(
            '<a href="' . route('settings.index') . '" title="Settings"',
            $header
        );

        // Exactly one of each — no duplicate desktop/mobile controls.
        $this->assertSame(1, substr_count($header, route('profile.show')));
        $this->assertSame(1, substr_count($header, route('settings.index')));
        $this->assertSame(1, substr_count($header, 'action="' . route('logout') . '"'));
    }

    public function test_topbar_contains_profile_settings_and_logout_controls(): void
    {
        $header = $this->headerHtml($this->dashboardHtml());

        // My Profile menu item opens the existing Logistics Profile page.
        $this->assertStringContainsString(
            '<a href="' . route('profile.show') . '" title="Open profile"',
            $header
        );

        // Settings menu item opens the existing Logistics Settings page.
        $this->assertStringContainsString(
            'href="' . route('settings.index') . '" title="Settings"',
            $header
        );

        // Logout reuses the existing secure POST form (never a GET link),
        // visually separated from the navigation links.
        $this->assertStringContainsString(
            '<form method="POST" action="' . route('logout') . '"',
            $header
        );
        $this->assertStringContainsString('aria-label="Log out"', $header);
        $this->assertStringContainsString('Log Out', $header);
        $this->assertStringContainsString('text-red-700', $header);
        $this->assertStringNotContainsString(
            'href="' . route('logout') . '"',
            $header
        );
    }

    public function test_topbar_logout_form_preserves_csrf_protection(): void
    {
        $header = $this->headerHtml($this->dashboardHtml());

        $this->assertStringContainsString('name="_token"', $header);
    }

    public function test_topbar_preserves_messages_and_notifications(): void
    {
        $header = $this->headerHtml($this->dashboardHtml());

        $this->assertStringContainsString(route('messages.index'), $header);
        $this->assertStringContainsString('title="Messages"', $header);
        $this->assertStringContainsString('notifOpen', $header);
        $this->assertStringContainsString('Notifications', $header);
        $this->assertStringContainsString(route('notifications.index'), $header);
    }

    public function test_sidebar_contains_only_primary_navigation(): void
    {
        $html = $this->dashboardHtml();
        $sidebar = $this->sidebarHtml($html);

        // Account controls no longer appear in the sidebar.
        $this->assertStringNotContainsString(route('profile.show'), $sidebar);
        $this->assertStringNotContainsString(route('settings.index'), $sidebar);
        $this->assertStringNotContainsString('action="' . route('logout') . '"', $sidebar);
        $this->assertStringNotContainsString('title="Open profile"', $sidebar);
        $this->assertStringNotContainsString('aria-label="Log out"', $sidebar);

        // Primary navigation, dropdowns, and tooltips are untouched.
        foreach (['aria-label="Dashboard"', 'aria-label="Deliveries"', 'aria-label="Scan Parcel"'] as $marker) {
            $this->assertStringContainsString($marker, $sidebar);
        }
        $this->assertStringContainsString('@click="deliveriesOpen = !deliveriesOpen"', $sidebar);
        $this->assertStringContainsString('@click="applicationsOpen = !applicationsOpen"', $sidebar);
        $this->assertStringContainsString('role="tooltip"', $sidebar);
    }

    public function test_profile_and_settings_pages_still_resolve(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->get(route('profile.show'))->assertOk();
        $this->actingAs($user)->get(route('settings.index'))->assertOk();
    }

    public function test_logout_submits_securely_and_redirects(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)->post(route('logout'));

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_account_menu_is_keyboard_touch_and_viewport_safe(): void
    {
        $header = $this->headerHtml($this->dashboardHtml());

        // Click toggles (touch), outside-click and Escape close (never
        // hover-only), Escape returns focus to the trigger, and every item
        // is a real link/button.
        $this->assertStringContainsString('aria-haspopup="menu"', $header);
        $this->assertStringContainsString('aria-label="Account menu"', $header);
        $this->assertStringContainsString('aria-controls="profile-menu"', $header);
        $this->assertStringContainsString('@click="accountOpen = !accountOpen"', $header);
        $this->assertStringContainsString('@click.outside="accountOpen = false"', $header);
        $this->assertStringContainsString('@keydown.escape.window', $header);
        $this->assertStringContainsString('$refs.accountTrigger.focus()', $header);
        $this->assertStringContainsString('role="menu"', $header);
        $this->assertStringContainsString('role="menuitem"', $header);
        $this->assertStringContainsString('x-show="accountOpen"', $header);

        // One trigger for every viewport (no competing desktop/mobile
        // clusters) with touch-size targets and viewport-safe width.
        $this->assertStringNotContainsString('hidden sm:flex items-center gap-1', $header);
        $this->assertStringNotContainsString('relative sm:hidden', $header);
        $this->assertStringContainsString('min-h-[44px]', $header);
        $this->assertStringContainsString('max-w-[calc(100vw-2rem)]', $header);
    }

    public function test_topbar_marks_the_active_account_page(): void
    {
        $settingsHeader = $this->headerHtml($this->dashboardHtml(route('settings.index')));
        $this->assertStringContainsString('aria-current="page"', $settingsHeader);

        $profileHeader = $this->headerHtml($this->dashboardHtml(route('profile.show')));
        $this->assertStringContainsString('aria-current="page"', $profileHeader);
    }

    public function test_unauthenticated_users_see_no_account_menu(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertRedirect();
        $this->assertStringNotContainsString('aria-label="Account menu"', (string) $response->getContent());
        $this->assertStringNotContainsString('id="profile-menu"', (string) $response->getContent());
    }
}
