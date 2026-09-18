<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DeliveryStatusLog;
use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Parcel QR lookup authorization: scanning is lookup ONLY.
 *
 * A rider may retrieve a delivery by tracking_number if and only if
 * delivery.rider_id === authenticated rider id. Scanning never assigns,
 * claims, mutates, or reveals anything about inaccessible deliveries.
 */
class RiderDeliveryLookupTest extends TestCase
{
    private function center(): LogisticsCenter
    {
        return LogisticsCenter::create([
            'name' => 'Lookup Center ' . uniqid(),
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
            'name' => 'Lookup Area ' . uniqid(),
            'is_active' => true,
        ]);
    }

    private function makeRider(LogisticsCenter $center, int $i = 1): array
    {
        $rider = Rider::create([
            'name' => 'Lookup Rider ' . $i . ' ' . uniqid(),
            'email' => 'lookup-rider-' . $i . '-' . uniqid() . '@test.com',
            'phone' => '090000000' . $i,
            'vehicle_type' => 'Motorcycle',
            'license_plate' => 'LKP ' . $i,
            'status' => 'available',
            'center_id' => $center->id,
            'approved_at' => now()->subDays(10),
            'vehicle_verification' => 'verified',
        ]);

        $user = User::create([
            'name' => $rider->name,
            'first_name' => 'Lookup',
            'last_name' => 'Rider',
            'sex' => 'male',
            'email' => $rider->email,
            'password' => bcrypt('password'),
            'phone' => $rider->phone,
            'birthday' => '1995-01-01',
            'age' => 30,
            'role' => 'rider',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $rider->update(['user_id' => $user->id]);

        return ['rider' => $rider, 'user' => $user];
    }

    private function delivery(?Rider $rider, string $status = 'assigned', array $overrides = []): Delivery
    {
        return Delivery::create(array_merge([
            'rider_id' => $rider?->id,
            'sender_name' => 'Lookup Shop',
            'sender_phone' => '09171234567',
            'sender_address' => '1 Lookup St',
            'recipient_name' => 'Lookup Customer',
            'recipient_phone' => '09171234568',
            'recipient_address' => '2 Lookup Ave',
            'status' => $status,
            'payment_method' => 'cash_on_delivery',
            'amount_to_collect' => 500.00,
        ], $overrides));
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    private function lookup(User $user, string $tracking)
    {
        // Guards memoize the resolved user inside one test process, so a
        // previous request's identity would otherwise leak into the next
        // request (production boots fresh per request and is unaffected).
        Auth::forgetGuards();

        return $this->getJson(
            '/api/rider/deliveries/lookup?tracking_number=' . urlencode($tracking),
            $this->headers($user)
        );
    }

    // ── Case 1: assigned to current rider → ALLOW ────────────────

    public function test_assigned_rider_can_look_up_own_delivery(): void
    {
        $center = $this->center();
        $data = $this->makeRider($center);
        $delivery = $this->delivery($data['rider']);
        DeliveryItem::create([
            'delivery_id' => $delivery->id, 'name' => 'Lookup Item',
            'quantity' => 2, 'price' => 100.00,
        ]);

        $response = $this->lookup($data['user'], $delivery->tracking_number);

        $response->assertOk();
        $response->assertJsonPath('delivery.id', $delivery->id);
        $response->assertJsonPath('delivery.tracking_number', $delivery->tracking_number);
        $response->assertJsonPath('delivery.customer.name', 'Lookup Customer');
    }

    // ── Case 2: assigned to another rider → DENY, no leak ────────

    public function test_other_rider_cannot_look_up_assigned_delivery(): void
    {
        $center = $this->center();
        $riderA = $this->makeRider($center, 1);
        $riderB = $this->makeRider($center, 2);
        $delivery = $this->delivery($riderA['rider']);

        $response = $this->lookup($riderB['user'], $delivery->tracking_number);

        $response->assertForbidden();
        $body = $response->getContent();
        $this->assertStringNotContainsString($delivery->tracking_number, $body);
        $this->assertStringNotContainsString('Lookup Customer', $body);
        $this->assertStringNotContainsString('09171234568', $body);
        $this->assertStringNotContainsString('Lookup Shop', $body);
    }

    // ── Case 3: unassigned → DENY, no leak ───────────────────────

    public function test_unassigned_delivery_is_not_revealed(): void
    {
        $center = $this->center();
        $data = $this->makeRider($center);
        $delivery = $this->delivery(null, 'waiting_for_rider');

        $response = $this->lookup($data['user'], $delivery->tracking_number);

        $response->assertForbidden();
        $body = $response->getContent();
        $this->assertStringNotContainsString($delivery->tracking_number, $body);
        $this->assertStringNotContainsString('Lookup Customer', $body);
    }

    // ── Case 4: nonexistent → NOT FOUND, no leak ─────────────────

    public function test_unknown_tracking_number_returns_not_found(): void
    {
        $center = $this->center();
        $data = $this->makeRider($center);

        $response = $this->lookup($data['user'], 'TRK-20000101-ZZZZ');

        $response->assertNotFound();
        $this->assertStringNotContainsString('Lookup Customer', $response->getContent());
    }

    public function test_tracking_number_is_required(): void
    {
        $center = $this->center();
        $data = $this->makeRider($center);

        $this->getJson('/api/rider/deliveries/lookup', $this->headers($data['user']))
            ->assertStatus(422);
    }

    // ── Case 5: archived behaves like show() ─────────────────────

    public function test_archived_delivery_follows_show_authorization(): void
    {
        $center = $this->center();
        $riderA = $this->makeRider($center, 1);
        $riderB = $this->makeRider($center, 2);
        $delivery = $this->delivery($riderA['rider'], 'delivered', ['archived_at' => now()]);

        // Assigned rider: same as show() (no archive exclusion there).
        $this->lookup($riderA['user'], $delivery->tracking_number)->assertOk();

        // Other rider: still denied.
        $this->lookup($riderB['user'], $delivery->tracking_number)->assertForbidden();
    }

    // ── Scanning never mutates ───────────────────────────────────

    public function test_lookup_never_assigns_claims_or_mutates(): void
    {
        $center = $this->center();
        $riderA = $this->makeRider($center, 1);
        $riderB = $this->makeRider($center, 2);

        $unassigned = $this->delivery(null, 'waiting_for_rider');
        $assigned = $this->delivery($riderA['rider'], 'assigned');
        $deliveryCount = Delivery::count();
        $logCount = DeliveryStatusLog::count();
        $originalParcelStatus = $unassigned->fresh()->parcel_status;

        // Allowed lookup changes nothing.
        $this->lookup($riderA['user'], $assigned->tracking_number)->assertOk();
        // Denied lookups change nothing.
        $this->lookup($riderB['user'], $assigned->tracking_number)->assertForbidden();
        $this->lookup($riderA['user'], $unassigned->tracking_number)->assertForbidden();
        $this->lookup($riderA['user'], 'TRK-20000101-ZZZZ')->assertNotFound();

        $this->assertSame($deliveryCount, Delivery::count());
        $this->assertSame($logCount, DeliveryStatusLog::count());
        $this->assertNull($unassigned->fresh()->rider_id);
        $this->assertSame('waiting_for_rider', $unassigned->fresh()->status);
        $this->assertSame($originalParcelStatus, $unassigned->fresh()->parcel_status);
        $this->assertSame($riderA['rider']->id, $assigned->fresh()->rider_id);
        $this->assertSame('assigned', $assigned->fresh()->status);
    }

    public function test_guest_lookup_is_unauthorized(): void
    {
        $this->getJson('/api/rider/deliveries/lookup?tracking_number=TRK-20000101-ZZZZ')
            ->assertUnauthorized();
    }
}
