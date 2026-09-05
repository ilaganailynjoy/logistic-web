<?php

namespace Tests\Feature;

use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\RiderApplication;
use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cross-system synchronization: Driver App and Logistics Web share the
 * single invoizdb database through the existing API — one applicant is one
 * rider_applications row, one approval provisions one users/riders pair,
 * and Logistics-controlled fields stay protected from rider edits.
 */
class RiderAppSyncTest extends TestCase
{
    private function jpg(string $name): UploadedFile
    {
        $base = tempnam(sys_get_temp_dir(), 'sync');
        $path = $base . '.jpg';
        rename($base, $path);
        file_put_contents(
            $path,
            "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00" . str_repeat("\x00", 300)
        );
        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Sync Admin',
            'first_name' => 'Sync',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'sync-admin-' . uniqid() . '@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000077',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => 'admin',
            'status' => 'active',
            'center_id' => null,
            'email_verified_at' => now(),
        ]);
    }

    public function test_apply_approve_login_profile_is_one_shared_record(): void
    {
        Storage::fake('local');
        $email = 'sync-flow-' . uniqid() . '@test.com';
        $phone = '0917' . random_int(1000000, 9999999);

        // 1. Driver App applies -> exactly one rider_applications row.
        $apply = $this->post('/api/rider/apply', [
            'name' => 'Sync Flow Rider',
            'email' => $email,
            'phone' => $phone,
            'address' => '123 Sync St',
            'vehicle_type' => 'motorcycle',
            'license_plate' => 'SYNC 1',
            'license_number' => 'LIC-SYNC',
            'vehicle_registration' => 'REG-SYNC',
            'rider_type' => 'full_time',
            'vehicle_ownership' => 'own',
            'documents' => [
                'valid_id' => $this->jpg('valid_id.jpg'),
                'drivers_license' => $this->jpg('license.jpg'),
                'vehicle_registration' => $this->jpg('registration.jpg'),
            ],
        ]);
        $apply->assertStatus(201);
        $appId = $apply->json('application.id');
        $this->assertSame(1, RiderApplication::where('email', $email)->count());

        // 2. Same record visible via status endpoint as pending.
        $this->getJson('/api/rider/application-status?email=' . urlencode($email))
            ->assertOk()
            ->assertJsonPath('application.id', $appId)
            ->assertJsonPath('application.status', 'pending');

        // 3. Logistics approves -> one users row + one linked riders row.
        $admin = $this->admin();
        $centerA = LogisticsCenter::create([
            'name' => 'Sync Center A ' . uniqid(), 'address' => 'A St',
            'city' => 'A City', 'province' => 'A', 'is_active' => true,
        ]);
        $areaA = ServiceArea::create([
            'logistics_center_id' => $centerA->id,
            'name' => 'Sync Area A ' . uniqid(), 'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post("/rider-applications/{$appId}/approve", [
                'password' => 'sync-pass-123',
                'password_confirmation' => 'sync-pass-123',
                'center_id' => $centerA->id,
                'service_area_id' => $areaA->id,
            ])
            ->assertRedirect();

        $this->assertSame(1, User::where('email', $email)->count());
        $this->assertSame(1, Rider::where('email', $email)->count());
        $user = User::where('email', $email)->first();
        $rider = Rider::where('email', $email)->first();
        $this->assertSame('rider', $user->role);
        $this->assertSame($user->id, (int) $rider->user_id);
        $this->assertSame($centerA->id, (int) $rider->center_id);

        // 4. Provisioned account logs in through the Driver App API.
        // Real clients send only the Bearer token; re-assert the sanctum
        // user here because actingAs($admin) above changed the test default
        // guard (a test-harness artifact, not an app bug).
        $this->postJson('/api/login', [
            'email' => $email,
            'password' => 'sync-pass-123',
        ])->assertOk()->assertJsonStructure(['token']);
        $this->actingAs($user, 'sanctum');

        // 5. Status endpoint now reflects the approval (no stale data).
        $this->getJson('/api/rider/application-status?email=' . urlencode($email))
            ->assertOk()
            ->assertJsonPath('application.status', 'approved');

        // 6. Profile shows the same shared record.
        $this->getJson('/api/rider/profile')
            ->assertOk()
            ->assertJsonPath('rider.email', $email)
            ->assertJsonPath('rider.phone', $phone)
            ->assertJsonPath('rider.vehicle_type', 'motorcycle');

        // 7. Rider edits phone (allowed); center/area reassignment ignored.
        $centerB = LogisticsCenter::create([
            'name' => 'Sync Center B ' . uniqid(), 'address' => 'B St',
            'city' => 'B City', 'province' => 'B', 'is_active' => true,
        ]);
        $newPhone = '0918' . random_int(1000000, 9999999);
        $this->patchJson('/api/rider/profile', [
            'phone' => $newPhone,
            'center_id' => $centerB->id,
            'service_area_id' => 999999,
            'status' => 'delivering',
            'is_verified' => true,
        ])->assertOk();

        $rider->refresh();
        $this->assertSame($newPhone, $rider->phone);
        $this->assertSame($centerA->id, (int) $rider->center_id);
        $this->assertSame($areaA->id, (int) $rider->service_area_id);

        // 8. Repeated reads create no duplicates anywhere.
        $this->getJson('/api/rider/profile')->assertOk();
        $this->getJson('/api/rider/dashboard')->assertOk();
        $this->assertSame(1, RiderApplication::where('email', $email)->count());
        $this->assertSame(1, User::where('email', $email)->count());
        $this->assertSame(1, Rider::where('email', $email)->count());
    }
}
