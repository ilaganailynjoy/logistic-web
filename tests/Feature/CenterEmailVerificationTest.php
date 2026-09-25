<?php

namespace Tests\Feature;

use App\Mail\CenterEmailVerificationMail;
use App\Models\LogisticsCenterApplication;
use App\Models\RiderEmailVerification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Email OTP gate for the public "Open a Logistics Center" form.
 *
 * Mirrors the rider-application OTP guarantees without touching that flow:
 * hashed single-use codes, expiry, attempt caps, resend cooldown, and a
 * server-side consumed-verification gate on submission scoped to the exact
 * submitted address.
 */
class CenterEmailVerificationTest extends TestCase
{
    private function record(array $overrides = []): RiderEmailVerification
    {
        return RiderEmailVerification::create(array_merge([
            'email' => 'center-otp-' . uniqid() . '@test.com',
            'otp_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
            'last_sent_at' => now()->subMinutes(2),
        ], $overrides));
    }

    public function test_send_creates_code_and_mails_it(): void
    {
        Mail::fake();

        $this->postJson('/logistics-center/verification/send', [
            'email' => 'center-new@test.com',
            'name' => 'Center Owner',
        ])->assertOk()
            ->assertJsonPath('message', 'Verification code sent. Please check your email.');

        Mail::assertSent(CenterEmailVerificationMail::class, function ($mail) {
            return $mail->email === 'center-new@test.com'
                && $mail->applicantName === 'Center Owner'
                && preg_match('/^\d{6}$/', $mail->code) === 1;
        });

        $this->assertDatabaseHas('rider_email_verifications', [
            'email' => 'center-new@test.com',
        ]);
    }

    public function test_send_is_rate_limited_by_cooldown(): void
    {
        Mail::fake();

        $this->postJson('/logistics-center/verification/send', ['email' => 'center-cool@test.com'])
            ->assertOk();
        $this->postJson('/logistics-center/verification/send', ['email' => 'center-cool@test.com'])
            ->assertStatus(429);
    }

    public function test_send_replaces_the_previous_active_code(): void
    {
        Mail::fake();

        $this->record(['email' => 'center-replace@test.com', 'consumed_at' => null, 'last_sent_at' => now()->subMinutes(5)]);

        $this->postJson('/logistics-center/verification/send', ['email' => 'center-replace@test.com'])
            ->assertOk();

        $this->assertSame(1, RiderEmailVerification::where('email', 'center-replace@test.com')->count());
    }

    public function test_verify_accepts_the_correct_code_once(): void
    {
        $record = $this->record();

        $this->postJson('/logistics-center/verification/verify', [
            'email' => $record->email,
            'code' => '123456',
        ])->assertOk()
            ->assertJsonPath('email', $record->email);

        $this->assertNotNull($record->fresh()->consumed_at);
        $this->assertTrue(RiderEmailVerification::isVerified($record->email));

        // Single-use: the consumed code can never verify again.
        $this->postJson('/logistics-center/verification/verify', [
            'email' => $record->email,
            'code' => '123456',
        ])->assertStatus(422);
    }

    public function test_verify_rejects_a_wrong_code_and_counts_attempts(): void
    {
        $record = $this->record();

        $this->postJson('/logistics-center/verification/verify', [
            'email' => $record->email,
            'code' => '000000',
        ])->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'The verification code is incorrect.');

        $this->assertSame(1, (int) $record->fresh()->attempts);
    }

    public function test_verify_rejects_expired_codes(): void
    {
        $record = $this->record(['expires_at' => now()->subMinute()]);

        $this->postJson('/logistics-center/verification/verify', [
            'email' => $record->email,
            'code' => '123456',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Verification code expired. Please request a new code.');
    }

    public function test_verify_blocks_after_too_many_attempts(): void
    {
        $record = $this->record(['attempts' => RiderEmailVerification::MAX_ATTEMPTS]);

        $this->postJson('/logistics-center/verification/verify', [
            'email' => $record->email,
            'code' => '123456',
        ])->assertStatus(429);
    }

    public function test_store_rejects_unverified_email(): void
    {
        $response = $this->from(route('center-application.apply'))
            ->post('/logistics-center/apply', ['email' => 'fresh-unverified@test.com']);

        $response->assertRedirect(route('center-application.apply'));
        $response->assertSessionHasErrors(
            'email',
            'Please verify your email address before submitting your application.'
        );
        $this->assertSame(0, LogisticsCenterApplication::count());
    }

    public function test_store_passes_the_gate_once_email_is_verified(): void
    {
        $email = 'center-verified-' . uniqid() . '@test.com';
        $this->record(['email' => $email, 'consumed_at' => now()]);

        // Gate passes, so the request falls through to the standard
        // validation errors for the missing application fields.
        $response = $this->from(route('center-application.apply'))
            ->post('/logistics-center/apply', ['email' => $email]);

        $response->assertSessionHasErrors('business_name');
        $response->assertValid('email');
    }

    public function test_apply_page_renders_the_verification_ui(): void
    {
        $this->get(route('center-application.apply'))
            ->assertOk()
            ->assertSee('Send verification code', false)
            ->assertSee('/logistics-center/verification/send', false)
            ->assertSee('/logistics-center/verification/verify', false);
    }
}
