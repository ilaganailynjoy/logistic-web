<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\ServiceArea;
use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

class SearchFilterSortTest extends TestCase
{
    private function admin(): User
    {
        return User::create([
            'name' => 'Sfs Admin',
            'first_name' => 'Sfs',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'sfs-admin-'.uniqid().'@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000010',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => 'admin',
            'status' => 'active',
            'center_id' => null,
            'email_verified_at' => now(),
        ]);
    }

    private function staff(LogisticsCenter $center): User
    {
        return User::create([
            'name' => 'Sfs Staff',
            'first_name' => 'Sfs',
            'last_name' => 'Staff',
            'sex' => 'female',
            'email' => 'sfs-staff-'.uniqid().'@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000011',
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
            'name' => 'Sfs Center '.uniqid(),
            'address' => 'Test St',
            'city' => 'Test City',
            'province' => 'Test Province '.uniqid(),
            'is_active' => true,
        ]);
    }

    private function area(LogisticsCenter $center): ServiceArea
    {
        return ServiceArea::create([
            'logistics_center_id' => $center->id,
            'name' => 'Sfs Area '.uniqid(),
            'is_active' => true,
        ]);
    }

    private function rider(LogisticsCenter $center, ?ServiceArea $area = null): Rider
    {
        return Rider::create([
            'name' => 'Sfs Rider '.uniqid(),
            'email' => 'sfs-rider-'.uniqid().'@test.com',
            'phone' => '09000000012',
            'vehicle_type' => 'Motorcycle',
            'license_plate' => 'AB 12',
            'status' => 'available',
            'center_id' => $center->id,
            'service_area_id' => $area?->id,
            'approved_at' => now()->subDays(5),
            'vehicle_verification' => 'verified',
        ]);
    }

    private function delivery(array $overrides = []): Delivery
    {
        return Delivery::create(array_merge([
            'sender_name' => 'Sfs Shop',
            'sender_phone' => '09171234501',
            'sender_address' => '1 Sfs St',
            'recipient_name' => 'Sfs Cust',
            'recipient_phone' => '09171234502',
            'recipient_address' => '2 Sfs Ave',
            'status' => 'waiting_for_rider',
            'delivery_fee' => 100.00,
            'priority' => 'normal',
        ], $overrides));
    }

    // ---------------------------------------------------------------
    // Deliveries — search
    // ---------------------------------------------------------------

    public function test_admin_can_search_delivery_by_primary_key_id(): void
    {
        $admin = $this->admin();
        $d = $this->delivery();

        $response = $this->actingAs($admin)->get('/deliveries?search=' . $d->id);
        $response->assertStatus(200);
        $response->assertSee($d->tracking_number);
    }

