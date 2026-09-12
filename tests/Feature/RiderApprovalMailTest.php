<?php

namespace Tests\Feature;

use App\Mail\RiderAccountApprovedMail;
use App\Models\LogisticsCenter;
use App\Models\Notification;
use App\Models\Rider;
use App\Models\RiderApplication;
use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Rider approval email: credentials are mailed once after successful
 * provisioning, never persisted, and never exposed through the API.
 * No real emails are sent here (Mail::fake()).
 */
class RiderApprovalMailTest extends TestCase
{
    private function admin(): User
    {
        return User::create([
            'name' => 'Mail Admin',
            'first_name' => 'Mail',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'mail-admin-' . uniqid() . '@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000073',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => 'admin',
            'status' => 'active',
            'center_id' => null,
            'email_verified_at' => now(),
        ]);
    }

    private function application(): RiderApplication
    {
        $center = LogisticsCenter::create([
            'name' => 'Mail Center ' . uniqid(), 'address' => 'M St',
            'city' => 'M City', 'province' => 'M', 'is_active' => true,
        ]);
        $area = ServiceArea::create([
            'logistics_center_id' => $center->id,
            'name' => 'Mail Area ' . uniqid(), 'is_active' => true,
        ]);

        return RiderApplication::create([
            'name' => 'Mail Applicant',
            'email' => 'mail-app-' . uniqid() . '@test.com',
            'phone' => '0917' . random_int(1000000, 9999999),
            'address' => '123 M St',
            'vehicle_type' => 'motorcycle',
            'license_plate' => 'MAIL 1',
            'license_number' => 'L-MAIL',
            'vehicle_registration' => 'R-MAIL',
            'status' => 'pending',
            'submitted_via' => 'mobile',
        ]);
    }

    private function approve(RiderApplication $app, array $extra = [])
    {
        $admin = $this->admin();
        $center = LogisticsCenter::where('is_active', true)->first();
        $area = ServiceArea::where('logistics_center_id', $center->id)->first();

        return $this->actingAs($admin)->post(
            "/rider-applications/{$app->id}/approve",
            array_merge([
                'center_id' => $center->id,
                'service_area_id' => $area->id,
            ], $extra)
        );
    }

    public function test_generated_password_email_has_all_content(): void
    {
        Mail::fake();
        $app = $this->application();

        $response = $this->approve($app)->assertRedirect();

        $this->assertSame('approved', $app->fresh()->status);

        // The Manager must never see the plaintext password: it must not be in
        // the response body nor in any session flash.
        $this->assertStringNotContainsString('temporaryPassword', $response->getContent());
        $response->assertSessionMissing('provisioned_credentials');

        $sent = Mail::sent(RiderAccountApprovedMail::class)
            ->first(fn ($mail) => $mail->application->id === $app->id);

        $this->assertNotNull($sent);
        $sentPassword = $sent->temporaryPassword;
        $html = $sent->render();

        $this->assertTrue($sent->hasTo($app->email));
        $this->assertTrue($sent->hasSubject('INVOIZ Rider Account Approved – Login Credentials'));
        $this->assertStringContainsString($app->email, $html);
        $this->assertStringContainsString($sentPassword, $html);
        $this->assertStringContainsString(RiderAccountApprovedMail::referenceFor($app->fresh()), $html);
        $this->assertStringContainsString('change your temporary password after your first successful login', $html);

        // The rider still receives the password through email (the mailable
        // carries it) while the database holds only the hash.
        $user = User::where('email', $app->email)->first();
        $this->assertTrue(Hash::check($sentPassword, $user->password));
        $this->assertStringNotContainsString($sentPassword, json_encode($user->toArray()));
        $this->assertStringNotContainsString($sentPassword, json_encode($app->fresh()->toArray()));
    }

    public function test_supplied_password_is_mailed_but_never_persisted(): void
    {
        Mail::fake();
        $app = $this->application();
        $secret = 'ManagerSet-2026!';

        $this->approve($app, [
            'password' => $secret,
            'password_confirmation' => $secret,
        ])->assertRedirect();

        Mail::assertSent(RiderAccountApprovedMail::class, function ($mail) use ($app, $secret) {
            return $mail->hasTo($app->email)
                && $mail->temporaryPassword === $secret
                && str_contains($mail->render(), $secret);
        });

        // Only the hash exists; plaintext appears in no persistent record.
        $user = User::where('email', $app->email)->first();
        $this->assertTrue(Hash::check($secret, $user->password));
        $this->assertStringNotContainsString($secret, json_encode($user->toArray()));
        $this->assertStringNotContainsString($secret, json_encode($app->fresh()->toArray()));
        foreach (Notification::all() as $notification) {
            $this->assertStringNotContainsString($secret, json_encode($notification->toArray()));
        }
    }

