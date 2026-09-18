<?php

namespace Tests\Feature;

use App\Mail\CenterApplicationApprovedMail;
use App\Models\LogisticsCenterApplication;
use App\Models\LogisticsCenterApplicationDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Admin-side Logistics Center application review: index access control,
 * search/status filters, pagination (including the per-page selector and its
 * fallback), the empty state, guarded document view/download, the recorded
 * wizard validation-step badges, and the approve/reject life-cycle.
 */
class CenterApplicationAdminTest extends TestCase
{
    private function application(array $overrides = []): LogisticsCenterApplication
    {
        return LogisticsCenterApplication::create(array_merge([
            'business_name' => 'Review Center ' . uniqid(),
            'owner_name' => 'Owner Name',
            'email' => 'admin-test-' . uniqid() . '@test.com',
            'phone' => '0917' . random_int(1000000, 9999999),
            'address' => '1 Admin St, Manila',
            'status' => 'pending',
            'submitted_via' => 'web',
        ], $overrides));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // ── Access control ───────────────────────────────────────────────

    public function test_index_requires_login(): void
    {
        $this->get('/center-applications')->assertRedirect(route('login'));
    }

    public function test_index_rejects_non_admin_roles(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']))
            ->get('/center-applications')
            ->assertForbidden();
    }

    public function test_index_lists_applications_for_admin(): void
    {
        $application = $this->application();

        $this->actingAs($this->admin())
            ->get('/center-applications')
            ->assertOk()
            ->assertSee('Logistics Center Applications')
            ->assertSee($application->business_name)
            ->assertSee($application->email);
    }

    // ── Filters ──────────────────────────────────────────────────────

    public function test_search_limits_results(): void
    {
        $hit = $this->application(['business_name' => 'Sunrise Bistro Center']);
        $miss = $this->application(['business_name' => 'Mountain Peak Center']);

        $this->actingAs($this->admin())
            ->get('/center-applications?search=Sunrise')
            ->assertOk()
            ->assertSee($hit->business_name)
            ->assertDontSee($miss->business_name);
    }

    public function test_status_filter_limits_results(): void
    {
        $pending = $this->application(['status' => 'pending']);
        $this->application(['status' => 'approved']);

        $this->actingAs($this->admin())
            ->get('/center-applications?status=approved')
            ->assertOk()
            ->assertSee('Approved')
            ->assertDontSee($pending->business_name);
    }

    // ── Pagination ───────────────────────────────────────────────────

    public function test_pagination_respects_per_page(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->application();
        }

        $this->actingAs($this->admin())
            ->get('/center-applications?per_page=25')
            ->assertOk()
            ->assertSee('Showing 1–12 of 12 applications')
            ->assertDontSee('Showing 1–10 of 12 applications');
    }

    public function test_invalid_per_page_falls_back_to_default(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->application();
        }