    public function test_admin_can_search_delivery_by_order_id(): void
    {
        $admin = $this->admin();

        // order_id is an FK to orders, which requires a buyer (user) + address.
        $buyer = User::create([
            'name' => 'Order Buyer',
            'first_name' => 'Order',
            'last_name' => 'Buyer',
            'sex' => 'male',
            'email' => 'order-buyer-'.uniqid().'@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000013',
            'birthday' => '1991-01-01',
            'age' => 34,
            'role' => 'staff',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $addressId = \DB::table('addresses')->insertGetId([
            'buyer_id' => $buyer->id,
            'recipient_name' => 'Order Buyer',
            'phone' => '09000000013',
            'address_line' => '1 Order St',
            'barangay' => 'Brgy 1',
            'city' => 'Test City',
            'province' => 'Test',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = \DB::table('orders')->insertGetId([
            'buyer_id' => $buyer->id,
            'address_id' => $addressId,
            'total_amount' => 150.00,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $d = $this->delivery(['order_id' => $orderId]);

        $response = $this->actingAs($admin)->get('/deliveries?search=' . $orderId);
        $response->assertStatus(200);
        $response->assertSee($d->tracking_number);
    }

    public function test_admin_can_search_delivery_by_tracking_number(): void
    {
        $admin = $this->admin();
        $d = $this->delivery();

        $response = $this->actingAs($admin)->get('/deliveries?search=' . $d->tracking_number);
        $response->assertStatus(200);
        $response->assertSee($d->tracking_number);
    }

    public function test_admin_can_search_delivery_by_sender_phone(): void
    {
        $admin = $this->admin();
        $d = $this->delivery();

        $response = $this->actingAs($admin)->get('/deliveries?search=' . $d->sender_phone);
        $response->assertStatus(200);
        $response->assertSee($d->tracking_number);
    }

    // ---------------------------------------------------------------
    // Deliveries — filters
    // ---------------------------------------------------------------

    public function test_admin_can_filter_deliveries_by_center(): void
    {
        $admin = $this->admin();
        $c1 = $this->center();
        $c2 = $this->center();

        $in1 = $this->delivery(['center_id' => $c1->id]);
        $in2 = $this->delivery(['center_id' => $c2->id]);

        $response = $this->actingAs($admin)->get('/deliveries?center_id=' . $c1->id);
        $response->assertStatus(200);
        $response->assertSee($in1->tracking_number);
        $response->assertDontSee($in2->tracking_number);
    }

    public function test_admin_can_filter_deliveries_by_service_area(): void
    {
        $admin = $this->admin();
        $c = $this->center();
        $a1 = $this->area($c);
        $a2 = $this->area($c);

        $d1 = $this->delivery(['center_id' => $c->id, 'service_area_id' => $a1->id]);
        $d2 = $this->delivery(['center_id' => $c->id, 'service_area_id' => $a2->id]);

        $response = $this->actingAs($admin)->get('/deliveries?service_area_id=' . $a1->id);
        $response->assertStatus(200);
        $response->assertSee($d1->tracking_number);
        $response->assertDontSee($d2->tracking_number);
    }

    public function test_admin_can_filter_deliveries_by_priority(): void
    {
        $admin = $this->admin();
        $urgent = $this->delivery(['priority' => 'urgent']);
        $normal = $this->delivery(['priority' => 'normal']);

        $response = $this->actingAs($admin)->get('/deliveries?priority=urgent');
        $response->assertStatus(200);
        $response->assertSee($urgent->tracking_number);
        $response->assertDontSee($normal->tracking_number);
    }

    public function test_admin_can_filter_deliveries_by_rider(): void
    {
        $admin = $this->admin();
        $c = $this->center();
        $a = $this->area($c);
        $rider = $this->rider($c, $a);

        $assigned = $this->delivery(['rider_id' => $rider->id, 'center_id' => $c->id]);
        $unassigned = $this->delivery(['center_id' => $c->id]);

        $response = $this->actingAs($admin)->get('/deliveries?rider_id=' . $rider->id);
        $response->assertStatus(200);
        $response->assertSee($assigned->tracking_number);
        $response->assertDontSee($unassigned->tracking_number);
    }

    // ---------------------------------------------------------------
    // Deliveries — sorting
    // ---------------------------------------------------------------

    public function test_admin_can_sort_deliveries_by_destination(): void
    {
        $admin = $this->admin();
        $destA = $this->center();
        $destB = $this->center();
        // Force names so ordering is deterministic
        $destA->name = 'AAAA Destination';
        $destA->save();
        $destB->name = 'BBBB Destination';
        $destB->save();

        $dA = $this->delivery(['destination_center_id' => $destA->id]);
        $dB = $this->delivery(['destination_center_id' => $destB->id]);

        $response = $this->actingAs($admin)->get('/deliveries?sort=destination&dir=asc');
        $response->assertStatus(200);
        $html = $response->getContent();
        $this->assertTrue(
            strpos($html, $dA->tracking_number) < strpos($html, $dB->tracking_number),
            'dest A should appear before dest B when sorting ascending'
        );
    }

    public function test_admin_can_sort_deliveries_by_service_area(): void
    {
        $admin = $this->admin();
        $c = $this->center();
        $a1 = $this->area($c);
        $a2 = $this->area($c);
        $a1->name = 'AAAA Area';
        $a1->save();
        $a2->name = 'BBBB Area';
        $a2->save();

        $d1 = $this->delivery(['service_area_id' => $a1->id, 'center_id' => $c->id]);
        $d2 = $this->delivery(['service_area_id' => $a2->id, 'center_id' => $c->id]);

        $response = $this->actingAs($admin)->get('/deliveries?sort=service_area&dir=asc');
        $response->assertStatus(200);
        $html = $response->getContent();
        $this->assertTrue(
            strpos($html, $d1->tracking_number) < strpos($html, $d2->tracking_number)
        );
    }

    public function test_admin_can_sort_deliveries_by_center(): void
    {
        $admin = $this->admin();
        $cA = $this->center();
        $cB = $this->center();
        $cA->name = 'AAAA Center';
        $cA->save();
        $cB->name = 'BBBB Center';
        $cB->save();

        $dA = $this->delivery(['center_id' => $cA->id]);
        $dB = $this->delivery(['center_id' => $cB->id]);

        $response = $this->actingAs($admin)->get('/deliveries?sort=center&dir=asc');
        $response->assertStatus(200);
        $html = $response->getContent();
        $this->assertTrue(
            strpos($html, $dA->tracking_number) < strpos($html, $dB->tracking_number)
        );
    }

    public function test_admin_can_sort_deliveries_by_priority(): void
    {
        $admin = $this->admin();

        // Sort ascending: high > normal (alphabetical h before n)
        $high = $this->delivery(['priority' => 'high']);
        $normal = $this->delivery(['priority' => 'normal']);

        $response = $this->actingAs($admin)->get('/deliveries?sort=priority&dir=asc');
        $response->assertStatus(200);
        $html = $response->getContent();
        // 'high' sorts before 'normal' alphabetically
        $this->assertTrue(
            strpos($html, $high->tracking_number) < strpos($html, $normal->tracking_number)
        );
    }

    public function test_admin_can_sort_deliveries_by_transaction_amount(): void
    {
        $admin = $this->admin();

        $dLow = $this->delivery(['delivery_fee' => 50.00]);
        $dHigh = $this->delivery(['delivery_fee' => 500.00]);

        // Create 1:1 transactions for both
        Transaction::create([
            'delivery_id' => $dLow->id,
            'tracking_number' => $dLow->tracking_number,
            'rider_id' => null,
            'logistics_center_id' => null,
            'service_area_id' => null,
            'amount' => 50.00,
            'rider_fee' => 15.00,
            'admin_commission' => 5.00,
            'status' => 'completed',
        ]);
        Transaction::create([
            'delivery_id' => $dHigh->id,
            'tracking_number' => $dHigh->tracking_number,
            'rider_id' => null,
            'logistics_center_id' => null,
            'service_area_id' => null,
            'amount' => 500.00,
            'rider_fee' => 15.00,
            'admin_commission' => 50.00,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($admin)->get('/deliveries?sort=amount&dir=asc');
        $response->assertStatus(200);
        $html = $response->getContent();
        $this->assertTrue(
            strpos($html, $dLow->tracking_number) < strpos($html, $dHigh->tracking_number)
        );
    }

    public function test_admin_can_sort_deliveries_newest_and_oldest(): void
    {
        $admin = $this->admin();
        $older = $this->delivery(['created_at' => now()->subDays(10)]);
        $newer = $this->delivery(['created_at' => now()]);

        $asc = $this->actingAs($admin)->get('/deliveries?sort=date&dir=asc')->getContent();
        $this->assertTrue(strpos($asc, $older->tracking_number) < strpos($asc, $newer->tracking_number));

        $desc = $this->actingAs($admin)->get('/deliveries?sort=date&dir=desc')->getContent();
        $this->assertTrue(strpos($desc, $newer->tracking_number) < strpos($desc, $older->tracking_number));
    }

    // ---------------------------------------------------------------
    // Deliveries — staff center scoping
    // ---------------------------------------------------------------

    public function test_staff_cannot_bypass_center_scoping_for_deliveries(): void
    {
        $c1 = $this->center();
        $c2 = $this->center();
        $staff = $this->staff($c1);

        $mine = $this->delivery(['center_id' => $c1->id]);
        $theirs = $this->delivery(['center_id' => $c2->id]);

        // Staff asks for c2's center via query param, but must still only see c1.
        $response = $this->actingAs($staff)->get('/deliveries?center_id=' . $c2->id);
        $response->assertStatus(200);
        $response->assertSee($mine->tracking_number);
        $response->assertDontSee($theirs->tracking_number);
    }

    // ---------------------------------------------------------------
    // Riders — filters & scoping
    // ---------------------------------------------------------------

    public function test_admin_can_filter_riders_by_service_area(): void
    {
        $admin = $this->admin();
        $c = $this->center();
        $a1 = $this->area($c);
        $a2 = $this->area($c);

        $r1 = $this->rider($c, $a1);
        $r2 = $this->rider($c, $a2);

        $response = $this->actingAs($admin)->get('/riders?service_area_id=' . $a1->id);
        $response->assertStatus(200);
        $response->assertSee($r1->name);
        $response->assertDontSee($r2->name);
    }

    public function test_admin_can_filter_riders_by_center(): void
    {
        $admin = $this->admin();
        $c1 = $this->center();
        $c2 = $this->center();

        $r1 = $this->rider($c1);
        $r2 = $this->rider($c2);

        $response = $this->actingAs($admin)->get('/riders?center_id=' . $c1->id);
        $response->assertStatus(200);
        $response->assertSee($r1->name);
        $response->assertDontSee($r2->name);
    }

    public function test_staff_riders_restricted_to_own_center(): void
    {
        $c1 = $this->center();
        $c2 = $this->center();
        $staff = $this->staff($c1);

        $mine = $this->rider($c1);
        $theirs = $this->rider($c2);

        // Even if staff tries to filter by c2, only own-center riders are shown.
        $response = $this->actingAs($staff)->get('/riders?center_id=' . $c2->id);
        $response->assertStatus(200);
        $response->assertSee($mine->name);
        $response->assertDontSee($theirs->name);
    }

    // ---------------------------------------------------------------
    // Centers — status filter & province search
    // ---------------------------------------------------------------

    public function test_admin_can_filter_centers_by_status(): void
    {
        $active = $this->center();
        $inactive = $this->center();
        $inactive->is_active = false;
        $inactive->save();

        $response = $this->actingAs($this->admin())->get('/centers?status=active');
        $response->assertStatus(200);
        $response->assertSee($active->name);
        $response->assertDontSee($inactive->name);
    }

    public function test_admin_can_search_centers_by_province(): void
    {
        $c = $this->center();
        $province = $c->province; // unique per center

        $response = $this->actingAs($this->admin())->get('/centers?search=' . urlencode($province));
        $response->assertStatus(200);
        $response->assertSee($c->name);
    }

    // ---------------------------------------------------------------
    // Service areas — status filter
    // ---------------------------------------------------------------

    public function test_admin_can_filter_service_areas_by_status(): void
    {
        $c = $this->center();
        $active = $this->area($c);
        $inactive = $this->area($c);
        $inactive->is_active = false;
        $inactive->save();

        $response = $this->actingAs($this->admin())->get('/service-areas?status=active');
        $response->assertStatus(200);
        $response->assertSee($active->name);
        $response->assertDontSee($inactive->name);
    }
}
