<?php

namespace Tests\Feature;

use App\Mail\RiderEmailVerificationMail;
use App\Models\Notification;
use App\Models\RiderEmailVerification;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pre-submission email OTP: request/verify/resend, hashing, expiry,
 * single-use, rate limits, and the POST /api/rider/apply gate.
 * No real emails are sent here (Mail::fake()).
 */
class RiderEmailVerificationTest extends TestCase
{
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
        $this->email = 'otp-' . uniqid() . '@test.com';
    }

    private function requestCode(?string $email = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/rider/email/request-code', [
            'email' => $email ?? $this->email,
            'name' => 'OTP Applicant',
        ]);
    }

    /** Capture the plaintext code from the faked mailable (never in API). */
    private function sentCode(?string $email = null): string
    {
        $code = null;
        Mail::assertSent(RiderEmailVerificationMail::class, function ($mail) use ($email, &$code) {
            if ($mail->email !== ($email ?? $this->email)) return false;
            $code = $mail->code;

            return true;
        });

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        return $code;
    }

    public function test_valid_email_can_request_otp(): void
    {
        $this->requestCode()->assertOk()
            ->assertJsonPath('message', 'Verification code sent. Please check your email.');

        $this->assertSame(1, RiderEmailVerification::where('email', $this->email)->count());
    }

    public function test_verification_email_sent_to_requested_email(): void
    {
        $this->requestCode()->assertOk();

        Mail::assertSent(RiderEmailVerificationMail::class, function ($mail) {
            return $mail->hasTo($this->email)
                && $mail->hasSubject('Verify Your Email – INVOIZ Rider Application');
        });
    }

    public function test_otp_never_returned_in_api_response(): void
    {
        $this->requestCode()->assertOk();
        $code = $this->sentCode();

        $verify = $this->postJson('/api/rider/email/verify', [
            'email' => $this->email,
            'code' => '000000',
        ])->assertStatus(422);

        foreach ([$this->requestCode(), $verify] as $response) {
            $this->assertStringNotContainsString($code, $response->getContent());
        }
    }

    public function test_otp_not_stored_plaintext(): void
    {
        $this->requestCode()->assertOk();
        $code = $this->sentCode();

        $row = RiderEmailVerification::where('email', $this->email)->first();
        $this->assertMatchesRegularExpression('/^\$2[aby]\$/', $row->otp_hash);
        $this->assertNotSame($code, $row->otp_hash);
        $this->assertTrue(Hash::check($code, $row->otp_hash));
    }

    public function test_correct_otp_verifies_email(): void
    {
        $this->requestCode()->assertOk();

        $this->postJson('/api/rider/email/verify', [
            'email' => $this->email,
            'code' => $this->sentCode(),
        ])->assertOk()->assertJsonPath('message', 'Email verified successfully.');

        $this->assertTrue(RiderEmailVerification::isVerified($this->email));
    }

    public function test_incorrect_otp_fails(): void
    {
        $this->requestCode()->assertOk();

        $this->postJson('/api/rider/email/verify', [
            'email' => $this->email,
            'code' => '000000',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Invalid verification code. Please try again.');

        $this->assertFalse(RiderEmailVerification::isVerified($this->email));
    }

    public function test_expired_otp_fails(): void
    {
        $this->requestCode()->assertOk();

        $row = RiderEmailVerification::where('email', $this->email)->first();
        $row->expires_at = now()->subMinute();
        $row->save();

        $this->postJson('/api/rider/email/verify', [
            'email' => $this->email,
            'code' => '000000',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Verification code expired. Please request a new code.');
    }

    public function test_used_otp_cannot_be_reused(): void
    {
        $this->requestCode()->assertOk();
        $code = $this->sentCode();

        $this->postJson('/api/rider/email/verify', [
            'email' => $this->email,
            'code' => $code,
        ])->assertOk();

        $this->postJson('/api/rider/email/verify', [
            'email' => $this->email,
            'code' => $code,
        ])->assertStatus(422);
    }

    public function test_resending_invalidates_previous_otp(): void
    {
        $this->requestCode()->assertOk();
        $old = $this->sentCode();

        // Bypass the 60s cooldown by aging the row (same as waiting).
        RiderEmailVerification::where('email', $this->email)
            ->update(['last_sent_at' => now()->subMinutes(2)]);

        $this->postJson('/api/rider/email/resend', [
            'email' => $this->email,
        ])->assertOk();

        $new = $this->sentCode();
        $this->assertNotSame($old, $new);

        $this->postJson('/api/rider/email/verify', [
            'email' => $this->email,
            'code' => $old,
        ])->assertStatus(422);

        $this->postJson('/api/rider/email/verify', [
            'email' => $this->email,
            'code' => $new,
        ])->assertOk();
    }

    public function test_resend_cooldown_returns_429(): void
    {
        $this->requestCode()->assertOk();

        $this->postJson('/api/rider/email/resend', [
            'email' => $this->email,
        ])->assertStatus(429);
    }

    public function test_attempts_exhausted_returns_429(): void
    {
        $this->requestCode()->assertOk();

        for ($i = 0; $i < RiderEmailVerification::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/rider/email/verify', [
                'email' => $this->email,
                'code' => '000000',
            ])->assertStatus(422);
        }

        $this->postJson('/api/rider/email/verify', [
            'email' => $this->email,
            'code' => '000000',
        ])->assertStatus(429);
    }

    public function test_throttle_blocks_rapid_sends(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/rider/email/request-code', [
                'email' => "throttle-{$i}-" . uniqid() . '@test.com',
            ])->assertOk();
        }

        $this->postJson('/api/rider/email/request-code', [
            'email' => 'throttle-5-' . uniqid() . '@test.com',
        ])->assertStatus(429);
    }

    private function jpg(string $name): UploadedFile
    {
        $base = tempnam(sys_get_temp_dir(), 'otp');
        $path = $base . '.jpg';
        rename($base, $path);
        file_put_contents(
            $path,
            "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00" . str_repeat("\x00", 300)
        );
        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    private function submitData(string $email): array
    {
        return [
            'name' => 'OTP Applicant',
            'email' => $email,
            'phone' => '0917' . random_int(1000000, 9999999),
            'address' => '123 OTP St',
            'vehicle_type' => 'motorcycle',
            'license_plate' => 'OTP 1',
            'license_number' => 'L-OTP',
            'vehicle_registration' => 'R-OTP',
            'documents' => [
                'valid_id' => $this->jpg('valid_id.jpg'),
                'drivers_license' => $this->jpg('license.jpg'),
                'vehicle_registration' => $this->jpg('registration.jpg'),
            ],
        ];
    }

    public function test_unverified_email_cannot_submit_application(): void
    {
        Storage::fake('local');

        $this->post('/api/rider/apply', $this->submitData($this->email))
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Please verify your email address before submitting your rider application.'
            );
    }

    public function test_verified_email_can_submit_application(): void
    {
        Storage::fake('local');
        $this->requestCode()->assertOk();

        $this->postJson('/api/rider/email/verify', [
            'email' => $this->email,
            'code' => $this->sentCode(),
        ])->assertOk();

        $this->post('/api/rider/apply', $this->submitData($this->email))
            ->assertStatus(201);
    }

    public function test_changing_email_invalidates_previous_verification(): void
    {
        Storage::fake('local');
        $this->requestCode()->assertOk();

        $this->postJson('/api/rider/email/verify', [
            'email' => $this->email,
            'code' => $this->sentCode(),
        ])->assertOk();

        // Verification for address A authorizes nothing for address B.
        $this->post('/api/rider/apply', $this->submitData('other-' . $this->email))
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Please verify your email address before submitting your rider application.'
            );

        // ...while the verified address itself still submits.
        $this->post('/api/rider/apply', $this->submitData($this->email))
            ->assertStatus(201);
    }

    public function test_otp_absent_from_logs_and_notifications(): void
    {
        $this->requestCode()->assertOk();
        $code = $this->sentCode();

        $this->postJson('/api/rider/email/verify', [
            'email' => $this->email,
            'code' => $code,
        ])->assertOk();

        $this->assertSame(0, Notification::count());

        $logPath = storage_path('logs/laravel.log');
        if (file_exists($logPath)) {
            $this->assertStringNotContainsString($code, (string) file_get_contents($logPath));
        }
    }
}
