<?php

namespace Tests\Feature;

use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\RiderApplication;
use App\Models\RiderApplicationLog;
use App\Models\ServiceArea;
use App\Models\User;
use Tests\TestCase;

/**
 * Rider Account Provisioning — the admin review flow that turns an approved
 * rider_application into a working rider login.
 *
 * This is the *proper* replacement for manually inserting a test rider: the
 * Logistics admin reviews a pending application and, on approval, the system
 * atomically creates the User (role=rider) + linked Rider profile so the
 * existing AuthController::login flow works without any manual DB seeding.
 */
class RiderAccountProvisioningTest extends TestCase
{
    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Provision',
            'first_name' => 'Admin',
            'last_name' => 'Provision',
            'sex' => 'male',
            'email' => 'provision-admin@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000000',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => 'admin',
            'status' => 'active',
            'center_id' => null,
            'email_verified_at' => now(),
        ]);
    }

    private function staff(): User
    {
        return User::create([
            'name' => 'Staff Provision',
            'first_name' => 'Staff',
            'last_name' => 'Provision',
            'sex' => 'female',
            'email' => 'provision-staff-'.uniqid().'@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000001',
            'birthday' => '1992-01-01',
            'age' => 33,
            'role' => 'staff',
            'status' => 'active',
            'center_id' => null,
            'email_verified_at' => now(),
        ]);
    }

    private function center(): LogisticsCenter
    {
        return LogisticsCenter::create([
            'name' => 'Provision Center '.uniqid(),
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
            'name' => 'Provision Area '.uniqid(),
            'is_active' => true,
        ]);
    }

    private function application(string $status = 'pending'): RiderApplication
    {
        return RiderApplication::create([
            'name' => 'Provision Applicant',
            'email' => 'provision-'.uniqid().'@test.com',
            'phone' => '09000000003',
            'address' => '123 Test St',
            'vehicle_type' => 'Motorcycle',
            'license_plate' => 'ABC 1',
            'license_number' => 'L-001',
            'vehicle_registration' => 'VR-001',
            'status' => $status,
            'submitted_via' => 'mobile',
        ]);
    }

    public function test_admin_can_list_applications(): void
    {
        $this->application();
        $this->application('approved');

        $this->actingAs($this->admin())
            ->get('/rider-applications')
            ->assertStatus(200)
            ->assertSee('Provision Applicant');
    }

    public function test_admin_can_approve_and_provision_account(): void
    {
        $admin = $this->admin();
        $center = $this->center();
        $area = $this->area($center);
        $app = $this->application();

        $this->actingAs($admin)
            ->post("/rider-applications/{$app->id}/approve", [
                'password' => 'rider-pass',
                'password_confirmation' => 'rider-pass',
                'center_id' => $center->id,
                'service_area_id' => $area->id,
                'notes' => 'All documents verified.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('rider_applications', [
            'id' => $app->id,
            'status' => 'approved',
            'approved_by' => $admin->id,
        ]);
        $this->assertNotNull($app->fresh()->provisioned_at);

        $this->assertDatabaseHas('users', [
            'email' => $app->email,
            'role' => 'rider',
            'status' => 'active',
            'center_id' => $center->id,
        ]);
        $this->assertDatabaseHas('riders', [
            'email' => $app->email,
            'center_id' => $center->id,
            'service_area_id' => $area->id,
            'vehicle_verification' => 'verified',
        ]);

        $this->assertDatabaseHas('rider_application_logs', [
            'rider_application_id' => $app->id,
            'previous_status' => 'pending',
            'new_status' => 'approved',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_provisioned_rider_can_log_in_via_api(): void
    {
        $admin = $this->admin();
        $center = $this->center();
        $area = $this->area($center);
        $app = $this->application();

        $this->actingAs($admin)
            ->post("/rider-applications/{$app->id}/approve", [
                'password' => 'secret-pass-123',
                'password_confirmation' => 'secret-pass-123',
                'center_id' => $center->id,
                'service_area_id' => $area->id,
            ])
            ->assertRedirect();

        // The acceptance criterion: the provisioned account can now log in
        // through the existing Rider AuthController (users.email + Hash::check).
        $this->postJson('/api/login', [
            'email' => $app->email,
            'password' => 'secret-pass-123',
        ])->assertStatus(200)
            ->assertJsonStructure(['token', 'user' => ['rider']]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_approving_links_preexisting_rider_row(): void
    {
        $admin = $this->admin();
        $center = $this->center();
        $area = $this->area($center);
        $app = $this->application();

        // A rider profile already exists (e.g. imported directory), but has no login.
        $existing = Rider::create([
            'name' => $app->name,
            'email' => $app->email,
            'phone' => $app->phone,
            'vehicle_type' => 'Motorcycle',
            'license_plate' => 'OLD 1',
            'status' => 'available',
            'approved_at' => now()->subDays(5),
            'vehicle_verification' => 'pending',
        ]);

        $this->actingAs($admin)
            ->post("/rider-applications/{$app->id}/approve", [
                'password' => 'rider-pass',
                'password_confirmation' => 'rider-pass',
                'center_id' => $center->id,
                'service_area_id' => $area->id,
            ])
            ->assertRedirect();

        $existing->refresh();
        $this->assertNotNull($existing->user_id);
        $this->assertEquals($center->id, $existing->center_id);
        $this->assertEquals($area->id, $existing->service_area_id);
        $this->assertEquals('verified', $existing->vehicle_verification);

        // No duplicate rider rows.
        $this->assertEquals(1, Rider::where('email', $app->email)->count());
        $this->assertEquals(1, User::where('email', $app->email)->count());
    }

    public function test_admin_can_reject_without_provisioning(): void
    {
        $admin = $this->admin();
        $app = $this->application();

        $this->actingAs($admin)
            ->post("/rider-applications/{$app->id}/reject", [
                'reason' => 'Invalid license document.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('rider_applications', [
            'id' => $app->id,
            'status' => 'rejected',
        ]);
        $this->assertNull($app->fresh()->provisioned_at);
        $this->assertDatabaseMissing('users', ['email' => $app->email]);
        $this->assertDatabaseMissing('riders', ['email' => $app->email]);
        $this->assertDatabaseHas('rider_application_logs', [
            'rider_application_id' => $app->id,
            'new_status' => 'rejected',
            'reason' => 'Invalid license document.',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_staff_cannot_access_application_review(): void
    {
        $this->actingAs($this->staff())
            ->get('/rider-applications')
            ->assertStatus(403);
    }

    public function test_non_pending_application_cannot_be_approved(): void
    {
        $admin = $this->admin();
        $center = $this->center();
        $area = $this->area($center);
        $app = $this->application('approved');

        $this->actingAs($admin)
            ->post("/rider-applications/{$app->id}/approve", [
                'password' => 'rider-pass',
                'password_confirmation' => 'rider-pass',
                'center_id' => $center->id,
                'service_area_id' => $area->id,
            ])
            ->assertStatus(422);
    }

    public function test_service_area_must_belong_to_chosen_center(): void
    {
        $admin = $this->admin();
        $centerA = $this->center();
        $centerB = $this->center();
        $areaOfB = $this->area($centerB);
        $app = $this->application();

        $this->actingAs($admin)
            ->post("/rider-applications/{$app->id}/approve", [
                'password' => 'rider-pass',
                'password_confirmation' => 'rider-pass',
                'center_id' => $centerA->id,
                'service_area_id' => $areaOfB->id,
            ])
            ->assertStatus(404);

        $this->assertDatabaseHas('rider_applications', ['id' => $app->id, 'status' => 'pending']);
        $this->assertDatabaseMissing('users', ['email' => $app->email]);
    }

    public function test_password_is_required_and_confirmed(): void
    {
        $admin = $this->admin();
        $center = $this->center();
        $area = $this->area($center);
        $app = $this->application();

        $this->actingAs($admin)
            ->post("/rider-applications/{$app->id}/approve", [
                'password' => 'short',
                'password_confirmation' => 'different',
                'center_id' => $center->id,
                'service_area_id' => $area->id,
            ])
            ->assertSessionHasErrors(['password']);

        $this->assertDatabaseMissing('users', ['email' => $app->email]);
    }

    public function test_admin_can_view_application_document(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $app = $this->application();
        $doc = \App\Models\RiderApplicationDocument::create([
            'rider_application_id' => $app->id,
            'document_type' => 'valid_id',
            'original_filename' => 'valid_id.jpg',
            'stored_path' => 'rider-documents/viewtest.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 4,
        ]);
        \Illuminate\Support\Facades\Storage::disk('local')->put('rider-documents/viewtest.jpg', "\xFF\xD8\xFF\xE0");

        $this->assertTrue($doc->fileExists());

        $admin = User::create([
            'name' => 'Doc Admin',
            'first_name' => 'Doc',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'doc-admin-'.uniqid().'@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000007',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => 'admin',
            'status' => 'active',
            'center_id' => null,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('rider-applications.documents.view', $doc))
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->actingAs($admin)
            ->get(route('rider-applications.documents.download', $doc))
            ->assertStatus(200)
            ->assertHeader('Content-Disposition', "attachment; filename=\"valid_id.jpg\"");
    }

    public function test_staff_cannot_access_application_document(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $app = $this->application();
        $doc = \App\Models\RiderApplicationDocument::create([
            'rider_application_id' => $app->id,
            'document_type' => 'valid_id',
            'original_filename' => 'valid_id.jpg',
            'stored_path' => 'rider-documents/viewtest2.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 4,
        ]);
        \Illuminate\Support\Facades\Storage::disk('local')->put('rider-documents/viewtest2.jpg', "\xFF\xD8\xFF\xE0");

        $this->actingAs($this->staff())
            ->get(route('rider-applications.documents.view', $doc))
            ->assertStatus(403);
    }
}
