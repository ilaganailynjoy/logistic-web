<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * Sidebar dropdowns: Deliveries > Scan Parcel and Applications >
 * Center Applications. Each parent link keeps navigating to its own page;
 * a separate arrow button toggles only its child, independently of the
 * main sidebar collapsed/expanded state.
 */
class SidebarDropdownTest extends TestCase
{
    private function adminHtml(?string $url = null): string
    {
        $user = User::factory()->create(['role' => 'admin']);

        return (string) $this->actingAs($user)
            ->get($url ?? route('dashboard'))
            ->assertOk()
            ->getContent();
    }

    private function sidebarHtml(string $html): string
    {
        $start = strpos($html, '<aside id="app-sidebar"');
        $end = strpos($html, '</aside>', $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    // ── Deliveries dropdown ──────────────────────────────────────

    public function test_deliveries_has_dropdown_control_and_scan_child(): void
    {
        $html = $this->sidebarHtml($this->adminHtml());

        // Parent link still navigates to the Deliveries page.
        $this->assertStringContainsString('aria-label="Deliveries"', $html);
        $this->assertStringContainsString(route('deliveries.index'), $html);

        // Separate arrow button toggles only the child.
        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('@click="deliveriesOpen = !deliveriesOpen"', $html);
        $this->assertStringContainsString('aria-controls="subnav-deliveries"', $html);
        $this->assertStringContainsString(':aria-label="deliveriesOpen ? \'Collapse Deliveries\' : \'Expand Deliveries\'"', $html);

        // Child visibility depends on the dropdown state, not just the sidebar.
        $this->assertStringContainsString('id="subnav-deliveries" x-show="sidebarHover && deliveriesOpen"', $html);
        $this->assertStringContainsString('aria-label="Scan Parcel"', $html);
        $this->assertStringContainsString(route('deliveries.scan-page'), $html);
    }

    public function test_deliveries_dropdown_starts_closed_and_auto_opens_on_scan_page(): void
    {
        // Dropdown defaults live in the layout Alpine state (outside aside).
        $user = User::factory()->create(['role' => 'admin']);
        $html = (string) $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('deliveriesOpen: false', $html);

        $scanHtml = (string) $this->actingAs($user)->get(route('deliveries.scan-page'))->assertOk()->getContent();
        $this->assertStringContainsString('deliveriesOpen: true', $scanHtml);

        // Parent keeps a subtle open indication while the child owns the
        // current-page marker — never two competing strong pills.
        $this->assertStringNotContainsString('aria-label="Deliveries"' . "\n" . '               aria-current="page"', $scanHtml);
    }

    public function test_deliveries_link_still_navigates(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->get(route('deliveries.index'))->assertOk();
        $this->actingAs($user)->get(route('deliveries.scan-page'))->assertOk();
    }

    // ── Applications dropdown ────────────────────────────────────

    public function test_applications_has_dropdown_control_and_center_child(): void
    {
        $html = $this->sidebarHtml($this->adminHtml());

        // Parent link still navigates to the Applications page.
        $this->assertStringContainsString('aria-label="Applications"', $html);
        $this->assertStringContainsString(route('rider-applications.index'), $html);

        // Separate arrow button toggles only the child.
        $this->assertStringContainsString('@click="applicationsOpen = !applicationsOpen"', $html);
        $this->assertStringContainsString('aria-controls="subnav-applications"', $html);
        $this->assertStringContainsString(':aria-label="applicationsOpen ? \'Collapse Applications\' : \'Expand Applications\'"', $html);

        // Child visibility depends on the dropdown state, not just the sidebar.
        $this->assertStringContainsString('id="subnav-applications" x-show="sidebarHover && applicationsOpen"', $html);
        $this->assertStringContainsString('aria-label="Center Applications"', $html);
        $this->assertStringContainsString(route('center-applications.index'), $html);
    }

    public function test_applications_dropdown_starts_closed_and_auto_opens_on_center_page(): void
    {
        // Dropdown defaults live in the layout Alpine state (outside aside).
        $user = User::factory()->create(['role' => 'admin']);
        $html = (string) $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('applicationsOpen: false', $html);

        $centerHtml = (string) $this->actingAs($user)->get(route('center-applications.index'))->assertOk()->getContent();
        $this->assertStringContainsString('applicationsOpen: true', $centerHtml);
    }

    public function test_applications_link_still_navigates(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->get(route('rider-applications.index'))->assertOk();
        $this->actingAs($user)->get(route('center-applications.index'))->assertOk();
    }

    // ── Sidebar invariants ───────────────────────────────────────

    public function test_rider_applications_item_was_not_added(): void
    {
        $html = $this->adminHtml();

        $this->assertStringNotContainsString('Rider Applications', $html);
    }

    public function test_messages_remains_absent_from_sidebar(): void
    {
        $html = $this->sidebarHtml($this->adminHtml());

        $this->assertStringNotContainsString(route('messages.index'), $html);
        $this->assertStringNotContainsString('>Messages<', $html);
    }

    public function test_collapsed_sidebar_has_no_child_rail_icons(): void
    {
        $html = $this->sidebarHtml($this->adminHtml());

        // Children render only inside expanded-and-open wrappers: no rail
        // icon and no collapsed tooltip of their own.
        $this->assertStringNotContainsString('>Center Apps<', $html);
        $this->assertSame(1, substr_count($html, '>Scan Parcel</span>'));
        $this->assertSame(1, substr_count($html, '>Center Applications</span>'));
    }

    public function test_active_nav_uses_teal_not_amber_fill(): void
    {
        $html = $this->sidebarHtml($this->adminHtml());

        // No amber/gold background fills anywhere in the sidebar.
        $this->assertStringNotContainsString('bg-secondary', $html);

        // The active treatment is light-teal plus the edge indicator
        // (verified on a page with an active child item).
        $user = User::factory()->create(['role' => 'admin']);
        $activeHtml = $this->sidebarHtml(
            (string) $this->actingAs($user)->get(route('center-applications.index'))->assertOk()->getContent()
        );
        $this->assertStringContainsString('bg-teal-light text-teal-dark', $activeHtml);
    }

    public function test_no_scroll_region_was_added(): void
    {
        $html = $this->adminHtml();

        $this->assertStringNotContainsString('overflow-y-auto" role="navigation"', $html);
        $this->assertStringNotContainsString('overflow-y: auto;', $html);
    }

    public function test_dividers_hide_when_collapsed_to_save_space(): void
    {
        $html = $this->sidebarHtml($this->adminHtml());

        $this->assertStringContainsString(
            'x-show="sidebarHover" x-cloak class="border-t border-teal/10',
            $html
        );
    }

    public function test_expanded_density_keeps_profile_logout_visible_without_scrolling(): void
    {
        $html = $this->adminHtml();

        // Expanded-only tightening for medium/short viewports keeps
        // Profile + Logout inside the pane: no scroll container, and the
        // rules never target the collapsed rail.
        $this->assertStringContainsString('@media (max-height: 820px)', $html);
        $this->assertStringContainsString('.sidebar-pane.is-expanded', $html);
        $this->assertStringNotContainsString('overflow-y-auto" role="navigation"', $html);
    }

    public function test_compact_density_keeps_usable_targets_on_short_viewports(): void
    {
        $html = $this->adminHtml();

        // Short viewports get tighter spacing via a height media query —
        // never smaller icons, removed items, or a scrollbar.
        $this->assertStringContainsString('@media (max-height: 720px)', $html);
        $this->assertStringContainsString('height: 36px', $html);

        // Normal density keeps the full 40px rows and 20px icons.
        $this->assertStringContainsString('flex items-center h-10 w-full', $html);
        $this->assertStringContainsString('h-5 w-5 flex-shrink-0', $html);
        $this->assertStringContainsString('h-10 w-10 rounded-xl', $html);
    }
}
