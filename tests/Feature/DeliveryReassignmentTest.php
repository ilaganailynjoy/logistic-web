<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryStatusLog;
use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\ServiceArea;
use App\Models\User;
use App\Models\VehicleType;
use Tests\TestCase;

/**
 * Rider reassignment on the delivery detail page.
 *
 * The backend reuses the existing assign-rider endpoint (no new routes):
 * moving an assigned/failed delivery to another eligible rider appends a
 * new assignment log entry — history is preserved and no duplicate
 * delivery is created. Only rider_id validation applies (no reason field).
 */
class DeliveryReassignmentTest extends TestCase
{
    private function admin(): User
    {
        return User::create([
            'name' => 'Reassign Admin ' . uniqid(),
            'first_name' => 'Reassign',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'reassign-admin-' . uniqid() . '@logistics.com',
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
            'name' => 'Reassign Center ' . uniqid(), 'address' => 'R St',
            'city' => 'R City', 'province' => 'R', 'is_active' => true,
        ]);
    }

    private function area(LogisticsCenter $center): ServiceArea
    {
        return ServiceArea::create([
            'logistics_center_id' => $center->id,
            'name' => 'Reassign Area ' . uniqid(),
            'is_active' => true,
        ]);
    }

    private function eligibleRider(LogisticsCenter $center, ?ServiceArea $area = null): Rider
    {
        VehicleType::updateOrCreate(
            ['name' => 'motorcycle'],
            ['label' => 'Motorcycle', 'capacity_kg' => 30, 'is_active' => true, 'sort_order' => 1],
        );

        return Rider::create([
            'name' => 'Relay Rider ' . uniqid(),
            'email' => 'relay-rider-' . uniqid() . '@test.com',
            'phone' => '09000000004',
            'vehicle_type' => 'motorcycle',
            'license_plate' => 'RSG ' . random_int(1, 9999),
            'status' => 'available',
            'is_online' => true,
            'vehicle_verification' => 'verified',
            'approved_at' => now()->subDay(),
            'center_id' => $center->id,
            'service_area_id' => $area?->id,
        ]);
    }

    private function delivery(array $overrides = []): Delivery
    {
        return Delivery::create(array_merge([
            'sender_name' => 'Shop',
            'sender_phone' => '09170000001',
            'sender_address' => 'Shop St',
            'recipient_name' => 'Cust',
            'recipient_phone' => '09170000002',
            'recipient_address' => 'Cust Ave',
            'status' => 'assigned',
        ], $overrides));
    }

    public function test_reassign_form_shows_for_assigned_delivery(): void
    {
        $center = $this->center();
        $area = $this->area($center);
        $riderA = $this->eligibleRider($center, $area);
        $riderB = $this->eligibleRider($center, $area);
        $delivery = $this->delivery([
            'center_id' => $center->id,
            'destination_center_id' => $center->id,
            'service_area_id' => $area->id,
            'rider_id' => $riderA->id,
            'status' => 'assigned',
        ]);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Reassign Rider', $html);
        $this->assertStringContainsString('Currently assigned:', $html);
        $this->assertStringContainsString($riderA->name, $html);
        $this->assertStringContainsString('id="reassign_rider_id"', $html);
        $this->assertStringContainsString(route('deliveries.assign-rider', $delivery), $html);
        // The free target rider is offered as an eligible option.
        $this->assertStringContainsString('value="' . $riderB->id . '"', $html);
        $this->assertStringContainsString($riderB->name, $html);
    }

    public function test_reassign_form_shows_retry_for_failed_delivery(): void
    {
        $center = $this->center();
        $riderA = $this->eligibleRider($center);
        $delivery = $this->delivery([
            'center_id' => $center->id,
            'destination_center_id' => $center->id,
            'rider_id' => $riderA->id,
            'status' => 'delivery_failed',
        ]);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Retry', $html);
        $this->assertStringContainsString('id="reassign_rider_id"', $html);
    }

    public function test_reassign_form_hidden_for_other_statuses(): void
    {
        $center = $this->center();
        $riderA = $this->eligibleRider($center);

        foreach (['waiting_for_rider', 'picked_up', 'out_for_delivery', 'delivered', 'cancelled'] as $status) {
            $delivery = $this->delivery([
                'center_id' => $center->id,
                'destination_center_id' => $center->id,
                'rider_id' => $status === 'waiting_for_rider' ? null : $riderA->id,
                'status' => $status,
            ]);

            $html = (string) $this->actingAs($this->admin())
                ->get(route('deliveries.show', $delivery))
                ->assertOk()
                ->getContent();

            $this->assertStringNotContainsString('Reassign Rider', $html);
            $this->assertStringNotContainsString('id="reassign_rider_id"', $html);
        }

        // The first-assignment form still owns the waiting state.
        $waiting = $this->delivery(['status' => 'waiting_for_rider']);
        $this->actingAs($this->admin())
            ->get(route('deliveries.show', $waiting))
            ->assertOk()
            ->assertSee('Assign Rider', false);
    }

    public function test_reassign_moves_delivery_and_preserves_history(): void
    {
        $center = $this->center();
        $area = $this->area($center);
        $riderA = $this->eligibleRider($center, $area);
        $riderB = $this->eligibleRider($center, $area);
        $delivery = $this->delivery([
            'center_id' => $center->id,
            'destination_center_id' => $center->id,
            'service_area_id' => $area->id,
        ]);

        // First assignment via the same endpoint.
        $this->actingAs($this->admin())
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $riderA->id])
            ->assertRedirect();
        $this->assertSame($riderA->id, $delivery->fresh()->rider_id);

        // Reassignment to another rider.
        $this->actingAs($this->admin())
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $riderB->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresh = $delivery->fresh();
        $this->assertSame($riderB->id, $fresh->rider_id);
        $this->assertSame('assigned', $fresh->status);

        // No duplicate delivery; history preserved as two assignment logs.
        $this->assertSame(1, Delivery::where('tracking_number', $delivery->tracking_number)->count());
        $this->assertSame(
            2,
            DeliveryStatusLog::where('delivery_id', $delivery->id)->where('status', 'assigned')->count()
        );
    }

    public function test_reassign_blocked_for_delivered_delivery(): void
    {
        $center = $this->center();
        $riderA = $this->eligibleRider($center);
        $riderB = $this->eligibleRider($center);
        $delivery = $this->delivery([
            'center_id' => $center->id,
            'destination_center_id' => $center->id,
            'rider_id' => $riderA->id,
            'status' => 'delivered',
        ]);

        $this->actingAs($this->admin())
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $riderB->id])
            ->assertSessionHasErrors('rider_id');

        $this->assertSame($riderA->id, $delivery->fresh()->rider_id);
        $this->assertSame('delivered', $delivery->fresh()->status);
    }

    public function test_reassign_to_same_rider_is_rejected_as_busy(): void
    {
        $center = $this->center();
        $riderA = $this->eligibleRider($center);
        $delivery = $this->delivery([
            'center_id' => $center->id,
            'destination_center_id' => $center->id,
            'rider_id' => $riderA->id,
            'status' => 'assigned',
        ]);

        // The current holder counts as busy (this active delivery), so
        // re-posting the same rider is rejected by the eligibility rules.
        $this->actingAs($this->admin())
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $riderA->id])
            ->assertSessionHasErrors('rider_id');

        $this->assertSame($riderA->id, $delivery->fresh()->rider_id);
    }
}
