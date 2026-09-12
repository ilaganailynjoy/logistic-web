<?php

namespace Tests\Feature;

use App\Mail\CenterApplicationApprovedMail;
use App\Models\LogisticsCenter;
use App\Models\LogisticsCenterApplication;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Mobile "Open a Logistics Center" application flow: submit via
 * POST /api/center/apply, check status, and the admin web review that
 * provisions the center + staff login with emailed credentials.
 */
class CenterApplicationApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function uniquePhone(): string
    {
        return '0918' . random_int(1000000, 9999999);
    }

    private function jpg(string $name): UploadedFile
    {
        $base = tempnam(sys_get_temp_dir(), 'cf');
        $path = $base . '.jpg';
        rename($base, $path);
        file_put_contents(
            $path,
            "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00" . str_repeat("\x00", 300)
        );
        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    private function submitData(): array
    {
        return [
            'business_name' => 'Test Center ' . uniqid(),
            'owner_name' => 'Juan Dela Cruz',
            'email' => 'center-' . uniqid() . '@test.com',
            'phone' => $this->uniquePhone(),
            'house_number' => '12',
            'street' => 'Rizal St',
            'barangay' => 'Barangay Uno',
            'municipality' => 'Manila',
            'province' => 'Metro Manila',
            'documents' => [
                'valid_id' => $this->jpg('valid.jpg'),
                'business_registration' => $this->jpg('dti.jpg'),
            ],
        ];
    }

    public function test_apply_creates_pending_center_application(): void
    {
        Storage::fake('local');
        $data = $this->submitData();

        $res = $this->post('/api/center/apply', $data);

        $res->assertStatus(201);
        $res->assertJson(['application' => ['status' => 'pending', 'submitted_via' => 'mobile']]);

        $this->assertDatabaseHas('logistics_center_applications', [
            'email' => $data['email'],
            'status' => 'pending',
            'submitted_via' => 'mobile',
        ]);

        $app = LogisticsCenterApplication::where('email', $data['email'])->firstOrFail();
        $this->assertSame('12, Rizal St, Barangay Uno, Manila, Metro Manila', $app->address);
        $this->assertSame(2, $app->supportingDocuments()->count());
    }

    public function test_apply_validates_required_fields(): void
    {
        $this->post('/api/center/apply', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['business_name', 'owner_name', 'email', 'phone']);
    }

    public function test_status_returns_application(): void
    {
        Storage::fake('local');
        $data = $this->submitData();
        $this->post('/api/center/apply', $data);

        $this->getJson('/api/center/application-status?email=' . urlencode($data['email']))
            ->assertOk()
            ->assertJsonPath('application.business_name', $data['business_name'])
            ->assertJsonPath('application.status', 'pending');
    }

    public function test_status_404_when_no_application(): void
    {
        $this->getJson('/api/center/application-status?email=nobody@test.com')
            ->assertNotFound();
    }

    public function test_approve_provisions_center_and_staff_account(): void
    {
        Storage::fake('local');
        $data = $this->submitData();
        $id = $this->post('/api/center/apply', $data)->json('application.id');

        $admin = User::create([
            'name' => 'Center Admin',
            'first_name' => 'Center',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'center-admin-' . uniqid() . '@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000007',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post("/center-applications/{$id}/approve", [
                'password' => 'center-pass',
                'password_confirmation' => 'center-pass',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('logistics_centers', [
            'name' => $data['business_name'],
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => $data['email'],
            'role' => 'staff',
            'status' => 'active',
        ]);

        $center = LogisticsCenter::where('name', $data['business_name'])->firstOrFail();
        $user = User::where('email', $data['email'])->firstOrFail();
        $this->assertSame($center->id, $user->center_id);

        $app = LogisticsCenterApplication::findOrFail($id);
        $this->assertSame('approved', $app->status);
        $this->assertNotNull($app->provisioned_at);

        Mail::assertSent(CenterApplicationApprovedMail::class, function ($mail) use ($data) {
            return $mail->application->email === $data['email']
                && $mail->temporaryPassword === 'center-pass';
        });
    }

    public function test_reject_marks_application_rejected(): void
    {
        Storage::fake('local');
        $data = $this->submitData();
        $id = $this->post('/api/center/apply', $data)->json('application.id');

        $admin = User::create([
            'name' => 'Reject Admin',
            'first_name' => 'Reject',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'reject-admin-' . uniqid() . '@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000008',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post("/center-applications/{$id}/reject", ['reason' => 'Documents incomplete.'])
            ->assertRedirect();

        $this->assertDatabaseHas('logistics_center_applications', [
            'id' => $id,
            'status' => 'rejected',
        ]);
    }

    public function test_approve_rejects_non_pending_application(): void
    {
        Storage::fake('local');
        $data = $this->submitData();
        $id = $this->post('/api/center/apply', $data)->json('application.id');
        LogisticsCenterApplication::whereKey($id)->update(['status' => 'approved']);

        $admin = User::create([
            'name' => 'Final Admin',
            'first_name' => 'Final',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'final-admin-' . uniqid() . '@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000009',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post("/center-applications/{$id}/approve", [])
            ->assertStatus(422);
    }
}