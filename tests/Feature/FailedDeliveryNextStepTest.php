<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\ServiceArea;
use App\Models\User;
use Tests\TestCase;

/**
 * Failed-delivery next steps (rider API).
 *
 * After a failure the rider may request a re-attempt (Logistics reassigns
 * through the existing failed → assigned flow) or return the parcel to the
 * sorting center. Both preserve the record, tracking, order, and history;
 * both are single-use per failure cycle, owner-guarded, and notify staff.
 * No new delivery, no new tracking number, no invented statuses.
 */
class FailedDeliveryNextStepTest extends TestCase
{
    private function center(string $suffix = ''): LogisticsCenter
    {
        return LogisticsCenter::create([
            'name' => 'Next Center ' . $suffix . ' ' . uniqid(),
            'address' => 'Test St', 'city' => 'Test City',
            'province' => 'Test', 'is_active' => true,
        ]);
    }

    private function area(LogisticsCenter $center): ServiceArea
    {
        return ServiceArea::create([
            'logistics_center_id' => $center->id,
            'name' => 'Next Area ' . uniqid(),
            'is_active' => true,
        ]);
    }

    private function makeRider(LogisticsCenter $center, ?ServiceArea $area = null, int $i = 1): array
    {
        $rider = Rider::create([
            'name' => 'Next Rider ' . $i . ' ' . uniqid(),
            'email' => 'next-rider-' . $i . '-' . uniqid() . '@test.com',
            'phone' => '090000001' . $i,
            'vehicle_type' => 'Motorcycle',
            'license_plate' => 'NXT ' . $i,
            'status' => 'available',
            'center_id' => $center->id,
            'service_area_id' => $area?->id,
            'approved_at' => now()->subDays(5),
            'vehicle_verification' => 'verified',
            'is_online' => true,
        ]);
        $user = User::create([
            'name' => $rider->name, 'first_name' => 'Next', 'last_name' => 'Rider',
            'sex' => 'male', 'email' => $rider->email,
            'password' => bcrypt('password'), 'phone' => $rider->phone,
            'birthday' => '1995-01-01', 'age' => 30,
            'role' => 'rider', 'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $rider->update(['user_id' => $user->id]);

        return ['rider' => $rider, 'user' => $user];
    }

    private function delivery(Rider $rider, string $status, array $overrides = []): Delivery
    {
        $d = Delivery::create(array_merge([
            'rider_id' => $rider->id,
            'tracking_number' => 'NXTV-' . strtoupper(uniqid()),
            'sender_name' => 'Test Shop',
            'sender_address' => '123 Shop Street',
            'recipient_name' => 'Test Customer',
            'recipient_address' => '456 Customer Ave',
            'status' => $status,
            'delivery_fee' => 80.00,
            'parcel_status' => 'pending_arrival',
        ], $overrides));
        DeliveryItem::create([
            'delivery_id' => $d->id, 'name' => 'Item', 'quantity' => 1, 'price' => 100,
        ]);

        return $d;
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    private function failedDelivery(Rider $rider, array $overrides = []): Delivery
    {
        return $this->delivery($rider, 'delivery_failed', array_merge([
            'failed_at' => now(),
            'failure_reason' => 'Recipient unavailable',
        ], $overrides));
    }

    public function test_request_reattempt_preserves_record_and_notifies(): void
    {
        $center = $this->center('A');
        $data = $this->makeRider($center);
        $delivery = $this->failedDelivery($data['rider']);

        $response = $this->postJson(
            "/api/rider/deliveries/{$delivery->id}/reattempt",
            [],
            $this->headers($data['user'])
        );

        $response->assertOk()
            ->assertJsonPath('delivery.status', 'delivery_failed')
            ->assertJsonPath('delivery.tracking_number', $delivery->tracking_number)
            ->assertJsonPath('delivery.reattempt_requested_at', fn ($v) => $v !== null);

        $fresh = $delivery->fresh();
        $this->assertNotNull($fresh->reattempt_requested_at);
        $this->assertNull($fresh->returned_at);
        $this->assertEquals('delivery_failed', $fresh->status);
        $this->assertDatabaseHas('delivery_status_logs', [
            'delivery_id' => $delivery->id, 'status' => 'reattempt_requested',
        ]);
        $this->assertDatabaseHas('notifications', [
            'type' => 'reattempt_requested',
        ]);
    }

    public function test_duplicate_reattempt_is_rejected(): void
    {
        $center = $this->center('B');
        $data = $this->makeRider($center);
        $delivery = $this->failedDelivery($data['rider']);

        $this->postJson("/api/rider/deliveries/{$delivery->id}/reattempt", [], $this->headers($data['user']))
            ->assertOk();
        $this->postJson("/api/rider/deliveries/{$delivery->id}/reattempt", [], $this->headers($data['user']))
            ->assertStatus(409);
    }

    public function test_return_to_center_preserves_record_and_clears_request(): void
    {
        $center = $this->center('C');
        $data = $this->makeRider($center);
        $delivery = $this->failedDelivery($data['rider']);

        $this->postJson("/api/rider/deliveries/{$delivery->id}/reattempt", [], $this->headers($data['user']))
            ->assertOk();

        $response = $this->postJson(
            "/api/rider/deliveries/{$delivery->id}/return-to-center",
            [],
            $this->headers($data['user'])
        );

        $response->assertOk()
            ->assertJsonPath('delivery.status', 'delivery_failed')
            ->assertJsonPath('delivery.returned_at', fn ($v) => $v !== null);

        $fresh = $delivery->fresh();
        $this->assertNotNull($fresh->returned_at);
        $this->assertEquals('Recipient unavailable', $fresh->return_reason);
        $this->assertNull($fresh->reattempt_requested_at);
        $this->assertDatabaseHas('delivery_status_logs', [
            'delivery_id' => $delivery->id, 'status' => 'returned_to_center',
        ]);
        $this->assertDatabaseHas('notifications', [
            'type' => 'parcel_returned_to_center',
        ]);

        $this->postJson("/api/rider/deliveries/{$delivery->id}/return-to-center", [], $this->headers($data['user']))
            ->assertStatus(409);
    }

    public function test_reattempt_after_return_is_rejected(): void
    {
        $center = $this->center('D');
        $data = $this->makeRider($center);
        $delivery = $this->failedDelivery($data['rider']);

        $this->postJson("/api/rider/deliveries/{$delivery->id}/return-to-center", [], $this->headers($data['user']))
            ->assertOk();
        $this->postJson("/api/rider/deliveries/{$delivery->id}/reattempt", [], $this->headers($data['user']))
            ->assertStatus(409);
    }

    public function test_next_steps_rejected_on_non_failed_status(): void
    {
        $center = $this->center('E');
        $data = $this->makeRider($center);
        $delivery = $this->delivery($data['rider'], 'picked_up');

        $this->postJson("/api/rider/deliveries/{$delivery->id}/reattempt", [], $this->headers($data['user']))
            ->assertStatus(409);
        $this->postJson("/api/rider/deliveries/{$delivery->id}/return-to-center", [], $this->headers($data['user']))
            ->assertStatus(409);
        $this->assertNull($delivery->fresh()->reattempt_requested_at);
        $this->assertNull($delivery->fresh()->returned_at);
    }

    public function test_other_rider_and_guest_cannot_act(): void
    {
        $center = $this->center('F');
        $a = $this->makeRider($center, null, 1);
        $b = $this->makeRider($center, null, 2);
        $delivery = $this->failedDelivery($a['rider']);

        $this->postJson("/api/rider/deliveries/{$delivery->id}/reattempt", [], $this->headers($b['user']))
            ->assertStatus(403);
        $this->postJson("/api/rider/deliveries/{$delivery->id}/return-to-center", [], $this->headers($b['user']))
            ->assertStatus(403);
        // Fresh app: the auth guard caches its user per application
        // instance, so a guest call needs a rebooted container to stay
        // genuinely unauthenticated.
        $this->refreshApplication();
        $this->postJson("/api/rider/deliveries/{$delivery->id}/reattempt", [])
            ->assertStatus(401);
    }

    public function test_fresh_failure_resets_next_step_cycle(): void
    {
        $center = $this->center('G');
        $data = $this->makeRider($center);
        // Stale flags from a previous cycle must not survive a new failure.
        $delivery = $this->delivery($data['rider'], 'out_for_delivery', [
            'reattempt_requested_at' => now()->subDay(),
            'returned_at' => now()->subDay(),
            'return_reason' => 'Recipient unavailable',
        ]);

        $this->postJson("/api/rider/deliveries/{$delivery->id}/failed", [
            'reason' => 'Recipient unavailable',
        ], $this->headers($data['user']))->assertOk();

        $fresh = $delivery->fresh();
        $this->assertEquals('delivery_failed', $fresh->status);
        $this->assertNull($fresh->reattempt_requested_at);
        $this->assertNull($fresh->returned_at);
    }

    public function test_reassignment_clears_next_step_flags(): void
    {
        $center = $this->center('H');
        $area = $this->area($center);
        $data = $this->makeRider($center, $area, 1);
        $other = $this->makeRider($center, $area, 2);
        $delivery = $this->failedDelivery($data['rider'], [
            'center_id' => $center->id,
            'destination_center_id' => $center->id,
            'service_area_id' => $area->id,
            'reattempt_requested_at' => now(),
        ]);

        $staff = User::create([
            'name' => 'Next Staff', 'first_name' => 'Next', 'last_name' => 'Staff',
            'sex' => 'male', 'email' => 'next-staff-' . uniqid() . '@logistics.com',
            'password' => bcrypt('password'), 'phone' => '09000000099',
            'birthday' => '1990-01-01', 'age' => 35,
            'role' => 'admin', 'status' => 'active', 'email_verified_at' => now(),
        ]);

        $this->actingAs($staff)->post(route('deliveries.assign-rider', $delivery), [
            'rider_id' => $other['rider']->id,
        ])->assertRedirect();

        $fresh = $delivery->fresh();
        $this->assertEquals('assigned', $fresh->status);
        $this->assertEquals($other['rider']->id, $fresh->rider_id);
        $this->assertNull($fresh->reattempt_requested_at);
        $this->assertNull($fresh->returned_at);
    }
}
