<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\RiderNotification;
use App\Models\ServiceArea;
use App\Models\User;
use App\Models\VehicleType;
use Tests\TestCase;

/**
 * Rider in-app notifications from existing backend events.
 *
 * Uses the pre-existing rider_notifications table/API/UI with no new
 * infrastructure: assignment (and reassignment) notifies the newly
 * assigned rider, staff-marked failures notify the assigned rider, and
 * sorting notifies the assigned rider when the parcel becomes actionable.
 * notifyOnce suppresses duplicates from retried requests. Rider-initiated
 * actions (own accept/pickup/failure) never notify the actor.
 */
class RiderNotificationProducersTest extends TestCase
{
    private function admin(): User
    {
        return User::create([
            'name' => 'RN Admin ' . uniqid(),
            'first_name' => 'RN',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'rn-admin-' . uniqid() . '@logistics.com',
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
            'name' => 'RN Center ' . uniqid(), 'address' => 'R St',
            'city' => 'R City', 'province' => 'R', 'is_active' => true,
        ]);
    }

    private function eligibleRider(LogisticsCenter $center, string $tag): Rider
    {
        VehicleType::updateOrCreate(
            ['name' => 'motorcycle'],
            ['label' => 'Motorcycle', 'capacity_kg' => 30, 'is_active' => true, 'sort_order' => 1],
        );

        return Rider::create([
            'name' => 'RN Rider ' . $tag . ' ' . uniqid(),
            'email' => 'rn-rider-' . strtolower($tag) . '-' . uniqid() . '@test.com',
            'phone' => '09000000004',
            'vehicle_type' => 'motorcycle',
            'license_plate' => 'RNN ' . $tag,
            'status' => 'available',
            'is_online' => true,
            'vehicle_verification' => 'verified',
            'approved_at' => now()->subDay(),
            'center_id' => $center->id,
        ]);
    }

    private function riderAccount(Rider $rider): User
    {
        $user = User::create([
            'name' => $rider->name,
            'first_name' => 'RN',
            'last_name' => 'Rider',
            'sex' => 'male',
            'email' => $rider->email,
            'password' => bcrypt('password'),
            'phone' => $rider->phone,
            'birthday' => '1995-01-01',
            'age' => 30,
            'role' => 'rider',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $rider->update(['user_id' => $user->id]);

        return $user;
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

    private function assign(Delivery $delivery, Rider $rider): void
    {
        $this->actingAs($this->admin())
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $rider->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_assignment_notifies_the_assigned_rider(): void
    {
        $center = $this->center();
        $rider = $this->eligibleRider($center, 'A');
        $delivery = $this->waitingDelivery($center);

        $this->assign($delivery, $rider);

        $this->assertDatabaseHas('rider_notifications', [
            'rider_id' => $rider->id,
            'type' => 'delivery_assigned',
            'title' => 'New Delivery Assignment',
        ]);

        $row = RiderNotification::where('rider_id', $rider->id)->firstOrFail();
        $this->assertSame($delivery->id, $row->data['delivery_id']);
        $this->assertSame($delivery->tracking_number, $row->data['tracking_number']);
        $this->assertStringContainsString($delivery->tracking_number, $row->body);
        $this->assertFalse((bool) $row->is_read);
    }

    public function test_reassignment_notifies_only_the_new_rider(): void
    {
        $center = $this->center();
        $riderA = $this->eligibleRider($center, 'A');
        $riderB = $this->eligibleRider($center, 'B');
        $delivery = $this->waitingDelivery($center);

        $this->assign($delivery, $riderA);
        $this->assign($delivery, $riderB);

        $this->assertSame(1, RiderNotification::where('rider_id', $riderA->id)->count());
        $this->assertSame(1, RiderNotification::where('rider_id', $riderB->id)->count());
        $this->assertSame(
            1,
            RiderNotification::where('rider_id', $riderB->id)->where('type', 'delivery_assigned')->count()
        );
    }

    public function test_retried_assignment_creates_no_duplicate(): void
    {
        $center = $this->center();
        $riderA = $this->eligibleRider($center, 'A');
        $riderB = $this->eligibleRider($center, 'B');
        $delivery = $this->waitingDelivery($center);

        $this->assign($delivery, $riderA);

        // Re-posting the current holder is rejected as busy: no new row.
        $this->actingAs($this->admin())
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $riderA->id])
            ->assertSessionHasErrors('rider_id');

        $this->assertSame(1, RiderNotification::where('rider_id', $riderA->id)->count());

        // Moving to B, then re-posting B: busy again, still exactly one.
        $this->assign($delivery, $riderB);
        $this->actingAs($this->admin())
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $riderB->id])
            ->assertSessionHasErrors('rider_id');

        $this->assertSame(1, RiderNotification::where('rider_id', $riderB->id)->count());
    }

