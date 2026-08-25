<?php

namespace Database\Seeders;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DeliveryStatusLog;
use App\Models\Rider;
use App\Models\RiderEarning;
use App\Models\RiderNotification;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RiderSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Link rider user accounts (role=rider) to the riders table.
        $riderNames = [
            'Ahmad Rahman' => 'ahmad@riders.com',
            'Siti Nurhaliza' => 'siti@riders.com',
            'Mohd Ali' => 'ali@riders.com',
            'Fatimah Zahra' => 'fatimah@riders.com',
            'Hassan Ibrahim' => 'hassan@riders.com',
        ];

        foreach ($riderNames as $name => $email) {
            $rider = Rider::where('email', $email)->first();
            if (! $rider) {
                continue;
            }

            $user = User::where('email', $email)->first();
            if (! $user) {
                $user = User::create([
                    'name' => $name,
                    'first_name' => explode(' ', $name)[0],
                    'last_name' => explode(' ', $name)[count(explode(' ', $name)) - 1],
                    'sex' => 'male',
                    'email' => $email,
                    'password' => 'password',
                    'phone' => $rider->phone,
                    'birthday' => '1995-01-01',
                    'age' => 30,
                    'role' => 'rider',
                    'status' => 'active',
                    'approval_status' => 'approved',
                    'email_verified_at' => now(),
                ]);
            }

            if (! $rider->user_id) {
                $rider->update(['user_id' => $user->id]);
            }
        }

        // 2. Ensure at least one online rider exists.
        Rider::where('email', 'ahmad@riders.com')->update(['is_online' => true]);

        // 3. Attach items, payment and coordinates to existing deliveries.
        $sampleItems = [
            ['Baby Bottle', '250ml', 2, 250.00],
            ['Baby Clothes', 'Size 3-6M', 1, 350.00],
            ['Baby Diapers', 'M, 40 pcs', 3, 120.00],
            ['Baby Wipes', '80 pcs', 2, 99.00],
            ['Milk Formula', 'Stage 1, 900g', 1, 950.00],
            ['Baby Lotion', '200ml', 1, 180.00],
            ['Pacifier Set', 'Silicone', 2, 150.00],
            ['Bath Towel', 'Organic', 1, 320.00],
        ];

        $paymentMethods = ['cash_on_delivery', 'gcash', 'bank_transfer'];

        Delivery::with('items')->get()->each(function (Delivery $delivery) use ($sampleItems, $paymentMethods) {
            if ($delivery->items()->count() === 0) {
                $pick = $sampleItems[array_rand($sampleItems)];
                $pick2 = $sampleItems[array_rand($sampleItems)];
                $items = [$pick, $pick2];
                foreach ($items as $item) {
                    DeliveryItem::create([
                        'delivery_id' => $delivery->id,
                        'name' => $item[0],
                        'variant_label' => $item[1],
                        'quantity' => $item[2],
                        'price' => $item[3],
                    ]);
                }
            }

            if (! $delivery->payment_method) {
                $method = $paymentMethods[array_rand($paymentMethods)];
                $delivery->payment_method = $method;
                if ($method === 'cash_on_delivery') {
                    $subtotal = $delivery->items()->sum(DB::raw('quantity * price'));
                    $delivery->amount_to_collect = round($subtotal + rand(40, 90), 2);
                }
                $delivery->delivery_fee = rand(50, 120);
                $delivery->pickup_pin = str_pad((string) rand(0, 9999), 4, '0', STR_PAD_LEFT);
                $delivery->sender_lat = 3.1390;
                $delivery->sender_lng = 101.6869;
                $delivery->recipient_lat = 3.1600 + (rand(0, 20) / 100);
                $delivery->recipient_lng = 101.7000 + (rand(0, 20) / 100);
                $delivery->save();
            }
        });

        // 4. Seed earnings for delivered deliveries.
        $riderIds = Rider::pluck('id');
        foreach (Delivery::where('status', 'delivered')->get() as $delivery) {
            if ($delivery->earnings()->count() === 0) {
                RiderEarning::create([
                    'rider_id' => $delivery->rider_id ?? $riderIds->first(),
                    'delivery_id' => $delivery->id,
                    'type' => 'delivery',
                    'amount' => $delivery->delivery_fee ?? 50,
                    'earned_on' => ($delivery->delivered_at ?? now())->toDateString(),
                    'description' => "Delivery {$delivery->tracking_number}",
                ]);
            }
        }

        // 5. Seed notifications for riders.
        foreach (Rider::all() as $rider) {
            if ($rider->notifications()->count() === 0) {
                $notifications = [
                    ['type' => 'delivery', 'title' => 'New delivery assigned', 'body' => 'You have a new delivery assignment. Check your deliveries.', 'is_read' => false],
                    ['type' => 'earnings', 'title' => 'Earnings updated', 'body' => 'Your earnings summary has been updated.', 'is_read' => false],
                    ['type' => 'system', 'title' => 'Welcome to Invoize Rider', 'body' => 'Complete your deliveries on time to earn more.', 'is_read' => true],
                    ['type' => 'announcement', 'title' => 'Safety reminder', 'body' => 'Always wear your helmet and follow traffic rules.', 'is_read' => false],
                ];

                foreach ($notifications as $n) {
                    RiderNotification::create([
                        'rider_id' => $rider->id,
                        'type' => $n['type'],
                        'title' => $n['title'],
                        'body' => $n['body'],
                        'is_read' => $n['is_read'],
                        'created_at' => now()->subHours(rand(1, 48)),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        $this->command->info('Rider data seeded.');
    }
}