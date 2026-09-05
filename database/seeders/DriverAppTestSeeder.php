<?php

namespace Database\Seeders;

use App\Http\Controllers\TransactionController;
use App\Models\Delivery;
use App\Models\DeliveryFailure;
use App\Models\DeliveryItem;
use App\Models\DeliveryStatusLog;
use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\RiderEarning;
use App\Models\RiderApplication;
use App\Models\RiderNotification;
use App\Models\ServiceArea;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Driver App Integration Test Dataset (idempotent).
 *
 * Seeds a fully working rider login for the existing Flutter Driver_app and the
 * actual Logistics Laravel system — using ONLY the existing tables, models,
 * relationships and API.
 *
 *   Email:    driver.test@invoiz.test
 *   Password: password
 *
 * It reuses existing logistics data (Laguna Logistics Hub + Santa Cruz service
 * area) where possible, and creates clearly-identifiable TEST deliveries,
 * transactions, earnings and notifications for that rider.
 *
 * The seeder is idempotent: re-running it never creates duplicate users /
 * riders / center-tracked data. It only fills in missing test deliveries per
 * deterministic tracking numbers.
 */
class DriverAppTestSeeder extends Seeder
{
    /** Test rider credentials. */
    public const TEST_EMAIL = 'driver.test@invoiz.test';
    public const TEST_PASSWORD = 'password';

    private User $user;
    private Rider $rider;
    private LogisticsCenter $center;
    private ServiceArea $area;

    public function run(): void
    {
        // 1) Reuse existing active logistics center + service area (Laguna Hub /
        //    Santa Cruz) or create clearly-named test records if absent.
        $this->center = LogisticsCenter::firstOrCreate(
            ['name' => 'Laguna Logistics Hub'],
            [
                'name' => 'Laguna Logistics Hub',
                'address' => 'National Highway, Brgy. San Antonio',
                'city' => 'Santa Cruz',
                'province' => 'Laguna',
                'phone' => '09171234567',
                'is_active' => true,
            ],
        );

        $this->area = ServiceArea::firstOrCreate(
            ['logistics_center_id' => $this->center->id, 'name' => 'Santa Cruz'],
            [
                'name' => 'Santa Cruz',
                'description' => 'Poblacion and surrounding barangays of Santa Cruz, Laguna (test).',
                'is_active' => true,
            ],
        );

        // 2) Test login user (role=rider, active) + linked rider profile.
        $this->user = User::updateOrCreate(
            ['email' => self::TEST_EMAIL],
            [
                'name' => 'Test Rider',
                'first_name' => 'Test',
                'last_name' => 'Rider',
                'middle_initial' => null,
                'sex' => 'male',
                'password' => Hash::make(self::TEST_PASSWORD),
                'phone' => '09170000001',
                'birthday' => '1995-01-15',
                'age' => 31,
                'province' => 'Laguna',
                'municipality' => 'Santa Cruz',
                'barangay' => 'Poblacion',
                'address_line' => 'Test Rider Drive, Poblacion',
                'role' => 'rider',
                'status' => 'active',
                'center_id' => $this->center->id,
                'email_verified_at' => now(),
            ],
        );

        $this->rider = Rider::updateOrCreate(
            ['email' => self::TEST_EMAIL],
            [
                'user_id' => $this->user->id,
                'center_id' => $this->center->id,
                'service_area_id' => $this->area->id,
                'name' => 'Test Rider',
                'phone' => '09170000001',
                'vehicle_type' => 'Motorcycle',
                'license_plate' => 'TST 0001',
                'vehicle_capacity_kg' => 30.00,
                'vehicle_verification' => 'verified',
                'vehicle_verified_at' => now()->subDays(30),
                'status' => 'available',
                'is_verified' => true,
                'approved_at' => now()->subDays(30),
                'is_online' => false,
            ],
        );

        // 3) Test deliveries across the full Rider state machine.
        $this->seedDeliveries();

        // 4) Test rider notifications.
        $this->seedNotifications();

        // 5) Vehicle types + rider-application fixtures for the apply/status flow.
        $this->call(VehicleTypeSeeder::class);
        $this->seedApplications();

        $this->command?->info('Driver App test data seeded:');
        $this->command?->info("  User ID   : {$this->user->id}  ({$this->user->email})");
        $this->command?->info("  Rider ID  : {$this->rider->id}");
        $this->command?->info("  Center    : {$this->center->id} - {$this->center->name}");
        $this->command?->info("  Area      : {$this->area->id} - {$this->area->name}");
    }