    public function test_no_email_when_provisioning_fails(): void
    {
        Mail::fake();
        $app = $this->application();

        $this->approve($app, [
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertRedirect();

        $this->assertSame('pending', $app->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_status_api_never_exposes_password(): void
    {
        Mail::fake();
        $app = $this->application();
        $secret = 'StatusCheck-2026!';

        $this->approve($app, [
            'password' => $secret,
            'password_confirmation' => $secret,
        ])->assertRedirect();

        $response = $this->getJson(
            '/api/rider/application-status?email=' . urlencode($app->email)
        )->assertOk()->assertJsonPath('application.status', 'approved');

        $this->assertStringNotContainsString($secret, $response->getContent());
    }

    public function test_resend_rotates_password_and_mails_only_to_applicant_email(): void
    {
        Mail::fake();
        $app = $this->application();
        $oldSecret = 'OldTemp-2026!';

        $this->approve($app, [
            'password' => $oldSecret,
            'password_confirmation' => $oldSecret,
        ])->assertRedirect();

        $response = $this->post("/rider-applications/{$app->id}/resend-credentials")->assertRedirect();

        // The Manager must never see the new plaintext password: not in the
        // response body, not in any session flash.
        $this->assertStringNotContainsString('temporaryPassword', $response->getContent());
        $response->assertSessionMissing('provisioned_credentials');

        // Extract the new password from the *resent* (latest) mailable — the
        // only legitimate place it exists, and distinct from the approval mail.
        $resent = Mail::sent(RiderAccountApprovedMail::class)
            ->last(function ($mail) use ($app) { return $mail->application->id === $app->id; });
        $this->assertNotNull($resent);
        $newSecret = $resent->temporaryPassword;
        $this->assertNotSame($oldSecret, $newSecret);

        $user = User::where('email', $app->email)->first();

        // Old temporary password is rotated out; only the new one verifies
        // against the stored (already hashed) password.
        $this->assertTrue(Hash::check($newSecret, $user->password));
        $this->assertFalse(Hash::check($oldSecret, $user->password));

        // Plaintext is never persisted anywhere (DB rows, applications,
        // notifications) and never exposed through the status API.
        $this->assertStringNotContainsString($newSecret, json_encode($user->toArray()));
        $this->assertStringNotContainsString($newSecret, json_encode($app->fresh()->toArray()));
        foreach (Notification::all() as $notification) {
            $this->assertStringNotContainsString($newSecret, json_encode($notification->toArray()));
        }
        $this->getJson('/api/rider/application-status?email=' . urlencode($app->email))
            ->assertOk()
            ->assertJsonMissing(['password' => $newSecret]);

        $html = $resent->render();
        $this->assertTrue($resent->hasTo($app->email));
        $this->assertSame($newSecret, $resent->temporaryPassword);
        $this->assertNotSame($oldSecret, $resent->temporaryPassword);
        $this->assertStringContainsString($newSecret, $html);
        $this->assertStringNotContainsString($oldSecret, $html);

        // The resent message relies solely on the configured From (the INVOIZ
        // system Gmail) and never overrides the sender address itself.
        $this->assertEmpty($resent->from);
    }

    public function test_resend_mail_failure_keeps_account_and_never_reveals_password(): void
    {
        Mail::fake();
        $app = $this->application();
        $oldSecret = 'KeepOld-2026!';

        $this->approve($app, [
            'password' => $oldSecret,
            'password_confirmation' => $oldSecret,
        ])->assertRedirect();

        // Simulate a transport-level failure during the resend send, exactly
        // like an SMTP outage. The exception carries no password material.
        $thrower = new class {
            public function send($mailable): void
            {
                throw new \RuntimeException('Simulated SMTP outage during resend');
            }
        };
        Mail::shouldReceive('to')->once()->andReturn($thrower);

        // report($e) in the controller logs the exception; assert it never
        // contains the plaintext password.
        Log::shouldReceive('error')->once()->with(
            \Mockery::on(fn ($subject) => ! str_contains((string) $subject, 'KeepOld-2026!')),
            \Mockery::any()
        );

        $response = $this->post("/rider-applications/{$app->id}/resend-credentials")
            ->assertRedirect()
            ->assertSessionHas('success');

        // The Manager must never see the password even on failure — not in the
        // session flash and not in the success warning message.
        $response->assertSessionMissing('provisioned_credentials');
        $success = session('success');
        $this->assertStringContainsString('could not be emailed to ' . $app->email, $success);
        $this->assertStringNotContainsString('KeepOld-2026!', $success);

        // The rider account is kept (never deleted), and the old temporary
        // password was rotated out even though delivery failed.
        $this->assertSame('approved', $app->fresh()->status);
        $user = User::where('email', $app->email)->first();
        $this->assertNotNull($user);
        $this->assertNotNull(Rider::where('email', $app->email)->first());
        $this->assertFalse(Hash::check($oldSecret, $user->password));

        // Plaintext is never persisted anywhere.
        $this->assertStringNotContainsString('KeepOld-2026!', json_encode($app->fresh()->toArray()));
        $this->assertStringNotContainsString('KeepOld-2026!', json_encode($user->toArray()));
        foreach (Notification::all() as $notification) {
            $this->assertStringNotContainsString('KeepOld-2026!', json_encode($notification->toArray()));
        }
    }

    public function test_email_contains_no_deep_link_or_credentials_in_urls(): void
    {
        Mail::fake();
        $app = $this->application();

        $this->approve($app)->assertRedirect();

        $sent = Mail::sent(RiderAccountApprovedMail::class)
            ->last(fn ($mail) => $mail->application->id === $app->id);
        $this->assertNotNull($sent);
        $html = $sent->render();

        // No custom-scheme deep link in the email.
        $this->assertStringNotContainsString('invoizrider://login', $html);
        $this->assertStringNotContainsString('invoizrider://', $html);

        // No clickable CTA button at all.
        $this->assertStringNotContainsString('Open the INVOIZ Rider App', $html);

        // Credentials never placed inside any URL.
        $this->assertStringNotContainsString('password=', $html);
        $this->assertStringNotContainsString('token=', $html);
        $this->assertStringNotContainsString('otp=', $html);
        $this->assertStringNotContainsString('email=', $html);

        // The email still contains all required content.
        $this->assertTrue($sent->hasTo($app->email));
        $this->assertStringContainsString($app->email, $html);
        $this->assertStringContainsString($sent->temporaryPassword, $html);
        $this->assertStringContainsString(RiderAccountApprovedMail::referenceFor($app->fresh()), $html);
        $this->assertStringContainsString('change your temporary password after your first successful login', $html);
        $this->assertStringContainsString('open the INVOIZ Rider App and log in', $html);
    }

    public function test_resend_email_contains_no_deep_link(): void
    {
        Mail::fake();
        $app = $this->application();

        $this->approve($app)->assertRedirect();
        $this->post("/rider-applications/{$app->id}/resend-credentials")->assertRedirect();

        $resent = Mail::sent(RiderAccountApprovedMail::class)
            ->last(fn ($mail) => $mail->application->id === $app->id);
        $this->assertNotNull($resent);
        $html = $resent->render();

        $this->assertStringNotContainsString('invoizrider://login', $html);
        $this->assertStringNotContainsString('invoizrider://', $html);
        $this->assertStringNotContainsString('password=', $html);
        $this->assertTrue($resent->hasTo($app->email));
    }

    public function test_resend_requires_approved_provisioned_application(): void
    {
        Mail::fake();
        $app = $this->application();
        $admin = $this->admin();

        // A pending application can never have credentials resent.
        $this->actingAs($admin)
            ->post("/rider-applications/{$app->id}/resend-credentials")
            ->assertStatus(422);
        Mail::assertNothingSent();
        $this->assertSame('pending', $app->fresh()->status);
    }

    public function test_resend_requires_provisioning_even_when_approved(): void
    {
        Mail::fake();
        $app = $this->application();
        $app->update(['status' => 'approved', 'provisioned_at' => null]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post("/rider-applications/{$app->id}/resend-credentials")
            ->assertStatus(422);

        Mail::assertNothingSent();
        $this->assertNull(User::where('email', $app->email)->first());
    }

    public function test_email_failure_keeps_account_and_shows_safe_warning(): void
    {
        Mail::fake();
        $app = $this->application();
        $secret = 'FailSafe-2026!';

        // Simulate a transport-level failure: the mailer throws while
        // delivering, exactly like an SMTP outage during the controller's
        // synchronous send. The exception carries no password material.
        $thrower = new class {
            public function send($mailable): void
            {
                throw new \RuntimeException('Simulated SMTP transport failure');
            }
        };
        Mail::shouldReceive('to')->once()->andReturn($thrower);

        // report($e) in the controller logs the exception; assert it never
        // contains the plaintext password.
        Log::shouldReceive('error')->once()->with(
            \Mockery::on(fn ($subject) => ! str_contains((string) $subject, 'FailSafe-2026!')),
            \Mockery::any()
        );

        $response = $this->approve($app, [
            'password' => $secret,
            'password_confirmation' => $secret,
        ]);

        $response->assertRedirect()->assertSessionHas('success');

        $success = session('success');
        $this->assertStringContainsString('could not be emailed to ' . $app->email, $success);
        $this->assertStringNotContainsString($secret, $success);

        // The rider account is kept even though the email transport failed.
        $this->assertSame('approved', $app->fresh()->status);
        $this->assertNotNull(User::where('email', $app->email)->first());
        $this->assertNotNull(Rider::where('email', $app->email)->first());

        // Plaintext is never persisted anywhere.
        $this->assertStringNotContainsString($secret, json_encode($app->fresh()->toArray()));
        $this->assertStringNotContainsString($secret, json_encode(User::where('email', $app->email)->first()->toArray()));
        foreach (Notification::all() as $notification) {
            $this->assertStringNotContainsString($secret, json_encode($notification->toArray()));
        }
    }
}
