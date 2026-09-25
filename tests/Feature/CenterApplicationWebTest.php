<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\LogisticsCenterApplication;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\RiderEmailVerification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Public Logistics web flows: "Open a Logistics Center" application and
 * "Check a Logistics Center Application Status", plus the login-page links.
 * The mobile API behavior is covered separately by CenterApplicationApiTest.
 */
class CenterApplicationWebTest extends TestCase
{
    private function uniquePhone(): string
    {
        return '0919' . random_int(1000000, 9999999);
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
            'business_name' => 'Web Center ' . uniqid(),
            'owner_name' => 'Maria Clara',
            'email' => 'center-web-' . uniqid() . '@test.com',
            'phone' => $this->uniquePhone(),
            'house_number' => '7',
            'street' => 'Bonifacio St',
            'barangay' => 'Barangay Dos',
            'municipality' => 'Pasig',
            'province' => 'Metro Manila',
            'documents' => [
                'valid_id' => $this->jpg('web-valid.jpg'),
                'business_registration' => $this->jpg('web-dti.jpg'),
            ],
        ];
    }

    /**
     * Satisfy the email OTP submission gate for the given address, mirroring
     * a completed in-form verification (consumed server-side record).
     */
    private function verifyEmail(string $email): void
    {
        RiderEmailVerification::create([
            'email' => strtolower($email),
            'otp_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
            'last_sent_at' => now()->subMinutes(2),
            'consumed_at' => now(),
        ]);
    }

    // ── Application page ────────────────────────────────────────────

    public function test_apply_page_loads_successfully(): void
    {
        $this->get('/logistics-center/apply')
            ->assertOk()
            ->assertSee('Open a Logistics Center')
            ->assertSee('Logistics Center Name')
            ->assertSee('Owner Name')
            ->assertSee("Owner's Valid ID")
            ->assertSee("Business Registration");
    }

    public function test_apply_page_validates_required_fields(): void
    {
        $this->post('/logistics-center/apply', [])
            ->assertSessionHasErrors([
                'business_name',
                'owner_name',
                'email',
                'phone',
                'documents.valid_id',
                'documents.business_registration',
            ]);
    }

    public function test_apply_rejects_invalid_email_and_phone(): void
    {
        $data = $this->submitData();
        $data['email'] = 'not-an-email';
        $data['phone'] = '12345';

        $this->post('/logistics-center/apply', $data)
            ->assertSessionHasErrors(['email', 'phone']);
    }

    public function test_apply_accepts_philippine_mobile_numbers(): void
    {
        Storage::fake('local');
        $accepted = [
            '09171234001',
            '+639171234002',
            '0917 123 4003',
            '09-17-123-4004',
            '+63 917 123 4005',
        ];

        foreach ($accepted as $phone) {
            $data = $this->submitData();
            $data['phone'] = $phone;
            $this->verifyEmail($data['email']);

            $this->post('/logistics-center/apply', $data)
                ->assertSessionHasNoErrors(['phone'])
                ->assertSessionHasNoErrors();
        }

        // +63 / formatted variants are stored normalized to 09XXXXXXXXX.
        $this->assertDatabaseHas('logistics_center_applications', ['phone' => '09171234001']);
        $this->assertDatabaseHas('logistics_center_applications', ['phone' => '09171234002']);
        $this->assertDatabaseHas('logistics_center_applications', ['phone' => '09171234003']);
        $this->assertDatabaseHas('logistics_center_applications', ['phone' => '09171234004']);
        $this->assertDatabaseHas('logistics_center_applications', ['phone' => '09171234005']);
    }

    public function test_apply_rejects_invalid_philippine_mobile_numbers(): void
    {
        Storage::fake('local');
        $rejected = [
            '12345',
            '0917123456',
            '091712345678',
            '12345678901',
            '0281234567',
            '0917123456a',
            '0917 123 45678',
            '+630917123456',
            '(0917) 123-4567extra',
            '',
        ];

        foreach ($rejected as $phone) {
            $data = $this->submitData();
            $data['phone'] = $phone;
            $this->verifyEmail($data['email']);

            $this->post('/logistics-center/apply', $data)
                ->assertSessionHasErrors('phone');
        }

        $this->assertSame(0, LogisticsCenterApplication::count());
    }

    public function test_apply_creates_web_application_with_documents(): void
    {
        Storage::fake('local');
        $data = $this->submitData();
        $this->verifyEmail($data['email']);

        $this->post('/logistics-center/apply', $data)
            ->assertRedirect(route('center-application.apply'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('logistics_center_applications', [
            'email' => $data['email'],
            'status' => 'pending',
            'submitted_via' => 'web',
        ]);

        $app = LogisticsCenterApplication::where('email', $data['email'])->firstOrFail();
        $this->assertSame('7, Bonifacio St, Barangay Dos, Pasig, Metro Manila', $app->address);
        $this->assertSame(2, $app->supportingDocuments()->count());

        $this->assertDatabaseHas('notifications', [
            'type' => 'new_center_application',
            'title' => 'New Center Application',
        ]);
    }

    public function test_apply_rejects_duplicate_email_and_phone(): void
    {
        Storage::fake('local');
        $data = $this->submitData();
        $this->verifyEmail($data['email']);
        $this->post('/logistics-center/apply', $data)->assertSessionHasNoErrors();

        $duplicateEmail = $this->submitData();
        $duplicateEmail['email'] = $data['email'];
        $this->from(route('center-application.apply'))
            ->post('/logistics-center/apply', $duplicateEmail)
            ->assertSessionHasErrors('email')
            ->assertSessionHasErrors('email', 'This email address already has an application on file. Check your application status or use a different email address.');

        $duplicatePhone = $this->submitData();
        $duplicatePhone['phone'] = $data['phone'];
        $this->verifyEmail($duplicatePhone['email']);
        $this->from(route('center-application.apply'))
            ->post('/logistics-center/apply', $duplicatePhone)
            ->assertSessionHasErrors('phone')
            ->assertSessionHasErrors('phone', 'This contact number is already on file for another application. Check your application status or use a different number.');
    }

    public function test_apply_rejects_equivalent_phone_formats_as_duplicates(): void
    {
        Storage::fake('local');

        $first = $this->submitData();
        $first['phone'] = '09171234567';
        $this->verifyEmail($first['email']);
        $this->post('/logistics-center/apply', $first)
            ->assertSessionHasNoErrors();

        foreach (['+639171234567', '+63 917 123 4567', '0917-123-4567'] as $duplicate) {
            $data = $this->submitData();
            $data['phone'] = $duplicate;
            $this->verifyEmail($data['email']);

            $this->from(route('center-application.apply'))
                ->post('/logistics-center/apply', $data)
                ->assertSessionHasErrors('phone')
                ->assertSessionHasErrors('phone', 'This contact number is already on file for another application. Check your application status or use a different number.');
        }

        $this->assertSame(1, LogisticsCenterApplication::where('phone', '09171234567')->count());
    }

    public function test_apply_accepts_a_different_phone_when_another_exists(): void
    {
        Storage::fake('local');

        $first = $this->submitData();
        $first['phone'] = '09171234567';
        $this->verifyEmail($first['email']);
        $this->post('/logistics-center/apply', $first)
            ->assertSessionHasNoErrors();

        $different = $this->submitData();
        $different['phone'] = '09181234567';
        $this->verifyEmail($different['email']);
        $this->post('/logistics-center/apply', $different)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(1, LogisticsCenterApplication::where('phone', '09181234567')->count());
    }

    // ── Address dropdowns (Province → City/Municipality → Barangay) ──

    private function seedAddressData(): Province
    {
        $province = Province::create(['code' => '013900000', 'name' => 'Metro Manila', 'region_code' => '013900000']);
        $municipality = Municipality::create(['code' => '137404000', 'name' => 'Pasig', 'province_id' => $province->id, 'region_code' => '013900000']);
        Barangay::create(['code' => '137404001', 'name' => 'San Nicolas', 'municipality_id' => $municipality->id]);

        return $province;
    }

    public function test_apply_page_renders_address_dropdowns_when_psgc_data_is_available(): void
    {
        $province = $this->seedAddressData();

        $this->get('/logistics-center/apply')
            ->assertOk()
            ->assertSee('<select id="province"', false)
            ->assertSee('<select id="municipality"', false)
            ->assertSee('<select id="barangay"', false)
            ->assertSee('value="Metro Manila" data-id="' . $province->id . '"', false);
    }

    public function test_apply_page_keeps_old_address_values_after_failed_submission(): void
    {
        $province = $this->seedAddressData();

        $data = $this->submitData();
        $data['email'] = 'not-an-email';

        $this->post('/logistics-center/apply', $data)
            ->assertSessionHasErrors('email')
            ->assertRedirect();

        $this->get(route('center-application.apply'))
            ->assertOk()
            ->assertSee('value="Metro Manila" data-id="' . $province->id . '" selected', false)
            ->assertSee('value="Pasig" data-id="', false)
            ->assertSee('value="San Nicolas" data-id="', false)
            ->assertSee('value="Barangay Dos" data-id="" selected', false);
    }

    public function test_apply_page_falls_back_to_text_inputs_without_psgc_data(): void
    {
        $this->get('/logistics-center/apply')
            ->assertOk()
            ->assertSee('<input id="province"', false)
            ->assertSee('<input id="municipality"', false)
            ->assertSee('<input id="barangay"', false);
    }

    // ── Application status page ─────────────────────────────────────

    public function test_status_page_loads_successfully(): void
    {
        $this->get('/logistics-center/application-status')
            ->assertOk()
            ->assertSee('Check a Logistics Center Application Status')
            ->assertSee('Email Address');
    }

    public function test_status_validates_email(): void
    {
        $this->post('/logistics-center/application-status', ['email' => 'nope'])
            ->assertSessionHasErrors('email');
    }

    public function test_status_displays_application(): void
    {
        LogisticsCenterApplication::create([
            'business_name' => 'Status Hub Center',
            'owner_name' => 'Jose Rizal',
            'email' => 'status-finder@test.com',
            'phone' => '09171234567',
            'address' => '3 Status St, Manila',
            'status' => 'pending',
            'submitted_via' => 'web',
        ]);

        $this->post('/logistics-center/application-status', ['email' => 'status-finder@test.com'])
            ->assertOk()
            ->assertSee('Status Hub Center')
            ->assertSee('Pending');
    }

    public function test_status_shows_not_found_when_no_application(): void
    {
        $this->post('/logistics-center/application-status', ['email' => 'nobody@test.com'])
            ->assertOk()
            ->assertSee('No application found');
    }

    public function test_status_does_not_expose_private_notes_or_internal_ids(): void
    {
        $app = LogisticsCenterApplication::create([
            'business_name' => 'Confidential Center',
            'owner_name' => 'Juan Tamad',
            'email' => 'secret-status@test.com',
            'phone' => '09171234568',
            'address' => '9 Private St, Manila',
            'status' => 'rejected',
            'notes' => 'CONFIDENTIAL:: applicant flagged for fraudulent documents',
            'reviewed_at' => now(),
            'submitted_via' => 'mobile',
        ]);

        $this->post('/logistics-center/application-status', ['email' => 'secret-status@test.com'])
            ->assertOk()
            ->assertSee('Confidential Center')
            ->assertDontSee('CONFIDENTIAL::')
            // The internal DB id must never surface in a URL, element id,
            // name, or value. A bare assertDontSee((string) $app->id) would
            // false-positive once the auto-increment counter hits a number
            // that coincidentally appears in shared CSS (e.g. the
            // "http://www.w3.org/2000/svg" SVG namespace), so check the
            // concrete leak channels instead.
            ->assertDontSee('center-applications/' . $app->id)
            ->assertDontSee('application-status/' . $app->id)
            ->assertDontSeeHtml('id="' . $app->id . '"')
            ->assertDontSeeHtml('name="' . $app->id . '"')
            ->assertDontSeeHtml('value="' . $app->id . '"')
            ->assertSee('not approved');
    }

    // ── Multi-step wizard structure ─────────────────────────────────

    public function test_wizard_renders_four_step_indicator_items(): void
    {
        $html = $this->get('/logistics-center/apply')->assertOk()->content();

        // All four step titles must appear in the indicator list
        $this->assertStringContainsString('Center &amp; Owner', $html);
        $this->assertStringContainsString('Center Location', $html);
        $this->assertStringContainsString('Supporting Documents', $html);
        $this->assertStringContainsString('Review &amp; Submit', $html);

        // Step headings for each wizard panel are in the DOM
        $this->assertStringContainsString('wizard-step-1', $html);
        $this->assertStringContainsString('wizard-step-2', $html);
        $this->assertStringContainsString('wizard-step-3', $html);
        $this->assertStringContainsString('wizard-step-4', $html);
    }

    public function test_wizard_initial_step_is_zero_on_first_visit(): void
    {
        $this->get('/logistics-center/apply')
            ->assertOk()
            ->assertSee('data-wizard-initial-step="0"', false)
            ->assertSee('data-wizard-error-steps="[]"', false);
    }

    public function test_wizard_opens_step_one_on_invalid_email_error(): void
    {
        $data = $this->submitData();
        $data['email'] = 'not-an-email';

        $this->from(route('center-application.apply'))
            ->post('/logistics-center/apply', $data);

        $html = $this->get(route('center-application.apply'))->content();

        // Step 1 (Center & Owner) contains the email field — initial step should be 0 (zero-based)
        $this->assertStringContainsString('data-wizard-initial-step="0"', $html);
        $this->assertStringContainsString('Fix in Step 1', $html);
    }

    public function test_wizard_opens_step_three_on_missing_document_error(): void
    {
        // Submit valid text fields but no documents — the required-doc errors
        // should route to step 3 (zero-based index 2)
        $data = $this->submitData();
        unset($data['documents']);
        $this->verifyEmail($data['email']);

        $this->from(route('center-application.apply'))
            ->post('/logistics-center/apply', $data);

        $html = $this->get(route('center-application.apply'))->content();

        $this->assertStringContainsString('data-wizard-initial-step="2"', $html);
        $this->assertStringContainsString('Fix in Step 3', $html);
    }

    public function test_wizard_error_summary_groups_by_step_and_links_back(): void
    {
        // Submit completely empty form — errors in step 1 and step 3
        $this->from(route('center-application.apply'))
            ->post('/logistics-center/apply', []);

        $html = $this->get(route('center-application.apply'))->content();

        // Both Fix-in-step buttons should be present
        $this->assertStringContainsString('Fix in Step 1', $html);
        $this->assertStringContainsString('Fix in Step 3', $html);
    }

    public function test_wizard_contains_review_and_submit_section(): void
    {
        $this->get('/logistics-center/apply')
            ->assertOk()
            ->assertSee('Review your application')
            ->assertSee('Privacy')
            ->assertSee('Submit Application');
    }

    public function test_wizard_has_back_and_next_buttons(): void
    {
        $html = $this->get('/logistics-center/apply')->content();

        // Back button exists (hidden via x-show on step 0, but in DOM)
        $this->assertStringContainsString('x-on:click="back"', $html);

        // Next button exists (visible when step < 3)
        $this->assertStringContainsString('x-on:click="next"', $html);

        // Submit button exists (type=submit, visible on step 3)
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('Submit Application', $html);
    }

    public function test_wizard_live_region_for_step_changes(): void
    {
        $html = $this->get('/logistics-center/apply')->content();

        // Announce region for assistive technology
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('aria-label="Application steps"', $html);
    }

    public function test_wizard_mobile_progress_summary(): void
    {
        $html = $this->get('/logistics-center/apply')->content();

        $this->assertStringContainsString('sm:hidden', $html);
        $this->assertStringContainsString('progressPercent()', $html);
    }

    public function test_wizard_error_summary_is_alert_and_grouped(): void
    {
        $this->from(route('center-application.apply'))
            ->post('/logistics-center/apply', []);

        $html = $this->get(route('center-application.apply'))->content();

        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('aria-live="assertive"', $html);
    }

    public function test_failed_submission_records_wizard_error_steps_on_later_success(): void
    {
        $data = $this->submitData();
        $data['phone'] = 'not-a-phone';
        $email = $data['email'];
        $this->verifyEmail($email);

        // First attempt fails validation on the phone (step 1) only — documents
        // are valid, so step 3 must NOT be recorded.
        $this->from(route('center-application.apply'))
            ->post('/logistics-center/apply', $data)
            ->assertSessionHasErrors('phone');

        $this->assertDatabaseMissing('logistics_center_applications', ['email' => $email]);

        // Same email retries with a valid phone and succeeds — the staged step
        // is carried onto the created application record for the admins.
        $data['phone'] = $this->uniquePhone();
        $this->post('/logistics-center/apply', $data)
            ->assertSessionHas('success');

        $application = LogisticsCenterApplication::where('email', $email)->first();
        $this->assertNotNull($application);
        $this->assertSame([1], $application->wizard_error_steps);
    }

    // ── Login page links ────────────────────────────────────────────

    public function test_login_page_links_to_both_center_actions(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Open a Logistics Center')
            ->assertSee('Check a Logistics Center Application Status')
            ->assertSee(route('center-application.apply'), false)
            ->assertSee(route('center-application.status'), false);
    }
}