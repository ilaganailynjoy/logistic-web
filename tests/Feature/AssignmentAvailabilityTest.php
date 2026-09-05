<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Delivery;
use App\Models\LogisticsCenter;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Rider;
use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssignmentAvailabilityTest extends TestCase
{
    private function user(string $role = 'admin'): User
    {
        return User::create([
            'name' => 'Assign '.$role.' '.uniqid(),
            'first_name' => 'Assign',
            'last_name' => ucfirst($role),
            'sex' => 'male',
            'email' => 'assign-'.$role.'-'.uniqid().'@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000000',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => $role,
            'status' => 'active',
            'center_id' => null,
            'email_verified_at' => now(),
        ]);
    }

    private function center(): LogisticsCenter
    {
        return LogisticsCenter::create([
            'name' => 'Assign Center '.uniqid(),
            'address' => 'Test St',
            'city' => 'Test City',
            'province' => 'Test',
            'is_active' => true,
        ]);
    }

    private function area(LogisticsCenter $center): ServiceArea
    {
        return ServiceArea::create([
            'logistics_center_id' => $center->id,
            'name' => 'Assign Area '.uniqid(),
            'is_active' => true,
        ]);
    }

    private function rider(LogisticsCenter $center, ?ServiceArea $area = null, array $overrides = []): Rider
    {
        return Rider::create(array_merge([
            'name' => 'Assign Rider '.uniqid(),
            'email' => 'assign-rider-'.uniqid().'@test.com',
            'phone' => '09000000002',
            'vehicle_type' => 'Motorcycle',
            'license_plate' => 'ABC '.random_int(1, 999),
            'status' => 'available',
            'center_id' => $center->id,
            'service_area_id' => $area?->id,
            'approved_at' => now()->subDays(10),
            'vehicle_verification' => 'verified',
        ], $overrides));
    }

    private function delivery(array $overrides = []): Delivery
    {
        return Delivery::create(array_merge([
            'sender_name' => 'Shop',
            'sender_phone' => '09171234567',
            'sender_address' => '1 Shop St',
            'recipient_name' => 'Cust',
            'recipient_phone' => '09171234568',
            'recipient_address' => '2 Cust Ave',
            'status' => 'waiting_for_rider',
            'delivery_fee' => 100.00,
        ], $overrides));
    }

    public function test_offline_rider_cannot_be_assigned_a_new_delivery(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $rider = $this->rider($center); // is_online defaults to false
        $delivery = $this->delivery();

        $response = $this->actingAs($admin)
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $rider->id]);

        $response->assertSessionHasErrors('rider_id');

        $delivery->refresh();
        $this->assertNull($delivery->rider_id);
        $this->assertEquals('waiting_for_rider', $delivery->status);
    }

    public function test_online_eligible_rider_can_be_assigned(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $rider = $this->rider($center, null, ['is_online' => true]);
        $delivery = $this->delivery();

        $this->actingAs($admin)
            ->post(route('deliveries.assign-rider', $delivery), ['rider_id' => $rider->id]);

        $delivery->refresh();
        $this->assertEquals($rider->id, $delivery->rider_id);
        $this->assertEquals('assigned', $delivery->status);
    }

    public function test_delivery_show_marks_offline_rider_unavailable_and_online_first(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $area = $this->area($center);
        $online = $this->rider($center, $area, ['is_online' => true]);
        $offline = $this->rider($center, $area); // offline
        $delivery = $this->delivery();

        $response = $this->actingAs($admin)->get(route('deliveries.show', $delivery));
        $response->assertOk();

        $eligibility = collect($response->viewData('riderEligibility'));

        $onlineRow = $eligibility->firstWhere('rider.id', $online->id);
        $offlineRow = $eligibility->firstWhere('rider.id', $offline->id);

        $this->assertTrue($onlineRow['eligible']);
        $this->assertTrue($onlineRow['is_online']);
        $this->assertFalse($offlineRow['eligible']);
        $this->assertFalse($offlineRow['is_online']);
        $this->assertStringContainsString('Offline', $offlineRow['reason']);

        // Online+eligible riders always sort before offline ones.
        $ordered = $eligibility->values();
        $this->assertLessThan(
            $ordered->search(fn ($r) => $r['rider']->id === $offline->id),
            $ordered->search(fn ($r) => $r['rider']->id === $online->id)
        );
    }

    public function test_attachment_serves_from_local_and_public_disks_and_404s_when_missing(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $rider = $this->rider($center);

        $conversation = Conversation::create([
            'participant_type' => 'rider',
            'participant_id' => $rider->id,
            'participant_name' => $rider->name,
            'subject' => 'Attachment test',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'rider',
            'sender_id' => $rider->id,
            'body' => 'See attachment',
        ]);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        // Local disk (web uploads)
        $localPath = "message-attachments/{$conversation->id}/local-test.png";
        Storage::disk('local')->put($localPath, $png);
        $localAtt = MessageAttachment::create([
            'message_id' => $message->id,
            'original_filename' => 'local-test.png',
            'stored_path' => $localPath,
            'mime_type' => 'image/png',
            'file_size' => strlen($png),
            'disk' => 'local',
        ]);

        // Public disk (Rider App API uploads)
        $publicPath = "message-attachments/{$conversation->id}/public-test.png";
        Storage::disk('public')->put($publicPath, $png);
        $publicAtt = MessageAttachment::create([
            'message_id' => $message->id,
            'original_filename' => 'public-test.png',
            'stored_path' => $publicPath,
            'mime_type' => 'image/png',
            'file_size' => strlen($png),
            'disk' => 'public',
        ]);

        $this->assertTrue($localAtt->fileExists());
        $this->assertTrue($publicAtt->fileExists());
        $this->assertStringContainsString('app', $localAtt->absolutePath());
        $this->assertTrue($localAtt->isImage());

        // Authorized staff can view the local-disk attachment.
        $this->actingAs($admin)
            ->get(route('messages.attachments.view', $localAtt))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        // A non-staff user is redirected away by the staff middleware.
        $plainUser = User::create([
            'name' => 'Plain Rider User',
            'first_name' => 'Plain',
            'last_name' => 'Rider',
            'sex' => 'male',
            'email' => 'plain-'.uniqid().'@test.com',
            'password' => bcrypt('password'),
            'phone' => '09000000009',
            'birthday' => '1991-01-01',
            'age' => 34,
            'role' => 'rider',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($plainUser)
            ->get(route('messages.attachments.view', $localAtt))
            ->assertStatus(302);

        // Once the file is gone, serving returns 404.
        Storage::disk('local')->delete($localPath);
        $this->actingAs($admin)
            ->get(route('messages.attachments.view', $localAtt))
            ->assertNotFound();
    }
}
