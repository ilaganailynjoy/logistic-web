<?php

namespace Tests\Feature;

use App\Models\LoginHistory;
use App\Models\LogisticsSetting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Proves that every setting on the Settings page actually saves to the
 * database and is still correct after a fresh model load (i.e. persists
 * across requests / logins).
 */
class SettingsTest extends TestCase
{
    // ── Page access ──────────────────────────────────────────────

    public function test_guest_is_redirected_from_settings(): void
    {
        $this->get(route('settings.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_staff_can_view_settings_page(): void
    {
        $user = User::factory()->create(['role' => 'staff', 'status' => 'active']);

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Settings');
    }

    public function test_settings_page_shows_all_required_sections(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Account')
            ->assertSee('Profile')
            ->assertSee('Security &amp; Password', false)
            ->assertSee('Preferences')
            ->assertSee('Notifications')
            ->assertSee('Delivery Preferences')
            ->assertSee('Appearance')
            ->assertSee('Navigation Preferences')
            ->assertSee('Language &amp; Region', false)
            ->assertSee('Privacy &amp; Security', false)
            ->assertSee('Login History')
            ->assertSee('Support')
            ->assertSee('Help &amp; Support', false);
    }

    public function test_settings_page_has_search_field(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Search settings');
    }

    public function test_settings_page_does_not_contain_profile_fields(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertDontSee('Full Name')
            ->assertDontSee('Contact Number')
            ->assertDontSee('Change Photo');
    }

    public function test_settings_page_links_to_profile_show(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee(route('profile.show'));
    }

    // ── Password ─────────────────────────────────────────────────

    public function test_password_change_saves_new_hash_to_database(): void
    {
        $user = User::factory()->create([
            'role'     => 'admin',
            'password' => Hash::make('OldPass123!'),
        ]);

        $this->actingAs($user)
            ->put(route('settings.update-password'), [
                'current_password'      => 'OldPass123!',
                'password'              => 'NewPass456!',
                'password_confirmation' => 'NewPass456!',
            ])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('NewPass456!', $user->fresh()->password));
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('Correct1!')]);

        $this->actingAs($user)
            ->put(route('settings.update-password'), [
                'current_password'      => 'WrongPass!',
                'password'              => 'NewPass456!',
                'password_confirmation' => 'NewPass456!',
            ])
            ->assertSessionHasErrors('current_password');
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('Correct1!')]);

        $this->actingAs($user)
            ->put(route('settings.update-password'), [
                'current_password'      => 'Correct1!',
                'password'              => 'NewPass456!',
                'password_confirmation' => 'DifferentPass!',
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_password_shorter_than_8_chars_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('Correct1!')]);

        $this->actingAs($user)
            ->put(route('settings.update-password'), [
                'current_password'      => 'Correct1!',
                'password'              => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrors('password');
    }

    // ── Notifications ─────────────────────────────────────────────

    public function test_notification_preferences_save_and_persist(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        // Turn everything OFF
        $payload = array_fill_keys(LogisticsSetting::NOTIFICATION_KEYS, '0');
        $payload['email_notifications'] = '0';

        $this->actingAs($user)
            ->put(route('settings.update-notifications'), $payload)
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('success');

        $settings = LogisticsSetting::forUser($user->id)->fresh();
        foreach (LogisticsSetting::NOTIFICATION_KEYS as $key) {
            $this->assertFalse($settings->notificationEnabled($key), "Expected $key to be OFF");
        }
        $this->assertFalse((bool) $settings->email_notifications);
    }

    public function test_notification_preferences_can_be_turned_back_on(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        // First turn off
        $off = array_fill_keys(LogisticsSetting::NOTIFICATION_KEYS, '0');
        $this->actingAs($user)->put(route('settings.update-notifications'), $off);

        // Then turn on
        $on = array_fill_keys(LogisticsSetting::NOTIFICATION_KEYS, '1');
        $on['email_notifications'] = '1';

        $this->actingAs($user)
            ->put(route('settings.update-notifications'), $on)
            ->assertRedirect(route('settings.index'));

        $settings = LogisticsSetting::forUser($user->id)->fresh();
        foreach (LogisticsSetting::NOTIFICATION_KEYS as $key) {
            $this->assertTrue($settings->notificationEnabled($key), "Expected $key to be ON");
        }
        $this->assertTrue((bool) $settings->email_notifications);
    }

    public function test_individual_notification_toggle_persists_independently(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $payload = array_fill_keys(LogisticsSetting::NOTIFICATION_KEYS, '1');
        $payload['new_messages'] = '0'; // only this one off

        $this->actingAs($user)
            ->put(route('settings.update-notifications'), $payload)
            ->assertRedirect(route('settings.index'));

        $settings = LogisticsSetting::forUser($user->id)->fresh();
        $this->assertFalse($settings->notificationEnabled('new_messages'));
        $this->assertTrue($settings->notificationEnabled('delivery_completed'));
    }

    // ── Delivery Preferences ──────────────────────────────────────

    public function test_delivery_preferences_save_and_persist(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->put(route('settings.update-delivery'), [
                'require_proof'     => '1',
                'max_attempts'      => '3',
                'allow_reassignment'=> '0',
            ])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('success');

        $delivery = LogisticsSetting::forUser($user->id)->fresh()->delivery;
        $this->assertTrue((bool) $delivery['require_proof']);
        $this->assertSame(3, (int) $delivery['max_attempts']);
        $this->assertFalse((bool) $delivery['allow_reassignment']);
    }

    public function test_max_attempts_must_be_between_1_and_3(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->put(route('settings.update-delivery'), ['max_attempts' => '5'])
            ->assertSessionHasErrors('max_attempts');

        $this->actingAs($user)
            ->put(route('settings.update-delivery'), ['max_attempts' => '0'])
            ->assertSessionHasErrors('max_attempts');
    }

    // ── Appearance ────────────────────────────────────────────────

    public function test_appearance_theme_saves_and_persists(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        foreach (['light', 'dark', 'system'] as $theme) {
            $this->actingAs($user)
                ->put(route('settings.update-appearance'), ['theme' => $theme])
                ->assertRedirect(route('settings.index'))
                ->assertSessionHas('success');

            $this->assertSame($theme, LogisticsSetting::forUser($user->id)->fresh()->theme());
        }
    }

    public function test_invalid_theme_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->put(route('settings.update-appearance'), ['theme' => 'rainbow'])
            ->assertSessionHasErrors('theme');
    }

    public function test_saved_theme_is_reflected_in_layout_html(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        LogisticsSetting::forUser($user->id)->savePreferences(['theme' => 'dark']);

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee("window.__invoizTheme = 'dark'", false);
    }

    // ── Navigation Preferences ────────────────────────────────────

    public function test_remember_sidebar_saves_and_persists(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->patch(route('settings.update-navigation'), ['remember_sidebar' => '1'])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('success');

        $this->assertTrue(LogisticsSetting::forUser($user->id)->fresh()->rememberSidebar());
    }

    public function test_disabling_remember_sidebar_persists(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        LogisticsSetting::forUser($user->id)->savePreferences(['remember_sidebar' => true]);

        $this->actingAs($user)
            ->patch(route('settings.update-navigation'), ['remember_sidebar' => '0'])
            ->assertRedirect(route('settings.index'));

        $this->assertFalse(LogisticsSetting::forUser($user->id)->fresh()->rememberSidebar());
    }

    public function test_navigation_update_returns_json_when_requested_via_fetch(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        LogisticsSetting::forUser($user->id)->savePreferences(['remember_sidebar' => true]);

        $this->actingAs($user)
            ->patchJson(route('settings.update-navigation'), ['sidebar_expanded' => true])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertTrue(LogisticsSetting::forUser($user->id)->fresh()->sidebarExpanded());
    }

    // ── Language & Region ─────────────────────────────────────────

    public function test_timezone_saves_and_persists(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->put(route('settings.update-region'), ['timezone' => 'Asia/Tokyo'])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('success');

        $this->assertSame('Asia/Tokyo', LogisticsSetting::forUser($user->id)->fresh()->timezone());
    }

    public function test_invalid_timezone_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->put(route('settings.update-region'), ['timezone' => 'Mars/Olympus'])
            ->assertSessionHasErrors('timezone');
    }

    // ── Login History ─────────────────────────────────────────────

    public function test_login_history_section_shows_real_records(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        LoginHistory::create([
            'user_id'      => $user->id,
            'logged_in_at' => now()->subHours(2),
            'ip_address'   => '192.168.1.10',
            'user_agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
        ]);

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Login History')
            ->assertSee('192.168.1.10')
            ->assertSee('Chrome on Windows');
    }

    public function test_login_history_shows_empty_state_when_no_records(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        LoginHistory::where('user_id', $user->id)->delete();

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('No login history recorded yet');
    }

    public function test_login_history_only_shows_current_users_records(): void
    {
        $user  = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'staff']);

        LoginHistory::create([
            'user_id'      => $other->id,
            'logged_in_at' => now(),
            'ip_address'   => '10.0.0.99',
            'user_agent'   => 'Mozilla/5.0',
        ]);

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertDontSee('10.0.0.99');
    }

    public function test_login_history_capped_at_10_records(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        for ($i = 1; $i <= 12; $i++) {
            LoginHistory::create([
                'user_id'      => $user->id,
                'logged_in_at' => now()->subMinutes($i),
                'ip_address'   => "10.0.0.$i",
                'user_agent'   => 'Mozilla/5.0',
            ]);
        }

        $response = $this->actingAs($user)->get(route('settings.index'))->assertOk();

        // The oldest two IPs (10.0.0.11 and 10.0.0.12) should not appear
        $response->assertDontSee('10.0.0.11');
        $response->assertDontSee('10.0.0.12');
    }

    // ── Support section ───────────────────────────────────────────

    public function test_support_section_links_to_messages(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee(route('messages.index'));
    }

    // ── Settings isolation: one user cannot affect another ────────

    public function test_settings_are_isolated_per_user(): void
    {
        $alice = User::factory()->create(['role' => 'admin']);
        $bob   = User::factory()->create(['role' => 'staff']);

        $this->actingAs($alice)
            ->put(route('settings.update-appearance'), ['theme' => 'dark']);

        $this->actingAs($bob)
            ->put(route('settings.update-appearance'), ['theme' => 'light']);

        $this->assertSame('dark',  LogisticsSetting::forUser($alice->id)->fresh()->theme());
        $this->assertSame('light', LogisticsSetting::forUser($bob->id)->fresh()->theme());
    }
}