    /**
     * Fixture rider-applications for the mobile apply / status / admin-review
     * flow, one per reviewable status. Idempotent via email.
     */
    private function seedApplications(): void
    {
        $apps = [
            [
                'email' => 'apply.pending@invoiz.test',
                'rider_type' => 'full_time',
                'vehicle_ownership' => 'borrowed',
                'status' => 'pending',
                'notes' => null,
                'reviewed_at' => null,
            ],
            [
                'email' => 'apply.approved@invoiz.test',
                'rider_type' => 'part_time',
                'vehicle_ownership' => 'financing',
                'status' => 'approved',
                'notes' => 'All documents verified.',
                'reviewed_at' => now()->subDays(1),
            ],
            [
                'email' => 'apply.rejected@invoiz.test',
                'rider_type' => 'full_time',
                'vehicle_ownership' => 'own',
                'status' => 'rejected',
                'notes' => 'Missing valid driver\'s license document.',
                'reviewed_at' => now()->subDays(1),
            ],
        ];

        foreach ($apps as $app) {
            RiderApplication::updateOrCreate(
                ['email' => $app['email']],
                [
                    'name' => 'Fixture Applicant',
                    'phone' => '0917888999' . substr($app['email'], 6, 3),
                    'address' => 'Fixture Lane, Poblacion, Santa Cruz, Laguna',
                    'vehicle_type' => 'motorcycle',
                    'license_plate' => 'FIX '.substr(strtoupper(md5($app['email'])), 0, 4),
                    'license_number' => 'LIC-'.substr(strtoupper(md5($app['email'])), 0, 6),
                    'vehicle_registration' => 'REG-'.substr(strtoupper(md5($app['email'])), 0, 6),
                    'rider_type' => $app['rider_type'],
                    'vehicle_ownership' => $app['vehicle_ownership'],
                    'submitted_via' => 'mobile',
                    'status' => $app['status'],
                    'notes' => $app['notes'],
                    'reviewed_at' => $app['reviewed_at'],
                ],
            );
        }
    }

