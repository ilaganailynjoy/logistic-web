<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\ServiceArea;
use App\Models\User;
use Tests\TestCase;

class RiderManagementTest extends TestCase
{
    private function user(string $role = 'admin', ?LogisticsCenter $center = null): User
    {
        return User::create([
            'name' => 'Mgmt '.$role.' '.uniqid(),
            'first_name' => 'Mgmt',
            'last_name' => ucfirst($role),
            'sex' => 'male',
            'email' => 'mgmt-'.$role.'-'.uniqid().'@logistics.com',
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

    private function center(): LogisticsCenter
    {
        return LogisticsCenter::create([
            'name' => 'Mgmt Center '.uniqid(),
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
            'name' => 'Mgmt Area '.uniqid(),
            'is_active' => true,
        ]);
    }

    private function rider(LogisticsCenter $center, ?ServiceArea $area = null, array $overrides = []): Rider
    {
        return Rider::create(array_merge([
            'name' => 'Mgmt Rider '.uniqid(),
            'email' => 'mgmt-rider-'.uniqid().'@test.com',
            'phone' => '09000000002',
            'vehicle_type' => 'Motorcycle',
            'license_plate' => 'ABC '.random_int(1, 999),
            'status' => 'available',
            'center_id' => $center->id,
            'service_area_id' => $area?->id,
            'approved_at' => now()->subDays(10),
            'vehicle_verification' => 'verified',
        ], $overrides));
    }

    private function delivery(array $overrides = []): Delivery
    {
        return Delivery::create(array_merge([
            'sender_name' => 'Shop',
            'sender_phone' => '09171234567',
            'sender_address' => '1 Shop St',
            'recipient_name' => 'Cust',
            'recipient_phone' => '09171234568',
            'recipient_address' => '2 Cust Ave',
            'status' => 'waiting_for_rider',
            'delivery_fee' => 100.00,
        ], $overrides));
    }

    public function test_staff_can_deactivate_an_active_rider(): void
    {
        $center = $this->center();
        $staff = $this->user('staff', $center);
        $rider = $this->rider($center, null, ['is_online' => true]);

        $response = $this->actingAs($staff)
            ->from(route('riders.show', $rider))
            ->post(route('riders.deactivate', $rider));

        $response->assertRedirect()->assertSessionHas('success');

        $rider->refresh();
        $this->assertEquals('inactive', $rider->status);
    }

    public function test_staff_can_activate_an_inactive_rider(): void
    {
        $center = $this->center();
        $staff = $this->user('staff', $center);
        $rider = $this->rider($center, null, ['status' => 'inactive']);

        $response = $this->actingAs($staff)
            ->from(route('riders.show', $rider))
            ->post(route('riders.activate', $rider));

        $response->assertRedirect()->assertSessionHas('success');

        $rider->refresh();
        $this->assertEquals('available', $rider->status);
    }

    public function test_staff_from_another_center_cannot_change_rider(): void
    {
        $centerA = $this->center();
        $centerB = $this->center();
        $staffA = $this->user('staff', $centerA);
        $riderB = $this->rider($centerB, null, ['is_online' => true]);

        $response = $this->actingAs($staffA)
            ->from(route('riders.show', $riderB))
            ->post(route('riders.deactivate', $riderB));

        $response->assertForbidden();

        $riderB->refresh();
        $this->assertEquals('available', $riderB->status);
    }

    public function test_admin_can_manage_any_rider_across_centers(): void
    {
        $center = $this->center();
        $admin = $this->user('admin');
        $rider = $this->rider($center, null, ['is_online' => true]);

        $this->actingAs($admin)
            ->from(route('riders.show', $rider))
            ->post(route('riders.deactivate', $rider))
            ->assertRedirect()->assertSessionHas('success');

        $rider->refresh();
        $this->assertEquals('inactive', $rider->status);
    }

    public function test_inactive_rider_cannot_be_assigned_a_new_delivery(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $area = $this->area($center);
        $rider = $this->rider($center, $area, ['is_online' => true, 'status' => 'inactive']);
        $delivery = $this->delivery(['destination_center_id' => $center->id, 'service_area_id' => $area->id]);

        $response = $this->actingAs($admin)
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $rider->id]);

        $response->assertSessionHasErrors('rider_id');

        $delivery->refresh();
        $this->assertNull($delivery->rider_id);
        $this->assertEquals('waiting_for_rider', $delivery->status);
    }

    public function test_active_rider_remains_assignable_when_all_guards_pass(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $area = $this->area($center);
        $rider = $this->rider($center, $area, ['is_online' => true]);
        $delivery = $this->delivery(['destination_center_id' => $center->id, 'service_area_id' => $area->id]);

        $this->actingAs($admin)
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $rider->id]);

        $delivery->refresh();
        $this->assertEquals($rider->id, $delivery->rider_id);
        $this->assertEquals('assigned', $delivery->status);
    }

    public function test_existing_center_and_service_area_assignment_rules_still_apply(): void
    {
        $admin = $this->user();
        $centerA = $this->center();
        $centerB = $this->center();
        $areaB1 = $this->area($centerB);
        $areaB2 = $this->area($centerB);

        $outOfCenter = $this->rider($centerA, null, ['is_online' => true]);
        $wrongArea = $this->rider($centerB, $areaB1, ['is_online' => true]);
        $matchRider = $this->rider($centerB, $areaB2, ['is_online' => true]);

        $delivery = $this->delivery(['destination_center_id' => $centerB->id, 'service_area_id' => $areaB2->id]);

        $this->actingAs($admin)
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $outOfCenter->id])
            ->assertSessionHasErrors('rider_id');

        $this->actingAs($admin)
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $wrongArea->id])
            ->assertSessionHasErrors('rider_id');

        $this->actingAs($admin)
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $matchRider->id]);

        $delivery->refresh();
        $this->assertEquals($matchRider->id, $delivery->rider_id);
        $this->assertEquals('assigned', $delivery->status);
    }

    public function test_deactivation_preserves_assigned_delivery_and_reactivation_restores_status(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $area = $this->area($center);
        $rider = $this->rider($center, $area, ['is_online' => true, 'status' => 'delivering']);

        $delivery = $this->delivery([
            'destination_center_id' => $center->id,
            'service_area_id' => $area->id,
            'status' => 'assigned',
            'rider_id' => $rider->id,
        ]);

        $this->actingAs($admin)
            ->from(route('riders.show', $rider))
            ->post(route('riders.deactivate', $rider))
            ->assertRedirect()->assertSessionHas('success');

        $rider->refresh();
        $this->assertEquals('inactive', $rider->status);

        $delivery->refresh();
        $this->assertEquals($rider->id, $delivery->rider_id);
        $this->assertEquals('assigned', $delivery->status);

        $this->actingAs($admin)
            ->from(route('riders.show', $rider))
            ->post(route('riders.activate', $rider))
            ->assertRedirect()->assertSessionHas('success');

        $rider->refresh();
        $this->assertEquals('delivering', $rider->status);
        $this->assertEquals('assigned', $delivery->refresh()->status);
    }
}