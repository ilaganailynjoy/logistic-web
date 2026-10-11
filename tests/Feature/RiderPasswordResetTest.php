<?php

namespace Tests\Feature;

use App\Mail\RiderPasswordResetMail;
use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Rider self-service password reset (public API).
 *
 * Secure broker flow: cryptographically random tokens, hashed at rest,
 * 60-minute expiry, single-use, generic anti-enumeration responses,
 * throttled endpoints, Sanctum revocation on success. Staff web reset
 * stays disabled and is untouched (see PasswordResetTest).
 */
class RiderPasswordResetTest extends TestCase
{
    private function riderUser(array $userOverrides = []): array
    {
        $center = LogisticsCenter::create([
            'name' => 'PR Center ' . uniqid(), 'address' => 'P St',
            'city' => 'P City', 'province' => 'P', 'is_active' => true,
        ]);
        $rider = Rider::create([
            'name' => 'PR Rider ' . uniqid(),
            'email' => 'pr-rider-' . uniqid() . '@test.com',
            'phone' => '09000000011',
            'vehicle_type' => 'motorcycle',
            'status' => 'available',
            'center_id' => $center->id,
            'approved_at' => now()->subDay(),
            'vehicle_verification' => 'verified',
        ]);
        $user = User::create(array_merge([
            'name' => $rider->name,
            'first_name' => 'PR',
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
        ], $userOverrides));
        $rider->update(['user_id' => $user->id]);

        return [$rider, $user->fresh()];
    }

    /** @return array{user: User, token: string} */
    private function emailedToken(User $user): array
    {
        Mail::fake();
        $this->postJson('/api/rider/password/forgot', ['email' => $user->email])
            ->assertOk();
        $token = null;
        Mail::assertSent(RiderPasswordResetMail::class, function ($mail) use ($user, &$token) {
            $token = $mail->resetToken;
            return $mail->hasTo($user->email);
        });

        return ['user' => $user, 'token' => $token];
    }

    public function test_forgot_sends_mail_and_generic_message(): void
    {
        [, $user] = $this->riderUser();
        Mail::fake();

        $response = $this->postJson('/api/rider/password/forgot', ['email' => $user->email]);

        $response->assertOk()->assertJson([
            'message' => 'If an active rider account exists for that email address, a password reset link has been sent.',
        ]);
        Mail::assertSent(RiderPasswordResetMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_forgot_rejects_invalid_email_format(): void
    {
        $this->postJson('/api/rider/password/forgot', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_forgot_is_generic_for_unknown_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/rider/password/forgot', ['email' => 'nobody-' . uniqid() . '@test.com']);

        $response->assertOk()->assertJson([
            'message' => 'If an active rider account exists for that email address, a password reset link has been sent.',
        ]);
        Mail::assertNothingSent();
    }

    public function test_forgot_is_generic_for_non_rider_and_inactive(): void
    {
        [, $user] = $this->riderUser();
        Mail::fake();

        $staff = User::create([
            'name' => 'PR Staff', 'first_name' => 'PR', 'last_name' => 'Staff',
            'sex' => 'male', 'email' => 'pr-staff-' . uniqid() . '@test.com',
            'password' => bcrypt('password'), 'phone' => '09000000012',
            'birthday' => '1990-01-01', 'age' => 35,
            'role' => 'staff', 'status' => 'active', 'email_verified_at' => now(),
        ]);

        $this->postJson('/api/rider/password/forgot', ['email' => $staff->email])->assertOk();
        $this->postJson('/api/rider/password/forgot', ['email' => $user->email])->assertOk();

        $user->update(['status' => 'inactive']);
        $this->postJson('/api/rider/password/forgot', ['email' => $user->email])->assertOk();

        Mail::assertSent(RiderPasswordResetMail::class, 1);
    }

    public function test_reset_succeeds_and_rotates_credentials(): void
    {
        [, $user] = $this->riderUser();
        ['token' => $token] = $this->emailedToken($user);
        $apiToken = $user->createToken('rider-app', ['rider'])->plainTextToken;

        $response = $this->postJson('/api/rider/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertOk()->assertJson([
            'message' => 'Password reset successfully. You may now log in with your new password.',
        ]);
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
        $this->assertSame(0, $user->fresh()->tokens()->count());

        // Old password no longer works; new password works.
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'old-password-123'])
            ->assertStatus(422);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'new-password-123'])
            ->assertOk()
            ->assertJsonPath('token', fn ($t) => ! empty($t));
        $this->assertNotEmpty($apiToken);
    }

    public function test_reset_token_is_single_use(): void
    {
        [, $user] = $this->riderUser();
        ['token' => $token] = $this->emailedToken($user);

        $payload = [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ];
        $this->postJson('/api/rider/password/reset', $payload)->assertOk();
        $this->postJson('/api/rider/password/reset', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', 'This password reset link is invalid or has expired.');
    }

    public function test_reset_token_expires(): void
    {
        [, $user] = $this->riderUser();
        ['token' => $token] = $this->emailedToken($user);

        $this->travel(61)->minutes();

        $this->postJson('/api/rider/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422);
        $this->assertTrue(Hash::check('old-password-123', $user->fresh()->password));
    }

    public function test_reset_rejects_wrong_token_and_cross_account_token(): void
    {
        [, $userA] = $this->riderUser();
        [, $userB] = $this->riderUser();
        ['token' => $tokenA] = $this->emailedToken($userA);

        $payload = fn ($email, $token) => [
            'email' => $email,
            'token' => $token,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ];

        $this->postJson('/api/rider/password/reset', $payload($userA->email, 'wrong-token'))
            ->assertStatus(422);
        // A's token cannot reset B's account.
        $this->postJson('/api/rider/password/reset', $payload($userB->email, $tokenA))
            ->assertStatus(422);
        $this->assertTrue(Hash::check('old-password-123', $userB->fresh()->password));
    }

    public function test_reset_is_generic_for_unknown_email(): void
    {
        $this->postJson('/api/rider/password/reset', [
            'email' => 'ghost-' . uniqid() . '@test.com',
            'token' => 'whatever',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', 'This password reset link is invalid or has expired.');
    }

    public function test_forgot_is_rate_limited(): void
    {
        [, $user] = $this->riderUser();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/rider/password/forgot', ['email' => $user->email])->assertOk();
        }
        $this->postJson('/api/rider/password/forgot', ['email' => $user->email])->assertStatus(429);
    }
}
