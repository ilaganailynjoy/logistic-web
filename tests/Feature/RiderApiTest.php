<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DeliveryStatusLog;
use App\Models\Rider;
use App\Models\User;
use Tests\TestCase;

class RiderApiTest extends TestCase
{
    private function makeRider(array $attributes = []): array
    {
        $rider = Rider::create(array_merge([
            'name' => 'Test Rider',
            'email' => 'rider@test.com',
            'phone' => '09000000000',
            'vehicle_type' => 'Motorcycle',
            'license_plate' => 'ABC 123',
            'status' => 'available',
        ], $attributes));

        $user = User::create([
            'name' => $rider->name,
            'first_name' => 'Test',
            'last_name' => 'Rider',
            'sex' => 'male',
            'email' => $rider->email,
            'password' => bcrypt('password'),
            'phone' => $rider->phone,
            'birthday' => '1995-01-01',
            'age' => 30,
            'role' => 'rider',
            'status' => 'active',
            'approval_status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $rider->update(['user_id' => $user->id]);

        return ['rider' => $rider, 'user' => $user];
    }

    private function makeDelivery(Rider $rider, string $status, array $overrides = []): Delivery
    {
        $delivery = Delivery::create(array_merge([
            'rider_id' => $rider->id,
            'sender_name' => 'Test Shop',
            'sender_phone' => '0312345678',
            'sender_address' => '123 Shop Street',
            'recipient_name' => 'Test Customer',
            'recipient_phone' => '09171234567',
            'recipient_address' => '456 Customer Ave',
            'status' => $status,
            'payment_method' => 'cash_on_delivery',
            'amount_to_collect' => 500.00,
            'delivery_fee' => 60.00,
            'pickup_pin' => '4821',
        ], $overrides));

        DeliveryItem::create([
            'delivery_id' => $delivery->id,
            'name' => 'Baby Bottle',
            'quantity' => 2,
            'price' => 250.00,
        ]);

        DeliveryStatusLog::create([
            'delivery_id' => $delivery->id,
            'status' => $status,
            'notes' => 'status created',
        ]);

        return $delivery;
    }

    // ---------------------------------------------------------------
    // Authentication
    // ---------------------------------------------------------------

    public function test_valid_login_returns_token(): void
    {
        $this->makeRider();

        $response = $this->postJson('/api/login', [
            'email' => 'rider@test.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['role', 'rider']])
            ->assertJsonPath('user.role', 'rider');
    }

    public function test_invalid_login_fails(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'rider@test.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
    }

    public function test_customer_cannot_login_as_rider(): void
    {
        User::create([
            'name' => 'Customer',
            'first_name' => 'Cus',
            'last_name' => 'Tomer',
            'sex' => 'female',
            'email' => 'customer@test.com',
            'password' => bcrypt('password'),
            'phone' => '09000000001',
            'birthday' => '1995-01-01',
            'age' => 30,
            'role' => 'buyer',
            'status' => 'active',
            'approval_status' => 'approved',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'customer@test.com',
            'password' => 'password',
        ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/rider/profile')->assertStatus(401);
        $this->getJson('/api/rider/dashboard')->assertStatus(401);
    }

    public function test_non_rider_role_cannot_access_rider_endpoints(): void
    {
        $user = User::create([
            'name' => 'Admin',
            'first_name' => 'Ad',
            'last_name' => 'Min',
            'sex' => 'male',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'phone' => '09000000002',
            'birthday' => '1995-01-01',
            'age' => 30,
            'role' => 'admin',
            'status' => 'active',
            'approval_status' => 'approved',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $this->getJson('/api/rider/profile', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403);
    }

    public function test_logout_revokes_token(): void
    {
        $data = $this->makeRider();
        $token = $data['user']->createToken('test')->plainTextToken;

        $this->postJson('/api/logout', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $data['user']->id,
        ]);

        $this->refreshApplication();

        $this->getJson('/api/rider/profile', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(401);
    }

    // ---------------------------------------------------------------
    // Profile / Status
    // ---------------------------------------------------------------

    public function test_rider_profile_is_returned(): void
    {
        $data = $this->makeRider();
        $token = $data['user']->createToken('test')->plainTextToken;

        $this->getJson('/api/rider/profile', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('rider.name', 'Test Rider');
    }

    public function test_rider_can_toggle_online_status(): void
    {
        $data = $this->makeRider();
        $token = $data['user']->createToken('test')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->patchJson('/api/rider/status', ['status' => 'online'], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'online');

        $this->assertTrue($data['rider']->fresh()->is_online);

        $this->patchJson('/api/rider/status', ['status' => 'offline'], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'offline');

        $this->assertFalse($data['rider']->fresh()->is_online);
    }

    // ---------------------------------------------------------------
    // Deliveries
    // ---------------------------------------------------------------

    public function test_delivery_list_returns_only_own_deliveries(): void
    {
        $data = $this->makeRider();
        $other = $this->makeRider(['name' => 'Other Rider', 'email' => 'other@test.com']);

        $mine = $this->makeDelivery($data['rider'], 'assigned');
        $theirs = $this->makeDelivery($other['rider'], 'assigned');

        $token = $data['user']->createToken('test')->plainTextToken;

        $response = $this->getJson('/api/rider/deliveries', ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        $ids = collect($response->json('deliveries'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    public function test_delivery_detail_is_visible(): void
    {
        $data = $this->makeRider();
        $delivery = $this->makeDelivery($data['rider'], 'assigned');
        $token = $data['user']->createToken('test')->plainTextToken;

        $this->getJson("/api/rider/deliveries/{$delivery->id}", ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('delivery.tracking_number', $delivery->tracking_number)
            ->assertJsonPath('delivery.shop.name', 'Test Shop')
            ->assertJsonPath('delivery.customer.name', 'Test Customer')
            ->assertJsonCount(1, 'delivery.items');
    }

    public function test_cannot_view_another_riders_delivery(): void
    {
        $data = $this->makeRider();
        $other = $this->makeRider(['name' => 'Other', 'email' => 'other2@test.com']);
        $theirs = $this->makeDelivery($other['rider'], 'assigned');
        $token = $data['user']->createToken('test')->plainTextToken;

        $this->getJson("/api/rider/deliveries/{$theirs->id}", ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403);
    }

    public function test_accept_assignment(): void
    {
        $data = $this->makeRider();
        $delivery = $this->makeDelivery($data['rider'], 'assigned');
        $token = $data['user']->createToken('test')->plainTextToken;

        $this->postJson("/api/rider/deliveries/{$delivery->id}/accept", [], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('delivery.status', 'accepted');

        $this->assertEquals('accepted', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->accepted_at);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $data = $this->makeRider();
        $delivery = $this->makeDelivery($data['rider'], 'picked_up');
        $token = $data['user']->createToken('test')->plainTextToken;

        // picked_up -> delivered is not allowed via the generic status endpoint.
        $this->patchJson(
            "/api/rider/deliveries/{$delivery->id}/status",
            ['status' => 'arrived_at_shop'],
            ['Authorization' => "Bearer {$token}"],
        )->assertStatus(409);

        $this->assertEquals('picked_up', $delivery->fresh()->status);
    }

    public function test_pickup_requires_correct_pin(): void
    {
        $data = $this->makeRider();
        $delivery = $this->makeDelivery($data['rider'], 'arrived_at_shop');
        $token = $data['user']->createToken('test')->plainTextToken;

        $this->postJson(
            "/api/rider/deliveries/{$delivery->id}/pickup",
            ['pickup_pin' => '0000'],
            ['Authorization' => "Bearer {$token}"],
        )->assertStatus(422);

        $this->postJson(
            "/api/rider/deliveries/{$delivery->id}/pickup",
            ['pickup_pin' => '4821'],
            ['Authorization' => "Bearer {$token}"],
        )->assertOk()
            ->assertJsonPath('delivery.status', 'picked_up');
    }

    public function test_complete_cod_requires_amount_received(): void
    {
        $data = $this->makeRider();
        $delivery = $this->makeDelivery($data['rider'], 'arrived_at_customer');
        $token = $data['user']->createToken('test')->plainTextToken;

        // Missing amount_received for a COD delivery.
        $this->postJson(
            "/api/rider/deliveries/{$delivery->id}/complete",
            ['proof_type' => 'signature', 'signature_name' => 'Test Customer'],
            ['Authorization' => "Bearer {$token}"],
        )->assertStatus(422);

        // Complete with amount received.
        $this->postJson(
            "/api/rider/deliveries/{$delivery->id}/complete",
            ['proof_type' => 'signature', 'signature_name' => 'Test Customer', 'amount_received' => 500.00],
            ['Authorization' => "Bearer {$token}"],
        )->assertOk()
            ->assertJsonPath('delivery.status', 'delivered');

        $this->assertDatabaseHas('rider_earnings', [
            'delivery_id' => $delivery->id,
            'amount' => 60.00,
        ]);
    }

    public function test_failed_delivery_is_recorded(): void
    {
        $data = $this->makeRider();
        $delivery = $this->makeDelivery($data['rider'], 'out_for_delivery');
        $token = $data['user']->createToken('test')->plainTextToken;

        $this->postJson(
            "/api/rider/deliveries/{$delivery->id}/failed",
            ['reason' => 'Customer unavailable', 'notes' => 'Called twice, no answer.'],
            ['Authorization' => "Bearer {$token}"],
        )->assertOk()
            ->assertJsonPath('delivery.status', 'delivery_failed');

        $this->assertDatabaseHas('delivery_failures', [
            'delivery_id' => $delivery->id,
            'reason' => 'Customer unavailable',
        ]);
    }

    // ---------------------------------------------------------------
    // Location / Earnings / Notifications
    // ---------------------------------------------------------------

    public function test_location_is_recorded_when_online(): void
    {
        $data = $this->makeRider();
        $data['rider']->update(['is_online' => true]);
        $token = $data['user']->createToken('test')->plainTextToken;

        $this->postJson('/api/rider/location', [
            'latitude' => 3.1390,
            'longitude' => 101.6869,
        ], ['Authorization' => "Bearer {$token}"])
            ->assertCreated();

        $this->assertDatabaseHas('rider_locations', [
            'rider_id' => $data['rider']->id,
            'latitude' => 3.1390,
        ]);
    }

    public function test_earnings_summary_is_returned(): void
    {
        $data = $this->makeRider();
        $token = $data['user']->createToken('test')->plainTextToken;

        $this->getJson('/api/rider/earnings', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonStructure(['today', 'this_week', 'this_month', 'history']);
    }

    public function test_notifications_list_and_mark_read(): void
    {
        $data = $this->makeRider();
        $data['rider']->notifications()->create([
            'type' => 'system',
            'title' => 'Hello',
            'body' => 'Welcome',
            'is_read' => false,
        ]);
        $token = $data['user']->createToken('test')->plainTextToken;

        $response = $this->getJson('/api/rider/notifications', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('unread_count', 1);

        $notificationId = $response->json('notifications.0.id');

        $this->patchJson("/api/rider/notifications/{$notificationId}/read", [], ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        $this->getJson('/api/rider/notifications', ['Authorization' => "Bearer {$token}"])
            ->assertJsonPath('unread_count', 0);
    }
}