        $this->actingAs($this->admin())
            ->get('/center-applications?per_page=999')
            ->assertOk()
            ->assertSee('Showing 1–10 of 12 applications')
            ->assertSee('page=2', false);
    }

    public function test_pagination_preserves_filters_in_page_links(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->application(['status' => 'pending']);
        }

        $this->actingAs($this->admin())
            ->get('/center-applications?search=Review&status=pending&per_page=10')
            ->assertOk()
            ->assertSee('page=2', false)
            ->assertSee('search=Review', false)
            ->assertSee('status=pending', false)
            ->assertSee('per_page=10', false);
    }

    public function test_empty_index_renders_empty_state(): void
    {
        $this->actingAs($this->admin())
            ->get('/center-applications')
            ->assertOk()
            ->assertSee('No applications found');
    }

    // ── Wizard validation-step badges ───────────────────────────────

    public function test_index_shows_wizard_error_step_badges(): void
    {
        $this->application(['wizard_error_steps' => [1]]);
        $this->application(['wizard_error_steps' => [3]]);

        $this->actingAs($this->admin())
            ->get('/center-applications')
            ->assertOk()
            ->assertSee('Step 1 — Center & Owner')
            ->assertSee('Step 3 — Supporting Documents');
    }

    public function test_index_shows_all_steps_when_multiple_wizard_errors(): void
    {
        $this->application(['wizard_error_steps' => [1, 3]]);

        $this->actingAs($this->admin())
            ->get('/center-applications')
            ->assertOk()
            ->assertSee('Step 1 — Center & Owner')
            ->assertSee('Step 3 — Supporting Documents')
            ->assertDontSee('Step 2 —')
            ->assertDontSee('Step 4 —');
    }

    public function test_index_shows_neutral_badge_when_no_wizard_steps_recorded(): void
    {
        $this->application();
        $this->application(['wizard_error_steps' => []]);

        $this->actingAs($this->admin())
            ->get('/center-applications')
            ->assertOk()
            ->assertSee('No recorded wizard validation step')
            ->assertDontSee('Step 1 —');
    }

    public function test_index_tolerates_malformed_or_legacy_wizard_error_steps(): void
    {
        $this->application(['wizard_error_steps' => ['not-a-step', 9, -1]]);
        $this->application(['wizard_error_steps' => '1,3']);

        $this->actingAs($this->admin())
            ->get('/center-applications')
            ->assertOk()
            ->assertSee('No recorded wizard validation step')
            ->assertSee('Step 1 — Center & Owner')
            ->assertSee('Step 3 — Supporting Documents');
    }

    // ── Approve & reject ───────────────────────────────────────────

    public function test_admin_can_approve_pending_application(): void
    {
        Mail::fake();
        $application = $this->application();

        $this->actingAs($this->admin())
            ->post(route('center-applications.approve', $application), ['notes' => 'Looks good'])
            ->assertRedirect(route('center-applications.show', $application));

        $this->assertSame('approved', $application->fresh()->status);
        $this->assertNotNull($application->fresh()->provisioned_at);

        $this->assertDatabaseHas('logistics_centers', ['name' => $application->business_name]);
        $this->assertDatabaseHas('users', ['email' => $application->email, 'role' => 'staff']);
        $this->assertDatabaseHas('notifications', ['type' => 'center_approved']);
        Mail::assertSent(fn (CenterApplicationApprovedMail $mail) => $mail->hasTo($application->email));
    }

    public function test_non_admin_cannot_approve_or_reject(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $application = $this->application();

        $this->actingAs($staff)
            ->post(route('center-applications.approve', $application))
            ->assertForbidden();

        $this->actingAs($staff)
            ->post(route('center-applications.reject', $application), ['reason' => 'Nope'])
            ->assertForbidden();

        $this->assertSame('pending', $application->fresh()->status);
    }

    public function test_admin_can_reject_pending_application(): void
    {
        $application = $this->application();

        $this->actingAs($this->admin())
            ->post(route('center-applications.reject', $application), ['reason' => 'Incomplete supporting documents'])
            ->assertRedirect(route('center-applications.show', $application));

        $this->assertSame('rejected', $application->fresh()->status);
        $this->assertSame('Incomplete supporting documents', $application->fresh()->notes);
        $this->assertDatabaseHas('notifications', ['type' => 'center_rejected']);
    }

    // ── Documents ────────────────────────────────────────────────────

    public function test_document_view_requires_admin_and_serves_file(): void
    {
        Storage::fake('local');
        $application = $this->application();
        $path = UploadedFile::fake()->create('valid.jpg', 2, 'image/jpeg')
            ->storeAs("center-documents/{$application->id}", 'valid.jpg');

        $document = LogisticsCenterApplicationDocument::create([
            'logistics_center_application_id' => $application->id,
            'document_type' => 'valid_id',
            'original_filename' => 'valid.jpg',
            'stored_path' => $path,
            'mime_type' => 'image/jpeg',
            'file_size' => 2048,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'staff']))
            ->get(route('center-applications.documents.view', $document))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('center-applications.documents.view', $document))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Content-Disposition', 'inline; filename="valid.jpg"');

        $this->actingAs($this->admin())
            ->get(route('center-applications.documents.download', $document))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="valid.jpg"');
    }

    public function test_document_view_previews_when_stored_mime_is_generic(): void
    {
        Storage::fake('local');
        $application = $this->application();

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        $path = UploadedFile::fake()->createWithContent('blank.png', $png)
            ->storeAs("center-documents/{$application->id}", 'blank.png');

        $document = LogisticsCenterApplicationDocument::create([
            'logistics_center_application_id' => $application->id,
            'document_type' => 'valid_id',
            'original_filename' => 'blank.png',
            'stored_path' => $path,
            'mime_type' => 'application/octet-stream',
            'file_size' => strlen($png),
        ]);

        // Despite the generic stored MIME, the browser must receive a
        // renderable type plus an inline disposition so it previews instead
        // of downloading.
        $this->actingAs($this->admin())
            ->get(route('center-applications.documents.view', $document))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename="blank.png"');
    }

    public function test_document_view_404_when_file_is_missing(): void
    {
        Storage::fake('local');
        $application = $this->application();

        $document = LogisticsCenterApplicationDocument::create([
            'logistics_center_application_id' => $application->id,
            'document_type' => 'valid_id',
            'original_filename' => 'ghost.jpg',
            'stored_path' => "center-documents/{$application->id}/ghost.jpg",
            'mime_type' => 'image/jpeg',
            'file_size' => 2048,
        ]);

        $this->actingAs($this->admin())
            ->get(route('center-applications.documents.view', $document))
            ->assertNotFound();
    }
}