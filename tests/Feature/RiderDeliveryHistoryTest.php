<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryStatusLog;
use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\RiderApplication;
use App\Models\ServiceArea;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Rider delivery Status History: the rider API returns only the delivery's
 * own actual log records in chronological order, assignment of terminal
 * deliveries is rejected, and approval without a password generates a
 * one-time initial credential.
 */
class RiderDeliveryHistoryTest extends TestCase
{
    private function admin(): User
    {
        return User::create([
            'name' => 'History Admin',
            'first_name' => 'History',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'history-admin-' . uniqid() . '@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000071',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => 'admin',
            'status' => 'active',
            'center_id' => null,
            'email_verified_at' => now(),
        ]);
    }

    private function rider(string $email): array
    {
        VehicleType::updateOrCreate(
            ['name' => 'motorcycle'],
            ['label' => 'Motorcycle', 'capacity_kg' => 30, 'is_active' => true, 'sort_order' => 1],
        );

        $rider = Rider::create([
            'name' => 'History Rider',
            'email' => $email,
            'phone' => '09000000072',
            'vehicle_type' => 'motorcycle',
            'license_plate' => 'HIST 1',
            'status' => 'available',
            'is_online' => true,
            'vehicle_verification' => 'verified',
            'approved_at' => now(),
        ]);

        $user = User::create([
            'name' => $rider->name,
            'first_name' => 'History',
            'last_name' => 'Rider',
            'sex' => 'male',
            'email' => $email,
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

    private function delivery(Rider $rider, string $status): Delivery
    {
        return Delivery::create([
            'rider_id' => $rider->id,
            'sender_name' => 'Shop',
            'sender_phone' => '09170000000',
            'sender_address' => 'Shop St',
            'recipient_name' => 'Customer',
            'recipient_phone' => '09171111111',
            'recipient_address' => 'Customer Ave',
            'status' => $status,
        ]);
    }

    private function log(int $deliveryId, string $status, string $at): void
    {
        // Timestamps are not mass-assignable (and Eloquent stamps inserts
        // with now()), so assign them directly to control event ordering.
        $log = DeliveryStatusLog::create([
            'delivery_id' => $deliveryId,
            'status' => $status,
            'notes' => $status . ' note',
        ]);
        $log->created_at = $at;
        $log->updated_at = $at;
        $log->save();
    }

    private function riderHeaders(User $user): array
    {
        $this->actingAs($user, 'sanctum');

        return [];
    }

    public function test_detail_returns_only_own_logs_chronological(): void
    {
        $data = $this->rider('history-a-' . uniqid() . '@test.com');
        $delivery = $this->delivery($data['rider'], 'picked_up');

        // Inserted out of order on purpose; another delivery's logs must not leak.
        $this->log($delivery->id, 'picked_up', '2026-09-05 10:42:00');
        $this->log($delivery->id, 'assigned', '2026-09-05 10:30:00');
        $this->log($delivery->id, 'accepted', '2026-09-05 10:35:00');

        $other = $this->delivery($data['rider'], 'delivered');
        $this->log($other->id, 'delivered', '2026-09-05 11:00:00');

        $response = $this->getJson(
            "/api/rider/deliveries/{$delivery->id}",
            $this->riderHeaders($data['user'])
        );

        $response->assertOk();
        $logs = $response->json('delivery.status_logs');
        $this->assertSame(
            ['assigned', 'accepted', 'picked_up'],
            array_column($logs, 'status')
        );
        $this->assertSame('2026-09-05T10:30:00+00:00', $logs[0]['created_at']);
        foreach ($logs as $log) {
            $this->assertNotEmpty($log['created_at']);
        }
    }

    public function test_assign_rejects_delivered_and_cancelled(): void
    {
        $admin = $this->admin();
        $data = $this->rider('history-b-' . uniqid() . '@test.com');

        foreach (['delivered', 'cancelled'] as $status) {
            $delivery = $this->delivery($data['rider'], $status);
            $logCount = DeliveryStatusLog::where('delivery_id', $delivery->id)->count();

            $this->actingAs($admin)
                ->post("/deliveries/{$delivery->id}/assign-rider", [
                    'rider_id' => $data['rider']->id,
                ])
                ->assertRedirect();

            $this->assertSame($status, $delivery->fresh()->status);
            $this->assertSame(
                $logCount,
                DeliveryStatusLog::where('delivery_id', $delivery->id)->count()
            );
        }
    }

    public function test_assign_allows_failed_retry(): void
    {
        $admin = $this->admin();
        $data = $this->rider('history-c-' . uniqid() . '@test.com');
        $delivery = $this->delivery($data['rider'], 'delivery_failed');
        $this->log($delivery->id, 'delivery_failed', '2026-09-05 09:00:00');

        $this->actingAs($admin)
            ->post("/deliveries/{$delivery->id}/assign-rider", [
                'rider_id' => $data['rider']->id,
            ])
            ->assertRedirect();

        $this->assertSame('assigned', $delivery->fresh()->status);
        $this->assertDatabaseHas('delivery_status_logs', [
            'delivery_id' => $delivery->id,
            'status' => 'assigned',
        ]);
    }

    public function test_approve_without_password_generates_one_time_credential(): void
    {
        $admin = $this->admin();
        $center = LogisticsCenter::create([
            'name' => 'History Center ' . uniqid(), 'address' => 'H St',
            'city' => 'H City', 'province' => 'H', 'is_active' => true,
        ]);
        $area = ServiceArea::create([
            'logistics_center_id' => $center->id,
            'name' => 'History Area ' . uniqid(), 'is_active' => true,
        ]);
        $app = RiderApplication::create([
            'name' => 'History Applicant',
            'email' => 'history-app-' . uniqid() . '@test.com',
            'phone' => '0917' . random_int(1000000, 9999999),
            'address' => '123 H St',
            'vehicle_type' => 'motorcycle',
            'license_plate' => 'HIS 1',
            'license_number' => 'L-HIS',
            'vehicle_registration' => 'R-HIS',
            'status' => 'pending',
            'submitted_via' => 'mobile',
        ]);

        $this->actingAs($admin)
            ->post("/rider-applications/{$app->id}/approve", [
                'center_id' => $center->id,
                'service_area_id' => $area->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('provisioned_credentials');

        $creds = session('provisioned_credentials');
        $this->assertSame($app->email, $creds['email']);
        $this->assertTrue($creds['generated']);
        $this->assertSame(12, strlen($creds['password']));

        // The one-time credential actually logs in; plaintext lives nowhere else.
        $this->postJson('/api/login', [
            'email' => $app->email,
            'password' => $creds['password'],
        ])->assertOk()->assertJsonStructure(['token']);

        $user = User::where('email', $app->email)->first();
        $this->assertTrue(Hash::check($creds['password'], $user->password));
        $this->assertStringNotContainsString($creds['password'], (string) $user);
    }

    public function test_approve_short_password_rejected(): void
    {
        $admin = $this->admin();
        $center = LogisticsCenter::create([
            'name' => 'History Center ' . uniqid(), 'address' => 'H St',
            'city' => 'H City', 'province' => 'H', 'is_active' => true,
        ]);
        $area = ServiceArea::create([
            'logistics_center_id' => $center->id,
            'name' => 'History Area ' . uniqid(), 'is_active' => true,
        ]);
        $app = RiderApplication::create([
            'name' => 'History Applicant',
            'email' => 'history-app-' . uniqid() . '@test.com',
            'phone' => '0917' . random_int(1000000, 9999999),
            'address' => '123 H St',
            'vehicle_type' => 'motorcycle',
            'license_plate' => 'HIS 1',
            'license_number' => 'L-HIS',
            'vehicle_registration' => 'R-HIS',
            'status' => 'pending',
            'submitted_via' => 'mobile',
        ]);

        $this->actingAs($admin)
            ->post("/rider-applications/{$app->id}/approve", [
                'password' => 'short',
                'password_confirmation' => 'short',
                'center_id' => $center->id,
                'service_area_id' => $area->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('password');

        $this->assertSame('pending', $app->fresh()->status);
    }
}