    /**
     * Create one delivery per supported Rider state, all assigned to the test
     * rider (so the Rider API authorization rules pass).
     *
     * Each fixture is identified by a DETERMINISTIC, UNIQUE tracking number
     * (e.g. DVRTEST-ASSIGNED-0001). Re-running the seeder finds the fixture by
     * that tracking number — NOT by its mutable status — so it always reuses
     * the same delivery and never creates a duplicate record. If a live demo /
     * test moved a fixture through the state machine, this re-syncs it back to
     * its canonical state so every state stays represented under one stable
     * tracking number.
     *
     * Older seeder versions generated "DVRTEST-<tag>-0001-<uniqid>" numbers.
     * Those non-deterministic records are the source of the visible duplicate
     * delivery numbers; they are reconciled (removed) below.
     */
    private function seedDeliveries(): void
    {
        $senders = [
            'Test Electronics Shop' => ['09178881001', '1 Test Ave, Santa Cruz, Laguna'],
            'Test Grocery Mart' => ['09178881002', '22 Test Road, Pagsanjan, Laguna'],
            'Test Apparel Co' => ['09178881003', '33 Test Street, Los Baños, Laguna'],
        ];
        $recipients = [
            'Ramon Test' => ['09178881011', '10 Customer St, Santa Cruz, Laguna'],
            'Liza Test' => ['09178881012', '11 Customer Ave, Pagsanjan, Laguna'],
            'Marco Test' => ['09178881013', '12 Customer Rd, Los Baños, Laguna'],
        ];

        // Canonical fixtures. Each tracking number is unique + deterministic.
        $states = [
            'assigned' => ['accepted_at' => null, 'picked_up_at' => null, 'delivered_at' => null, 'failed_at' => null, 'failure_reason' => null],
            'accepted' => ['accepted_at' => now()->subMinutes(5), 'picked_up_at' => null, 'delivered_at' => null, 'failed_at' => null, 'failure_reason' => null],
            'picked_up' => ['accepted_at' => now()->subMinutes(20), 'picked_up_at' => now()->subMinutes(10), 'delivered_at' => null, 'failed_at' => null, 'failure_reason' => null],
            'out_for_delivery' => ['accepted_at' => now()->subMinutes(30), 'picked_up_at' => now()->subMinutes(15), 'delivered_at' => null, 'failed_at' => null, 'failure_reason' => null],
            'delivered' => ['accepted_at' => now()->subDay()->subHour(), 'picked_up_at' => now()->subDay(), 'delivered_at' => now()->subHours(5), 'failed_at' => null, 'failure_reason' => null],
            'delivery_failed' => ['accepted_at' => now()->subDay()->subHours(2), 'picked_up_at' => now()->subDay()->subHour(), 'delivered_at' => null, 'failed_at' => now()->subHour(), 'failure_reason' => 'Recipient not reachable after multiple attempts'],
        ];

        foreach ($states as $status => $times) {
            $tag = strtoupper(str_replace('_', '-', $status));
            // Stable, unique tracking number — no uniqid() suffix.
            $tracking = "DVRTEST-$tag-0001";

            $delivery = Delivery::firstOrCreate(
                ['tracking_number' => $tracking],
                [
                    'rider_id' => $this->rider->id,
                    'center_id' => $this->center->id,
                    'destination_center_id' => $this->center->id,
                    'service_area_id' => $this->area->id,
                    'status' => $status,
                    'sender_name' => array_keys($senders)[0],
                    'sender_phone' => $senders[array_keys($senders)[0]][0],
                    'sender_address' => $senders[array_keys($senders)[0]][1],
                    'recipient_name' => array_keys($recipients)[0],
                    'recipient_phone' => $recipients[array_keys($recipients)[0]][0],
                    'recipient_address' => $recipients[array_keys($recipients)[0]][1],
                    'sender_lat' => 14.2790,
                    'sender_lng' => 121.4320,
                    'recipient_lat' => 14.2830,
                    'recipient_lng' => 121.4350,
                    'weight' => 2.50,
                    'notes' => 'Driver App test delivery (' . $status . ').',
                    'delivery_notes' => 'Leave with neighbor if unavailable.',
                    'package_type' => 'parcel',
                    'package_description' => 'Test package for Driver App integration.',
                    'priority' => 'normal',
                    'parcel_status' => 'sorted',
                    'payment_method' => 'cash_on_delivery',
                    'amount_to_collect' => $status === 'delivered' ? 1250.00 : 500.00,
                    'delivery_fee' => 80.00,
                    'estimated_delivery_at' => now()->addDay(),
                    'assigned_at' => $status === 'assigned' ? now()->subMinutes(15) : now()->subHour(),
                    'accepted_at' => $times['accepted_at'],
                    'picked_up_at' => $times['picked_up_at'],
                    'delivered_at' => $times['delivered_at'],
                    'failed_at' => $times['failed_at'],
                    'failure_reason' => $times['failure_reason'],
                    'created_by' => User::where('role', 'admin')->value('id'),
                    'received_at' => now()->subDay()->subHours(6),
                    'scanned_at' => now()->subDay()->subHours(5),
                    'sorted_at' => now()->subDay()->subHours(4),
                ],
            );

            // Ensure it stays assigned to the test rider even on re-runs and is
            // re-synced to its canonical state (so a moved fixture is reused,
            // never duplicated). Status logs are reset too: without this, a
            // fixture previously driven through the full workflow would keep
            // stale future logs (e.g. delivered) while showing as assigned.
            $delivery->update(['rider_id' => $this->rider->id, 'status' => $status]);
            DeliveryStatusLog::where('delivery_id', $delivery->id)->delete();

            $this->ensureChildrenFor($delivery, $status, $times);
        }

        // Second delivered fixture with its own unique tracking number. This
        // demonstrates two distinct delivered deliveries (e.g. the same product
        // bought twice -> two orders -> two deliveries with different
        // identifiers), rather than reusing/duplicating one number.
        $this->ensureSecondDeliveredFixture($states['delivered']);

        $this->reconcileLegacyDuplicates();
    }

