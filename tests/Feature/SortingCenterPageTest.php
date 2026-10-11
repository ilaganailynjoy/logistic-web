<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\LogisticsCenter;
use App\Models\ServiceArea;
use App\Models\User;
use Tests\TestCase;

/**
 * Standalone Sorting Center hub (/sorting-center).
 *
 * Read-only aggregation over the existing parcel pipeline; mutations reuse
 * the deliveries.* routes (no duplicated logic). Staff are center-scoped
 * at the query level; admins see everything. Returned / re-attempt rows
 * surface here for the failed-delivery next steps.
 */
class SortingCenterPageTest extends TestCase
{
    private function center(string $suffix = ''): LogisticsCenter
    {
        return LogisticsCenter::create([
            'name' => 'Sort Hub ' . $suffix . ' ' . uniqid(),
            'address' => 'S St', 'city' => 'S City', 'province' => 'S',
            'is_active' => true,
        ]);
    }

    private function staff(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Sort Staff ' . uniqid(),
            'first_name' => 'Sort', 'last_name' => 'Staff',
            'sex' => 'male', 'email' => 'sort-staff-' . uniqid() . '@logistics.com',
            'password' => bcrypt('password'), 'phone' => '09000000031',
            'birthday' => '1990-01-01', 'age' => 35,
            'role' => 'staff', 'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    private function parcel(LogisticsCenter $center, string $parcelStatus, array $overrides = []): Delivery
    {
        $d = Delivery::create(array_merge([
            'tracking_number' => 'SCTR-' . strtoupper(uniqid()),
            'sender_name' => 'Shop', 'sender_address' => 'S St',
            'recipient_name' => 'Cust', 'recipient_address' => 'C Ave',
            'status' => 'waiting_for_rider',
            'parcel_status' => $parcelStatus,
            'center_id' => $center->id,
        ], $overrides));
        DeliveryItem::create([
            'delivery_id' => $d->id, 'name' => 'Item', 'quantity' => 1, 'price' => 100,
        ]);

        return $d;
    }

    public function test_page_renders_with_counts_and_rows(): void
    {
        $admin = $this->staff(['role' => 'admin']);
        $center = $this->center('A');
        $incoming = $this->parcel($center, 'pending_arrival');
        $sorted = $this->parcel($center, 'sorted');

        $response = $this->actingAs($admin)->get(route('sorting-center.index'));

        $response->assertOk()
            ->assertSee('Sorting Center', false)
            ->assertSee($incoming->tracking_number, false)
            ->assertSee($sorted->tracking_number, false)
            ->assertSee('Incoming', false)
            ->assertSee('Dispatched', false)
            ->assertSee('Scan Parcel', false);
    }

    public function test_tab_filter_narrows_to_parcel_status(): void
    {
        $admin = $this->staff(['role' => 'admin']);
        $center = $this->center('B');
        $received = $this->parcel($center, 'received');
        $sorted = $this->parcel($center, 'sorted');

        $response = $this->actingAs($admin)
            ->get(route('sorting-center.index', ['tab' => 'received']));

        $response->assertOk()
            ->assertSee($received->tracking_number, false)
            ->assertDontSee($sorted->tracking_number, false);
    }

    public function test_search_narrows_rows(): void
    {
        $admin = $this->staff(['role' => 'admin']);
        $center = $this->center('C');
        $wanted = $this->parcel($center, 'received');
        $other = $this->parcel($center, 'received');

        $response = $this->actingAs($admin)
            ->get(route('sorting-center.index', ['search' => $wanted->tracking_number]));

        $response->assertOk()
            ->assertSee($wanted->tracking_number, false)
            ->assertDontSee($other->tracking_number, false);
    }

    public function test_staff_sees_only_own_center(): void
    {
        $mine = $this->center('Mine');
        $theirs = $this->center('Theirs');
        $staff = $this->staff(['center_id' => $mine->id]);
        $own = $this->parcel($mine, 'received');
        $foreign = $this->parcel($theirs, 'received');

        $response = $this->actingAs($staff)->get(route('sorting-center.index'));

        $response->assertOk()
            ->assertSee($own->tracking_number, false)
            ->assertDontSee($foreign->tracking_number, false);
    }

    public function test_admin_can_filter_by_center(): void
    {
        $admin = $this->staff(['role' => 'admin']);
        $one = $this->center('One');
        $two = $this->center('Two');
        $first = $this->parcel($one, 'scanned');
        $second = $this->parcel($two, 'scanned');

        $response = $this->actingAs($admin)
            ->get(route('sorting-center.index', ['center_id' => $two->id]));

        $response->assertOk()
            ->assertSee($second->tracking_number, false)
            ->assertDontSee($first->tracking_number, false);
    }

    public function test_returned_and_reattempt_tabs_surface_next_steps(): void
    {
        $admin = $this->staff(['role' => 'admin']);
        $center = $this->center('D');
        $returned = $this->parcel($center, 'scanned', [
            'status' => 'delivery_failed',
            'returned_at' => now(),
            'return_reason' => 'Customer refused order',
        ]);
        $reattempt = $this->parcel($center, 'scanned', [
            'status' => 'delivery_failed',
            'reattempt_requested_at' => now(),
        ]);
        $plain = $this->parcel($center, 'scanned');

        $returnedTab = $this->actingAs($admin)
            ->get(route('sorting-center.index', ['tab' => 'returned']));
        $returnedTab->assertOk()
            ->assertSee($returned->tracking_number, false)
            ->assertSee('Returned', false)
            ->assertDontSee($plain->tracking_number, false);

        $reattemptTab = $this->actingAs($admin)
            ->get(route('sorting-center.index', ['tab' => 'reattempt']));
        $reattemptTab->assertOk()
            ->assertSee($reattempt->tracking_number, false)
            ->assertDontSee($plain->tracking_number, false);
    }

    public function test_guest_redirected_and_rider_refused(): void
    {
        $this->get(route('sorting-center.index'))->assertRedirect(route('login'));

        $riderUser = $this->staff([
            'role' => 'rider',
            'email' => 'sort-rider-' . uniqid() . '@test.com',
        ]);
        $this->actingAs($riderUser)->get(route('sorting-center.index'))
            ->assertRedirect(route('login'));
    }

    public function test_sidebar_links_to_hub(): void
    {
        $admin = $this->staff(['role' => 'admin']);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('sorting-center.index'), false);
    }
}
