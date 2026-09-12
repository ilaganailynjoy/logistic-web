<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\LogisticsCenter;
use App\Models\PickupRequest;
use App\Models\Rider;
use App\Models\ServiceArea;
use App\Models\User;
use Tests\TestCase;

class PickupRequestTest extends TestCase
{
    private function user(string $role = 'admin', ?LogisticsCenter $center = null): User
    {
        return User::create([
            'name' => 'Pickup '.$role.' '.uniqid(),
            'first_name' => 'Pickup',
            'last_name' => ucfirst($role),
            'sex' => 'male',
            'email' => 'pickup-'.$role.'-'.uniqid().'@logistics.com',
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
            'name' => 'Pickup Center '.uniqid(),
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
            'name' => 'Pickup Area '.uniqid(),
            'is_active' => true,
        ]);
    }

    private function delivery(array $overrides = []): Delivery
    {
        return Delivery::create(array_merge([
            'sender_name' => 'Pickup Shop',
            'sender_phone' => '09171234567',
            'sender_address' => '1 Shop St',
            'recipient_name' => 'Pickup Customer',
            'recipient_phone' => '09171234568',
            'recipient_address' => '2 Cust Ave',
            'status' => 'waiting_for_rider',
            'delivery_fee' => 100.00,
        ], $overrides));
    }

    private function pickupRequest(Delivery $delivery, ?LogisticsCenter $center = null, array $overrides = []): PickupRequest
    {
        return PickupRequest::create(array_merge([
            'delivery_id' => $delivery->id,
            'center_id' => $center?->id,
            'requested_at' => now(),
        ], $overrides));
    }