    /**
     * Create a second delivered delivery that carries its own deterministic,
     * unique tracking number (DVRTEST-DELIVERED-0002).
     */
    private function ensureSecondDeliveredFixture(array $times): void
    {
        $tracking = 'DVRTEST-DELIVERED-0002';
        $delivery = Delivery::firstOrCreate(
            ['tracking_number' => $tracking],
            [
                'rider_id' => $this->rider->id,
                'center_id' => $this->center->id,
                'destination_center_id' => $this->center->id,
                'service_area_id' => $this->area->id,
                'status' => 'delivered',
                'sender_name' => 'Test Apparel Co',
                'sender_phone' => '09178881003',
                'sender_address' => '33 Test Street, Los Baños, Laguna',
                'recipient_name' => 'Marco Test',
                'recipient_phone' => '09178881013',
                'recipient_address' => '12 Customer Rd, Los Baños, Laguna',
                'sender_lat' => 14.2890,
                'sender_lng' => 121.4380,
                'recipient_lat' => 14.2920,
                'recipient_lng' => 121.4410,
                'weight' => 3.10,
                'notes' => 'Driver App test delivery (delivered #2).',
                'delivery_notes' => 'Call on arrival.',
                'package_type' => 'parcel',
                'package_description' => 'Second delivered test package.',
                'priority' => 'normal',
                'parcel_status' => 'sorted',
                'payment_method' => 'cash_on_delivery',
                'amount_to_collect' => 1300.00,
                'delivery_fee' => 80.00,
                'estimated_delivery_at' => now()->addDay(),
                'assigned_at' => $times['assigned_at'] ?? now()->subDay()->subHours(3),
                'accepted_at' => $times['accepted_at'],
                'picked_up_at' => $times['picked_up_at'],
                'delivered_at' => $times['delivered_at'],
                'failed_at' => null,
                'failure_reason' => null,
                'created_by' => User::where('role', 'admin')->value('id'),
                'received_at' => now()->subDay()->subHours(7),
                'scanned_at' => now()->subDay()->subHours(6),
                'sorted_at' => now()->subDay()->subHours(5),
            ],
        );

        $this->ensureChildrenFor($delivery, 'delivered', $times);
    }

    /**
     * Remove the duplicate deliveries created by the previous non-deterministic
     * seeder ("DVRTEST-<tag>-0001-<uniqid>"). These share the readable
     * DVRTEST-*-0001 identity with a canonical fixture and are the source of the
     * reported duplicate delivery numbers. Canonical fixtures are untouched.
     */
    private function reconcileLegacyDuplicates(): void
    {
        $legacy = Delivery::where('tracking_number', 'regexp', '^DVRTEST-[\\w-]+-0001-[a-f0-9]+$')
            ->get();
        foreach ($legacy as $d) {
            $d->delete();
        }
    }

