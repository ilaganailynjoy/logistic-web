<?php

namespace Tests\Feature;

use App\Models\LogisticsSetting;
use App\Models\User;
use Tests\TestCase;

/**
 * The shared app sidebar (x-app-layout) wears the INVOIZ brand and its
 * bottom user-information block is a single clickable Profile link to the
 * dedicated Profile page (profile.show). Settings remains a separate
 * navigation destination (settings.index) and there is no standalone
 * "Profile" sidebar navigation item.
 */
class SidebarProfileLinkTest extends TestCase
{
    public function test_sidebar_renders_invoiz_logo_without_duplicate_text_branding(): void
    {
        $html = (string) $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        // The INVOIZ logo image and the recognized sidebar background remain.
        $this->assertStringContainsString('src="' . asset('images/logo.png') . '"', $html);
        $this->assertStringContainsString('alt="INVOIZ logo"', $html);
        $this->assertStringContainsString('bg-[#F8FAF9]', $html);

        // The separate textual "INVOIZ" brand label is removed; the logo image
        // carries the branding on its own.
        $this->assertStringNotContainsString('>INVOIZ</span>', $html);
    }

    public function test_sidebar_nav_labels_are_visible_via_the_expanded_state(): void
    {
        $html = (string) $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        // Label text is present in the markup for every advertised destination.
        foreach (['Dashboard', 'Deliveries', 'Scan Parcel', 'Riders', 'Pickup Requests', 'Staff', 'Applications', 'Settings', 'Log Out'] as $label) {
            $this->assertStringContainsString('>' . $label . '</span>', $html);
        }

        // The labels are gated by the same single sidebar state that drives the
        // width (x-show on sidebarHover), not by conflicting display classes.
        $this->assertStringContainsString('x-show="sidebarHover"', $html);
        $this->assertStringNotContainsString(":class=\"sidebarHover ? 'block' : 'hidden'\"", $html);
    }

