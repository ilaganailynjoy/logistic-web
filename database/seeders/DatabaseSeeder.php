<?php

namespace Database\Seeders;

use App\Models\Delivery;
use App\Models\DeliveryStatusLog;
use App\Models\Rider;
use App\Models\RiderApplication;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::create([
            'name' => 'Admin',
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
            'approval_status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $riders = [
            ['name' => 'Ahmad Rahman', 'email' => 'ahmad@riders.com', 'phone' => '0123456789', 'vehicle_type' => 'Motorcycle', 'license_plate' => 'ABC 1234', 'status' => 'available'],
            ['name' => 'Siti Nurhaliza', 'email' => 'siti@riders.com', 'phone' => '0123456780', 'vehicle_type' => 'Bicycle', 'license_plate' => null, 'status' => 'available'],
            ['name' => 'Mohd Ali', 'email' => 'ali@riders.com', 'phone' => '0123456781', 'vehicle_type' => 'Van', 'license_plate' => 'XYZ 5678', 'status' => 'delivering'],
            ['name' => 'Fatimah Zahra', 'email' => 'fatimah@riders.com', 'phone' => '0123456782', 'vehicle_type' => 'Motorcycle', 'license_plate' => 'DEF 9012', 'status' => 'available'],
            ['name' => 'Hassan Ibrahim', 'email' => 'hassan@riders.com', 'phone' => '0123456783', 'vehicle_type' => 'Truck', 'license_plate' => 'GHI 3456', 'status' => 'inactive'],
        ];

        $createdRiders = [];
        foreach ($riders as $rider) {
            $createdRiders[] = Rider::create($rider);
        }

        $applications = [
            ['name' => 'John Doe', 'email' => 'john@example.com', 'phone' => '0198765432', 'vehicle_type' => 'Motorcycle', 'license_plate' => 'JKL 7890', 'status' => 'pending', 'notes' => 'Experienced rider with 3 years delivery experience.'],
            ['name' => 'Jane Smith', 'email' => 'jane@example.com', 'phone' => '0198765433', 'vehicle_type' => 'Bicycle', 'license_plate' => null, 'status' => 'approved', 'reviewed_at' => now()->subDays(2)],
            ['name' => 'Bob Wilson', 'email' => 'bob@example.com', 'phone' => '0198765434', 'vehicle_type' => 'Van', 'license_plate' => 'MNO 1234', 'status' => 'rejected', 'notes' => 'Missing required documents.', 'reviewed_at' => now()->subDays(1)],
            ['name' => 'Alice Brown', 'email' => 'alice@example.com', 'phone' => '0198765435', 'vehicle_type' => 'Motorcycle', 'license_plate' => 'PQR 5678', 'status' => 'pending'],
        ];

        foreach ($applications as $app) {
            RiderApplication::create($app);
        }

        $statuses = ['waiting_for_rider', 'assigned', 'picked_up', 'out_for_delivery', 'delivered'];
        $senders = [
            ['name' => 'KL Electronics Store', 'phone' => '0312345678', 'address' => '123 Jalan Bukit Bintang, Kuala Lumpur'],
            ['name' => 'Penang Trading Co', 'phone' => '0412345678', 'address' => '45 Lebuh Chulia, George Town, Penang'],
            ['name' => 'Johor Goods Hub', 'phone' => '0712345678', 'address' => '67 Jalan Wong Ah Fook, Johor Bahru'],
        ];
        $recipients = [
            ['name' => 'Ali bin Abu', 'phone' => '01112345678', 'address' => '89 Jalan Tun Razak, Kuala Lumpur'],
            ['name' => 'Tan Wei Ming', 'phone' => '01223456789', 'address' => '12 Jalan Parameswara, Melaka'],
            ['name' => 'Nurul Izzah', 'phone' => '01334567890', 'address' => '34 Jalan Gurney, Kota Bharu, Kelantan'],
        ];

        for ($i = 0; $i < 15; $i++) {
            $status = $statuses[array_rand($statuses)];
            $sender = $senders[array_rand($senders)];
            $recipient = $recipients[array_rand($recipients)];

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
            ]);

            if (in_array($status, ['assigned', 'picked_up', 'out_for_delivery', 'delivered'])) {
                $rider = $createdRiders[array_rand(array_slice($createdRiders, 0, 3))];
                $delivery->update(['rider_id' => $rider->id]);

                DeliveryStatusLog::create([
                    'delivery_id' => $delivery->id,
                    'status' => 'assigned',
                    'notes' => "Assigned to {$rider->name}",
                ]);
            }

            if (in_array($status, ['picked_up', 'out_for_delivery', 'delivered'])) {
                $delivery->update(['picked_up_at' => now()->subHours(rand(1, 48))]);

                DeliveryStatusLog::create([
                    'delivery_id' => $delivery->id,
                    'status' => 'picked_up',
                    'notes' => 'Package picked up from sender',
                ]);
            }

            if (in_array($status, ['out_for_delivery', 'delivered'])) {
                DeliveryStatusLog::create([
                    'delivery_id' => $delivery->id,
                    'status' => 'out_for_delivery',
                    'notes' => 'Out for delivery',
                ]);
            }

            if ($status === 'delivered') {
                $delivery->update(['delivered_at' => now()->subHours(rand(1, 24))]);

                DeliveryStatusLog::create([
                    'delivery_id' => $delivery->id,
                    'status' => 'delivered',
                    'notes' => 'Package delivered successfully',
                ]);
            }
        }
    }
}
