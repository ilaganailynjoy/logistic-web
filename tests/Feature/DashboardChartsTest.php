<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\LogisticsCenter;
use App\Models\ServiceArea;
use App\Models\User;
use Tests\TestCase;
use Carbon\Carbon;

class DashboardChartsTest extends TestCase
{
    private function user(string $role = 'admin', ?LogisticsCenter $center = null): User
    {
        return User::create([
            'name' => 'Chart '.$role.' '.uniqid(),
            'first_name' => 'Chart',
            'last_name' => ucfirst($role),
            'sex' => 'male',
            'email' => 'chart-'.$role.'-'.uniqid().'@logistics.com',
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

    private function center(string $suffix = ''): LogisticsCenter
    {
        return LogisticsCenter::create([
            'name' => 'Chart Center '.$suffix.' '.uniqid(),
            'address' => 'Test St',
            'city' => 'Test City',
            'province' => 'Test',
            'is_active' => true,
        ]);
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
            'center_id' => $center->id,
            'parcel_status' => 'pending_arrival',
        ], $overrides));
    }

    public function test_status_chart_data_reflects_real_counts(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $this->delivery($center, 'waiting_for_rider');
        $this->delivery($center, 'waiting_for_rider');
        $this->delivery($center, 'delivered');
        $this->delivery($center, 'delivery_failed');

        $response = $this->actingAs($admin)->get(route('dashboard'));
        $response->assertOk();

        $chart = $response->viewData('statusChart');
        $this->assertNotNull($chart);
        $this->assertEquals(4, $chart['total']);
        $this->assertContains('Waiting for Rider', $chart['labels']);
        $this->assertContains('Delivered', $chart['labels']);
        $this->assertContains('Delivery Failed', $chart['labels']);
        // Zero-value statuses must not appear
        $this->assertNotContains('Assigned', $chart['labels']);
        $this->assertCount(count($chart['labels']), $chart['data']);
        $this->assertCount(count($chart['labels']), $chart['colors']);
        $this->assertEquals(array_sum($chart['data']), $chart['total']);
    }

    public function test_status_chart_empty_dataset(): void
    {
        $admin = $this->user();
        $response = $this->actingAs($admin)->get(route('dashboard'));
        $response->assertOk();
        $chart = $response->viewData('statusChart');
        $this->assertEquals(0, $chart['total']);
        $this->assertEmpty($chart['labels']);
        $this->assertEmpty($chart['data']);
    }

    public function test_trends_chart_data_is_ordered_and_counts_correctly(): void
    {
        $admin = $this->user();
        $center = $this->center();

        // Create deliveries on specific dates
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();
        $twoDaysAgo = Carbon::today()->subDays(2);

        $this->delivery($center, 'waiting_for_rider', ['created_at' => $today, 'updated_at' => $today]);
        $this->delivery($center, 'waiting_for_rider', ['created_at' => $yesterday, 'updated_at' => $yesterday]);
        $this->delivery($center, 'delivered', ['created_at' => $twoDaysAgo, 'delivered_at' => $today, 'updated_at' => $today]);
        $this->delivery($center, 'delivery_failed', ['created_at' => $today, 'failed_at' => $today, 'updated_at' => $today]);

        $response = $this->actingAs($admin)->get(route('dashboard', ['trends_range' => 7]));
        $response->assertOk();
        $trends = $response->viewData('trendsChart');
        $this->assertCount(7, $trends['labels']);
        $this->assertCount(7, $trends['dates']);
        $this->assertCount(7, $trends['created']);
        $this->assertCount(7, $trends['delivered']);
        $this->assertCount(7, $trends['failed']);

        // Dates must be chronological
        $dates = $trends['dates'];
        $sorted = $dates;
        sort($sorted);
        $this->assertEquals($sorted, $dates);

        // Total created in last 7 days should be 4
        $this->assertEquals(4, array_sum($trends['created']));
        // Delivered today should be 1
        $todayLabel = $today->format('M j');
        $todayIdx = array_search($todayLabel, $trends['labels'], true);
        $this->assertNotFalse($todayIdx);
        $this->assertEquals(1, $trends['delivered'][$todayIdx]);
        $this->assertEquals(1, $trends['failed'][$todayIdx]);
    }

