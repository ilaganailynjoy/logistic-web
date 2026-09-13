<?php

namespace Tests\Feature;

use Laravel\Sanctum\Sanctum;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DeliveryStatusLog;
use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\ServiceArea;
use App\Models\User;
use Tests\TestCase;

class SortingCenterHandoffWorkflowTest extends TestCase
{
    private function center(string $suffix = ''): LogisticsCenter
    {
        return LogisticsCenter::create([
            'name' => 'Handoff Center '.$suffix.' '.uniqid(),
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
            'name' => 'Handoff Area '.uniqid(),
            'is_active' => true,
        ]);
    }

    private function makeRider(LogisticsCenter $center, ?ServiceArea $area = null, int $i = 1, array $overrides = []): array
    {
        $rider = Rider::create(array_merge([
            'name' => 'Handoff Rider '.$i.' '.uniqid(),
            'email' => 'handoff-rider-'.$i.'-'.uniqid().'@test.com',
            'phone' => '0900000000'.$i,
            'vehicle_type' => 'Motorcycle',
            'license_plate' => 'HND '.$i,
            'status' => 'available',
            'center_id' => $center->id,
            'service_area_id' => $area?->id,
            'approved_at' => now()->subDays(10),
            'vehicle_verification' => 'verified',
        ], $overrides));

        $user = User::create([
            'name' => $rider->name,
            'first_name' => 'Handoff',
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

    private function user(string $role = 'admin', ?LogisticsCenter $center = null): User
    {
        return User::create([
            'name' => 'Hnd '.$role.' '.uniqid(),
            'first_name' => 'Hnd',
            'last_name' => ucfirst($role),
            'sex' => 'male',
            'email' => 'hnd-'.$role.'-'.uniqid().'@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000000',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => $role,
            'status' => 'active',
            'center_id' => $center?->id,
            'email_verified_at' => now(),
        ]);
    }

    private function delivery(Rider $rider, string $status, array $overrides = []): Delivery
    {
        $d = Delivery::create(array_merge([
            'rider_id' => $rider->id,
            'sender_name' => 'Test Shop',
            'sender_phone' => '0312345678',
            'sender_address' => '123 Shop Street',
            'recipient_name' => 'Test Customer',
            'recipient_phone' => '09171234567',
            'recipient_address' => '456 Customer Ave',
            'status' => $status,
            'delivery_fee' => 80.00,
            'parcel_status' => 'pending_arrival',
        ], $overrides));

        DeliveryItem::create([
            'delivery_id' => $d->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 100,
        ]);

        return $d;
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    // ── Handoff: cannot before seller pickup ────────────────────────────────

    public function test_rider_cannot_handover_before_seller_pickup(): void
    {
        $center = $this->center('A');
        $data = $this->makeRider($center);
        $delivery = $this->delivery($data['rider'], 'assigned');

        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-handoff", [], $this->headers($data['user']))
            ->assertStatus(409);

        $this->assertNull($delivery->fresh()->sorting_center_handoff_at);
    }

    public function test_authorized_pickup_rider_can_handover(): void
    {
        $center = $this->center('B');
        $data = $this->makeRider($center);
        $delivery = $this->delivery($data['rider'], 'picked_up', ['center_id' => $center->id]);

        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-handoff", [], $this->headers($data['user']))
            ->assertOk()
            ->assertJsonPath('delivery.sorting_center_handoff_at', fn ($v) => $v !== null);

        $fresh = $delivery->fresh();
        $this->assertNotNull($fresh->sorting_center_handoff_at);
        $this->assertEquals($data['rider']->id, $fresh->sorting_center_handoff_rider_id);
        $this->assertEquals('picked_up', $fresh->status);
        $this->assertDatabaseHas('delivery_status_logs', [
            'delivery_id' => $delivery->id,
            'status' => 'sorting_center_handoff',
        ]);
    }

    public function test_unauthorized_rider_cannot_handover(): void
    {
        $center = $this->center('C');
        $a = $this->makeRider($center, null, 1);
        $b = $this->makeRider($center, null, 2);
        $delivery = $this->delivery($b['rider'], 'picked_up');

        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-handoff", [], $this->headers($a['user']))
            ->assertStatus(403);

        $this->assertNull($delivery->fresh()->sorting_center_handoff_at);
    }

    public function test_parcel_cannot_be_handed_over_twice(): void
    {
        $center = $this->center('D');
        $data = $this->makeRider($center);
        $delivery = $this->delivery($data['rider'], 'picked_up');

        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-handoff", [], $this->headers($data['user']))->assertOk();
        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-handoff", [], $this->headers($data['user']))->assertStatus(409);

        $this->assertEquals(1, DeliveryStatusLog::where('delivery_id', $delivery->id)->where('status', 'sorting_center_handoff')->count());
    }

    public function test_handoff_creates_timestamp_and_history_and_preserves_other_fields(): void
    {
        $center = $this->center('E');
        $area = $this->area($center);
        $data = $this->makeRider($center, $area);
        $delivery = $this->delivery($data['rider'], 'picked_up', [
            'center_id' => $center->id,
            'destination_center_id' => $center->id,
            'service_area_id' => $area->id,
            'recipient_address' => 'Original Address',
        ]);

        $origCenter = $delivery->center_id;
        $origDest = $delivery->destination_center_id;
        $origArea = $delivery->service_area_id;
        $origRider = $delivery->rider_id;

        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-handoff", [], $this->headers($data['user']))->assertOk();

        $fresh = $delivery->fresh();
        $this->assertNotNull($fresh->sorting_center_handoff_at);
        $this->assertEquals($origCenter, $fresh->center_id);
        $this->assertEquals($origDest, $fresh->destination_center_id);
        $this->assertEquals($origArea, $fresh->service_area_id);
        $this->assertEquals($origRider, $fresh->rider_id);
        $this->assertEquals('Original Address', $fresh->recipient_address);
        $this->assertDatabaseHas('delivery_status_logs', [
            'delivery_id' => $delivery->id,
            'status' => 'sorting_center_handoff',
        ]);
    }

    public function test_handoff_wrong_center_is_rejected(): void
    {
        $centerA = $this->center('FA');
        $centerB = $this->center('FB');
        $riderA = $this->makeRider($centerA);
        // Delivery belongs to center B but rider is from center A; handoff should be 403 if center_id already set.
        $delivery = $this->delivery($riderA['rider'], 'picked_up', ['center_id' => $centerB->id]);

        // Need a rider from center A but delivery's rider_id must match requesting user.
        // So create riderA owns delivery but delivery center is B -> mismatch.
        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-handoff", [], $this->headers($riderA['user']))
            ->assertStatus(403);
    }

    // ── Pickup: cannot before sorted ───────────────────────────────────────

    public function test_rider_cannot_pickup_before_sorted(): void
    {
        $center = $this->center('G');
        $area = $this->area($center);
        $pickupRider = $this->makeRider($center, $area, 1);
        $deliveryRider = $this->makeRider($center, $area, 2);

        // Pickup rider picks up and hands over.
        $delivery = $this->delivery($pickupRider['rider'], 'picked_up', [
            'center_id' => $center->id,
            'parcel_status' => 'pending_arrival',
        ]);

        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-handoff", [], $this->headers($pickupRider['user']))->assertOk();

        // Still pending_arrival on web side; reassign to delivery rider.
        $delivery->update(['rider_id' => $deliveryRider['rider']->id, 'status' => 'assigned', 'parcel_status' => 'received']);
        $delivery->refresh();
        Sanctum::actingAs($deliveryRider['user']);
        $this->getJson("/api/rider/deliveries/{$delivery->id}")->assertOk();
        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-pickup")->assertStatus(409);

        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-pickup", [], $this->headers($deliveryRider['user']))
            ->assertStatus(409);

        $this->assertNull($delivery->fresh()->sorting_center_pickup_at);
    }

    public function test_rider_cannot_pickup_before_handoff(): void
    {
        $center = $this->center('H');
        $area = $this->area($center);
        $deliveryRider = $this->makeRider($center, $area, 1);
        $delivery = Delivery::create([
            'rider_id' => $deliveryRider['rider']->id,
            'sender_name' => 'Shop',
            'sender_phone' => '0312345678',
            'sender_address' => 'S',
            'recipient_name' => 'Cust',
            'recipient_phone' => '09171234567',
            'recipient_address' => 'A',
            'status' => 'assigned',
            'center_id' => $center->id,
            'parcel_status' => 'sorted',
            'sorted_at' => now(),
        ]);

        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-pickup", [], $this->headers($deliveryRider['user']))
            ->assertStatus(409);
    }

    public function test_only_assigned_delivery_rider_can_pickup(): void
    {
        $center = $this->center('I');
        $area = $this->area($center);
        $pickupRider = $this->makeRider($center, $area, 1);
        $deliveryRider = $this->makeRider($center, $area, 2);
        $intruder = $this->makeRider($center, $area, 3);

        $delivery = $this->delivery($pickupRider['rider'], 'picked_up', ['center_id' => $center->id]);
        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-handoff", [], $this->headers($pickupRider['user']))->assertOk();

        // Simulate center processing without mixing web auth (avoid actingAs pollution).
        $delivery->update([
            'parcel_status' => 'sorted',
            'sorted_at' => now(),
            'received_at' => now()->subMinutes(10),
            'scanned_at' => now()->subMinutes(5),
            'destination_center_id' => $center->id,
            'service_area_id' => $area->id,
        ]);
        $delivery->update(['rider_id' => $deliveryRider['rider']->id, 'status' => 'assigned']);
        $delivery->refresh();

        // Intruder (not assigned) cannot pickup.
        Sanctum::actingAs($intruder['user']);
        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-pickup")->assertStatus(403);

        // Assigned rider can.
        Sanctum::actingAs($deliveryRider['user']);
        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-pickup")->assertOk();
        $this->assertNotNull($delivery->fresh()->sorting_center_pickup_at);
    }

    public function test_unassigned_rider_cannot_pickup(): void
    {
        $center = $this->center('J');
        $area = $this->area($center);
        $pickupRider = $this->makeRider($center, $area, 1);
        $other = $this->makeRider($center, $area, 2);

        $delivery = $this->delivery($pickupRider['rider'], 'picked_up', [
            'center_id' => $center->id,
            'parcel_status' => 'sorted',
            'sorted_at' => now(),
            'sorting_center_handoff_at' => now(),
            'sorting_center_handoff_rider_id' => $pickupRider['rider']->id,
        ]);
        // Not assigned to other rider; belongs to pickupRider still, but pickup requires assigned to requester.
        // Create a delivery assigned to pickupRider, other tries to pickup after reassign without update: ensure 403 via ownership.
        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-pickup", [], $this->headers($other['user']))->assertStatus(403);
    }

    public function test_parcel_cannot_be_dispatched_twice_via_pickup(): void
    {
        $center = $this->center('K');
        $area = $this->area($center);
        $pickupRider = $this->makeRider($center, $area, 1);
        $deliveryRider = $this->makeRider($center, $area, 2);

        $delivery = $this->delivery($pickupRider['rider'], 'picked_up', [
            'center_id' => $center->id,
            'parcel_status' => 'pending_arrival',
        ]);
        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-handoff", [], $this->headers($pickupRider['user']))->assertOk();

        $delivery->update([
            'parcel_status' => 'sorted',
            'sorted_at' => now(),
            'received_at' => now()->subMinutes(10),
            'scanned_at' => now()->subMinutes(5),
            'destination_center_id' => $center->id,
            'service_area_id' => $area->id,
        ]);
        $delivery->update(['rider_id' => $deliveryRider['rider']->id, 'status' => 'assigned']);
        $delivery->refresh();

        Sanctum::actingAs($deliveryRider['user']);
        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-pickup")->assertOk();
        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-pickup")->assertStatus(409);

        $this->assertEquals(1, DeliveryStatusLog::where('delivery_id', $delivery->id)->where('status', 'sorting_center_pickup')->count());
    }

    public function test_pickup_creates_timestamp_and_dispatch_status_and_allows_out_for_delivery(): void
    {
        $center = $this->center('L');
        $area = $this->area($center);
        $pickupRider = $this->makeRider($center, $area, 1);
        $deliveryRider = $this->makeRider($center, $area, 2);

        $delivery = $this->delivery($pickupRider['rider'], 'picked_up', ['center_id' => $center->id]);
        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-handoff", [], $this->headers($pickupRider['user']))->assertOk();

        $delivery->update([
            'parcel_status' => 'sorted',
            'sorted_at' => now(),
            'received_at' => now()->subMinutes(10),
            'scanned_at' => now()->subMinutes(5),
            'destination_center_id' => $center->id,
            'service_area_id' => $area->id,
        ]);
        $delivery->update(['rider_id' => $deliveryRider['rider']->id, 'status' => 'assigned']);
        $delivery->refresh();

        Sanctum::actingAs($deliveryRider['user']);
        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-pickup")
            ->assertOk()
            ->assertJsonPath('delivery.parcel_status', 'dispatched');

        $fresh = $delivery->fresh();
        $this->assertNotNull($fresh->sorting_center_pickup_at);
        $this->assertEquals($deliveryRider['rider']->id, $fresh->sorting_center_pickup_rider_id);
        $this->assertEquals('dispatched', $fresh->parcel_status);
        $this->assertNotNull($fresh->dispatched_at);
        $this->assertDatabaseHas('delivery_status_logs', [
            'delivery_id' => $delivery->id,
            'status' => 'sorting_center_pickup',
        ]);
        $this->assertDatabaseHas('delivery_status_logs', [
            'delivery_id' => $delivery->id,
            'status' => 'dispatched',
        ]);

        // Out for delivery must still work for the assigned rider.
        Sanctum::actingAs($deliveryRider['user']);
        $this->patchJson("/api/rider/deliveries/{$delivery->id}/status", ['status' => 'out_for_delivery'])
            ->assertOk()
            ->assertJsonPath('delivery.status', 'out_for_delivery');

        $this->assertEquals('out_for_delivery', $delivery->fresh()->status);
    }

    public function test_full_workflow_handoff_then_sort_then_pickup_then_delivery(): void
    {
        $center = $this->center('M');
        $area = $this->area($center);
        $pickupRider = $this->makeRider($center, $area, 1);
        $deliveryRider = $this->makeRider($center, $area, 2);
        $deliveryRider['rider']->update(['is_online' => true]);

        // Create, seller pickup flow for pickup rider (assigned -> picked_up)
        $delivery = Delivery::create([
            'sender_name' => 'Shop',
            'sender_phone' => '0312345678',
            'sender_address' => 'Shop St',
            'recipient_name' => 'Cust',
            'recipient_phone' => '09171234567',
            'recipient_address' => 'Cust Ave',
            'status' => 'assigned',
            'rider_id' => $pickupRider['rider']->id,
            'center_id' => $center->id,
            'parcel_status' => 'pending_arrival',
            'delivery_fee' => 80,
        ]);
        $h = $this->headers($pickupRider['user']);
        $this->postJson("/api/rider/deliveries/{$delivery->id}/accept", [], $h)->assertOk();
        $this->patchJson("/api/rider/deliveries/{$delivery->id}/status", ['status' => 'going_to_pickup'], $h)->assertOk();
        $this->patchJson("/api/rider/deliveries/{$delivery->id}/status", ['status' => 'arrived_at_shop'], $h)->assertOk();
        $this->postJson("/api/rider/deliveries/{$delivery->id}/pickup", [], $h)->assertOk();
        $this->assertEquals('picked_up', $delivery->fresh()->status);

        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-handoff", [], $h)->assertOk();
        $this->assertNotNull($delivery->fresh()->sorting_center_handoff_at);

        $admin = $this->user('admin');
        $this->actingAs($admin)->post(route('deliveries.receive', $delivery), ['center_id' => $center->id])->assertSessionHas('success');
        $this->actingAs($admin)->patch(route('deliveries.scan', $delivery))->assertSessionHas('success');
        $this->actingAs($admin)->post(route('deliveries.sort', $delivery), [
            'destination_center_id' => $center->id,
            'service_area_id' => $area->id,
        ])->assertSessionHas('success');

        $this->actingAs($admin)->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $deliveryRider['rider']->id])->assertSessionHas('success');
        $delivery->refresh();
        $this->assertEquals($deliveryRider['rider']->id, $delivery->rider_id);
        $this->assertEquals('assigned', $delivery->status);

        Sanctum::actingAs($deliveryRider['user']);
        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-pickup")->assertOk();
        $this->patchJson("/api/rider/deliveries/{$delivery->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->patchJson("/api/rider/deliveries/{$delivery->id}/status", ['status' => 'arrived_at_customer'])->assertOk();
        $this->postJson("/api/rider/deliveries/{$delivery->id}/complete", [
            'proof_type' => 'signature',
            'signature_name' => 'Cust',
            'amount_received' => 500,
        ])->assertOk()->assertJsonPath('delivery.status', 'delivered');

        $this->assertEquals('delivered', $delivery->fresh()->status);
    }

    public function test_web_delivery_failed_fix_and_center_scoping(): void
    {
        $centerA = $this->center('NA');
        $centerB = $this->center('NB');
        $staffA = $this->user('staff', $centerA);
        $delivery = Delivery::create([
            'sender_name' => 'S',
            'sender_phone' => '0312345678',
            'sender_address' => 'A',
            'recipient_name' => 'R',
            'recipient_phone' => '09171234567',
            'recipient_address' => 'B',
            'status' => 'out_for_delivery',
            'center_id' => $centerB->id,
            'parcel_status' => 'dispatched',
        ]);

        // Staff from other center cannot view.
        $this->actingAs($staffA)->get(route('deliveries.show', $delivery))->assertStatus(403);
        // Admin can.
        $admin = $this->user('admin');
        $this->actingAs($admin)->get(route('deliveries.show', $delivery))->assertOk();

        // Delivery failed via web now works (previous 'failed' bug).
        $this->actingAs($admin)->patch(route('deliveries.update-status', $delivery), [
            'status' => 'delivery_failed',
            'reason' => 'Recipient unavailable',
        ])->assertSessionHas('success');

        $delivery->refresh();
        $this->assertEquals('delivery_failed', $delivery->status);
        $this->assertEquals('Recipient unavailable', $delivery->failure_reason);
    }

    public function test_handoff_sets_center_when_missing(): void
    {
        $center = $this->center('O');
        $data = $this->makeRider($center);
        $delivery = $this->delivery($data['rider'], 'picked_up', ['center_id' => null]);

        $this->postJson("/api/rider/deliveries/{$delivery->id}/sorting-center-handoff", [], $this->headers($data['user']))->assertOk();
        $this->assertEquals($center->id, $delivery->fresh()->center_id);
    }
}
