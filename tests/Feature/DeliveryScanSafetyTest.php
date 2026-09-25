<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryStatusLog;
use App\Models\LogisticsCenter;
use App\Models\User;
use Tests\TestCase;

/**
 * Scan-verify safety around the existing endpoint (no behavior changes):
 * repeated verifies stay idempotent down to the status-log row, tracking
 * input normalizes before lookup, center mismatches carry a clear staff
 * message, and malformed ?expect= values never resolve a parcel hint.
 */
class DeliveryScanSafetyTest extends TestCase
{
    private function admin(): User
    {
        return User::create([
            'name' => 'Scan Safety Admin ' . uniqid(),
            'first_name' => 'Scan',
            'last_name' => 'Safety',
            'sex' => 'male',
            'email' => 'scan-safety-' . uniqid() . '@logistics.com',
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

    private function center(string $tag): LogisticsCenter
    {
        return LogisticsCenter::create([
            'name' => 'Scan Safety ' . $tag . ' ' . uniqid(), 'address' => 'S St',
            'city' => 'S City', 'province' => 'S', 'is_active' => true,
        ]);
    }

    private function staff(LogisticsCenter $center): User
    {
        return User::create([
            'name' => 'Scan Staff ' . uniqid(),
            'first_name' => 'Scan',
            'last_name' => 'Staff',
            'sex' => 'female',
            'email' => 'scan-staff-' . uniqid() . '@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000002',
            'birthday' => '1992-02-02',
            'age' => 33,
            'role' => 'staff',
            'status' => 'active',
            'center_id' => $center->id,
            'email_verified_at' => now(),
        ]);
    }

    private function received(LogisticsCenter $center): Delivery
    {
        return Delivery::create([
            'sender_name' => 'Shop',
            'sender_phone' => '09170000001',
            'sender_address' => 'Shop St',
            'recipient_name' => 'Safety Receiver ' . uniqid(),
            'recipient_phone' => '09170000002',
            'recipient_address' => '2 Receiver Ave',
            'status' => 'waiting_for_rider',
            'parcel_status' => 'received',
            'received_at' => now()->subHour(),
            'center_id' => $center->id,
        ]);
    }

    public function test_double_verify_writes_a_single_scanned_log(): void
    {
        $delivery = $this->received($this->center('A'));

        $first = $this->actingAs($this->admin())
            ->postJson(route('deliveries.scan-verify'), ['tracking_number' => $delivery->tracking_number])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertArrayNotHasKey('already', $first->json());

        $this->actingAs($this->admin())
            ->postJson(route('deliveries.scan-verify'), ['tracking_number' => $delivery->tracking_number])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('already', true);

        $this->assertSame('scanned', $delivery->fresh()->parcel_status);
        $this->assertSame(
            1,
            DeliveryStatusLog::where('delivery_id', $delivery->id)->where('status', 'scanned')->count()
        );
    }

    public function test_tracking_input_normalizes_whitespace_and_case(): void
    {
        $delivery = $this->received($this->center('B'));

        $this->actingAs($this->admin())
            ->postJson(route('deliveries.scan-verify'), [
                'tracking_number' => '  ' . strtolower($delivery->tracking_number) . "\n",
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('delivery.tracking_number', $delivery->tracking_number);
    }

    public function test_center_mismatch_carries_a_clear_staff_message(): void
    {
        $delivery = $this->received($this->center('C'));

        $this->actingAs($this->staff($this->center('D')))
            ->postJson(route('deliveries.scan-verify'), ['tracking_number' => $delivery->tracking_number])
            ->assertForbidden()
            ->assertJsonPath('error', 'forbidden')
            ->assertJsonPath('message', 'This parcel belongs to another logistics center.');

        $this->assertSame('received', $delivery->fresh()->parcel_status);
    }

    public function test_unknown_expect_hint_resolves_no_parcel(): void
    {
        $delivery = $this->received($this->center('E'));

        // Well-formed but unknown tracking: page loads, banner echoes the
        // raw value, and no recipient hint leaks from any parcel.
        $this->actingAs($this->admin())
            ->get(route('deliveries.scan-page', ['expect' => 'TRK-20000101-ZZZZ']))
            ->assertOk()
            ->assertSee('Expected parcel:', false)
            ->assertDontSee($delivery->recipient_name);

        // Malformed expect values are ignored the same safe way.
        $this->actingAs($this->admin())
            ->get(route('deliveries.scan-page', ['expect' => 'not-a-tracking-number']))
            ->assertOk()
            ->assertDontSee($delivery->recipient_name);
    }
}