    public function test_trends_chart_supports_30_days_and_defaults_to_7(): void
    {
        $admin = $this->user();
        $response7 = $this->actingAs($admin)->get(route('dashboard'));
        $this->assertEquals(7, $response7->viewData('trendsRange'));
        $this->assertCount(7, $response7->viewData('trendsChart')['labels']);

        $response30 = $this->actingAs($admin)->get(route('dashboard', ['trends_range' => 30]));
        $response30->assertOk();
        $this->assertEquals(30, $response30->viewData('trendsRange'));
        $this->assertCount(30, $response30->viewData('trendsChart')['labels']);

        // Invalid range defaults to 7
        $responseInvalid = $this->actingAs($admin)->get(route('dashboard', ['trends_range' => 'invalid']));
        $this->assertEquals(7, $responseInvalid->viewData('trendsRange'));
    }

    public function test_center_performance_admin_sees_all_centers(): void
    {
        $admin = $this->user();
        $centerA = $this->center('A');
        $centerB = $this->center('B');
        $this->delivery($centerA, 'waiting_for_rider');
        $this->delivery($centerA, 'delivered');
        $this->delivery($centerB, 'delivery_failed');
        $this->delivery($centerB, 'waiting_for_rider');

        $response = $this->actingAs($admin)->get(route('dashboard'));
        $response->assertOk();
        $chart = $response->viewData('centerPerformanceChart');
        $this->assertContains($centerA->name, $chart['labels']);
        $this->assertContains($centerB->name, $chart['labels']);
        $this->assertFalse($chart['empty']);
        // Total per center
        $idxA = array_search($centerA->name, $chart['labels'], true);
        $this->assertEquals(2, $chart['total'][$idxA]);
        $this->assertEquals(1, $chart['delivered'][$idxA]);
        $idxB = array_search($centerB->name, $chart['labels'], true);
        $this->assertEquals(1, $chart['failed'][$idxB]);
    }

    public function test_center_performance_staff_sees_only_own_center(): void
    {
        $centerA = $this->center('A');
        $centerB = $this->center('B');
        $staff = $this->user('staff', $centerA);
        $this->delivery($centerA, 'waiting_for_rider');
        $this->delivery($centerA, 'delivered');
        $this->delivery($centerB, 'delivered');
        $this->delivery($centerB, 'delivery_failed');

        $response = $this->actingAs($staff)->get(route('dashboard'));
        $response->assertOk();
        $chart = $response->viewData('centerPerformanceChart');
        $this->assertCount(1, $chart['labels']);
        $this->assertEquals($centerA->name, $chart['labels'][0]);
        $this->assertNotContains($centerB->name, $chart['labels']);
        $this->assertEquals(2, $chart['total'][0]);
        $this->assertEquals(1, $chart['delivered'][0]);
    }

    public function test_center_performance_empty_state(): void
    {
        // No deliveries, but centers exist
        $admin = $this->user();
        $this->center('Empty');

        $response = $this->actingAs($admin)->get(route('dashboard'));
        $response->assertOk();
        $chart = $response->viewData('centerPerformanceChart');
        $this->assertTrue($chart['empty']);
        // Labels still present (centers) but all zeros
        $this->assertNotEmpty($chart['labels']);

        // No centers at all
        \Illuminate\Support\Facades\DB::table('logistics_centers')->delete();
        \Illuminate\Support\Facades\DB::table('deliveries')->delete();
        $response2 = $this->actingAs($admin)->get(route('dashboard'));
        $chart2 = $response2->viewData('centerPerformanceChart');
        $this->assertTrue($chart2['empty']);
        $this->assertEmpty($chart2['labels']);
    }

    public function test_staff_with_no_center_sees_all_centers_like_admin(): void
    {
        // Staff without center_id should not be scoped (fallback to admin behavior)
        $centerA = $this->center('A');
        $centerB = $this->center('B');
        $staffNoCenter = $this->user('staff', null);
        $this->delivery($centerA, 'delivered');
        $this->delivery($centerB, 'delivered');

        $response = $this->actingAs($staffNoCenter)->get(route('dashboard'));
        $response->assertOk();
        $chart = $response->viewData('centerPerformanceChart');
        // Should see both centers (no scoping)
        $this->assertCount(2, $chart['labels']);
    }
}
