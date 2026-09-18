<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryStatusLog;
use App\Models\LogisticsCenter;
use App\Models\User;
use Tests\TestCase;

class DeliveryScanTest extends TestCase
{
    private function user(string $role = 'admin', ?LogisticsCenter $center = null): User
    {
        return User::create([
            'name' => 'Scan '.$role.' '.uniqid(),
            'first_name' => 'Scan',
            'last_name' => ucfirst($role),
            'sex' => 'female',
            'email' => 'scan-'.$role.'-'.uniqid().'@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000001',
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
            'name' => 'Scan Center '.uniqid(),
            'address' => 'Test St',
            'city' => 'Test City',
            'province' => 'Test',
            'is_active' => true,
        ]);
    }

    private function delivery(array $overrides = []): Delivery
    {
        return Delivery::create(array_merge([
            'sender_name' => 'Scan Shop',
            'sender_phone' => '09171234567',
            'sender_address' => '1 Shop St',
            'recipient_name' => 'Scan Cust',
            'recipient_phone' => '09171234568',
            'recipient_address' => '2 Cust Ave',
            'status' => 'waiting_for_rider',
            'delivery_fee' => 100.00,
        ], $overrides));
    }

    public function test_staff_can_view_scan_page(): void
    {
        $center = $this->center();
        $staff = $this->user('staff', $center);

        $response = $this->actingAs($staff)->get(route('deliveries.scan-page'));

        $response->assertOk();
        $response->assertSee('Parcel Scanner');
        $response->assertSee('Start Camera');
        $response->assertSee('Verify Manually');
    }

    public function test_admin_can_view_scan_page(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->get(route('deliveries.scan-page'))->assertOk();
    }

    public function test_scan_page_requires_authentication(): void
    {
        $this->get(route('deliveries.scan-page'))->assertRedirect(route('login'));
    }

    public function test_buyer_cannot_view_scan_page(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($buyer)->get(route('deliveries.scan-page'))->assertRedirect(route('login'));
    }