    private function riderWithUser(LogisticsCenter $center): array
    {
        $rider = Rider::create([
            'name' => 'Pickup Rider '.uniqid(),
            'email' => 'pickup-rider-'.uniqid().'@test.com',
            'phone' => '09000000001',
            'vehicle_type' => 'Motorcycle',
            'license_plate' => 'PKP '.random_int(1, 999),
            'status' => 'available',
            'center_id' => $center->id,
            'approved_at' => now()->subDays(10),
            'vehicle_verification' => 'verified',
        ]);

        $user = User::create([
            'name' => $rider->name,
            'first_name' => 'Pickup',
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

    public function test_creating_a_delivery_creates_a_pending_pickup_request(): void
    {
        $admin = $this->user();

        $response = $this->actingAs($admin)
            ->post(route('deliveries.store'), [
                'sender_name' => 'Test Shop',
                'sender_phone' => '09171234567',
                'sender_address' => '123 Shop Street',
                'recipient_name' => 'Test Customer',
                'recipient_phone' => '09171234568',
                'recipient_address' => '456 Customer Ave',
                'package_type' => 'Parcel',
                'weight' => '2.5',
                'delivery_fee' => '100.00',
            ]);

        $response->assertRedirect();

        $pickup = PickupRequest::first();
        $this->assertNotNull($pickup, 'creating a delivery should create a pickup request');
        $this->assertEquals('pending', $pickup->status);
        $this->assertNotNull($pickup->requested_at);
        $this->assertDatabaseHas('pickup_requests', ['delivery_id' => $pickup->delivery_id, 'status' => 'pending']);
        $this->assertNull($pickup->center_id);
    }

    public function test_creating_a_delivery_cannot_create_duplicate_pickup_requests(): void
    {
        $center = $this->center();
        $staff = $this->user('staff', $center);

        $response = $this->actingAs($staff)
            ->from(route('pickup-requests.index'))
            ->post(route('deliveries.store'), [
                'sender_name' => 'Test Shop',
                'sender_phone' => '09171234567',
                'sender_address' => '123 Shop Street',
                'recipient_name' => 'Test Customer',
                'recipient_phone' => '09171234568',
                'recipient_address' => '456 Customer Ave',
            ]);

        $response->assertRedirect();

        $this->assertEquals(1, PickupRequest::count(), 'a delivery must have exactly one pickup request');
    }

    public function test_staff_can_list_pickup_requests_at_their_center(): void
    {
        $center = $this->center();
        $staff = $this->user('staff', $center);

        $delivery = $this->delivery(['recipient_name' => 'Visible Customer', 'center_id' => $center->id]);
        $this->pickupRequest($delivery, $center);

        $this->actingAs($staff)
            ->get(route('pickup-requests.index'))
            ->assertOk()
            ->assertSee('Visible Customer')
            ->assertSee($delivery->tracking_number);
    }

    public function test_staff_index_excludes_requests_from_other_centers(): void
    {
        $centerA = $this->center();
        $centerB = $this->center();
        $staffA = $this->user('staff', $centerA);

        $deliveryA = $this->delivery(['recipient_name' => 'Center A Customer', 'center_id' => $centerA->id]);
        $deliveryB = $this->delivery(['recipient_name' => 'Center B Customer', 'center_id' => $centerB->id]);
        $this->pickupRequest($deliveryA, $centerA);
        $this->pickupRequest($deliveryB, $centerB);

        $this->actingAs($staffA)
            ->get(route('pickup-requests.index'))
            ->assertOk()
            ->assertSee('Center A Customer')
            ->assertDontSee('Center B Customer');
    }

    public function test_staff_can_view_and_approve_a_pending_pickup_request(): void
    {
        $center = $this->center();
        $staff = $this->user('staff', $center);

        $delivery = $this->delivery(['center_id' => $center->id]);
        $pickup = $this->pickupRequest($delivery, $center);

        $this->actingAs($staff)
            ->get(route('pickup-requests.show', $pickup))
            ->assertOk()
            ->assertSee('Approve Pickup Request');

        $this->actingAs($staff)
            ->from(route('pickup-requests.show', $pickup))
            ->post(route('pickup-requests.approve', $pickup))
            ->assertRedirect()->assertSessionHas('success');

        $pickup->refresh();
        $this->assertEquals('approved', $pickup->status);
        $this->assertEquals($staff->id, $pickup->reviewed_by);
        $this->assertNotNull($pickup->reviewed_at);
        $this->assertNull($pickup->rejection_reason);
    }

    public function test_approving_a_pickup_request_does_not_assign_a_rider_or_change_the_delivery(): void
    {
        $center = $this->center();
        $admin = $this->user();

        $delivery = $this->delivery(['center_id' => $center->id]);
        $pickup = $this->pickupRequest($delivery, $center);

        $this->actingAs($admin)
            ->post(route('pickup-requests.approve', $pickup))
            ->assertSessionHas('success');

        $delivery->refresh();
        $this->assertEquals('waiting_for_rider', $delivery->status);
        $this->assertNull($delivery->rider_id);
        $this->assertEquals('pending_arrival', $delivery->parcel_status);
    }

    public function test_staff_can_reject_a_pending_pickup_request_with_a_reason(): void
    {
        $center = $this->center();
        $staff = $this->user('staff', $center);

        $delivery = $this->delivery(['center_id' => $center->id]);
        $pickup = $this->pickupRequest($delivery, $center);

        $this->actingAs($staff)
            ->from(route('pickup-requests.show', $pickup))
            ->post(route('pickup-requests.reject', $pickup), [
                'rejection_reason' => 'Shop does not have the package ready.',
            ])
            ->assertRedirect()->assertSessionHas('success');

        $pickup->refresh();
        $this->assertEquals('rejected', $pickup->status);
        $this->assertEquals($staff->id, $pickup->reviewed_by);
        $this->assertNotNull($pickup->reviewed_at);
        $this->assertEquals('Shop does not have the package ready.', $pickup->rejection_reason);
    }

    public function test_rejection_does_not_modify_the_delivery(): void
    {
        $center = $this->center();
        $area = $this->area($center);
        $staff = $this->user('staff', $center);

        $delivery = $this->delivery([
            'center_id' => $center->id,
            'destination_center_id' => $center->id,
            'service_area_id' => $area->id,
            'parcel_status' => 'scanned',
        ]);
        $pickup = $this->pickupRequest($delivery, $center);

        $this->actingAs($staff)
            ->post(route('pickup-requests.reject', $pickup), [
                'rejection_reason' => 'Suspicious package.',
            ])
            ->assertSessionHas('success');

        $delivery->refresh();
        $this->assertEquals('waiting_for_rider', $delivery->status);
        $this->assertEquals('scanned', $delivery->parcel_status);
        $this->assertNull($delivery->rider_id);
    }

    public function test_staff_from_another_center_cannot_review_a_pickup_request(): void
    {
        $centerA = $this->center();
        $centerB = $this->center();
        $staffB = $this->user('staff', $centerB);

        $delivery = $this->delivery(['center_id' => $centerA->id]);
        $pickup = $this->pickupRequest($delivery, $centerA);

        $this->actingAs($staffB)
            ->get(route('pickup-requests.show', $pickup))
            ->assertForbidden();

        $this->actingAs($staffB)
            ->post(route('pickup-requests.approve', $pickup))
            ->assertForbidden();

        $this->actingAs($staffB)
            ->post(route('pickup-requests.reject', $pickup), ['rejection_reason' => 'not mine'])
            ->assertForbidden();

        $this->assertEquals('pending', $pickup->refresh()->status);
        $this->assertNull($pickup->reviewed_by);
    }

    public function test_staff_cannot_review_unallocated_requests_and_admin_can(): void
    {
        $centerA = $this->center();
        $staffA = $this->user('staff', $centerA);
        $admin = $this->user();

        $delivery = $this->delivery(); // no center yet (not received)
        $pickup = $this->pickupRequest($delivery, null);

        $this->actingAs($staffA)
            ->get(route('pickup-requests.index'))
            ->assertOk()
            ->assertDontSee($delivery->tracking_number);

        $this->actingAs($staffA)
            ->post(route('pickup-requests.approve', $pickup))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('pickup-requests.approve', $pickup))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertEquals('approved', $pickup->refresh()->status);
    }

    public function test_approved_pickup_request_cannot_be_modified_again(): void
    {
        $center = $this->center();
        $admin = $this->user();

        $delivery = $this->delivery(['center_id' => $center->id]);
        $pickup = $this->pickupRequest($delivery, $center);

        $this->actingAs($admin)
            ->post(route('pickup-requests.approve', $pickup))
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->from(route('pickup-requests.show', $pickup))
            ->post(route('pickup-requests.approve', $pickup))
            ->assertRedirect()->assertSessionHas('error');

        $this->actingAs($admin)
            ->from(route('pickup-requests.show', $pickup))
            ->post(route('pickup-requests.reject', $pickup), ['rejection_reason' => 'late change'])
            ->assertRedirect()->assertSessionHas('error');

        $pickup->refresh();
        $this->assertEquals('approved', $pickup->status);
        $this->assertNull($pickup->rejection_reason);
    }

    public function test_completed_pickup_request_is_immutable(): void
    {
        $center = $this->center();
        $admin = $this->user();

        $delivery = $this->delivery(['center_id' => $center->id]);
        $pickup = $this->pickupRequest($delivery, $center, [
            'status' => 'completed',
            'reviewed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from(route('pickup-requests.show', $pickup))
            ->post(route('pickup-requests.approve', $pickup))
            ->assertRedirect()->assertSessionHas('error');

        $this->actingAs($admin)
            ->from(route('pickup-requests.show', $pickup))
            ->post(route('pickup-requests.reject', $pickup), ['rejection_reason' => 'too late'])
            ->assertRedirect()->assertSessionHas('error');

        $pickup->refresh();
        $this->assertEquals('completed', $pickup->status);
        $this->assertNull($pickup->rejection_reason);
    }

    public function test_web_status_update_to_picked_up_completes_the_pickup_request(): void
    {
        $center = $this->center();
        $admin = $this->user();

        $delivery = $this->delivery(['center_id' => $center->id, 'status' => 'arrived_at_shop']);
        $pickup = $this->pickupRequest($delivery, $center, ['status' => 'approved']);

        $this->actingAs($admin)
            ->patch(route('deliveries.update-status', $delivery), ['status' => 'picked_up'])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertEquals('picked_up', $delivery->fresh()->status);
        $pickup->refresh();
        $this->assertEquals('completed', $pickup->status);
        $this->assertNotNull($pickup->reviewed_at);
    }

    public function test_rider_api_pickup_completes_the_pickup_request(): void
    {
        $center = $this->center();
        $area = $this->area($center);
        $data = $this->riderWithUser($center);

        $delivery = $this->delivery([
            'rider_id' => $data['rider']->id,
            'center_id' => $center->id,
            'service_area_id' => $area->id,
            'status' => 'arrived_at_shop',
            'pickup_pin' => '4821',
        ]);
        $pickup = $this->pickupRequest($delivery, $center);

        $response = $this->actingAs($data['user'], 'sanctum')
            ->postJson("/api/rider/deliveries/{$delivery->id}/pickup", ['pickup_pin' => '4821']);

        $response->assertOk();

        $this->assertEquals('picked_up', $delivery->fresh()->status);
        $pickup->refresh();
        $this->assertEquals('completed', $pickup->status);
    }

    public function test_status_filter_on_index_shows_only_matching_requests(): void
    {
        $center = $this->center();
        $admin = $this->user();

        $pendingDelivery = $this->delivery(['recipient_name' => 'Still Pending', 'center_id' => $center->id]);
        $approvedDelivery = $this->delivery(['recipient_name' => 'Already Approved', 'center_id' => $center->id]);
        $this->pickupRequest($pendingDelivery, $center);
        $this->pickupRequest($approvedDelivery, $center, ['status' => 'approved', 'reviewed_at' => now()]);

        $this->actingAs($admin)
            ->get(route('pickup-requests.index', ['status' => 'approved']))
            ->assertOk()
            ->assertSee('Already Approved')
            ->assertDontSee('Still Pending');
    }
}