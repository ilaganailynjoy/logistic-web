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

        $this->approve($app)->assertRedirect();

        $this->assertSame('approved', $app->fresh()->status);
        $creds = session('provisioned_credentials');
        $this->assertTrue($creds['generated']);

        Mail::assertSent(RiderAccountApprovedMail::class, function ($mail) use ($app, $creds) {
            if ($mail->application->id !== $app->id) return false;
            if ($mail->temporaryPassword !== $creds['password']) return false;

            $html = $mail->render();

            return $mail->hasTo($app->email)
                && $mail->hasSubject('INVOIZ Rider Account Approved – Login Credentials')
                && str_contains($html, $app->email)
                && str_contains($html, $creds['password'])
                && str_contains($html, RiderAccountApprovedMail::referenceFor($app->fresh()))
                && str_contains($html, 'change your temporary password after your first successful login');
        });
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
