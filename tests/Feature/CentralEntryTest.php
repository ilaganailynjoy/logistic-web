<?php

namespace Tests\Feature;

use App\Http\Controllers\CentralEntryController;
use App\Models\User;
use Tests\TestCase;

/**
 * INVOIZ central entry: no public landing page — guests go straight to
 * the Logistics login, authenticated users are routed server-side by
 * their existing account role (never a platform-selection screen), and
 * accounts without a logistics destination are signed out to login.
 * Access enforcement stays with middleware.
 */
class CentralEntryTest extends TestCase
{
    private function user(string $role, array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Entry ' . $role . ' ' . uniqid(),
            'first_name' => 'Entry',
            'last_name' => ucfirst($role),
            'sex' => 'male',
            'email' => 'entry-' . $role . '-' . uniqid() . '@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000000',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => $role,
            'status' => 'active',
            'center_id' => null,
            'email_verified_at' => now(),
        ], $overrides));
    }

    // ── Guest ────────────────────────────────────────────────────

    public function test_guest_goes_straight_to_login(): void
    {
        $this->get(route('landing'))
            ->assertRedirect(route('login'));
    }

    // ── Authenticated routing ────────────────────────────────────

    public function test_logistics_users_are_routed_to_dashboard(): void
    {
        foreach (['admin', 'staff'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('landing'))
                ->assertRedirect(route('dashboard', absolute: false));
        }
    }

    public function test_rider_is_routed_to_rider_entry(): void
    {
        $this->actingAs($this->user('rider'))
            ->get(route('landing'))
            ->assertRedirect(route('rider.entry', absolute: false));
    }

    public function test_buyer_without_configured_platform_is_signed_out_to_login(): void
    {
        $this->actingAs($this->user('buyer'))
            ->get(route('landing'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_seller_without_configured_platform_is_signed_out_to_login(): void
    {
        $this->actingAs($this->user('seller'))
            ->get(route('landing'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_destination_for_resolves_each_role(): void
    {
        $this->assertSame(
            route('dashboard', absolute: false),
            CentralEntryController::destinationFor($this->user('admin'))
        );
        $this->assertSame(
            route('rider.entry', absolute: false),
            CentralEntryController::destinationFor($this->user('rider'))
        );
        $this->assertNull(CentralEntryController::destinationFor($this->user('buyer')));
        $this->assertNull(CentralEntryController::destinationFor($this->user('seller')));
    }

    // ── Rider entry ──────────────────────────────────────────────

    public function test_rider_entry_shows_deeplink_and_web_fallback(): void
    {
        $html = (string) $this->actingAs($this->user('rider'))
            ->get(route('rider.entry'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('invoizrider://login', $html);
        $this->assertStringContainsString('Open Rider App', $html);
        $this->assertStringContainsString('Continue on Web', $html);
        $this->assertStringContainsString(route('rider.messages'), $html);
    }

    public function test_rider_entry_requires_rider_role(): void
    {
        $this->get(route('rider.entry'))->assertRedirect(route('login'));

        $this->actingAs($this->user('staff'))
            ->get(route('rider.entry'))
            ->assertRedirect(route('login'));
    }

    // ── Login / logout integration ───────────────────────────────

    public function test_login_routes_rider_to_rider_entry(): void
    {
        $user = $this->user('rider');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('rider.entry', absolute: false));
    }

    public function test_buyer_login_is_rejected_without_logistics_access(): void
    {
        // The Logistics login admits only admin/staff/rider (existing rule
        // in LoginRequest): buyer accounts are refused and never signed in.
        $user = $this->user('buyer');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_logout_returns_to_login(): void
    {
        $this->actingAs($this->user('staff'))
            ->post(route('logout'))
            ->assertRedirect('/');

        $this->get(route('landing'))->assertRedirect(route('login'));
        $this->followRedirects($this->get(route('login')))->assertOk();
    }

    // ── Middleware still enforces access ─────────────────────────

    public function test_rider_cannot_access_logistics_dashboard(): void
    {
        $this->actingAs($this->user('rider'))
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_buyer_cannot_access_logistics_dashboard(): void
    {
        $this->actingAs($this->user('buyer'))
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_staff_cannot_access_rider_entry(): void
    {
        $this->actingAs($this->user('staff'))
            ->get(route('rider.entry'))
            ->assertRedirect(route('login'));
    }
}
