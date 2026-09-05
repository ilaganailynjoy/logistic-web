<?php

namespace Tests\Feature;

use App\Models\RiderApplication;
use App\Models\RiderApplicationDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Mobile rider-application flow: applicant submits via POST /api/rider/apply
 * (personal + rider type + vehicle ownership + conditional documents), checks
 * status, and admins review. Vehicle types are seeded so activeLabels() is non-empty.
 */
class RiderApplicationControllerTest extends TestCase
{
    private function uniquePhone(): string
    {
        return '0917' . random_int(1000000, 9999999);
    }

    private function jpg(string $name): UploadedFile
    {
        $base = tempnam(sys_get_temp_dir(), 'rf');
        $path = $base . '.jpg';
        rename($base, $path);
        file_put_contents(
            $path,
            "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00" . str_repeat("\x00", 300)
        );
        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    private function submitData(string $ownership = 'own', string $riderType = 'full_time'): array
    {
        $docs = [
            'valid_id' => $this->jpg('valid_id.jpg'),
            'drivers_license' => $this->jpg('license.jpg'),
            'vehicle_registration' => $this->jpg('registration.jpg'),
        ];
        if ($ownership === 'borrowed') {
            $docs['authorization_letter'] = $this->jpg('auth.jpg');
            $docs['owner_valid_id'] = $this->jpg('owner.jpg');
        }
        if ($ownership === 'financing') {
            $docs['sales_invoice'] = $this->jpg('invoice.jpg');
            $docs['encumbrance_certificate'] = $this->jpg('encumbrance.jpg');
        }

        return [
            'name' => 'Applicant Test ' . uniqid(),
            'email' => 'applier-' . uniqid() . '@test.com',
            'phone' => $this->uniquePhone(),
            'address' => '123 Rider St, Manila',
            'vehicle_type' => 'motorcycle',
            'license_plate' => 'ABC 101',
            'license_number' => 'LIC-101',
            'vehicle_registration' => 'REG-101',
            'rider_type' => $riderType,
            'vehicle_ownership' => $ownership,
            'documents' => $docs,
        ];
    }

    public function test_apply_with_rider_type_and_ownership_succeeds(): void
    {
        Storage::fake('local');

        $response = $this->post('/api/rider/apply', $this->submitData('borrowed', 'part_time'));

        $response->assertStatus(201)
            ->assertJsonPath('application.status', 'pending');

        $id = $response->json('application.id');
        $this->assertDatabaseHas('rider_applications', [
            'id' => $id,
            'rider_type' => 'part_time',
            'vehicle_ownership' => 'borrowed',
            'status' => 'pending',
            'submitted_via' => 'mobile',
        ]);

        // Conditional documents persisted.
        $this->assertDatabaseHas('rider_application_documents', [
            'rider_application_id' => $id,
            'document_type' => 'authorization_letter',
        ]);
        $this->assertDatabaseHas('rider_application_documents', [
            'rider_application_id' => $id,
            'document_type' => 'owner_valid_id',
        ]);
    }

    public function test_apply_defaults_rider_type_to_full_time(): void
    {
        Storage::fake('local');
        $data = $this->submitData('own');
        unset($data['rider_type']);

        $response = $this->post('/api/rider/apply', $data);

        $response->assertStatus(201);
        $this->assertDatabaseHas('rider_applications', [
            'id' => $response->json('application.id'),
            'rider_type' => 'full_time',
        ]);
    }

    public function test_status_returns_rider_type_and_ownership(): void
    {
        Storage::fake('local');
        $data = $this->submitData('financing', 'full_time');
        $created = $this->post('/api/rider/apply', $data)->json('application');

        $this->getJson('/api/rider/application-status?email=' . urlencode($data['email']))
            ->assertStatus(200)
            ->assertJsonPath('application.id', $created['id'])
            ->assertJsonPath('application.rider_type', 'full_time')
            ->assertJsonPath('application.vehicle_ownership', 'financing')
            ->assertJsonCount(5, 'application.documents');
    }

    public function test_status_returns_404_for_unknown_email(): void
    {
        $this->getJson('/api/rider/application-status?email=unknown@test.com')
            ->assertStatus(404)
            ->assertJsonPath('application', null);
    }

    public function test_approval_page_form_is_post_only_and_get_approve_404s(): void
    {
        Storage::fake('local');
        $data = $this->submitData('own', 'full_time');
        $id = $this->post('/api/rider/apply', $data)->json('application.id');

        $admin = User::create([
            'name' => 'Form Admin',
            'first_name' => 'Form',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'form-admin-' . uniqid() . '@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000005',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $html = $this->actingAs($admin)
            ->get("/rider-applications/{$id}")
            ->assertOk()
            ->getContent();

        $action = route('rider-applications.approve', $id);

        // The approve form must be a POST form pointing at the named route,
        // carry the CSRF token, and use a real submit button — never a GET link.
        $this->assertMatchesRegularExpression(
            '/<form[^>]*action="[^"]*' . preg_quote($action, '/') . '"[^>]*method="POST"/',
            $html
        );
        $this->assertStringContainsString('<input type="hidden" name="_token"', $html);
        $this->assertStringNotContainsString('href="' . $action . '"', $html);

        // Approval must remain a state-changing POST: the same URL via GET is
        // unroutable (405 Method Not Allowed — the router refuses it before
        // any controller runs), so any client that navigates there is NOT
        // submitting this form.
        $this->actingAs($admin)->get($action)->assertStatus(405);
    }

    public function test_admin_can_review_and_approve_an_application_with_new_fields(): void
    {
        Storage::fake('local');
        $data = $this->submitData('financing', 'part_time');
        $id = $this->post('/api/rider/apply', $data)->json('application.id');

        $admin = User::create([
            'name' => 'App Admin',
            'first_name' => 'App',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'app-admin-' . uniqid() . '@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000004',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $center = \App\Models\LogisticsCenter::create([
            'name' => 'App Center ' . uniqid(),
            'address' => 'Addr',
            'city' => 'City',
            'province' => 'Prov',
            'is_active' => true,
        ]);
        $area = \App\Models\ServiceArea::create([
            'logistics_center_id' => $center->id,
            'name' => 'App Area ' . uniqid(),
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post("/rider-applications/{$id}/approve", [
                'password' => 'rider-pass',
                'password_confirmation' => 'rider-pass',
                'center_id' => $center->id,
                'service_area_id' => $area->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => $data['email'], 'role' => 'rider']);
        $this->assertDatabaseHas('riders', ['email' => $data['email']]);
    }
}
