<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryStatusLog;
use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\ServiceArea;
use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    private function user(string $role = 'admin', ?LogisticsCenter $center = null): User
    {
        return User::create([
            'name' => 'Dash '.$role.' '.uniqid(),
            'first_name' => 'Dash',
            'last_name' => ucfirst($role),
            'sex' => 'male',
            'email' => 'dash-'.$role.'-'.uniqid().'@logistics.com',
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
            'name' => 'Dash Center '.uniqid(),
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
            'name' => 'Dash Area '.uniqid(),
            'is_active' => true,
        ]);
    }

    private function rider(LogisticsCenter $center, array $overrides = []): Rider
    {
        return Rider::create(array_merge([
            'name' => 'Dash Rider '.uniqid(),
            'email' => 'dash-rider-'.uniqid().'@test.com',
            'phone' => '09000000002',
            'vehicle_type' => 'Motorcycle',
            'license_plate' => 'DSH '.random_int(1, 999),
            'status' => 'available',
            'center_id' => $center->id,
            'approved_at' => now()->subDays(10),
            'vehicle_verification' => 'verified',
        ], $overrides));
    }

    private function delivery(LogisticsCenter $center, string $status, array $overrides = []): Delivery
    {
        return Delivery::create(array_merge([
            'sender_name' => 'Shop',
            'sender_phone' => '09171234567',
            'sender_address' => '1 Shop St',
            'recipient_name' => 'Cust',
            'recipient_phone' => '09171234568',
            'recipient_address' => '2 Cust Ave',
            'status' => $status,
            'delivery_fee' => 100.00,
            'center_id' => $center->id,
        ], $overrides));
    }

    public function test_admin_can_see_overall_dashboard_data(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $this->delivery($center, 'waiting_for_rider');
        $this->delivery($center, 'delivered');
        $this->rider($center, ['is_online' => true]);

        $response = $this->actingAs($admin)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('Dashboard Overview');

        $deliveries = $response->viewData('deliveries');
        $this->assertEquals(2, $deliveries['total']);
        $this->assertEquals(1, $deliveries['waiting_for_rider']);
        $this->assertEquals(1, $deliveries['delivered']);
    }

    public function test_staff_only_sees_assigned_centers_data(): void
    {
        $centerA = $this->center();
        $centerB = $this->center();
        $staff = $this->user('staff', $centerA);

        $this->delivery($centerA, 'waiting_for_rider');
        $this->delivery($centerA, 'delivered');
        $this->delivery($centerB, 'delivered');
        $this->delivery($centerB, 'delivery_failed');
        $this->rider($centerA, ['is_online' => true]);
        $this->rider($centerB, ['is_online' => true]);

        $response = $this->actingAs($staff)->get(route('dashboard'));
        $response->assertOk();

        // Staff at A sees only A's 2 deliveries, none from B.
        $deliveries = $response->viewData('deliveries');
        $this->assertEquals(2, $deliveries['total']);
        $this->assertEquals(1, $deliveries['waiting_for_rider']);

        // B's failed delivery must not show up.
        $needs = $response->viewData('needsAttention');
        $this->assertEquals(0, $needs['failed']);

        // Staff only sees their own center's riders.
        $riders = $response->viewData('riders');
        $this->assertEquals(1, $riders['total']);
        $this->assertEquals(1, $riders['online']);
    }

    public function test_delivery_counts_are_correct(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $this->delivery($center, 'waiting_for_rider');
        $this->delivery($center, 'assigned');
        $this->delivery($center, 'out_for_delivery');
        $this->delivery($center, 'delivered');
        $this->delivery($center, 'delivery_failed');

        $response = $this->actingAs($admin)->get(route('dashboard'));
        $d = $response->viewData('deliveries');
        $this->assertEquals(5, $d['total']);
        $this->assertEquals(1, $d['waiting_for_rider']);
        $this->assertEquals(1, $d['assigned']);
        $this->assertEquals(1, $d['out_for_delivery']);
        $this->assertEquals(1, $d['delivered']);
        $this->assertEquals(1, $d['failed']);

        // Needs attention reflects waiting + failed.
        $needs = $response->viewData('needsAttention');
        $this->assertEquals(1, $needs['waitingForRider']);
        $this->assertEquals(1, $needs['failed']);
    }

    public function test_online_offline_rider_counts_are_correct(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $this->rider($center, ['is_online' => true]);
        $this->rider($center, ['is_online' => true]);
        $this->rider($center, ['is_online' => false]);

        $response = $this->actingAs($admin)->get(route('dashboard'));
        $r = $response->viewData('riders');
        $this->assertEquals(3, $r['total']);
        $this->assertEquals(2, $r['online']);
        $this->assertEquals(1, $r['offline']);
    }

    public function test_failed_and_unassigned_appear_in_needs_attention(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $this->delivery($center, 'waiting_for_rider');
        $this->delivery($center, 'delivery_failed');

        $response = $this->actingAs($admin)->get(route('dashboard'));
        $needs = $response->viewData('needsAttention');
        $this->assertEquals(1, $needs['waitingForRider']);
        $this->assertEquals(1, $needs['failed']);
        $this->assertSame(1, $response->viewData('deliveries')['failed']);
    }

    public function test_dashboard_does_not_expose_other_centers_data_to_staff(): void
    {
        $centerA = $this->center();
        $centerB = $this->center();
        $staff = $this->user('staff', $centerA);

        $this->delivery($centerA, 'delivered');
        $this->delivery($centerB, 'delivered');

        // Staff sees only center A via the API surface exposed in view data.
        $response = $this->actingAs($staff)->get(route('dashboard'));
        $this->assertEquals(1, $response->viewData('deliveries')['total']);

        // Explicitly assert the other center's records are not present in any view data list.
        foreach (['serviceAreas', 'recent_deliveries'] as $key) {
            $items = $response->viewData($key);
            foreach ($items as $item) {
                $cId = is_array($item) ? ($item['id'] ?? null) : $item->center_id;
                $this->assertNotEquals($centerB->id, $cId);
            }
        }
    }

    public function test_dashboard_renders_correctly_with_no_data(): void
    {
        $admin = $this->user();

        $response = $this->actingAs($admin)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('All current deliveries are progressing normally.');
        $this->assertEquals(0, $response->viewData('deliveries')['total']);
        $this->assertEquals(0, $response->viewData('riders')['total']);
    }

    public function test_financial_today_uses_stored_transaction_values(): void
    {
        $admin = $this->user();
        $center = $this->center();

        $delivery = $this->delivery($center, 'delivered');
        Transaction::create([
            'logistics_center_id' => $center->id,
            'delivery_id' => $delivery->id,
            'tracking_number' => $delivery->tracking_number,
            'amount' => 250.00,
            'rider_fee' => 50.00,
            'admin_commission' => 25.00,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard'));
        $financial = $response->viewData('financialToday');
        $this->assertEquals(1, $financial['count']);
        $this->assertEquals(50.00, (float) $financial['rider_fee']);
        $this->assertEquals(25.00, (float) $financial['commission']);
    }

    public function test_riders_index_supports_online_availability_filter(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $online = $this->rider($center, ['is_online' => true]);
        $this->rider($center, ['is_online' => false]);

        $response = $this->actingAs($admin)->get(route('riders.index', ['online' => 'online']));
        $response->assertOk();
        $response->assertSee($online->name);
        $this->assertCount(1, $response->viewData('riders'));
    }

    public function test_activity_feed_reflects_real_status_logs_within_center_scope(): void
    {
        $centerA = $this->center();
        $centerB = $this->center();
        $staff = $this->user('staff', $centerA);
        $admin = $this->user();

        $dA = $this->delivery($centerA, 'delivered');
        $dB = $this->delivery($centerB, 'delivered');

        DeliveryStatusLog::create(['delivery_id' => $dA->id, 'status' => 'delivered', 'notes' => 'ok']);
        DeliveryStatusLog::create(['delivery_id' => $dB->id, 'status' => 'delivered', 'notes' => 'other center']);

        // Admin sees both activities.
        $adminResponse = $this->actingAs($admin)->get(route('dashboard'));
        $this->assertCount(2, $adminResponse->viewData('activities'));

        // Staff sees only their center's activity.
        $staffResponse = $this->actingAs($staff)->get(route('dashboard'));
        $activities = $staffResponse->viewData('activities');
        $this->assertCount(1, $activities);
        $this->assertEquals($dA->tracking_number, $activities->first()['tracking']);
    }
}
