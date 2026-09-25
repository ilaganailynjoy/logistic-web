<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryStatusLog;
use App\Models\LogisticsCenter;
use App\Models\PickupRequest;
use App\Models\Rider;
use App\Models\ServiceArea;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VehicleType;
use Tests\TestCase;

/**
 * Delivery Details page sections: the existing reassignment UI, the
 * pickup-request display, and the payment/transaction display. Viewing
 * the page is strictly read-only — no records are created.
 */
class DeliveryDetailsSectionsTest extends TestCase
{
    private function admin(): User
    {
        return User::create([
            'name' => 'Details Admin ' . uniqid(),
            'first_name' => 'Details',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'details-admin-' . uniqid() . '@logistics.com',
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

    private function center(): LogisticsCenter
    {
        return LogisticsCenter::create([
            'name' => 'Details Center ' . uniqid(), 'address' => 'D St',
            'city' => 'D City', 'province' => 'D', 'is_active' => true,
        ]);
    }

    private function area(LogisticsCenter $center): ServiceArea
    {
        return ServiceArea::create([
            'logistics_center_id' => $center->id,
            'name' => 'Details Area ' . uniqid(),
            'is_active' => true,
        ]);
    }

    private function eligibleRider(LogisticsCenter $center, ?ServiceArea $area = null): Rider
    {
        VehicleType::updateOrCreate(
            ['name' => 'motorcycle'],
            ['label' => 'Motorcycle', 'capacity_kg' => 30, 'is_active' => true, 'sort_order' => 1],
        );

        return Rider::create([
            'name' => 'Details Rider ' . uniqid(),
            'email' => 'details-rider-' . uniqid() . '@test.com',
            'phone' => '09000000004',
            'vehicle_type' => 'motorcycle',
            'license_plate' => 'DET ' . random_int(1, 9999),
            'status' => 'available',
            'is_online' => true,
            'vehicle_verification' => 'verified',
            'approved_at' => now()->subDay(),
            'center_id' => $center->id,
            'service_area_id' => $area?->id,
        ]);
    }

    private function delivery(array $overrides = []): Delivery
    {
        return Delivery::create(array_merge([
            'sender_name' => 'Shop', 'sender_phone' => '09171234567',
            'sender_address' => '1 Shop St', 'recipient_name' => 'Cust',
            'recipient_phone' => '09171234568', 'recipient_address' => '2 Cust Ave',
            'status' => 'waiting_for_rider',
        ], $overrides));
    }

    private function showPage(Delivery $delivery): string
    {
        return (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->getContent();
    }

    // ── Reassignment UI (existing backend, exposed in view) ───────

    public function test_reassign_form_shown_for_assigned_with_current_and_eligible_riders(): void
    {
        $center = $this->center();
        $current = $this->eligibleRider($center);
        $candidate = $this->eligibleRider($center);
        $delivery = $this->delivery(['rider_id' => $current->id, 'status' => 'assigned']);

        $html = $this->showPage($delivery);

        $this->assertStringContainsString('Reassign Rider', $html);
        $this->assertStringContainsString($current->name, $html);
        $this->assertStringContainsString($candidate->name, $html);
        $this->assertStringContainsString('action="' . route('deliveries.assign-rider', $delivery) . '"', $html);
        $this->assertStringNotContainsString('Retry — Assign Rider', $html);
    }

    public function test_retry_form_shown_for_failed_deliveries(): void
    {
        $delivery = $this->delivery(['status' => 'delivery_failed']);

        $html = $this->showPage($delivery);

        $this->assertStringContainsString('Retry — Assign Rider', $html);
        $this->assertStringContainsString('Assign &amp; Retry', $html);
        $this->assertStringNotContainsString('Reassign Rider', $html);
    }

    public function test_no_reassign_form_for_waiting_or_terminal_statuses(): void
    {
        $waiting = $this->showPage($this->delivery(['status' => 'waiting_for_rider']));
        $this->assertStringContainsString('Assign Rider', $waiting);
        $this->assertStringNotContainsString('Reassign Rider', $waiting);
        $this->assertStringNotContainsString('Assign &amp; Retry', $waiting);

        $delivered = $this->showPage($this->delivery(['status' => 'delivered']));
        $this->assertStringNotContainsString('Reassign Rider', $delivered);
        $this->assertStringNotContainsString('Retry — Assign Rider', $delivered);
    }

    // ── Pickup information ────────────────────────────────────────

    public function test_pickup_request_displayed_when_present(): void
    {
        $delivery = $this->delivery();
        PickupRequest::create([
            'delivery_id' => $delivery->id,
            'status' => 'approved',
            'requested_at' => now()->subHour(),
            'reviewed_at' => now(),
            'reviewed_by' => $this->admin()->id,
        ]);

        $html = $this->showPage($delivery);

        $this->assertStringContainsString('Pickup Request', $html);
        $this->assertStringContainsString('Approved', $html);
        $this->assertStringContainsString(
            $delivery->pickupRequest->requested_at->format('M d, Y h:i A'),
            $html
        );
    }

    public function test_pickup_rejection_reason_displayed(): void
    {
        $delivery = $this->delivery();
        PickupRequest::create([
            'delivery_id' => $delivery->id,
            'status' => 'rejected',
            'requested_at' => now()->subHour(),
            'rejection_reason' => 'Shop was closed',
        ]);

        $html = $this->showPage($delivery);

        $this->assertStringContainsString('Rejected', $html);
        $this->assertStringContainsString('Shop was closed', $html);
    }

    public function test_pickup_fallback_when_absent(): void
    {
        $html = $this->showPage($this->delivery());

        $this->assertStringContainsString('No pickup request for this delivery.', $html);
    }

    // ── Payment / transaction information ─────────────────────────

    public function test_payment_and_transaction_displayed_when_present(): void
    {
        $delivery = $this->delivery([
            'payment_method' => 'cash_on_delivery',
            'amount_to_collect' => 1250.50,
        ]);
        Transaction::create([
            'delivery_id' => $delivery->id,
            'tracking_number' => $delivery->tracking_number,
            'amount' => 1300.00,
            'rider_fee' => 50.00,
            'admin_commission' => 25.00,
            'status' => 'completed',
        ]);

        $html = $this->showPage($delivery);

        $this->assertStringContainsString('Payment &amp; Transaction', $html);
        $this->assertStringContainsString('CASH_ON_DELIVERY', $html);
        $this->assertStringContainsString('₱1,250.50', $html);
        $this->assertStringContainsString('₱1,300.00', $html);
        $this->assertStringContainsString('₱50.00', $html);
        $this->assertStringContainsString('₱25.00', $html);
        $this->assertStringContainsString('Completed', $html);
    }

    public function test_transaction_fallback_when_absent(): void
    {
        $html = $this->showPage($this->delivery());

        $this->assertStringContainsString('No transaction recorded yet.', $html);
    }

    // ── Status actions coverage (no new transitions added) ───────

    public function test_status_actions_match_supported_transitions(): void
    {
        $assigned = $this->showPage($this->delivery(['status' => 'assigned']));
        $this->assertStringContainsString('Confirm Pickup', $assigned);

        $pickedUp = $this->showPage($this->delivery(['status' => 'picked_up']));
        $this->assertStringContainsString('Mark as Out for Delivery', $pickedUp);

        $outForDelivery = $this->showPage($this->delivery(['status' => 'out_for_delivery']));
        $this->assertStringContainsString('Mark as Delivered', $outForDelivery);
        $this->assertStringContainsString('Mark as Failed', $outForDelivery);
    }

    // ── Viewing is read-only ──────────────────────────────────────

    public function test_viewing_details_creates_no_records(): void
    {
        $delivery = $this->delivery(['status' => 'assigned']);
        $counts = fn () => [
            Delivery::count(),
            DeliveryStatusLog::count(),
        ];

        $before = $counts();
        $this->showPage($delivery);
        $this->assertSame($before, $counts());
    }
}
