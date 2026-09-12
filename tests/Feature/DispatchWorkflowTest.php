<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryStatusLog;
use App\Models\LogisticsCenter;
use App\Models\PickupRequest;
use App\Models\Rider;
use App\Models\ServiceArea;
use App\Models\User;
use Tests\TestCase;

class DispatchWorkflowTest extends TestCase
{
    private function user(string $role = 'admin', ?LogisticsCenter $center = null): User
    {
        return User::create([
            'name' => 'Dsp '.$role.' '.uniqid(),
            'first_name' => 'Dsp',
            'last_name' => ucfirst($role),
            'sex' => 'male',
            'email' => 'dsp-'.$role.'-'.uniqid().'@logistics.com',
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
            'name' => 'Dsp Center '.uniqid(),
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
            'name' => 'Dsp Area '.uniqid(),
            'is_active' => true,
        ]);
    }

    private function rider(LogisticsCenter $center, ?ServiceArea $area = null, array $overrides = []): Rider
    {
        return Rider::create(array_merge([
            'name' => 'Dsp Rider '.uniqid(),
            'email' => 'dsp-rider-'.uniqid().'@test.com',
            'phone' => '09000000002',
            'vehicle_type' => 'Motorcycle',
            'license_plate' => 'DSP '.random_int(1, 999),
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
            'sender_name' => 'Dsp Shop',
            'sender_phone' => '09171234567',
            'sender_address' => '1 Shop St',
            'recipient_name' => 'Dsp Cust',
            'recipient_phone' => '09171234568',
            'recipient_address' => '2 Cust Ave',
            'status' => 'waiting_for_rider',
            'delivery_fee' => 100.00,
        ], $overrides));
    }

    private function sorted(LogisticsCenter $center, array $overrides = []): Delivery
    {
        return $this->delivery(array_merge([
            'center_id' => $center->id,
            'destination_center_id' => $center->id,
            'service_area_id' => ServiceArea::where('logistics_center_id', $center->id)->first()?->id,
            'parcel_status' => 'sorted',
            'received_at' => now()->subHour(),
            'scanned_at' => now()->subMinutes(50),
            'sorted_at' => now()->subMinutes(30),
        ], $overrides));
    }

    private function assertNotDispatched(Delivery $delivery): void
    {
        $this->assertSame('sorted', $delivery->fresh()->parcel_status);
        $this->assertDatabaseMissing('delivery_status_logs', [
            'delivery_id' => $delivery->id,
            'status' => 'dispatched',
        ]);
    }

    public function test_sorted_delivery_can_be_dispatched(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $area = $this->area($center);
        $delivery = $this->sorted($center, ['service_area_id' => $area->id]);

        $this->actingAs($admin)
            ->post(route('deliveries.dispatch', $delivery))
            ->assertSessionHas('success');

        $delivery->refresh();
        $this->assertSame('dispatched', $delivery->parcel_status);
        $this->assertNotNull($delivery->dispatched_at);
        $this->assertDatabaseHas('delivery_status_logs', [
            'delivery_id' => $delivery->id,
            'status' => 'dispatched',
        ]);
    }

    public function test_staff_from_own_center_can_dispatch(): void
    {
        $center = $this->center();
        $area = $this->area($center);
        $staff = $this->user('staff', $center);
        $delivery = $this->sorted($center, ['service_area_id' => $area->id]);

        $this->actingAs($staff)
            ->post(route('deliveries.dispatch', $delivery))
            ->assertSessionHas('success');

        $this->assertSame('dispatched', $delivery->fresh()->parcel_status);
    }

    public function test_staff_from_another_center_cannot_dispatch(): void
    {
        $c1 = $this->center();
        $c2 = $this->center();
        $area = $this->area($c2);
        $staff = $this->user('staff', $c1);
        $delivery = $this->sorted($c2, ['service_area_id' => $area->id]);

        $this->actingAs($staff)
            ->post(route('deliveries.dispatch', $delivery))
            ->assertStatus(403);

        $this->assertNotDispatched($delivery);
    }

    public function test_staff_without_center_cannot_dispatch(): void
    {
        $center = $this->center();
        $area = $this->area($center);
        $staff = $this->user('staff');
        $delivery = $this->sorted($center, ['service_area_id' => $area->id]);

        $this->actingAs($staff)
            ->post(route('deliveries.dispatch', $delivery))
            ->assertStatus(403);

        $this->assertNotDispatched($delivery);
    }

    public function test_admin_can_dispatch_for_any_center(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $area = $this->area($center);
        $delivery = $this->sorted($center, ['service_area_id' => $area->id]);

        $this->actingAs($admin)
            ->post(route('deliveries.dispatch', $delivery))
            ->assertSessionHas('success');

        $this->assertSame('dispatched', $delivery->fresh()->parcel_status);
    }

    public function test_unqualified_statuses_cannot_be_dispatched(): void
    {
        $admin = $this->user();
        $center = $this->center();

        foreach (['pending_arrival', 'received', 'scanned'] as $status) {
            $delivery = $this->delivery([
                'center_id' => $center->id,
                'parcel_status' => $status,
            ]);

            $this->actingAs($admin)
                ->post(route('deliveries.dispatch', $delivery))
                ->assertSessionHas('error');

            $this->assertSame($status, $delivery->fresh()->parcel_status);
            $this->assertNull($delivery->fresh()->dispatched_at);
            $this->assertDatabaseMissing('delivery_status_logs', [
                'delivery_id' => $delivery->id,
                'status' => 'dispatched',
            ]);
        }
    }

