<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\LogisticsCenter;
use App\Models\Notification;
use App\Models\Rider;
use App\Models\User;
use App\Models\VehicleType;
use Tests\TestCase;

/**
 * Staff notification bell behavior: producers write linked records,
 * preference filtering (including application_updates) governs the list
 * and the badge count, read actions persist, and destinations pass
 * through to the UI. No new notification types or pages are introduced.
 */
class NotificationBellTest extends TestCase
{
    private function admin(): User
    {
        return User::create([
            'name' => 'Bell Admin ' . uniqid(),
            'first_name' => 'Bell',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'bell-admin-' . uniqid() . '@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000001',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => 'admin',
            'status' => 'active',
            'center_id' => null,
            'email_verified_at' => now(),
        ]);
    }

    private function center(): LogisticsCenter
    {
        return LogisticsCenter::create([
            'name' => 'Bell Center ' . uniqid(), 'address' => 'B St',
            'city' => 'B City', 'province' => 'B', 'is_active' => true,
        ]);
    }

    private function eligibleRider(LogisticsCenter $center): Rider
    {
        VehicleType::updateOrCreate(
            ['name' => 'motorcycle'],
            ['label' => 'Motorcycle', 'capacity_kg' => 30, 'is_active' => true, 'sort_order' => 1],
        );

        return Rider::create([
            'name' => 'Bell Rider ' . uniqid(),
            'email' => 'bell-rider-' . uniqid() . '@test.com',
            'phone' => '09000000004',
            'vehicle_type' => 'motorcycle',
            'license_plate' => 'BEL ' . random_int(1, 9999),
            'status' => 'available',
            'is_online' => true,
            'vehicle_verification' => 'verified',
            'approved_at' => now()->subDay(),
            'center_id' => $center->id,
        ]);
    }

    private function waitingDelivery(LogisticsCenter $center): Delivery
    {
        return Delivery::create([
            'sender_name' => 'Shop',
            'sender_phone' => '09170000001',
            'sender_address' => 'Shop St',
            'recipient_name' => 'Cust',
            'recipient_phone' => '09170000002',
            'recipient_address' => 'Cust Ave',
            'status' => 'waiting_for_rider',
            'center_id' => $center->id,
            'destination_center_id' => $center->id,
        ]);
    }

    /** All preference keys on except the given ones (PUT semantics). */
    private function prefsExcept(User $admin, array $off = []): void
    {
        $payload = [];
        foreach (\App\Models\LogisticsSetting::NOTIFICATION_KEYS as $key) {
            $payload[$key] = in_array($key, $off, true) ? 0 : 1;
        }

        $this->actingAs($admin)
            ->put(route('settings.update-notifications'), $payload)
            ->assertRedirect();
    }

    public function test_rider_assignment_produces_linked_notification(): void
    {
        $center = $this->center();
        $rider = $this->eligibleRider($center);
        $delivery = $this->waitingDelivery($center);

        $this->actingAs($this->admin())
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $rider->id])
            ->assertRedirect();

        $this->assertDatabaseHas('notifications', [
            'type' => 'rider_accepted_delivery',
            'title' => 'Rider Assigned to Delivery',
            'link' => route('deliveries.show', $delivery),
        ]);
    }

    public function test_application_updates_preference_filters_end_to_end(): void
    {
        $admin = $this->admin();
        Notification::create([
            'type' => 'new_center_application',
            'title' => 'New Center Application',
            'message' => 'A center applied.',
            'icon' => '🏢',
            'priority' => 'high',
        ]);

        // Default preferences: everything enabled.
        $list = $this->actingAs($admin)->getJson(route('notifications.index'))->assertOk();
        $list->assertJsonPath('unread_count', 1);
        $list->assertJsonFragment(['type' => 'new_center_application']);

        // Disabled preference suppresses list and badge together.
        $this->prefsExcept($admin, ['application_updates']);

        $filtered = $this->actingAs($admin)->getJson(route('notifications.index'))->assertOk();
        $filtered->assertJsonPath('unread_count', 0);
        $filtered->assertJsonMissing(['type' => 'new_center_application']);

        // Re-enabled preference restores it (row persisted, still unread).
        $this->prefsExcept($admin);

        $restored = $this->actingAs($admin)->getJson(route('notifications.index'))->assertOk();
        $restored->assertJsonPath('unread_count', 1);
        $restored->assertJsonFragment(['type' => 'new_center_application']);
    }

    public function test_mark_one_read_persists_and_drops_scoped_count(): void
    {
        $admin = $this->admin();
        $first = Notification::create([
            'type' => 'new_delivery_request', 'title' => 'First',
            'message' => 'One.', 'icon' => '📦', 'priority' => 'normal',
        ]);
        Notification::create([
            'type' => 'new_delivery_request', 'title' => 'Second',
            'message' => 'Two.', 'icon' => '📦', 'priority' => 'normal',
        ]);

        $this->actingAs($admin)
            ->patch(route('notifications.mark-read', $first))
            ->assertOk()
            ->assertJsonPath('unread_count', 1);

        $this->assertTrue($first->fresh()->is_read);

        // Read rows remain available in the list.
        $this->actingAs($admin)
            ->getJson(route('notifications.index'))
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonFragment(['title' => 'First']);
    }

    public function test_mark_all_read_clears_scoped_count(): void
    {
        $admin = $this->admin();
        Notification::create([
            'type' => 'pickup_failed', 'title' => 'Pickup A',
            'message' => 'A.', 'icon' => '⚠️', 'priority' => 'high',
        ]);
        Notification::create([
            'type' => 'delivery_delivered', 'title' => 'Delivered B',
            'message' => 'B.', 'icon' => '✅', 'priority' => 'normal',
        ]);

        $this->actingAs($admin)
            ->patch(route('notifications.mark-all-read'))
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, Notification::where('is_read', false)->count());
    }

    public function test_bell_passes_stored_destinations_through(): void
    {
        $admin = $this->admin();
        Notification::create([
            'type' => 'new_message',
            'title' => 'Message',
            'message' => 'Hello.',
            'icon' => '💬',
            'priority' => 'normal',
            'link' => route('messages.index', ['conversation' => 42]),
        ]);

        $this->actingAs($admin)
            ->getJson(route('notifications.index'))
            ->assertOk()
            ->assertJsonFragment(['link' => route('messages.index', ['conversation' => 42])]);
    }
}
