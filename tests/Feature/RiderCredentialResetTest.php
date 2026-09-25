<?php

namespace Tests\Feature;

use App\Mail\RiderCredentialsResetMail;
use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Logistics Rider Management credential reset.
 *
 * The admin generates a NEW temporary credential for the rider (same
 * provisioning concept as application approval): random password, hashed
 * before persistence, old credential rotated out and unrecoverable. The
 * rider's status and assignments are untouched.
 */
class RiderCredentialResetTest extends TestCase
{
    private function admin(): User
    {
        return User::create([
            'name' => 'Reset Admin ' . uniqid(),
            'first_name' => 'Reset',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'reset-admin-' . uniqid() . '@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000001',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => 'admin',
            'status' => 'active',
            'center_id' => null,
            'email_verified_at' => now(),
        ]);
    }

    private function center(): LogisticsCenter
    {
        return LogisticsCenter::create([
            'name' => 'Reset Center ' . uniqid(), 'address' => 'R St',
            'city' => 'R City', 'province' => 'R', 'is_active' => true,
        ]);
    }

    private function riderWithUser(LogisticsCenter $center, array $overrides = []): array
    {
        $rider = Rider::create(array_merge([
            'name' => 'Reset Rider ' . uniqid(),
            'email' => 'reset-rider-' . uniqid() . '@test.com',
            'phone' => '09000000002',
            'vehicle_type' => 'motorcycle',
            'license_plate' => 'RST 1',
            'status' => 'available',
            'center_id' => $center->id,
            'approved_at' => now()->subDay(),
            'vehicle_verification' => 'verified',
        ], $overrides));

        $user = User::create([
            'name' => $rider->name,
            'first_name' => 'Reset',
            'last_name' => 'Rider',
            'sex' => 'male',
            'email' => $rider->email,
            'password' => bcrypt('old-password-123'),
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

    public function test_admin_reset_generates_new_hashed_credential(): void
    {
        Mail::fake();
        $data = $this->riderWithUser($this->center());

        $this->actingAs($this->admin())
            ->post(route('riders.reset-credentials', $data['rider']))
            ->assertRedirect()
            ->assertSessionHas('success');

        $fresh = $data['user']->fresh();

        // Old credential rotated out, new one unknown to us — prove it only
        // via the hash and the mailed mailable, never from storage.
        $this->assertFalse(Hash::check('old-password-123', $fresh->password));
        $this->assertNotSame('old-password-123', $fresh->password);
        $this->assertNotEmpty($fresh->password);

        Mail::assertSent(RiderCredentialsResetMail::class, function ($mail) use ($data, $fresh) {
            $this->assertTrue($mail->hasTo($data['rider']->email));
            $this->assertTrue(Hash::check($mail->temporaryPassword, $fresh->password));
            $this->assertNotSame('old-password-123', $mail->temporaryPassword);

            return true;
        });
    }

    public function test_new_credential_authenticates_and_old_is_rejected(): void
    {
        Mail::fake();
        $data = $this->riderWithUser($this->center());

        $this->actingAs($this->admin())
            ->post(route('riders.reset-credentials', $data['rider']))
            ->assertRedirect();

        $newPassword = null;
        Mail::assertSent(RiderCredentialsResetMail::class, function ($mail) use (&$newPassword, $data) {
            if ($mail->hasTo($data['rider']->email)) {
                $newPassword = $mail->temporaryPassword;
            }

            return $mail->hasTo($data['rider']->email);
        });
        $this->assertNotEmpty($newPassword);

        $this->postJson('/api/login', [
            'email' => $data['user']->email,
            'password' => 'old-password-123',
        ])->assertStatus(422);

        $this->postJson('/api/login', [
            'email' => $data['user']->email,
            'password' => $newPassword,
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_reset_preserves_status_and_assignments(): void
    {
        Mail::fake();
        $data = $this->riderWithUser($this->center());

        $this->actingAs($this->admin())
            ->post(route('riders.reset-credentials', $data['rider']))
            ->assertRedirect();

        $this->assertSame('available', $data['rider']->fresh()->status);
        $this->assertSame('active', $data['user']->fresh()->status);
    }

    public function test_staff_cannot_reset_other_centers_rider(): void
    {
        Mail::fake();
        $centerA = $this->center();
        $centerB = $this->center();
        $data = $this->riderWithUser($centerB);

        $staff = $this->admin();
        $staff->update(['role' => 'staff', 'center_id' => $centerA->id]);

        $this->actingAs($staff)
            ->post(route('riders.reset-credentials', $data['rider']))
            ->assertForbidden();

        $this->assertTrue(Hash::check('old-password-123', $data['user']->fresh()->password));
        Mail::assertNotSent(RiderCredentialsResetMail::class);
    }

    public function test_guest_cannot_reset_credentials(): void
    {
        $data = $this->riderWithUser($this->center());

        $this->post(route('riders.reset-credentials', $data['rider']))
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('old-password-123', $data['user']->fresh()->password));
    }

    public function test_reset_requires_existing_login_account(): void
    {
        $rider = Rider::create([
            'name' => 'Orphan Rider ' . uniqid(),
            'email' => 'orphan-rider-' . uniqid() . '@test.com',
            'phone' => '09000000003',
            'vehicle_type' => 'motorcycle',
            'license_plate' => 'ORP 1',
            'status' => 'available',
        ]);

        $this->actingAs($this->admin())
            ->post(route('riders.reset-credentials', $rider))
            ->assertStatus(422);
    }

    public function test_reset_button_visible_on_rider_page(): void
    {
        $data = $this->riderWithUser($this->center());

        $this->actingAs($this->admin())
            ->get(route('riders.show', $data['rider']))
            ->assertOk()
            ->assertSee('Generate New Credential')
            ->assertSee(route('riders.reset-credentials', $data['rider']), false);
    }
}