    public function test_already_dispatched_delivery_cannot_be_dispatched_twice(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $area = $this->area($center);
        $delivery = $this->sorted($center, ['service_area_id' => $area->id]);

        $this->actingAs($admin)
            ->post(route('deliveries.dispatch', $delivery))
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->post(route('deliveries.dispatch', $delivery))
            ->assertSessionHas('error');

        $this->assertSame('dispatched', $delivery->fresh()->parcel_status);
        $this->assertSame(
            1,
            DeliveryStatusLog::where('delivery_id', $delivery->id)->where('status', 'dispatched')->count()
        );
    }

    public function test_dispatch_does_not_assign_rider(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $area = $this->area($center);
        $delivery = $this->sorted($center, ['service_area_id' => $area->id]);

        $this->actingAs($admin)
            ->post(route('deliveries.dispatch', $delivery))
            ->assertSessionHas('success');

        $delivery->refresh();
        $this->assertNull($delivery->rider_id);
        $this->assertSame('waiting_for_rider', $delivery->status);
    }

    public function test_dispatched_delivery_can_still_be_assigned_to_eligible_rider(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $area = $this->area($center);
        $rider = $this->rider($center, $area, ['is_online' => true]);
        $delivery = $this->sorted($center, ['service_area_id' => $area->id]);

        $this->actingAs($admin)
            ->post(route('deliveries.dispatch', $delivery))
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $rider->id])
            ->assertSessionHas('success');

        $delivery->refresh();
        $this->assertSame($rider->id, $delivery->rider_id);
        $this->assertSame('assigned', $delivery->status);
        $this->assertSame('dispatched', $delivery->parcel_status);

        $this->actingAs($admin)
            ->patch(route('deliveries.update-status', $delivery), ['status' => 'out_for_delivery'])
            ->assertSessionHas('success');

        $this->assertSame('out_for_delivery', $delivery->fresh()->status);
    }

    public function test_center_and_service_area_assignment_rules_remain_enforced_after_dispatch(): void
    {
        $admin = $this->user();
        $centerA = $this->center();
        $centerB = $this->center();
        $areaB1 = $this->area($centerB);
        $areaB2 = $this->area($centerB);

        $outOfCenter = $this->rider($centerA, null, ['is_online' => true]);
        $wrongArea = $this->rider($centerB, $areaB1, ['is_online' => true]);
        $matchRider = $this->rider($centerB, $areaB2, ['is_online' => true]);

        $delivery = $this->sorted($centerB, ['service_area_id' => $areaB2->id]);

        $this->actingAs($admin)
            ->post(route('deliveries.dispatch', $delivery))
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $outOfCenter->id])
            ->assertSessionHasErrors('rider_id');
        $this->actingAs($admin)
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $wrongArea->id])
            ->assertSessionHasErrors('rider_id');

        $this->assertNull($delivery->fresh()->rider_id);

        $this->actingAs($admin)
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $matchRider->id])
            ->assertSessionHas('success');

        $delivery->refresh();
        $this->assertSame($matchRider->id, $delivery->rider_id);
        $this->assertSame('assigned', $delivery->status);
    }

    public function test_status_history_records_dispatch_transition(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $dest = $this->center();
        $area = $this->area($dest);
        $delivery = $this->delivery(['parcel_status' => 'pending_arrival']);

        $this->actingAs($admin)->post(route('deliveries.receive', $delivery), ['center_id' => $center->id])
            ->assertSessionHas('success');
        $this->actingAs($admin)->patch(route('deliveries.scan', $delivery))
            ->assertSessionHas('success');
        $this->actingAs($admin)->post(route('deliveries.sort', $delivery), [
            'destination_center_id' => $dest->id,
            'service_area_id' => $area->id,
        ])->assertSessionHas('success');
        $this->actingAs($admin)->post(route('deliveries.dispatch', $delivery))
            ->assertSessionHas('success');

        $delivery->refresh();
        $this->assertSame('dispatched', $delivery->parcel_status);

        $this->assertDatabaseHas('delivery_status_logs', [
            'delivery_id' => $delivery->id,
            'status' => 'dispatched',
            'changed_by' => $admin->id,
        ]);

        $this->assertSame(
            ['received', 'scanned', 'sorted', 'dispatched'],
            DeliveryStatusLog::where('delivery_id', $delivery->id)
                ->orderBy('id')
                ->pluck('status')
                ->all()
        );
    }

    public function test_dispatch_does_not_affect_pickup_request_workflow(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $area = $this->area($center);
        $delivery = $this->sorted($center, [
            'status' => 'arrived_at_shop',
            'service_area_id' => $area->id,
        ]);

        $pickup = PickupRequest::create([
            'delivery_id' => $delivery->id,
            'center_id' => $center->id,
            'requested_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('deliveries.dispatch', $delivery))
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->patch(route('deliveries.update-status', $delivery), ['status' => 'picked_up'])
            ->assertSessionHas('success');

        $delivery->refresh();
        $this->assertSame('picked_up', $delivery->status);
        $this->assertSame('dispatched', $delivery->parcel_status);

        $pickup->refresh();
        $this->assertSame('completed', $pickup->status);
        $this->assertNotNull($pickup->reviewed_at);
    }

    public function test_dashboard_reports_still_work_after_dispatch(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $area = $this->area($center);
        $delivery = $this->sorted($center, ['service_area_id' => $area->id]);

        $this->actingAs($admin)
            ->post(route('deliveries.dispatch', $delivery))
            ->assertSessionHas('success');

        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('reports.index'))->assertOk();
    }
}