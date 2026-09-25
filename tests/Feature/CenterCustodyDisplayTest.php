<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\User;
use Tests\TestCase;

/**
 * Sorting-center custody visibility on the delivery detail page.
 *
 * Staff can see not just when the parcel reached / left the center, but
 * which rider handed it over and which rider picked it up again — read
 * from the existing handoff/pickup relations. Delivery status (rider
 * lifecycle) and parcel status (center pipeline) stay separate.
 */
class CenterCustodyDisplayTest extends TestCase
{
    private function admin(): User
    {
        return User::create([
            'name' => 'Custody Admin ' . uniqid(),
            'first_name' => 'Custody',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'custody-admin-' . uniqid() . '@logistics.com',
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

    private function rider(string $tag): Rider
    {
        return Rider::create([
            'name' => 'Custody Rider ' . $tag . ' ' . uniqid(),
            'email' => 'custody-rider-' . $tag . '-' . uniqid() . '@test.com',
            'phone' => '09000000009',
            'vehicle_type' => 'Motorcycle',
            'license_plate' => 'CUS ' . $tag,
            'status' => 'available',
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
            'status' => 'picked_up',
        ], $overrides));
    }

    public function test_handoff_rider_and_time_are_shown(): void
    {
        $center = LogisticsCenter::create([
            'name' => 'Custody Center ' . uniqid(), 'address' => 'C St',
            'city' => 'C City', 'province' => 'C', 'is_active' => true,
        ]);
        $handoffer = $this->rider('H');
        $delivery = $this->delivery([
            'center_id' => $center->id,
            'parcel_status' => 'received',
            'received_at' => now()->subHours(3),
            'sorting_center_handoff_at' => now()->subHours(2),
            'sorting_center_handoff_rider_id' => $handoffer->id,
        ]);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Handed to Center:', $html);
        $this->assertStringContainsString($handoffer->name, $html);
        $this->assertStringContainsString(
            $delivery->fresh()->sorting_center_handoff_at->format('M d, Y h:i A'),
            $html
        );
        $this->assertStringContainsString('Handling Center:', $html);
        $this->assertStringContainsString($center->name, $html);
    }

    public function test_pickup_rider_and_time_are_shown(): void
    {
        $pickuper = $this->rider('P');
        $delivery = $this->delivery([
            'parcel_status' => 'dispatched',
            'sorting_center_handoff_at' => now()->subHours(4),
            'sorting_center_pickup_at' => now()->subHour(),
            'sorting_center_pickup_rider_id' => $pickuper->id,
            'dispatched_at' => now()->subHour(),
        ]);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Picked from Center:', $html);
        $this->assertStringContainsString($pickuper->name, $html);
        $this->assertStringContainsString(
            $delivery->fresh()->sorting_center_pickup_at->format('M d, Y h:i A'),
            $html
        );
    }

    public function test_custody_rows_hidden_without_handoff_or_pickup(): void
    {
        $delivery = $this->delivery(['parcel_status' => 'pending_arrival']);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Handed to Center:', $html);
        $this->assertStringNotContainsString('Picked from Center:', $html);
    }
}
