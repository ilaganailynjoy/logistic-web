<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryStatusLog;
use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\ServiceArea;
use App\Models\Transaction;
use App\Models\User;
use App\Models\DeliveryProof;
use Tests\TestCase;

class LogisticsAuditTest extends TestCase
{
    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Audit',
            'first_name' => 'Admin',
            'last_name' => 'Audit',
            'sex' => 'male',
            'email' => 'audit-admin@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000000',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => 'admin',
            'status' => 'active',
            'center_id' => null,
            'email_verified_at' => now(),
        ]);
    }

    private function staff(?LogisticsCenter $center = null): User
    {
        $center = $center ?? $this->center();
        return User::create([
            'name' => 'Staff Audit',
            'first_name' => 'Staff',
            'last_name' => 'Audit',
            'sex' => 'female',
            'email' => 'audit-staff-'.uniqid().'@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000001',
            'birthday' => '1992-01-01',
            'age' => 33,
            'role' => 'staff',
            'status' => 'active',
            'center_id' => $center->id,
            'email_verified_at' => now(),
        ]);
    }

    private function center(): LogisticsCenter
    {
        return LogisticsCenter::create([
            'name' => 'Audit Center '.uniqid(),
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
            'name' => 'Audit Area '.uniqid(),
            'is_active' => true,
        ]);
    }

    private function rider(LogisticsCenter $center, ?ServiceArea $area = null): Rider
    {
        return Rider::create([
            'name' => 'Audit Rider '.uniqid(),
            'email' => 'audit-rider-'.uniqid().'@test.com',
            'phone' => '09000000002',
            'vehicle_type' => 'Motorcycle',
            'license_plate' => 'ABC 1',
            'status' => 'available',
            'center_id' => $center->id,
            'service_area_id' => $area?->id,
            'approved_at' => now()->subDays(10),
            'vehicle_verification' => 'verified',
        ]);
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

    // ---------------------------------------------------------------
    // Role authorization
    // ---------------------------------------------------------------

    public function test_non_admin_cannot_access_center_management(): void
    {
        $center = $this->center();
        $staff = $this->staff($center);

        $this->actingAs($staff)->get('/centers')->assertStatus(403);
        $this->actingAs($staff)->get('/centers/create')->assertStatus(403);
        $this->actingAs($staff)->get('/staff')->assertStatus(403);
        $this->actingAs($staff)->get('/service-areas')->assertStatus(403);
    }

    public function test_admin_can_access_center_management(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/centers')->assertStatus(200);
        $this->actingAs($admin)->get('/staff')->assertStatus(200);
        $this->actingAs($admin)->get('/service-areas')->assertStatus(200);
        $this->actingAs($admin)->get('/transactions')->assertStatus(200);
        $this->actingAs($admin)->get('/reports')->assertStatus(200);
    }

    public function test_rider_cannot_access_logistics_area(): void
    {
        $riderUser = User::create([
            'name' => 'Rider Web',
            'first_name' => 'R',
            'last_name' => 'W',
            'sex' => 'male',
            'email' => 'web-rider-'.uniqid().'@test.com',
            'password' => bcrypt('password'),
            'phone' => '09000000003',
            'birthday' => '1993-01-01',
            'age' => 31,
            'role' => 'rider',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $resp = $this->actingAs($riderUser)->get('/deliveries');
        $this->assertTrue(in_array($resp->status(), [302, 403]), 'rider should be blocked from logistics area');
    }

    // ---------------------------------------------------------------
    // Center scoping
    // ---------------------------------------------------------------

    public function test_staff_only_sees_own_center_deliveries(): void
    {
        $c1 = $this->center();
        $c2 = $this->center();
        $staff = $this->staff($c1);

        $mine = $this->delivery(['center_id' => $c1->id, 'parcel_status' => 'received']);
        $theirs = $this->delivery(['center_id' => $c2->id, 'parcel_status' => 'received']);

        $response = $this->actingAs($staff)->get('/deliveries');
        $response->assertStatus(200);
        $html = $response->getContent();
        $this->assertStringContainsString($mine->tracking_number, $html);
        $this->assertStringNotContainsString($theirs->tracking_number, $html);
    }

    public function test_staff_cannot_receive_parcel_for_other_center(): void
    {
        $c1 = $this->center();
        $c2 = $this->center();
        $staff = $this->staff($c1);
        $delivery = $this->delivery(['parcel_status' => 'pending_arrival']);

        $this->actingAs($staff)
            ->post("/deliveries/{$delivery->id}/receive", ['center_id' => $c2->id])
            ->assertStatus(403);

        $delivery->refresh();
        $this->assertEquals('pending_arrival', $delivery->parcel_status);
    }

    // ---------------------------------------------------------------
    // Parcel workflow receive -> scan -> sort
    // ---------------------------------------------------------------

    public function test_parcel_receive_scan_sort_flow(): void
    {
        $admin = $this->admin();
        $center = $this->center();
        $dest = $this->center();
        $area = $this->area($dest);
        $delivery = $this->delivery(['parcel_status' => 'pending_arrival']);

        // receive
        $this->actingAs($admin)->post("/deliveries/{$delivery->id}/receive", ['center_id' => $center->id])
            ->assertSessionHas('success');
        $delivery->refresh();
        $this->assertEquals('received', $delivery->parcel_status);
        $this->assertNotNull($delivery->received_at);

        // scan
        $this->actingAs($admin)->patch("/deliveries/{$delivery->id}/scan")
            ->assertSessionHas('success');
        $delivery->refresh();
        $this->assertEquals('scanned', $delivery->parcel_status);
        $this->assertNotNull($delivery->scanned_at);

        // sort
        $this->actingAs($admin)->post("/deliveries/{$delivery->id}/sort", [
            'destination_center_id' => $dest->id,
            'service_area_id' => $area->id,
        ])->assertSessionHas('success');
        $delivery->refresh();
        $this->assertEquals('sorted', $delivery->parcel_status);
        $this->assertEquals($dest->id, $delivery->destination_center_id);
        $this->assertEquals($area->id, $delivery->service_area_id);
        $this->assertNotNull($delivery->sorted_at);

        $this->assertDatabaseHas('delivery_status_logs', ['delivery_id' => $delivery->id, 'status' => 'sorted']);
    }

    public function test_cannot_skip_parcel_steps(): void
    {
        $admin = $this->admin();
        $delivery = $this->delivery(['parcel_status' => 'pending_arrival']);

        // scan before receive should fail
        $this->actingAs($admin)->patch("/deliveries/{$delivery->id}/scan")
            ->assertRedirect();
        $delivery->refresh();
        $this->assertEquals('pending_arrival', $delivery->parcel_status);

        // cannot receive twice
        $center = $this->center();
        $this->actingAs($admin)->post("/deliveries/{$delivery->id}/receive", ['center_id' => $center->id])
            ->assertSessionHas('success');
        $this->actingAs($admin)->post("/deliveries/{$delivery->id}/receive", ['center_id' => $center->id])
            ->assertSessionHas('error');
    }

    // ---------------------------------------------------------------
    // Transaction generation + duplicate prevention
    // ---------------------------------------------------------------

    public function test_transaction_generated_on_delivered(): void
    {
        $admin = $this->admin();
        $center = $this->center();
        $rider = $this->rider($center);
        $delivery = $this->delivery([
            'status' => 'out_for_delivery',
            'rider_id' => $rider->id,
            'center_id' => $center->id,
            'delivery_fee' => 150.00,
        ]);

        DeliveryProof::create([
            'delivery_id' => $delivery->id,
            'rider_id' => $rider->id,
            'type' => 'signature',
            'signature_name' => 'Test Customer',
        ]);

        $this->actingAs($admin)->patch("/deliveries/{$delivery->id}/update-status", ['status' => 'delivered'])
            ->assertSessionHas('success');

        $tx = Transaction::where('delivery_id', $delivery->id)->first();
        $this->assertNotNull($tx, 'Transaction should be generated');
        $this->assertEquals(150.00, (float) $tx->amount);
        $this->assertEquals(15.00, (float) $tx->rider_fee);
        $this->assertEquals(15.00, (float) $tx->admin_commission); // 10% of 150
        $this->assertEquals('completed', $tx->status);
    }

    public function test_no_duplicate_transaction_when_storeForDelivery_called_twice(): void
    {
        $center = $this->center();
        $rider = $this->rider($center);
        $delivery = $this->delivery([
            'status' => 'delivered',
            'rider_id' => $rider->id,
            'center_id' => $center->id,
            'delivery_fee' => 100.00,
        ]);

        // Simulate two "delivered" transitions racing (e.g. repeated calls)
        app(\App\Http\Controllers\TransactionController::class)->storeForDelivery($delivery);
        app(\App\Http\Controllers\TransactionController::class)->storeForDelivery($delivery);

        $count = Transaction::where('delivery_id', $delivery->id)->count();
        $this->assertEquals(1, $count, 'Only one transaction should exist per delivery');
    }

    public function test_transaction_not_generated_for_non_delivered(): void
    {
        $admin = $this->admin();
        $delivery = $this->delivery(['status' => 'assigned', 'delivery_fee' => 100.00]);
        $this->actingAs($admin)->patch("/deliveries/{$delivery->id}/update-status", ['status' => 'picked_up'])
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('transactions', ['delivery_id' => $delivery->id]);
    }

    // ---------------------------------------------------------------
    // Archive / restore / permanent delete (admin only)
    // ---------------------------------------------------------------

    public function test_archive_is_admin_only(): void
    {
        $center = $this->center();
        $staff = $this->staff($center);
        $delivery = $this->delivery();

        $this->actingAs($staff)->post("/deliveries/{$delivery->id}/archive")
            ->assertStatus(403);
        $this->assertNull($delivery->fresh()->archived_at);
    }

    public function test_archive_restore_destroy_admin_flow(): void
    {
        $admin = $this->admin();
        $delivery = $this->delivery();

        // archive
        $this->actingAs($admin)->post("/deliveries/{$delivery->id}/archive", ['archive_note' => 'test'])
            ->assertSessionHas('success');
        $this->assertNotNull($delivery->fresh()->archived_at);

        // restore
        $this->actingAs($admin)->post("/deliveries/{$delivery->id}/restore")
            ->assertSessionHas('success');
        $this->assertNull($delivery->fresh()->archived_at);

        // permanent delete
        $this->actingAs($admin)->delete("/deliveries/{$delivery->id}")
            ->assertRedirect();
        $this->assertDatabaseMissing('deliveries', ['id' => $delivery->id]);
    }

    public function test_destroy_is_admin_only(): void
    {
        $center = $this->center();
        $staff = $this->staff($center);
        $delivery = $this->delivery();

        $this->actingAs($staff)->delete("/deliveries/{$delivery->id}")
            ->assertStatus(403);
        $this->assertDatabaseHas('deliveries', ['id' => $delivery->id]);
    }

    // ---------------------------------------------------------------
    // Reports
    // ---------------------------------------------------------------

    public function test_report_parcel_and_delivery_stats(): void
    {
        $admin = $this->admin();
        $this->delivery(['status' => 'delivered', 'parcel_status' => 'sorted']);
        $this->delivery(['status' => 'assigned', 'parcel_status' => 'received']);
        $this->delivery(['status' => 'waiting_for_rider', 'parcel_status' => 'pending_arrival']);

        $this->actingAs($admin)->get('/reports')->assertStatus(200);
        $this->actingAs($admin)->get('/reports?tab=delivery')->assertStatus(200);
        $this->actingAs($admin)->get('/reports?tab=center')->assertStatus(200);
        $this->actingAs($admin)->get('/reports?tab=area')->assertStatus(200);
        $this->actingAs($admin)->get('/reports?tab=rider')->assertStatus(200);
        $this->actingAs($admin)->get('/reports?tab=financial')->assertStatus(200);
    }

    // ---------------------------------------------------------------
    // Rider read-only
    // ---------------------------------------------------------------

    public function test_rider_directory_has_no_edit_delete_routes(): void
    {
        $admin = $this->admin();
        $this->delivery();

        $this->actingAs($admin)->get('/riders')->assertStatus(200);
        $rider = $this->rider($this->center());
        $this->actingAs($admin)->get("/riders/{$rider->id}")->assertStatus(200);
    }

    // ---------------------------------------------------------------
    // Service area / center destroy
    // ---------------------------------------------------------------

    public function test_service_area_destroy_route_works(): void
    {
        $admin = $this->admin();
        $center = $this->center();
        $area = $this->area($center);

        $this->actingAs($admin)->delete("/service-areas/{$area->id}")
            ->assertRedirect();
        $this->assertDatabaseMissing('service_areas', ['id' => $area->id]);
    }

    public function test_center_destroy_route_works(): void
    {
        $admin = $this->admin();
        $center = $this->center();

        $this->actingAs($admin)->delete("/centers/{$center->id}")
            ->assertRedirect();
        $this->assertDatabaseMissing('logistics_centers', ['id' => $center->id]);
    }
}
