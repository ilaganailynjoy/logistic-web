<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryStatusLog;
use App\Models\LogisticsCenter;
use App\Models\LoginHistory;
use App\Models\LogisticsSetting;
use App\Models\Notification;
use App\Models\Rider;
use App\Models\ServiceArea;
use App\Models\User;
use App\Models\VehicleType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Logistics Center Settings: every displayed setting is genuinely
 * functional — persisted server-side and observable in behaviour.
 */
class SettingsFunctionalTest extends TestCase
{
    protected function tearDown(): void
    {
        // ApplyUserTimezone sets a process-global timezone per request;
        // restore the default so later suites are unaffected.
        date_default_timezone_set(config('app.timezone', 'UTC'));

        parent::tearDown();
    }

    private function admin(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Settings Admin ' . uniqid(),
            'first_name' => 'Settings',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'settings-' . uniqid() . '@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000001',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => 'admin',
            'status' => 'active',
            'center_id' => null,
            'email_verified_at' => now(),
        ], $overrides));
    }

    private function notificationPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'new_delivery_request',
            'title' => 'Bell Test ' . uniqid(),
            'message' => 'Bell test notification.',
            'icon' => '📦',
            'priority' => 'normal',
        ], $overrides);
    }

    // ── Access ───────────────────────────────────────────────────

    public function test_guest_cannot_access_settings(): void
    {
        $this->get(route('settings.index'))->assertRedirect(route('login'));
    }

    public function test_admin_can_access_settings(): void
    {
        $this->actingAs($this->admin())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Manage your account preferences, security, and notifications');
    }

    // ── Password ─────────────────────────────────────────────────

    public function test_password_change_works_with_valid_current_password(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->put(route('settings.update-password'), [
                'current_password' => 'password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('success', 'Password changed successfully.');

        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
    }

    public function test_password_change_rejects_wrong_current_password(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->put(route('settings.update-password'), [
                'current_password' => 'wrong-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_change_validates_length_and_confirmation(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->put(route('settings.update-password'), [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_requirements_are_visible_before_submission(): void
    {
        $this->actingAs($this->admin())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('min. 8 characters')
            ->assertSee('Change Password');
    }

    // ── Notifications ────────────────────────────────────────────

    public function test_notification_preferences_persist_and_are_isolated_per_user(): void
    {
        $user = $this->admin();
        $other = $this->admin();

        $payload = array_fill_keys(LogisticsSetting::NOTIFICATION_KEYS, 1);
        $payload['rider_applications'] = 0;
        $payload['delivery_completed'] = 0;

        $this->actingAs($user)
            ->put(route('settings.update-notifications'), $payload)
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('success', 'Notification preferences updated successfully.');

        $stored = LogisticsSetting::forUser($user->id)->fresh()->notifications;
        $this->assertFalse((bool) $stored['rider_applications']);
        $this->assertFalse((bool) $stored['delivery_completed']);
        $this->assertTrue((bool) $stored['delivery_requests']);

        // The other user's defaults are untouched.
        $otherStored = LogisticsSetting::forUser($other->id)->fresh();
        $this->assertTrue($otherStored->notificationEnabled('rider_applications'));
        $this->assertTrue($otherStored->notificationEnabled('delivery_completed'));

        // Saved values survive a fresh page load.
        $html = (string) $this->actingAs($user)->get(route('settings.index'))->getContent();
        $this->assertStringNotContainsString('name="rider_applications" value="1" checked', $html);
    }

    public function test_notification_bell_only_shows_enabled_categories(): void
    {
        $user = $this->admin();

        $riderNotif = Notification::create($this->notificationPayload([
            'type' => 'new_rider_application',
            'title' => 'Rider Bell ' . uniqid(),
        ]));
        $doneNotif = Notification::create($this->notificationPayload([
            'type' => 'delivery_delivered',
            'title' => 'Done Bell ' . uniqid(),
        ]));
        $alwaysNotif = Notification::create($this->notificationPayload([
            'type' => 'delivery_cancelled',
            'title' => 'Cancelled Bell ' . uniqid(),
        ]));

        // Defaults: everything visible.
        $all = $this->actingAs($user)->getJson(route('notifications.index'))->assertOk()->json();
        $this->assertContains($riderNotif->title, array_column($all['notifications'], 'title'));
        $this->assertContains($doneNotif->title, array_column($all['notifications'], 'title'));

        // Disable rider applications: matching rows disappear from the bell
        // and from the unread count, while other categories stay.
        $payload = array_fill_keys(LogisticsSetting::NOTIFICATION_KEYS, 1);
        $payload['rider_applications'] = 0;
        $this->actingAs($user)->put(route('settings.update-notifications'), $payload)->assertRedirect();

        $filtered = $this->actingAs($user)->getJson(route('notifications.index'))->assertOk()->json();
        $titles = array_column($filtered['notifications'], 'title');
        $this->assertNotContains($riderNotif->title, $titles);
        $this->assertContains($doneNotif->title, $titles);
        // Unmapped operational alerts are always shown.
        $this->assertContains($alwaysNotif->title, $titles);
        $this->assertSame(
            Notification::forUserPreferences($user->id)->unread()->count(),
            $filtered['unread_count']
        );
    }

    public function test_new_producers_feed_their_notification_categories(): void
    {
        $admin = $this->admin();
        $center = LogisticsCenter::create([
            'name' => 'Notif Center ' . uniqid(), 'address' => 'N St',
            'city' => 'N City', 'province' => 'N', 'is_active' => true,
        ]);

        // Pickup rejection produces a failed_pickups notification.
        $delivery = Delivery::create([
            'sender_name' => 'Shop', 'sender_phone' => '09170000001',
            'sender_address' => 'Shop St', 'recipient_name' => 'Cust',
            'recipient_phone' => '09170000002', 'recipient_address' => 'Cust Ave',
            'status' => 'waiting_for_rider',
        ]);
        $pickup = \App\Models\PickupRequest::create([
            'delivery_id' => $delivery->id, 'requested_at' => now(),
        ]);
        $this->actingAs($admin)
            ->post(route('pickup-requests.reject', $pickup), ['rejection_reason' => 'Shop closed'])
            ->assertRedirect();
        $this->assertDatabaseHas('notifications', ['type' => 'pickup_failed']);

        // Rider activation produces a rider_status_updates notification.
        VehicleType::updateOrCreate(['name' => 'motorcycle'], ['label' => 'Motorcycle', 'capacity_kg' => 30, 'is_active' => true, 'sort_order' => 1]);
        $rider = Rider::create([
            'name' => 'Notif Rider', 'email' => 'notif-rider-' . uniqid() . '@test.com',
            'phone' => '09000000003', 'vehicle_type' => 'motorcycle', 'license_plate' => 'NTF 1',
            'status' => 'inactive', 'center_id' => $center->id,
        ]);
        $this->actingAs($admin)->post(route('riders.activate', $rider))->assertRedirect();
        $this->assertDatabaseHas('notifications', ['type' => 'rider_status_changed']);

        // Disabling the categories hides the new rows from the bell.
        $payload = array_fill_keys(LogisticsSetting::NOTIFICATION_KEYS, 1);
        $payload['failed_pickups'] = 0;
        $payload['rider_status_updates'] = 0;
        $this->actingAs($admin)->put(route('settings.update-notifications'), $payload)->assertRedirect();

        $titles = array_column(
            $this->actingAs($admin)->getJson(route('notifications.index'))->assertOk()->json('notifications'),
            'type'
        );
        $this->assertNotContains('pickup_failed', $titles);
        $this->assertNotContains('rider_status_changed', $titles);
    }

    // ── Appearance ───────────────────────────────────────────────

    public function test_appearance_theme_saves_and_applies(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->put(route('settings.update-appearance'), ['theme' => 'dark'])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('success', 'Appearance updated successfully.');

        $this->assertSame('dark', LogisticsSetting::forUser($user->id)->fresh()->theme());

        $html = (string) $this->actingAs($user)->get(route('settings.index'))->getContent();
        $this->assertStringContainsString("window.__invoizTheme = 'dark'", $html);
    }

    public function test_appearance_rejects_invalid_theme_and_keeps_previous_value(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->put(route('settings.update-appearance'), ['theme' => 'neon'])
            ->assertSessionHasErrors('theme');

        $this->assertSame('light', LogisticsSetting::forUser($user->id)->fresh()->theme());
    }

    public function test_appearance_is_isolated_per_user(): void
    {
        $user = $this->admin();
        $other = $this->admin();

        $this->actingAs($user)->put(route('settings.update-appearance'), ['theme' => 'dark']);

        $this->assertSame('dark', LogisticsSetting::forUser($user->id)->fresh()->theme());
        $this->assertSame('light', LogisticsSetting::forUser($other->id)->fresh()->theme());
    }

    // ── Language & Region ────────────────────────────────────────

    public function test_timezone_saves_and_changes_displayed_dates(): void
    {
        $user = $this->admin();

        // Pin the connection timezone so the fixed instant below is stored
        // exactly (direct writes bypass the per-request middleware).
        DB::statement("SET time_zone = '+00:00'");
        LoginHistory::create([
            'user_id' => $user->id,
            'logged_in_at' => Carbon::parse('2026-01-01 00:00:00', 'UTC'),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test',
        ]);

        $this->actingAs($user)
            ->put(route('settings.update-region'), ['timezone' => 'Asia/Tokyo'])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('success', 'Language & region updated successfully.');

        $this->assertSame('Asia/Tokyo', LogisticsSetting::forUser($user->id)->fresh()->timezone());

        $expectedDate = Carbon::parse('2026-01-01 00:00:00', 'UTC')->setTimezone('Asia/Tokyo')->format('M j, Y');
        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee($expectedDate);
    }

    public function test_timezone_select_reflects_saved_preference_and_defaults_to_app_default(): void
    {
        $user = $this->admin();

        // No saved preference: the application default is pre-selected —
        // never a hardcoded city the user did not choose.
        $html = (string) $this->actingAs($user)->get(route('settings.index'))->getContent();
        $this->assertStringContainsString(
            '<option value="' . config('app.timezone') . '" selected>',
            $html
        );
        $this->assertStringNotContainsString('<option value="Asia/Manila" selected>', $html);

        // A saved preference wins when Settings is reopened.
        $this->actingAs($user)
            ->put(route('settings.update-region'), ['timezone' => 'Asia/Tokyo'])
            ->assertRedirect();

        $html = (string) $this->actingAs($user)->get(route('settings.index'))->getContent();
        $this->assertStringContainsString('<option value="Asia/Tokyo" selected>', $html);
    }

    public function test_timezone_rejects_unsupported_values_and_keeps_previous(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->put(route('settings.update-region'), ['timezone' => 'Mars/Olympus'])
            ->assertSessionHasErrors('timezone');

        $this->assertNull(LogisticsSetting::forUser($user->id)->fresh()->timezone());
    }

    public function test_language_shows_english_only_without_fake_options(): void
    {
        // The UI offers a timezone selector but no language selector: no
        // unsupported language option can be presented or submitted.
        $this->actingAs($this->admin())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Language &amp; Region', false)
            ->assertSee('Timezone')
            ->assertDontSee('name="language"', false);
    }

    // ── Navigation ───────────────────────────────────────────────

    public function test_navigation_preference_saves_and_changes_sidebar_start_state(): void
    {
        $user = $this->admin();

        // Default: sidebar starts collapsed.
        $html = (string) $this->actingAs($user)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('sidebarHover: false', $html);

        $this->actingAs($user)
            ->patch(route('settings.update-navigation'), [
                'remember_sidebar' => true,
                'sidebar_expanded' => true,
            ])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('success', 'Navigation preferences updated successfully.');

        $settings = LogisticsSetting::forUser($user->id)->fresh();
        $this->assertTrue($settings->rememberSidebar());
        $this->assertTrue($settings->sidebarExpanded());

        // The layout now starts with the sidebar expanded for this user.
        $html = (string) $this->actingAs($user)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('sidebarHover: true', $html);
    }

    public function test_navigation_expanded_state_ignored_when_remembering_is_off(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->patch(route('settings.update-navigation'), [
                'remember_sidebar' => false,
                'sidebar_expanded' => true,
            ])
            ->assertRedirect();

        $settings = LogisticsSetting::forUser($user->id)->fresh();
        $this->assertFalse($settings->rememberSidebar());
        $this->assertFalse($settings->sidebarExpanded());
    }

    // ── Delivery preference enforcement ──────────────────────────

    private function eligibleRider(LogisticsCenter $center, ?ServiceArea $area = null): Rider
    {
        VehicleType::updateOrCreate(
            ['name' => 'motorcycle'],
            ['label' => 'Motorcycle', 'capacity_kg' => 30, 'is_active' => true, 'sort_order' => 1],
        );

        return Rider::create([
            'name' => 'Settings Rider ' . uniqid(),
            'email' => 'settings-rider-' . uniqid() . '@test.com',
            'phone' => '09000000004',
            'vehicle_type' => 'motorcycle',
            'license_plate' => 'SET ' . random_int(1, 9999),
            'status' => 'available',
            'is_online' => true,
            'vehicle_verification' => 'verified',
            'approved_at' => now()->subDay(),
            'center_id' => $center->id,
            'service_area_id' => $area?->id,
        ]);
    }

    private function deliveryFor(LogisticsCenter $center, ?ServiceArea $area, array $overrides = []): Delivery
    {
        return Delivery::create(array_merge([
            'sender_name' => 'Shop', 'sender_phone' => '09170000001',
            'sender_address' => 'Shop St', 'recipient_name' => 'Cust',
            'recipient_phone' => '09170000002', 'recipient_address' => 'Cust Ave',
            'status' => 'waiting_for_rider',
            'center_id' => $center->id,
            'destination_center_id' => $center->id,
            'service_area_id' => $area?->id,
        ], $overrides));
    }

    public function test_reassignment_guard_follows_preference(): void
    {
        $admin = $this->admin();
        $center = LogisticsCenter::create([
            'name' => 'Reassign Center ' . uniqid(), 'address' => 'R St',
            'city' => 'R City', 'province' => 'R', 'is_active' => true,
        ]);
        $riderA = $this->eligibleRider($center);
        $riderB = $this->eligibleRider($center);
        $delivery = $this->deliveryFor($center, null, ['rider_id' => $riderA->id, 'status' => 'assigned']);

        // Disable reassignment: moving to another rider is blocked.
        $this->actingAs($admin)->put(route('settings.update-delivery'), [
            'require_proof' => 1, 'max_attempts' => 2, 'allow_reassignment' => 0,
        ])->assertRedirect();

        $this->actingAs($admin)
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $riderB->id])
            ->assertSessionHasErrors('rider_id');
        $this->assertSame($riderA->id, $delivery->fresh()->rider_id);

        // Re-enable: the same move succeeds.
        $this->actingAs($admin)->put(route('settings.update-delivery'), [
            'require_proof' => 1, 'max_attempts' => 2, 'allow_reassignment' => 1,
        ])->assertRedirect();

        $this->actingAs($admin)
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $riderB->id])
            ->assertRedirect();
        $this->assertSame($riderB->id, $delivery->fresh()->rider_id);
    }

    public function test_attempt_budget_blocks_exhausted_retries(): void
    {
        $admin = $this->admin();
        $center = LogisticsCenter::create([
            'name' => 'Attempt Center ' . uniqid(), 'address' => 'A St',
            'city' => 'A City', 'province' => 'A', 'is_active' => true,
        ]);
        $rider = $this->eligibleRider($center);
        $delivery = $this->deliveryFor($center, null, ['status' => 'delivery_failed']);

        foreach ([1, 2] as $i) {
            DeliveryStatusLog::create([
                'delivery_id' => $delivery->id,
                'status' => 'delivery_failed',
                'notes' => "Failed attempt {$i}",
                'changed_by' => $admin->id,
            ]);
        }

        // Default budget is 2 and both attempts are used: retry blocked.
        $this->actingAs($admin)
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $rider->id])
            ->assertSessionHasErrors('rider_id');

        // Raising the budget re-allows the retry.
        $this->actingAs($admin)->put(route('settings.update-delivery'), [
            'require_proof' => 1, 'max_attempts' => 3, 'allow_reassignment' => 1,
        ])->assertRedirect();

        $this->actingAs($admin)
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $rider->id])
            ->assertRedirect();
        $this->assertSame('assigned', $delivery->fresh()->status);
    }

    public function test_attempt_indicator_shows_real_usage_on_delivery_page(): void
    {
        $admin = $this->admin();
        $center = LogisticsCenter::create([
            'name' => 'Indicator Center ' . uniqid(), 'address' => 'I St',
            'city' => 'I City', 'province' => 'I', 'is_active' => true,
        ]);
        $delivery = $this->deliveryFor($center, null, ['status' => 'delivery_failed']);
        DeliveryStatusLog::create([
            'delivery_id' => $delivery->id, 'status' => 'delivery_failed', 'notes' => 'x',
        ]);

        $this->actingAs($admin)
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertSee('Attempts 1 of 2');
    }

    // ── Login history ────────────────────────────────────────────

    public function test_successful_login_records_history(): void
    {
        $user = $this->admin();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect();

        $entry = LoginHistory::where('user_id', $user->id)->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertNotNull($entry->logged_in_at);
    }

    public function test_activity_section_lists_recorded_sign_ins(): void
    {
        $user = $this->admin();
        LoginHistory::create([
            'user_id' => $user->id,
            'logged_in_at' => now()->subHour(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0',
        ]);

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Login History')
            ->assertSee('Chrome on Windows')
            ->assertSee('127.0.0.1');
    }

    // ── Profile separation ───────────────────────────────────────

    public function test_settings_links_to_profile_without_duplicating_it(): void
    {
        // The profile card links out to the Profile page; no profile fields
        // (name/email inputs, photo upload) are duplicated in Settings.
        $this->actingAs($this->admin())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Open Profile')
            ->assertSee(route('profile.show'), false)
            ->assertDontSee('Edit Profile')
            ->assertDontSee('Profile Information')
            ->assertDontSee('name="email"', false)
            ->assertDontSee('Full Name');
    }

    public function test_duplicate_profile_update_route_was_removed(): void
    {
        $this->assertFalse(Route::has('settings.update-profile'));
        $this->assertTrue(Route::has('profile.update'));
    }

    // ── Search / help / privacy ──────────────────────────────────

    public function test_settings_search_and_help_sections_are_present(): void
    {
        $this->actingAs($this->admin())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Search settings')
            ->assertSee('No settings match')
            ->assertSee('data-section="notifications', false)
            ->assertSee('Help &amp; Support', false)
            ->assertSee('Open Messages')
            ->assertSee('Privacy &amp; Security', false)
            ->assertSee('Login History')
            ->assertSee('Navigation Preferences')
            ->assertSee('Language &amp; Region', false);
    }

    public function test_login_history_shows_real_sign_in_facts(): void
    {
        // The privacy-group history lists genuine sign-ins with device, IP,
        // and timestamp — never fabricated rows.
        $user = $this->admin();
        LoginHistory::create([
            'user_id' => $user->id,
            'logged_in_at' => Carbon::parse('2026-02-03 04:05:06', 'UTC'),
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Firefox/121.0',
        ]);

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Firefox on macOS')
            ->assertSee('203.0.113.7')
            ->assertSee('Login History');
    }
}
