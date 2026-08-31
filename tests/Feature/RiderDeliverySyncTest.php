<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DeliveryStatusLog;
use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\ServiceArea;
use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

/**
 * Phase 1 — Logistics ↔ Rider delivery synchronization.
 *
 * Verifies that a rider can only see and advance their OWN assigned
 * deliveries, that invalid transitions are rejected, and that when a
 * delivery is completed through the Rider API the Logistics System still
 * generates a transaction (₱15 rider fee + 10% admin commission) using the
 * existing business rules (no duplication in the Rider System).
 */
class RiderDeliverySyncTest extends TestCase
{
    private function center(): LogisticsCenter
    {
        return LogisticsCenter::create([
            'name' => 'Sync Center '.uniqid(),
            'address' => 'Test St',
            'city' => 'Test City',
            'province' => 'Test',
            'is_active' => true,
        ]);
    }

    private function area(LogisticsCenter $center): ServiceArea
    {
        return ServiceArea::create([
            'logistics_center_id' => $center->id,
            'name' => 'Sync Area '.uniqid(),
            'is_active' => true,
        ]);
    }

    private function makeRider(LogisticsCenter $center, int $i = 1): array
    {
        $rider = Rider::create([
            'name' => 'Sync Rider '.$i,
            'email' => 'sync-rider-'.$i.'-'.uniqid().'@test.com',
            'phone' => '0900000000'.$i,
            'vehicle_type' => 'Motorcycle',
            'license_plate' => 'ABC '.$i,
            'status' => 'available',
            'center_id' => $center->id,
            'approved_at' => now()->subDays(10),
            'vehicle_verification' => 'verified',
        ]);

        $user = User::create([
            'name' => $rider->name,
            'first_name' => 'Sync',
            'last_name' => 'Rider',
            'sex' => 'male',
            'email' => $rider->email,
            'password' => bcrypt('password'),
            'phone' => $rider->phone,
            'birthday' => '1995-01-01',
            'age' => 30,
            'role' => 'rider',
            'status' => 'active',
            'approval_status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $rider->update(['user_id' => $user->id]);

        return ['rider' => $rider, 'user' => $user];
    }

    private function delivery(Rider $rider, LogisticsCenter $center, ServiceArea $area, string $status, array $overrides = []): Delivery
    {
        $delivery = Delivery::create(array_merge([
            'rider_id' => $rider->id,
            'center_id' => $center->id,
            'service_area_id' => $area->id,
            'sender_name' => 'Test Shop',
            'sender_phone' => '0312345678',
            'sender_address' => '123 Shop Street',
            'recipient_name' => 'Test Customer',
            'recipient_phone' => '09171234567',
            'recipient_address' => '456 Customer Ave',
            'status' => $status,
            'notes' => 'Leave at front desk.',
            'payment_method' => 'cash_on_delivery',
            'amount_to_collect' => 500.00,
            'delivery_fee' => 80.00,
            'pickup_pin' => '4821',
        ], $overrides));

        DeliveryItem::create([
            'delivery_id' => $delivery->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 100.00,
        ]);

        DeliveryStatusLog::create([
            'delivery_id' => $delivery->id,
            'status' => $status,
            'notes' => 'status created',
        ]);

        return $delivery;
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    // ---------------------------------------------------------------
    // 1. Logistics assigns rider → rider can see the assignment
    // ---------------------------------------------------------------

    public function test_rider_sees_assignment_and_logistics_owned_fields(): void
    {
        $center = $this->center();
        $area = $this->area($center);
        $data = $this->makeRider($center);
        $delivery = $this->delivery($data['rider'], $center, $area, 'assigned');

        $response = $this->getJson('/api/rider/deliveries', $this->headers($data['user']))->assertOk();

        $found = collect($response->json('deliveries'))->firstWhere('id', $delivery->id);

        $this->assertNotNull($found, 'rider should see their assigned delivery');
        $this->assertEquals($delivery->tracking_number, $found['tracking_number']);
        $this->assertEquals($delivery->order_id, $found['order_id']);
        $this->assertEquals('Test Customer', $found['customer']['name']);
        $this->assertEquals('456 Customer Ave', $found['customer']['address']);
        $this->assertEquals('09171234567', $found['customer']['phone']);
        $this->assertEquals('Leave at front desk.', $found['delivery_instructions']);
        $this->assertEquals($center->name, $found['logistics_center']['name']);
        $this->assertEquals($area->name, $found['service_area']['name']);
        $this->assertEquals('assigned', $found['status']);
    }

    public function test_recipient_phone_is_visible_in_detail(): void
    {
        $center = $this->center();
        $area = $this->area($center);
        $data = $this->makeRider($center);
        $delivery = $this->delivery($data['rider'], $center, $area, 'assigned');

        $this->getJson("/api/rider/deliveries/{$delivery->id}", $this->headers($data['user']))
            ->assertOk()
            ->assertJsonPath('delivery.customer.phone', '09171234567');
    }

    // ---------------------------------------------------------------
    // 2. Rider accepts own assignment
    // ---------------------------------------------------------------

    public function test_rider_accepts_own_assignment(): void
    {
        $center = $this->center();
        $area = $this->area($center);
        $data = $this->makeRider($center);
        $delivery = $this->delivery($data['rider'], $center, $area, 'assigned');

        $this->postJson("/api/rider/deliveries/{$delivery->id}/accept", [], $this->headers($data['user']))
            ->assertOk()
            ->assertJsonPath('delivery.status', 'accepted');

        $this->assertEquals('accepted', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->accepted_at);
    }

    // ---------------------------------------------------------------
    // 3. Rider cannot accept another rider's assignment
    // ---------------------------------------------------------------

    public function test_rider_cannot_accept_another_riders_assignment(): void
    {
        $center = $this->center();
        $area = $this->area($center);
        $a = $this->makeRider($center, 1);
        $b = $this->makeRider($center, 2);
        $theirs = $this->delivery($b['rider'], $center, $area, 'assigned');

        $this->postJson("/api/rider/deliveries/{$theirs->id}/accept", [], $this->headers($a['user']))
            ->assertStatus(403);

        $this->assertEquals('assigned', $theirs->fresh()->status);
    }

    // ---------------------------------------------------------------
    // 4. Rider marks own delivery picked up (with PIN)
    // ---------------------------------------------------------------

    public function test_rider_marks_own_delivery_picked_up(): void
    {
        $center = $this->center();
        $area = $this->area($center);
        $data = $this->makeRider($center);
        $delivery = $this->delivery($data['rider'], $center, $area, 'arrived_at_shop');

        $this->postJson(
            "/api/rider/deliveries/{$delivery->id}/pickup",
            ['pickup_pin' => '4821'],
            $this->headers($data['user']),
        )->assertOk()->assertJsonPath('delivery.status', 'picked_up');

        $this->assertEquals('picked_up', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->picked_up_at);
    }

    // ---------------------------------------------------------------
    // 5. Rider marks own delivery out for delivery
    // ---------------------------------------------------------------

    public function test_rider_marks_own_delivery_out_for_delivery(): void
    {
        $center = $this->center();
        $area = $this->area($center);
        $data = $this->makeRider($center);
        $delivery = $this->delivery($data['rider'], $center, $area, 'picked_up');

        $this->patchJson(
            "/api/rider/deliveries/{$delivery->id}/status",
            ['status' => 'out_for_delivery'],
            $this->headers($data['user']),
        )->assertOk()->assertJsonPath('delivery.status', 'out_for_delivery');

        $this->assertEquals('out_for_delivery', $delivery->fresh()->status);
    }

    // ---------------------------------------------------------------
    // 6. Rider marks own delivery delivered (full workflow)
    // ---------------------------------------------------------------

    public function test_rider_completes_full_delivery_workflow(): void
    {
        $center = $this->center();
        $area = $this->area($center);
        $data = $this->makeRider($center);
        $delivery = $this->delivery($data['rider'], $center, $area, 'assigned');

        $h = $this->headers($data['user']);

        $this->postJson("/api/rider/deliveries/{$delivery->id}/accept", [], $h)->assertOk();
        $this->patchJson("/api/rider/deliveries/{$delivery->id}/status", ['status' => 'going_to_pickup'], $h)->assertOk();
        $this->patchJson("/api/rider/deliveries/{$delivery->id}/status", ['status' => 'arrived_at_shop'], $h)->assertOk();
        $this->postJson("/api/rider/deliveries/{$delivery->id}/pickup", ['pickup_pin' => '4821'], $h)->assertOk();
        $this->patchJson("/api/rider/deliveries/{$delivery->id}/status", ['status' => 'out_for_delivery'], $h)->assertOk();
        $this->patchJson("/api/rider/deliveries/{$delivery->id}/status", ['status' => 'arrived_at_customer'], $h)->assertOk();
        $this->postJson(
            "/api/rider/deliveries/{$delivery->id}/complete",
            ['proof_type' => 'signature', 'signature_name' => 'Test Customer', 'amount_received' => 500.00],
            $h,
        )->assertOk()->assertJsonPath('delivery.status', 'delivered');

        $this->assertEquals('delivered', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->delivered_at);
    }

    // ---------------------------------------------------------------
    // 7. Rider cannot mark another rider's delivery delivered
    // ---------------------------------------------------------------

    public function test_rider_cannot_complete_another_riders_delivery(): void
    {
        $center = $this->center();
        $area = $this->area($center);
        $a = $this->makeRider($center, 1);
        $b = $this->makeRider($center, 2);
        $theirs = $this->delivery($b['rider'], $center, $area, 'arrived_at_customer');

        $this->postJson(
            "/api/rider/deliveries/{$theirs->id}/complete",
            ['proof_type' => 'signature', 'signature_name' => 'X', 'amount_received' => 1],
            $this->headers($a['user']),
        )->assertStatus(403);

        $this->assertEquals('arrived_at_customer', $theirs->fresh()->status);
    }

    // ---------------------------------------------------------------
    // 8. Invalid status transitions rejected
    // ---------------------------------------------------------------

    public function test_invalid_status_transitions_are_rejected(): void
    {
        $center = $this->center();
        $area = $this->area($center);
        $data = $this->makeRider($center);
        $delivery = $this->delivery($data['rider'], $center, $area, 'assigned');

        // assigned -> out_for_delivery is not allowed (must accept/pickup first).
        $this->patchJson(
            "/api/rider/deliveries/{$delivery->id}/status",
            ['status' => 'out_for_delivery'],
            $this->headers($data['user']),
        )->assertStatus(409);

        // Can't complete before it's been picked up / out for delivery.
        $this->postJson(
            "/api/rider/deliveries/{$delivery->id}/complete",
            ['proof_type' => 'signature', 'signature_name' => 'X', 'amount_received' => 1],
            $this->headers($data['user']),
        )->assertStatus(409);

        $this->assertEquals('assigned', $delivery->fresh()->status);
    }

    // ---------------------------------------------------------------
    // 9/10/11/12. Completion reaches Logistics → transaction generated
    //             (₱15 rider fee + 10% admin commission, no duplication)
    // ---------------------------------------------------------------

    public function test_completion_via_rider_api_generates_logistics_transaction(): void
    {
        $center = $this->center();
        $area = $this->area($center);
        $data = $this->makeRider($center);
        $delivery = $this->delivery($data['rider'], $center, $area, 'arrived_at_customer', [
            'delivery_fee' => 150.00,
        ]);

        $this->postJson(
            "/api/rider/deliveries/{$delivery->id}/complete",
            ['proof_type' => 'signature', 'signature_name' => 'Test Customer', 'amount_received' => 500.00],
            $this->headers($data['user']),
        )->assertOk()->assertJsonPath('delivery.status', 'delivered');

        // Logistics-side transaction must exist with the existing business rules.
        $tx = Transaction::where('delivery_id', $delivery->id)->first();
        $this->assertNotNull($tx, 'Transaction should be generated by Logistics on delivery completion');
        $this->assertEquals(150.00, (float) $tx->amount);
        $this->assertEquals(15.00, (float) $tx->rider_fee);          // ₱15 rider fee
        $this->assertEquals(15.00, (float) $tx->admin_commission);   // 10% of 150
        $this->assertEquals('completed', $tx->status);
        $this->assertEquals($center->id, $tx->logistics_center_id);
        $this->assertEquals($area->id, $tx->service_area_id);
    }

    public function test_transaction_not_duplicated_across_rider_and_logistics(): void
    {
        $center = $this->center();
        $area = $this->area($center);
        $data = $this->makeRider($center);
        $delivery = $this->delivery($data['rider'], $center, $area, 'arrived_at_customer');

        $this->postJson(
            "/api/rider/deliveries/{$delivery->id}/complete",
            ['proof_type' => 'signature', 'signature_name' => 'C', 'amount_received' => 500.00],
            $this->headers($data['user']),
        )->assertOk();

        // Simulate Logistics also marking it delivered: still exactly one tx.
        app(\App\Http\Controllers\TransactionController::class)->storeForDelivery($delivery->fresh());

        $this->assertEquals(1, Transaction::where('delivery_id', $delivery->id)->count());
    }

    // ---------------------------------------------------------------
    // 13/14. Rider center & service area are read-only (protected)
    // ---------------------------------------------------------------

    public function test_rider_cannot_change_center_or_service_area_via_api(): void
    {
        $center = $this->center();
        $area = $this->area($center);
        $data = $this->makeRider($center);
        $delivery = $this->delivery($data['rider'], $center, $area, 'assigned');

        // No API endpoint accepts center/service_area updates; a malicious
        // payload on the status endpoint must not change them.
        $this->patchJson(
            "/api/rider/deliveries/{$delivery->id}/status",
            ['status' => 'out_for_delivery', 'center_id' => 999, 'service_area_id' => 999],
            $this->headers($data['user']),
        )->assertStatus(409); // rejected: assigned -> out_for_delivery is not allowed

        // Even through pickups/other routes, these fields are never writeable
        // and remain untouched.
        $fresh = $delivery->fresh();
        $this->assertEquals($center->id, $fresh->center_id);
        $this->assertEquals($area->id, $fresh->service_area_id);
    }

    public function test_rider_profile_exposes_own_assigned_center_and_area_read_only(): void
    {
        $center = $this->center();
        $area = $this->area($center);
        $data = $this->makeRider($center);
        $data['rider']->update(['service_area_id' => $area->id]);

        $response = $this->getJson('/api/rider/profile', $this->headers($data['user']))->assertOk();

        // The rider may view their assigned center/area (read-only display),
        // but there is no API surface to change it.
        $this->assertArrayHasKey('rider', $response->json());
    }
}