    public function test_scan_by_tracking_scans_received_parcel(): void
    {
        $admin = $this->user();
        $delivery = $this->delivery(['parcel_status' => 'received']);

        $response = $this->actingAs($admin)->postJson(route('deliveries.scan-verify'), [
            'tracking_number' => $delivery->tracking_number,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('delivery.tracking_number', $delivery->tracking_number)
            ->assertJsonPath('delivery.parcel_status', 'scanned');

        $delivery->refresh();
        $this->assertSame('scanned', $delivery->parcel_status);
        $this->assertNotNull($delivery->scanned_at);
        $this->assertDatabaseHas('delivery_status_logs', [
            'delivery_id' => $delivery->id,
            'status' => 'scanned',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_scan_is_idempotent_when_parcel_already_scanned(): void
    {
        $admin = $this->user();
        $delivery = $this->delivery(['parcel_status' => 'scanned', 'scanned_at' => now()->subHour()]);

        $response = $this->actingAs($admin)->postJson(route('deliveries.scan-verify'), [
            'tracking_number' => $delivery->tracking_number,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('already', true);

        $this->assertDatabaseCount('delivery_status_logs', 0);
        $this->assertSame('scanned', $delivery->fresh()->parcel_status);
    }

    public function test_scan_rejects_parcel_not_received_yet(): void
    {
        $admin = $this->user();
        $delivery = $this->delivery(['parcel_status' => 'pending_arrival']);

        $response = $this->actingAs($admin)->postJson(route('deliveries.scan-verify'), [
            'tracking_number' => $delivery->tracking_number,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'invalid_state');
        $this->assertSame('pending_arrival', $delivery->fresh()->parcel_status);
    }

    public function test_scan_rejects_sorted_parcel(): void
    {
        $admin = $this->user();
        $delivery = $this->delivery(['parcel_status' => 'sorted']);

        $response = $this->actingAs($admin)->postJson(route('deliveries.scan-verify'), [
            'tracking_number' => $delivery->tracking_number,
        ]);

        $response->assertStatus(422)->assertJsonPath('error', 'invalid_state');
    }

    public function test_scan_rejects_unknown_tracking_number(): void
    {
        $admin = $this->user();

        $response = $this->actingAs($admin)->postJson(route('deliveries.scan-verify'), [
            'tracking_number' => 'TRK-20990101-ABCD',
        ]);

        $response->assertStatus(404)->assertJsonPath('error', 'not_found');
    }

    public function test_scan_rejects_malformed_tracking_number(): void
    {
        $admin = $this->user();

        $response = $this->actingAs($admin)->postJson(route('deliveries.scan-verify'), [
            'tracking_number' => 'not-a-tracking-number',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('tracking_number');
    }

    public function test_scan_requires_tracking_number(): void
    {
        $admin = $this->user();

        $response = $this->actingAs($admin)->postJson(route('deliveries.scan-verify'), []);

        $response->assertStatus(422)->assertJsonValidationErrors('tracking_number');
    }

    public function test_staff_cannot_scan_parcel_of_another_center(): void
    {
        $centerA = $this->center();
        $centerB = $this->center();
        $staff = $this->user('staff', $centerA);
        $delivery = $this->delivery(['center_id' => $centerB->id, 'parcel_status' => 'received']);

        $response = $this->actingAs($staff)->postJson(route('deliveries.scan-verify'), [
            'tracking_number' => $delivery->tracking_number,
        ]);

        $response->assertStatus(403)->assertJsonPath('error', 'forbidden');
        $this->assertSame('received', $delivery->fresh()->parcel_status);
    }

    public function test_staff_can_scan_parcel_of_own_center(): void
    {
        $center = $this->center();
        $staff = $this->user('staff', $center);
        $delivery = $this->delivery(['center_id' => $center->id, 'parcel_status' => 'received']);

        $response = $this->actingAs($staff)->postJson(route('deliveries.scan-verify'), [
            'tracking_number' => $delivery->tracking_number,
        ]);

        $response->assertOk()->assertJsonPath('delivery.parcel_status', 'scanned');
    }

    public function test_scan_rejects_archived_parcel(): void
    {
        $admin = $this->user();
        $delivery = $this->delivery(['parcel_status' => 'received', 'archived_at' => now()]);

        $response = $this->actingAs($admin)->postJson(route('deliveries.scan-verify'), [
            'tracking_number' => $delivery->tracking_number,
        ]);

        $response->assertStatus(404)->assertJsonPath('error', 'not_found');
    }

    public function test_scan_accepts_lowercase_tracking_number(): void
    {
        $admin = $this->user();
        $delivery = $this->delivery(['parcel_status' => 'received']);

        $response = $this->actingAs($admin)->postJson(route('deliveries.scan-verify'), [
            'tracking_number' => strtolower($delivery->tracking_number),
        ]);

        $response->assertOk()->assertJsonPath('delivery.tracking_number', $delivery->tracking_number);
        $this->assertSame('scanned', $delivery->fresh()->parcel_status);
    }

    public function test_scan_verify_requires_authentication(): void
    {
        $this->postJson(route('deliveries.scan-verify'), ['tracking_number' => 'TRK-20260101-ABCD'])
            ->assertStatus(401);
    }

    public function test_scan_page_shows_expected_parcel_hint_for_accessible_center(): void
    {
        $center = $this->center();
        $staff = $this->user('staff', $center);
        $delivery = $this->delivery(['center_id' => $center->id]);

        $response = $this->actingAs($staff)->get(route('deliveries.scan-page', ['expect' => $delivery->tracking_number]));

        $response->assertOk();
        $response->assertSee($delivery->tracking_number);
        $response->assertSee('Expected parcel');
    }

    public function test_scan_page_omits_expected_hint_when_it_escapes_center_scope(): void
    {
        $centerA = $this->center();
        $centerB = $this->center();
        $staff = $this->user('staff', $centerA);
        $delivery = $this->delivery(['center_id' => $centerB->id]);

        $response = $this->actingAs($staff)->get(route('deliveries.scan-page', ['expect' => $delivery->tracking_number]));

        $response->assertOk();
        $response->assertSee($delivery->tracking_number);
        $response->assertDontSee($delivery->recipient_name);
    }

    public function test_existing_patch_scan_route_still_works(): void
    {
        $admin = $this->user();
        $delivery = $this->delivery(['parcel_status' => 'received']);

        $this->actingAs($admin)->patch(route('deliveries.scan', $delivery))->assertSessionHas('success');

        $this->assertSame('scanned', $delivery->fresh()->parcel_status);
        $this->assertDatabaseHas('delivery_status_logs', [
            'delivery_id' => $delivery->id,
            'status' => 'scanned',
        ]);
    }

    public function test_delivery_status_log_records_qr_scan_note(): void
    {
        $admin = $this->user();
        $delivery = $this->delivery(['parcel_status' => 'received']);

        $this->actingAs($admin)->postJson(route('deliveries.scan-verify'), [
            'tracking_number' => $delivery->tracking_number,
        ])->assertOk();

        $this->assertDatabaseHas('delivery_status_logs', [
            'delivery_id' => $delivery->id,
            'status' => 'scanned',
            'notes' => 'Parcel scanned and verified via QR.',
        ]);
    }
}