    /**
     * Ensure a test delivery has its items, status history and (for terminal
     * states) the earnings / transaction / failure records it needs.
     *
     * The transaction for a delivered delivery is generated through the real
     * Logistics service (TransactionController::storeForDelivery) and the
     * earning is recorded exactly as the Rider completion flow does — so no
     * business logic is duplicated here.
     */
    private function ensureChildrenFor(Delivery $delivery, string $status, array $times): void
    {
        // One item so the Driver_app item list renders.
        DeliveryItem::firstOrCreate(
            [
                'delivery_id' => $delivery->id,
                'name' => 'Test Product',
            ],
            [
                'variant_label' => 'Standard',
                'quantity' => 2,
                'price' => 250.00,
            ],
        );

        // Status history so the app detail view has logs.
        $this->ensureLog($delivery->id, 'assigned', 'Assigned to Test Rider');

        if (in_array($status, ['accepted', 'picked_up', 'out_for_delivery', 'delivered', 'delivery_failed'])) {
            $this->ensureLog($delivery->id, 'accepted', 'Delivery accepted by rider.');
        }
        if (in_array($status, ['picked_up', 'out_for_delivery', 'delivered', 'delivery_failed'])) {
            $this->ensureLog($delivery->id, 'picked_up', 'Package picked up from shop.');
        }
        if (in_array($status, ['out_for_delivery', 'delivered'])) {
            $this->ensureLog($delivery->id, 'out_for_delivery', 'Out for delivery.');
        }

        // Terminal states use the existing application flows:
        if ($status === 'delivered') {
            $this->ensureLog($delivery->id, 'delivered', 'Package delivered to customer.');
            // Generate the transaction (₱15 fee + 10% commission) via the
            // real Logistics service, and record the earning exactly as the
            // Rider completion flow does.
            app(TransactionController::class)->storeForDelivery($delivery);
            RiderEarning::firstOrCreate(
                ['rider_id' => $this->rider->id, 'delivery_id' => $delivery->id, 'type' => 'delivery'],
                [
                    'amount' => $delivery->delivery_fee ?? 50.00,
                    'earned_on' => now()->toDateString(),
                    'description' => "Delivery {$delivery->tracking_number}",
                ],
            );
        }

        if ($status === 'delivery_failed') {
            $this->ensureLog($delivery->id, 'delivery_failed', 'Delivery failed: ' . $times['failure_reason']);
            DeliveryFailure::firstOrCreate(
                ['delivery_id' => $delivery->id, 'rider_id' => $this->rider->id],
                [
                    'reason' => $times['failure_reason'],
                    'notes' => 'Driver App test failure.',
                    'reported_at' => $times['failed_at'],
                ],
            );
        }
    }

    private function ensureLog(int $deliveryId, string $status, string $notes): void
    {
        if (! DeliveryStatusLog::where('delivery_id', $deliveryId)->where('status', $status)->exists()) {
            DeliveryStatusLog::create([
                'delivery_id' => $deliveryId,
                'status' => $status,
                'notes' => $notes,
                'changed_by' => User::where('role', 'admin')->value('id'),
            ]);
        }
    }

    private function seedNotifications(): void
    {
        $notifications = [
            ['type' => 'delivery_assigned', 'title' => 'New delivery assigned', 'body' => 'You have a new delivery: DVRTEST-ASSIGNED-0001.', 'data' => ['delivery_id' => null], 'is_read' => false],
            ['type' => 'delivery_update', 'title' => 'Delivery out for delivery', 'body' => 'Your delivery DVRTEST-OUT-FOR-DELIVERY-0001 is now out for delivery.', 'data' => ['delivery_id' => null], 'is_read' => false],
            ['type' => 'delivery_completed', 'title' => 'Delivery completed', 'body' => 'Delivery DVRTEST-DELIVERED-0001 was completed. ₱80.00 earned.', 'data' => ['delivery_id' => null], 'is_read' => true],
        ];

        foreach ($notifications as $n) {
            RiderNotification::updateOrCreate(
                ['rider_id' => $this->rider->id, 'type' => $n['type'], 'title' => $n['title']],
                [
                    'body' => $n['body'],
                    'data' => $n['data'],
                    'is_read' => $n['is_read'],
                ],
            );
        }
    }
}