    public function test_staff_marked_failure_notifies_the_assigned_rider(): void
    {
        $center = $this->center();
        $rider = $this->eligibleRider($center, 'F');
        $delivery = $this->waitingDelivery($center);
        $this->assign($delivery, $rider);

        $this->actingAs($this->admin())
            ->patch(route('deliveries.update-status', $delivery), [
                'status' => 'delivery_failed',
                'reason' => 'Recipient refused package',
            ])
            ->assertRedirect();

        $row = RiderNotification::where('rider_id', $rider->id)
            ->where('type', 'delivery_failed')
            ->firstOrFail();
        $this->assertSame('Delivery Failed', $row->title);
        $this->assertStringContainsString($delivery->tracking_number, $row->body);
        $this->assertStringContainsString('Recipient refused package', $row->body);
    }

    public function test_rider_initiated_failure_creates_no_notification(): void
    {
        $center = $this->center();
        $rider = $this->eligibleRider($center, 'S');
        $user = $this->riderAccount($rider);
        $delivery = $this->waitingDelivery($center);
        $this->assign($delivery, $rider);
        $delivery->update(['status' => 'out_for_delivery']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/rider/deliveries/{$delivery->id}/failed", ['reason' => 'Customer unavailable'])
            ->assertOk();

        $this->assertSame(
            0,
            RiderNotification::where('rider_id', $rider->id)->where('type', 'delivery_failed')->count()
        );
    }

    public function test_sorting_notifies_the_assigned_rider_only(): void
    {
        $center = $this->center();
        $area = ServiceArea::create([
            'logistics_center_id' => $center->id,
            'name' => 'RN Area ' . uniqid(),
            'is_active' => true,
        ]);
        $rider = $this->eligibleRider($center, 'P');
        $other = $this->eligibleRider($center, 'Q');
        $delivery = $this->waitingDelivery($center);
        $this->assign($delivery, $rider);
        $delivery->update(['parcel_status' => 'received']);

        $this->actingAs($this->admin())
            ->post(route('deliveries.sort', $delivery), [
                'destination_center_id' => $center->id,
                'service_area_id' => $area->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $row = RiderNotification::where('rider_id', $rider->id)
            ->where('type', 'parcel_ready')
            ->firstOrFail();
        $this->assertSame('Parcel Ready for Pickup', $row->title);
        $this->assertStringContainsString($delivery->tracking_number, $row->body);
        $this->assertSame(0, RiderNotification::where('rider_id', $other->id)->count());
    }

    public function test_sorting_without_rider_notifies_nobody(): void
    {
        $center = $this->center();
        $area = ServiceArea::create([
            'logistics_center_id' => $center->id,
            'name' => 'RN Area ' . uniqid(),
            'is_active' => true,
        ]);
        $delivery = $this->waitingDelivery($center);
        $delivery->update(['parcel_status' => 'received']);

        $this->actingAs($this->admin())
            ->post(route('deliveries.sort', $delivery), [
                'destination_center_id' => $center->id,
                'service_area_id' => $area->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0, RiderNotification::count());
    }

    public function test_rider_isolation_list_and_mark_read(): void
    {
        $center = $this->center();
        $riderA = $this->eligibleRider($center, 'A');
        $riderB = $this->eligibleRider($center, 'B');
        $userA = $this->riderAccount($riderA);
        $userB = $this->riderAccount($riderB);

        RiderNotification::create([
            'rider_id' => $riderB->id, 'type' => 'delivery_assigned',
            'title' => 'For B', 'body' => 'Only B.',
            'data' => ['delivery_id' => 1],
        ]);

        // A sees nothing of B's.
        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/rider/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonCount(0, 'notifications');

        // A cannot mark B's row read.
        $rowId = RiderNotification::where('rider_id', $riderB->id)->firstOrFail()->id;
        $this->actingAs($userA, 'sanctum')
            ->patchJson("/api/rider/notifications/{$rowId}/read")
            ->assertNotFound();

        // B reads their own; count drops, row persists.
        $this->actingAs($userB, 'sanctum')
            ->getJson('/api/rider/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonFragment(['title' => 'For B', 'data' => ['delivery_id' => 1]]);

        $this->actingAs($userB, 'sanctum')
            ->patchJson("/api/rider/notifications/{$rowId}/read")
            ->assertOk();

        $this->actingAs($userB, 'sanctum')
            ->getJson('/api/rider/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonFragment(['title' => 'For B']);
    }
}
