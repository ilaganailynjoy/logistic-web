<?php

namespace Tests\Feature;

use App\Models\Rider;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RiderPasswordTest extends TestCase
{
    private function makeRider(string $email = 'pw.rider@test.com'): array
    {
        $rider = Rider::create([
            'name' => 'Pw Rider',
            'email' => $email,
            'phone' => '09000000099',
            'vehicle_type' => 'Motorcycle',
            'license_plate' => 'PW 123',
            'status' => 'available',
        ]);

        $user = User::create([
            'name' => $rider->name,
            'first_name' => 'Pw',
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

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_guest_cannot_change_password(): void
    {
        $this->patchJson('/api/rider/password', [
            'current_password' => 'x',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertUnauthorized();
    }

    public function test_non_rider_cannot_change_rider_password(): void
    {
        $staff = User::create([
            'name' => 'Staff',
            'first_name' => 'St',
            'last_name' => 'Aff',
            'sex' => 'female',
            'email' => 'pw.staff@test.com',
            'password' => bcrypt('old-password-123'),
            'phone' => '09000000098',
            'birthday' => '1995-01-01',
            'age' => 30,
            'role' => 'staff',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->patchJson('/api/rider/password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ], $this->headers($staff))->assertForbidden();
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $data = $this->makeRider();

        $this->patchJson('/api/rider/password', [
            'current_password' => 'not-the-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ], $this->headers($data['user']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('old-password-123', $data['user']->fresh()->password));
    }

    public function test_mismatched_confirmation_is_rejected(): void
    {
        $data = $this->makeRider();

        $this->patchJson('/api/rider/password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'different-password',
        ], $this->headers($data['user']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_short_password_is_rejected(): void
    {
        $data = $this->makeRider();

        $this->patchJson('/api/rider/password', [
            'current_password' => 'old-password-123',
            'password' => 'short',
            'password_confirmation' => 'short',
        ], $this->headers($data['user']))->assertStatus(422);
    }

    public function test_rider_can_change_password_and_login_with_new(): void
    {
        $data = $this->makeRider();

        $this->patchJson('/api/rider/password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ], $this->headers($data['user']))
            ->assertOk()
            ->assertJsonPath('message', 'Password changed successfully.');

        $fresh = $data['user']->fresh();
        $this->assertTrue(Hash::check('new-password-123', $fresh->password));
        $this->assertFalse(Hash::check('old-password-123', $fresh->password));

        // Old password no longer works, new one does.
        $this->postJson('/api/login', [
            'email' => $data['user']->email,
            'password' => 'old-password-123',
        ])->assertStatus(422);

        $this->postJson('/api/login', [
            'email' => $data['user']->email,
            'password' => 'new-password-123',
        ])->assertOk()->assertJsonStructure(['token']);
    }
}
