<?php

namespace Tests\Feature;

use App\Models\LogisticsSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tests for the dedicated Profile page (profile.show) which holds all personal
 * info editing (name, email, phone, photo) in a single form → profile.update.
 * Settings (settings.index) no longer contains a Profile section.
 */
class ProfilePageTest extends TestCase
{
    private function fakeImage(string $name = 'avatar.jpg'): UploadedFile
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'invoiz_test_avatar_');
        // Minimal valid 1×1 transparent PNG (37 bytes)
        file_put_contents($tmpFile, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        return new UploadedFile($tmpFile, $name, 'image/png', null, true);
    }

    private function cleanupAvatar(?string $path): void
    {
        if ($path && file_exists(public_path($path))) {
            @unlink(public_path($path));
        }
    }

    // ── Page access ─────────────────────────────────────────────

    public function test_guest_cannot_access_profile_page(): void
    {
        $this->get(route('profile.show'))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_access_profile_page(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertSee('Profile Information')
            ->assertSee('Save Changes')
            ->assertSee('Change Photo')
            ->assertSee($user->name)
            ->assertSee($user->email);
    }

    // ── Combined form: name / email / phone ─────────────────────

    public function test_user_can_update_name_email_phone_via_combined_form(): void
    {
        $user = User::factory()->create(['role' => 'staff', 'phone' => '09171234567']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name'  => 'Juan Dela Cruz',
                'email' => 'juan@invoiz.test',
                'phone' => '09189998877',
            ])
            ->assertRedirect(route('profile.show'))
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('Juan Dela Cruz', $user->name);
        $this->assertSame('juan@invoiz.test', $user->email);
        $this->assertSame('09189998877', $user->phone);
    }

    public function test_profile_update_validates_required_fields(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name'  => '',
                'email' => 'not-an-email',
                'phone' => '09189998877',
            ])
            ->assertSessionHasErrors(['name', 'email']);
    }

    public function test_email_must_be_unique_on_profile_update(): void
    {
        $existing = User::factory()->create(['role' => 'staff', 'email' => 'taken@invoiz.test']);
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name'  => $user->name,
                'email' => 'taken@invoiz.test',
                'phone' => $user->phone,
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_profile_update_does_not_null_email_verified_at(): void
    {
        $user = User::factory()->create([
            'role'             => 'admin',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name'  => $user->name,
                'email' => 'newaddress@invoiz.test',
                'phone' => $user->phone,
            ])
            ->assertRedirect(route('profile.show'));

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
    }

    // ── Combined form: photo upload ─────────────────────────────

    public function test_profile_update_with_photo_saves_file_on_disk(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'photo' => $this->fakeImage(),
            ])
            ->assertRedirect(route('profile.show'))
            ->assertSessionHas('success');

        $path = LogisticsSetting::forUser($user->id)->fresh()->photo_path;
        $this->assertNotNull($path);
        $this->assertStringStartsWith('uploads/avatars/', $path);
        $this->assertTrue(file_exists(public_path($path)));

        // Verify profile page shows the photo <img>
        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertSee('<img src="' . asset($path) . '" alt="Profile photo"', false);

        $this->cleanupAvatar($path);
    }

    public function test_sidebar_shows_new_photo_after_combined_update(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'photo' => $this->fakeImage(),
            ]);

        $path = LogisticsSetting::forUser($user->id)->fresh()->photo_path;

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<img src="' . asset($path) . '" alt="Profile photo"', false);

        $this->cleanupAvatar($path);
    }

    public function test_sidebar_shows_new_name_for_staff_after_combined_update(): void
    {
        $user = User::factory()->create(['role' => 'staff']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name'  => 'Maria Santos',
                'email' => $user->email,
                'phone' => $user->phone,
            ])
            ->assertRedirect(route('profile.show'));

        // Staff users see their name (not role label) in the sidebar account block
        $this->actingAs($user->fresh())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Maria Santos');
    }

    public function test_user_cannot_modify_another_users_profile_via_combined_form(): void
    {
        $other = User::factory()->create(['role' => 'staff']);
        LogisticsSetting::forUser($other->id)->update(['photo_path' => 'uploads/avatars/existing.jpg']);
        $me = User::factory()->create(['role' => 'admin']);

        $this->actingAs($me)
            ->patch(route('profile.update'), [
                'name'  => $me->name,
                'email' => $me->email,
                'phone' => $me->phone,
                'photo' => $this->fakeImage(),
            ])
            ->assertRedirect(route('profile.show'));

        $this->assertSame('uploads/avatars/existing.jpg', LogisticsSetting::forUser($other->id)->fresh()->photo_path);
        $myPath = LogisticsSetting::forUser($me->id)->fresh()->photo_path;
        $this->assertStringStartsWith('uploads/avatars/', $myPath);

        $this->cleanupAvatar($myPath);
    }

    public function test_invalid_photo_type_rejected_on_combined_form(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'photo' => UploadedFile::fake()->create('file.txt', 10),
            ])
            ->assertSessionHasErrors('photo');
    }

    // ── Legacy profile.update-photo endpoint (kept) ─────────────

    public function test_legacy_update_photo_endpoint_still_works(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->post(route('profile.update-photo'), [
                'photo' => $this->fakeImage(),
            ])
            ->assertRedirect(route('profile.show'));

        $path = LogisticsSetting::forUser($user->id)->fresh()->photo_path;
        $this->assertNotNull($path);
        $this->assertStringStartsWith('uploads/avatars/', $path);
        $this->assertTrue(file_exists(public_path($path)));

        $this->cleanupAvatar($path);
    }

    public function test_legacy_update_photo_redirects_to_profile_show(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->post(route('profile.update-photo'), [
                'photo' => $this->fakeImage(),
            ])
            ->assertRedirect(route('profile.show'));

        $path = LogisticsSetting::forUser($user->id)->fresh()->photo_path;
        $this->cleanupAvatar($path);
    }

    // ── Settings page: separation verification ───────────────────

    public function test_settings_page_has_no_profile_information_section(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Change Password')
            ->assertDontSee('Profile Information')
            ->assertDontSee('Full Name');
    }

    public function test_settings_page_retains_security_password_section(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Change Password')
            ->assertSee('Keep your account secure');
    }

    public function test_settings_page_retains_notifications_section(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Notifications')
            ->assertSee('New Rider Applications')
            ->assertSee('Delivery Completed');
    }

    public function test_settings_page_retains_delivery_section(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Delivery Preferences')
            ->assertSee('Require Proof of Delivery')
            ->assertSee('Maximum Delivery Attempts')
            ->assertSee('Allow Rider Reassignment');
    }

    // ── Settings page: still separate from Profile ──────────────

    public function test_settings_page_remains_separate_and_accessible(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Settings');
    }
}
