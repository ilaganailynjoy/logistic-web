<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryStatusLog;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Delivery vs parcel vs history presentation consistency.
 *
 * Database values are never changed here — only their user-facing labels
 * and visual separation are asserted:
 * - one canonical label per delivery status (shared with the Rider App),
 * - parcel-pipeline and record log events tagged as history (never shown
 *   as a current status),
 * - terminal deliveries expose override only (no forward actions),
 * - the printed waybill carries no dynamic status badge.
 */
class DeliveryStatusPresentationTest extends TestCase
{
    private function admin(): User
    {
        return User::create([
            'name' => 'Status Admin ' . uniqid(),
            'first_name' => 'Status',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'status-admin-' . uniqid() . '@logistics.com',
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

    private function delivery(array $overrides = []): Delivery
    {
        return Delivery::create(array_merge([
            'sender_name' => 'Shop',
            'sender_phone' => '09170000001',
            'sender_address' => 'Shop St',
            'recipient_name' => 'Cust',
            'recipient_phone' => '09170000002',
            'recipient_address' => 'Cust Ave',
            'status' => 'waiting_for_rider',
        ], $overrides));
    }

    public function test_status_badge_renders_one_canonical_label_per_delivery_status(): void
    {
        $expected = [
            'waiting_for_rider' => 'Waiting for Rider',
            'assigned' => 'Assigned',
            'accepted' => 'Accepted',
            'going_to_pickup' => 'Going to Pickup',
            'arrived_at_shop' => 'Arrived at Shop',
            'picked_up' => 'Picked Up',
            'out_for_delivery' => 'Out for Delivery',
            'arrived_at_customer' => 'Arrived at Customer',
            'delivered' => 'Delivered',
            'delivery_failed' => 'Delivery Failed',
            'cancelled' => 'Cancelled',
        ];

        foreach ($expected as $status => $label) {
            $html = Blade::render('<x-status-badge :status="$status" />', ['status' => $status]);

            $this->assertStringContainsString($label, $html, "Label mismatch for {$status}");
        }
    }

    public function test_detail_page_separates_delivery_and_parcel_status_sections(): void
    {
        $html = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $this->delivery()))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Delivery Progress', $html);
        $this->assertStringContainsString('rider-to-customer lifecycle', $html);
        $this->assertStringContainsString('Parcel Processing', $html);
        $this->assertStringContainsString('sorting-center pipeline', $html);
    }

    public function test_timeline_tags_parcel_and_record_events_as_history(): void
    {
        $delivery = $this->delivery(['status' => 'picked_up']);
        DeliveryStatusLog::create(['delivery_id' => $delivery->id, 'status' => 'assigned']);
        DeliveryStatusLog::create(['delivery_id' => $delivery->id, 'status' => 'received']);
        DeliveryStatusLog::create(['delivery_id' => $delivery->id, 'status' => 'sorting_center_handoff']);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>Parcel</span>', $html);
        $this->assertStringContainsString('>Sorting Center</span>', $html);
    }

    public function test_timeline_tags_record_events_as_history(): void
    {
        $delivery = $this->delivery(['status' => 'assigned', 'archived_at' => now()]);
        DeliveryStatusLog::create(['delivery_id' => $delivery->id, 'status' => 'archived']);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>Record</span>', $html);
    }

    public function test_terminal_deliveries_expose_override_without_forward_actions(): void
    {
        foreach (['delivered', 'delivery_failed', 'cancelled'] as $status) {
            $html = (string) $this->actingAs($this->admin())
                ->get(route('deliveries.show', $this->delivery(['status' => $status])))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('Override Status', $html);
            $this->assertStringNotContainsString('Confirm Pickup', $html);
            $this->assertStringNotContainsString('Mark as Out for Delivery', $html);
            $this->assertStringNotContainsString('Mark as Delivered', $html);
        }
    }

    public function test_printed_waybill_carries_no_dynamic_status_badge(): void
    {
        // Fresh waiting delivery: no logs, no proofs, no attempts pill.
        // The only status badge on the page must be the header badge —
        // `bg-current` is unique to the badge component's dot.
        $html = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $this->delivery()))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('WAYBILL / TRACKING', $html);
        $this->assertSame(1, substr_count($html, 'bg-current'));
    }
}
