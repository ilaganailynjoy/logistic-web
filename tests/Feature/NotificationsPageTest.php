<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Tests\TestCase;

/**
 * Dedicated /notifications page (staff web).
 *
 * The bell JSON feed keeps its exact contract (Accept: application/json);
 * browser navigation renders the full page with All/Unread/type filters,
 * preference filtering, read actions, and resource links. The bell itself
 * is untouched (see NotificationBellTest).
 */
class NotificationsPageTest extends TestCase
{
    private function staff(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Notif Staff ' . uniqid(),
            'first_name' => 'Notif',
            'last_name' => 'Staff',
            'sex' => 'male',
            'email' => 'notif-staff-' . uniqid() . '@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000021',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => 'staff',
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    private function make(string $type, string $title, bool $read = false): Notification
    {
        return Notification::create([
            'type' => $type, 'title' => $title,
            'message' => $title . ' body.', 'icon' => '🔔',
            'priority' => 'normal', 'is_read' => $read,
        ]);
    }

    private function prefsExcept(User $user, array $off = []): void
    {
        $payload = [];
        foreach (\App\Models\LogisticsSetting::NOTIFICATION_KEYS as $key) {
            $payload[$key] = in_array($key, $off, true) ? 0 : 1;
        }
        $this->actingAs($user)
            ->put(route('settings.update-notifications'), $payload)
            ->assertRedirect();
    }

    public function test_page_renders_with_notifications(): void
    {
        $staff = $this->staff();
        $this->make('new_delivery_request', 'Fresh Request');
        $this->make('rider_accepted_delivery', 'Rider Assigned', true);

        $response = $this->actingAs($staff)->get(route('notifications.index'));

        $response->assertOk()
            ->assertSee('Notifications', false)
            ->assertSee('Fresh Request', false)
            ->assertSee('Rider Assigned', false)
            ->assertSee('Mark all read', false);
    }

    public function test_unread_filter_hides_read(): void
    {
        $staff = $this->staff();
        $this->make('new_delivery_request', 'Unread One');
        $this->make('rider_accepted_delivery', 'Read One', true);

        $response = $this->actingAs($staff)
            ->get(route('notifications.index', ['filter' => 'unread']));

        $response->assertOk()
            ->assertSee('Unread One', false)
            ->assertDontSee('Read One', false);
    }

    public function test_type_filter_narrows_list(): void
    {
        $staff = $this->staff();
        $this->make('new_delivery_request', 'Request Row');
        $this->make('rider_accepted_delivery', 'Assigned Row');

        $response = $this->actingAs($staff)
            ->get(route('notifications.index', ['type' => 'rider_accepted_delivery']));

        $response->assertOk()
            ->assertSee('Assigned Row', false)
            ->assertDontSee('Request Row', false);
    }

    public function test_preferences_respected_on_page(): void
    {
        $staff = $this->staff();
        $this->make('new_center_application', 'Center Applied');

        $this->actingAs($staff)->get(route('notifications.index'))
            ->assertOk()->assertSee('Center Applied', false);

        $this->prefsExcept($staff, ['application_updates']);

        $this->actingAs($staff)->get(route('notifications.index'))
            ->assertOk()->assertDontSee('Center Applied', false);
    }

    public function test_bell_json_contract_unchanged(): void
    {
        $staff = $this->staff();
        $this->make('new_delivery_request', 'Bell Row');

        $this->actingAs($staff)->getJson(route('notifications.index'))
            ->assertOk()
            ->assertJsonStructure(['notifications', 'unread_count'])
            ->assertJsonFragment(['title' => 'Bell Row']);
    }

    public function test_guest_redirected_and_rider_refused(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));

        $riderUser = $this->staff(['role' => 'rider', 'email' => 'notif-rider-' . uniqid() . '@test.com']);
        $this->actingAs($riderUser)->get(route('notifications.index'))
            ->assertRedirect(route('login'));
    }

    public function test_sidebar_links_to_page(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('notifications.index'), false);
    }
}