    public function test_sidebar_collapsed_tooltips_remain_available(): void
    {
        $html = (string) $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        // Tooltips exist and are only shown while the sidebar is collapsed.
        $this->assertStringContainsString('x-show="!sidebarHover"', $html);
        $this->assertStringContainsString('role="tooltip"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_sidebar_logout_remains_available(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('action="' . route('logout') . '"', false)
            ->assertSee('aria-label="Log out"', false)
            ->assertSee('Log Out');
    }

    public function test_sidebar_all_navigation_destinations_remain_unchanged(): void
    {
        $html = (string) $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $urls = [
            route('dashboard'),
            route('deliveries.index'),
            route('deliveries.scan-page'),
            route('riders.index'),
            route('pickup-requests.index'),
            route('staff.index'),
            route('rider-applications.index'),
            route('center-applications.index'),
            route('centers.index'),
            route('service-areas.index'),
            route('transactions.index'),
            route('reports.index'),
            route('deliveries.archived'),
            route('settings.index'),
            route('profile.show'),
        ];

        foreach ($urls as $url) {
            $this->assertStringContainsString($url, $html);
        }
    }

    public function test_sidebar_user_block_links_to_the_profile_page(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<a href="' . route('profile.show') . '" title="Open profile"', false)
            ->assertSee('class="group relative flex items-center rounded-xl transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary"', false)
            ->assertSee($user->email);
    }

    public function test_sidebar_profile_block_does_not_link_to_the_settings_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('dashboard'))
            ->assertDontSee('href="' . route('settings.index') . '" title="Open profile"', false)
            ->assertDontSee('href="' . route('settings.index') . '" class="group flex items-center gap-3', false);
    }

    public function test_sidebar_settings_nav_item_still_links_to_settings_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('settings.index'));
    }

    public function test_sidebar_has_no_separate_profile_navigation_item(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('>Profile<');
    }

    public function test_sidebar_displays_profile_photo_when_one_exists(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $avatar = public_path('uploads/avatars/test-avatar.jpg');
        @mkdir(public_path('uploads/avatars'), 0777, true);
        file_put_contents($avatar, 'x');

        LogisticsSetting::forUser($user->id)->update(['photo_path' => 'uploads/avatars/test-avatar.jpg']);

        try {
            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertSee('<img src="' . asset('uploads/avatars/test-avatar.jpg') . '" alt="Profile photo"', false);
        } finally {
            @unlink($avatar);
        }
    }

    public function test_sidebar_falls_back_to_initial_avatar_when_no_profile_photo_exists(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('uploads/avatars/')
            ->assertSee('bg-teal-light');
    }

    public function test_sidebar_logo_is_expanded_only(): void
    {
        $html = (string) $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        // A single logo image renders at the expanded size (h-14 w-14)
        // and is gated by the SAME sidebarHover state as every other
        // expanded-only piece of content, so it never appears while the
        // sidebar is collapsed. There is no separate collapsed-size
        // binding and no duplicate textual INVOIZ branding.
        $this->assertStringContainsString('alt="INVOIZ logo"', $html);
        $this->assertStringContainsString('class="h-14 w-14 rounded-lg object-cover flex-shrink-0 transition-all duration-300"', $html);
        $this->assertStringNotContainsString(":class=\"sidebarHover ? 'h-12 w-12' : 'h-10 w-10'\"", $html);
        // MENU is plain text (no filled pill background).
        $this->assertStringNotContainsString('text-white bg-teal rounded-md', $html);
        $this->assertStringContainsString('uppercase text-teal-dark flex-shrink-0">MENU</span>', $html);
        $this->assertStringContainsString('aria-label="Home"', $html);
        $this->assertStringNotContainsString('>INVOIZ</span>', $html);
    }

    public function test_center_applications_is_a_child_of_applications(): void
    {
        $html = (string) $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        // Parent keeps its own link, label, icon tooltip and destination.
        $this->assertStringContainsString('aria-label="Applications"', $html);
        $this->assertStringContainsString(route('rider-applications.index'), $html);

        // Child is an indented, expanded-only link under the parent: no rail
        // icon and no collapsed tooltip of its own.
        $this->assertStringContainsString('aria-label="Center Applications"', $html);
        $this->assertStringContainsString(route('center-applications.index'), $html);
        $this->assertStringContainsString('pl-14 pr-3', $html);
        $this->assertStringContainsString(
            '<div id="subnav-applications" x-show="sidebarHover && applicationsOpen" x-cloak x-collapse>' . "\n" . '            <a href="' . route('center-applications.index') . '"',
            $html
        );
        $this->assertStringNotContainsString('>Center Apps<', $html);
    }

    public function test_center_applications_child_shows_active_state(): void
    {
        $html = (string) $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('center-applications.index'))
            ->assertOk()
            ->getContent();

        // The current-page marker sits on the child link itself (secondary
        // active treatment, not the primary pill).
        $this->assertMatchesRegularExpression(
            '/' . preg_quote(route('center-applications.index'), '/') .
            '"\s+aria-label="Center Applications"\s+aria-current="page"/',
            $html
        );
        $this->assertStringContainsString('bg-teal-light text-teal-dark', $html);
    }

    public function test_applications_section_stays_admin_only(): void
    {
        $html = (string) $this->actingAs(User::factory()->create(['role' => 'staff']))
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(route('rider-applications.index'), $html);
        $this->assertStringNotContainsString(route('center-applications.index'), $html);
    }

    public function test_scan_parcel_is_a_child_of_deliveries(): void
    {
        $html = (string) $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        // Parent keeps its own link, label, icon tooltip and destination.
        $this->assertStringContainsString('aria-label="Deliveries"', $html);
        $this->assertStringContainsString(route('deliveries.index'), $html);

        // Child is an indented, expanded-only link under the parent: no rail
        // icon and no collapsed tooltip of its own.
        $this->assertStringContainsString('aria-label="Scan Parcel"', $html);
        $this->assertStringContainsString(route('deliveries.scan-page'), $html);
        $this->assertStringContainsString('pl-14 pr-3', $html);
        $this->assertStringContainsString(
            '<div id="subnav-deliveries" x-show="sidebarHover && deliveriesOpen" x-cloak x-collapse>' . "\n" . '            <a href="' . route('deliveries.scan-page') . '"',
            $html
        );
        $this->assertStringNotContainsString('role="tooltip" aria-hidden="true">Scan Parcel</span>', $html);
    }

    public function test_scan_parcel_child_shows_active_state(): void
    {
        $html = (string) $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('deliveries.scan-page'))
            ->assertOk()
            ->getContent();

        // The current-page marker sits on the child link itself (secondary
        // active treatment, not the primary pill).
        $this->assertMatchesRegularExpression(
            '/' . preg_quote(route('deliveries.scan-page'), '/') .
            '"\s+aria-label="Scan Parcel"\s+aria-current="page"/',
            $html
        );
        $this->assertStringContainsString('bg-teal-light text-teal-dark', $html);
    }

    public function test_sidebar_has_no_internal_nav_scroll_region(): void
    {
        $html = (string) $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        // The nav no longer owns a separate internal scroll area, so the
        // sidebar behaves as one coherent pane.
        $this->assertStringContainsString('<nav class="flex flex-col gap-1 px-2 py-2" role="navigation"', $html);
        $this->assertStringNotContainsString('<nav class="flex-1', $html);
        $this->assertStringNotContainsString('overflow-y-auto" role="navigation"', $html);

        // The sidebar must never scroll and must never show a scrollbar
        // at any viewport size: no scroll container on the pane, on the
        // nav, or nested anywhere in the sidebar.
        $this->assertStringNotContainsString('@media (min-width: 1024px) and (max-height: 900px)', $html);
        $this->assertStringNotContainsString('One coherent pane', $html);
        $this->assertStringNotContainsString('overflow-y: auto;', $html);
        $this->assertStringNotContainsString('overflow-x: hidden;', $html);
    }
}