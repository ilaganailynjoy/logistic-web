<?php

namespace Database\Seeders;

use App\Models\Delivery;
use App\Models\DeliveryStatusLog;
use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\RiderApplication;
use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // ---------- Logistics Centers ----------
        $lagunaCenter = LogisticsCenter::create([
            'name' => 'Laguna Logistics Hub',
            'address' => 'National Highway, Brgy. San Antonio',
            'city' => 'Santa Cruz',
            'province' => 'Laguna',
            'phone' => '09171234567',
            'is_active' => true,
        ]);

        $calambaCenter = LogisticsCenter::create([
            'name' => 'Calamba Distribution Center',
            'address' => 'Chipeco Avenue, Brgy. Real',
            'city' => 'Calamba',
            'province' => 'Laguna',
            'phone' => '09179876543',
            'is_active' => true,
        ]);

        $caviteCenter = LogisticsCenter::create([
            'name' => 'Cavite Sorting Facility',
            'address' => 'Governor\'s Drive, Brgy. San Jose',
            'city' => 'Dasmariñas',
            'province' => 'Cavite',
            'phone' => '09172221111',
            'is_active' => true,
        ]);

        // ---------- Service Areas ----------
        $santaCruz = ServiceArea::create([
            'logistics_center_id' => $lagunaCenter->id,
            'name' => 'Santa Cruz',
            'description' => 'Poblacion and surrounding barangays of Santa Cruz, Laguna.',
            'is_active' => true,
        ]);

        $pagsanjan = ServiceArea::create([
            'logistics_center_id' => $lagunaCenter->id,
            'name' => 'Pagsanjan',
            'description' => 'Pagsanjan, Laguna and nearby towns.',
            'is_active' => true,
        ]);

        $losBanos = ServiceArea::create([
            'logistics_center_id' => $lagunaCenter->id,
            'name' => 'Los Baños',
            'description' => 'Los Baños, Laguna covering UPLB area and Bay.',
            'is_active' => true,
        ]);

        $calambaArea = ServiceArea::create([
            'logistics_center_id' => $calambaCenter->id,
            'name' => 'Calamba City',
            'description' => 'Calamba City proper.',
            'is_active' => true,
        ]);

        // ---------- Users (Admin + Staff) ----------
        User::create([
            'name' => 'Admin Logistics',
            'first_name' => 'Admin',
            'last_name' => 'Logistics',
            'sex' => 'male',
            'email' => 'admin@logistics.com',
            'password' => 'password',
            'phone' => '09000000000',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $staffUsers = [
            [
                'name' => 'Maria Santos',
                'email' => 'maria.staff@logistics.com',
                'phone' => '09151111111',
                'center_id' => $lagunaCenter->id,
            ],
            [
                'name' => 'Juan Dela Cruz',
                'email' => 'juan.staff@logistics.com',
                'phone' => '09152222222',
                'center_id' => $calambaCenter->id,
            ],
            [
                'name' => 'Ana Reyes',
                'email' => 'ana.staff@logistics.com',
                'phone' => '09153333333',
                'center_id' => $caviteCenter->id,
            ],
        ];

        foreach ($staffUsers as $staff) {
            User::create([
                'name' => $staff['name'],
                'first_name' => Str::before($staff['name'], ' '),
                'last_name' => Str::after($staff['name'], ' '),
                'sex' => 'female',
                'email' => $staff['email'],
                'password' => 'password',
                'phone' => $staff['phone'],
                'birthday' => '1995-06-15',
                'age' => 30,
                'role' => 'staff',
                'status' => 'active',
                'center_id' => $staff['center_id'],
                'email_verified_at' => now(),
            ]);
        }

        // ---------- Riders ----------
        $riders = [
            ['name' => 'Miguel Cruz', 'email' => 'miguel@riders.com', 'phone' => '09171111111', 'vehicle_type' => 'Motorcycle', 'license_plate' => 'ABC 1234', 'status' => 'available', 'center_id' => $lagunaCenter->id, 'service_area_id' => $santaCruz->id, 'vehicle_verification' => 'verified', 'vehicle_verified_at' => now(), 'approved_at' => now()->subDays(30)],
            ['name' => 'Andrea Villanueva', 'email' => 'andrea@riders.com', 'phone' => '09172222222', 'vehicle_type' => 'Bicycle', 'license_plate' => null, 'status' => 'available', 'center_id' => $lagunaCenter->id, 'service_area_id' => $losBanos->id, 'vehicle_verification' => 'verified', 'vehicle_verified_at' => now(), 'approved_at' => now()->subDays(20)],
            ['name' => 'Paolo Mendoza', 'email' => 'paolo@riders.com', 'phone' => '09173333333', 'vehicle_type' => 'Van', 'license_plate' => 'XYZ 5678', 'status' => 'delivering', 'center_id' => $calambaCenter->id, 'service_area_id' => $calambaArea->id, 'vehicle_verification' => 'verified', 'vehicle_verified_at' => now(), 'approved_at' => now()->subDays(45)],
            ['name' => 'Bea Fernandez', 'email' => 'bea@riders.com', 'phone' => '09174444444', 'vehicle_type' => 'Motorcycle', 'license_plate' => 'DEF 9012', 'status' => 'available', 'center_id' => $lagunaCenter->id, 'service_area_id' => $pagsanjan->id, 'vehicle_verification' => 'verified', 'vehicle_verified_at' => now(), 'approved_at' => now()->subDays(10)],
            ['name' => 'Keith Navarro', 'email' => 'keith@riders.com', 'phone' => '09175555555', 'vehicle_type' => 'Truck', 'license_plate' => 'GHI 3456', 'status' => 'inactive', 'center_id' => $caviteCenter->id, 'service_area_id' => null, 'vehicle_verification' => 'pending', 'approved_at' => null],
        ];

        $createdRiders = [];
        foreach ($riders as $rider) {
            $createdRiders[] = Rider::create($rider);
        }

        $applications = [
            ['name' => 'John Doe', 'email' => 'john@example.com', 'phone' => '09172223333', 'vehicle_type' => 'Motorcycle', 'license_plate' => 'JKL 7890', 'status' => 'pending', 'notes' => 'Experienced rider with 3 years delivery experience.'],
            ['name' => 'Jane Smith', 'email' => 'jane@example.com', 'phone' => '09172224444', 'vehicle_type' => 'Bicycle', 'license_plate' => null, 'status' => 'approved', 'reviewed_at' => now()->subDays(2)],
            ['name' => 'Bob Wilson', 'email' => 'bob@example.com', 'phone' => '09172225555', 'vehicle_type' => 'Van', 'license_plate' => 'MNO 1234', 'status' => 'rejected', 'notes' => 'Missing required documents.', 'reviewed_at' => now()->subDays(1)],
            ['name' => 'Alice Brown', 'email' => 'alice@example.com', 'phone' => '09172226666', 'vehicle_type' => 'Motorcycle', 'license_plate' => 'PQR 5678', 'status' => 'pending'],
        ];

        foreach ($applications as $app) {
            RiderApplication::create($app);
        }

        // ---------- Deliveries ----------
        $statuses = ['waiting_for_rider', 'assigned', 'picked_up', 'out_for_delivery', 'delivered'];
        $senders = [
            ['name' => 'Laguna Electronics Store', 'phone' => '09178881111', 'address' => '123 Brgy. San Antonio, Santa Cruz, Laguna'],
            ['name' => 'Calamba Trading Co', 'phone' => '09178882222', 'address' => '45 Chipeco Ave, Calamba City, Laguna'],
            ['name' => 'Dasmariñas Goods Hub', 'phone' => '09178883333', 'address' => '67 Governor\'s Drive, Dasmariñas, Cavite'],
        ];
        $recipients = [
            ['name' => 'Ramon Bautista', 'phone' => '09178884444', 'address' => '89 Pagsanjan, Laguna'],
            ['name' => 'Liza Soberano', 'phone' => '09178885555', 'address' => '12 Los Baños, Laguna'],
            ['name' => 'Marco Reyes', 'phone' => '09178886666', 'address' => '34 Calamba City, Laguna'],
        ];
        $centers = [$lagunaCenter->id, $calambaCenter->id, $caviteCenter->id];
        $serviceAreas = [$santaCruz->id, $pagsanjan->id, $losBanos->id, $calambaArea->id];

        for ($i = 0; $i < 15; $i++) {
            $status = $statuses[array_rand($statuses)];
            $sender = $senders[array_rand($senders)];
            $recipient = $recipients[array_rand($recipients)];

            $parcelStatus = 'pending_arrival';
            $receivedAt = null;
            $scannedAt = null;
            $sortedAt = null;
            $centerId = null;
            $destinationCenterId = null;
            $serviceAreaId = null;

            // Simulate fuller parcel pipeline for later-stage deliveries
            if (in_array($status, ['picked_up', 'out_for_delivery', 'delivered'])) {
                $centerId = $centers[array_rand($centers)];
                $destinationCenterId = $centers[array_rand($centers)];
                $serviceAreaId = $serviceAreas[array_rand($serviceAreas)];
                $parcelStatus = 'sorted';
                $receivedAt = now()->subDays(rand(2, 6))->subHours(rand(1, 12));
                $scannedAt = $receivedAt->copy()->addHours(rand(1, 5));
                $sortedAt = $scannedAt->copy()->addHours(rand(1, 5));
            } elseif ($status === 'assigned') {
                $centerId = $centers[array_rand($centers)];
                $parcelStatus = 'received';
                $receivedAt = now()->subDays(rand(0, 3))->subHours(rand(1, 10));
            }

            $delivery = Delivery::create([
                'tracking_number' => 'TRK-' . now()->subDays(rand(0, 14))->format('Ymd') . '-' . strtoupper(Str::random(4)),
                'sender_name' => $sender['name'],
                'sender_phone' => $sender['phone'],
                'sender_address' => $sender['address'],
                'recipient_name' => $recipient['name'],
                'recipient_phone' => $recipient['phone'],
                'recipient_address' => $recipient['address'],
                'status' => $status,
                'weight' => rand(1, 50) . '.' . rand(0, 99),
                'notes' => $i % 3 === 0 ? 'Handle with care' : null,
                'center_id' => $centerId,
                'destination_center_id' => $destinationCenterId,
                'service_area_id' => $serviceAreaId,
                'parcel_status' => $parcelStatus,
                'received_at' => $receivedAt,
                'scanned_at' => $scannedAt,
                'sorted_at' => $sortedAt,
                'created_by' => User::where('role', 'admin')->first()->id,
            ]);

            if (in_array($status, ['assigned', 'picked_up', 'out_for_delivery', 'delivered'])) {
                $rider = $createdRiders[array_rand(array_slice($createdRiders, 0, 3))];
                $delivery->update(['rider_id' => $rider->id]);

                DeliveryStatusLog::create([
                    'delivery_id' => $delivery->id,
                    'status' => 'assigned',
                    'notes' => "Assigned to {$rider->name}",
                    'changed_by' => User::where('role', 'admin')->first()->id,
                ]);
            }

            if ($receivedAt) {
                DeliveryStatusLog::create([
                    'delivery_id' => $delivery->id,
                    'status' => 'received',
                    'notes' => 'Parcel received at logistics center.',
                    'changed_by' => User::where('role', 'staff')->first()->id ?? null,
                ]);
            }

            if ($scannedAt) {
                DeliveryStatusLog::create([
                    'delivery_id' => $delivery->id,
                    'status' => 'scanned',
                    'notes' => 'Parcel scanned and verified.',
                    'changed_by' => User::where('role', 'staff')->first()->id ?? null,
                ]);
            }

            if (in_array($status, ['picked_up', 'out_for_delivery', 'delivered'])) {
                $delivery->update(['picked_up_at' => now()->subHours(rand(1, 48))]);

                DeliveryStatusLog::create([
                    'delivery_id' => $delivery->id,
                    'status' => 'picked_up',
                    'notes' => 'Package picked up from sender',
                    'changed_by' => User::where('role', 'admin')->first()->id ?? null,
                ]);
            }

            if (in_array($status, ['out_for_delivery', 'delivered'])) {
                DeliveryStatusLog::create([
                    'delivery_id' => $delivery->id,
                    'status' => 'out_for_delivery',
                    'notes' => 'Out for delivery',
                    'changed_by' => User::where('role', 'admin')->first()->id ?? null,
                ]);
            }

            if ($status === 'delivered') {
                $delivery->update(['delivered_at' => now()->subHours(rand(1, 24))]);

                DeliveryStatusLog::create([
                    'delivery_id' => $delivery->id,
                    'status' => 'delivered',
                    'notes' => 'Package delivered successfully',
                    'changed_by' => User::where('role', 'admin')->first()->id ?? null,
                ]);
            }
        }
    }
}
