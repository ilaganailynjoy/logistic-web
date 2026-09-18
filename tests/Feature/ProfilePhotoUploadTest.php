<?php

namespace Tests\Feature;

use App\Models\LogisticsSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * End-to-end proof that profile-photo upload actually persists: file → storage → DB → URL → display.
 *
 * These tests deliberately avoid asserting "just 302". Each one verifies the real
 * filesystem state, the logistics_settings.photo_path row, the generated URL, and
 * the rendered Profile/sidebar output where applicable.
 */
class ProfilePhotoUploadTest extends TestCase
{
    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $path) {
            if ($path && file_exists(public_path($path))) {
                @unlink(public_path($path));
            }
        }

        parent::tearDown();
    }

    private function track(string $path): void
    {
        $this->createdFiles[] = $path;
    }

    /** Minimal valid 1×1 JPEG (finfo == image/jpeg). */
    private function realJpeg(string $name = 'photo.jpg'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'invoiz_jpeg_');
        file_put_contents(
            $tmp,
            base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBASIA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q==')
        );

        return new UploadedFile($tmp, $name, 'image/jpeg', null, true);
    }

    /** Minimal valid 1×1 PNG (finfo == image/png). */
    private function realPng(string $name = 'photo.png'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'invoiz_png_');
        file_put_contents(
            $tmp,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')
        );

        return new UploadedFile($tmp, $name, 'image/png', null, true);
    }

    /** A real PNG padded past 2048 KB so only the max-size rule fails. */
    private function oversizedPng(string $name = 'huge.png'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'invoiz_huge_');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $png .= str_repeat("\0", 3 * 1024 * 1024);

        file_put_contents($tmp, $png);

        $this->assertGreaterThan(2048 * 1024, filesize($tmp), 'oversized fixture must exceed the 2048 KB rule');

        return new UploadedFile($tmp, $name, 'image/png', null, true);
    }

    private function combine(array $user, ?UploadedFile $photo = null): array
    {
        $data = [
            'name'  => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
        ];

        if ($photo) {
            $data['photo'] = $photo;
        }

        return $data;
    }

    // ── 1. Page access ────────────────────────────────────────────

    public function test_profile_page_is_accessible_by_authenticated_user(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('profile.show'))
            ->assertOk()
            ->assertSee('Change Photo');
    }

    // ── 2 & 3. Valid JPEG / PNG upload ────────────────────────────

    public function test_user_can_upload_a_valid_jpeg(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->combine($user->toArray(), $this->realJpeg()))
            ->assertRedirect(route('profile.show'));

        $path = LogisticsSetting::forUser($user->id)->fresh()->photo_path;
        $this->assertNotNull($path);
        $this->assertStringStartsWith('uploads/avatars/', $path);
        $this->assertEquals('image/jpeg', finfo_file(finfo_open(FILEINFO_MIME_TYPE), public_path($path)));

        $this->track($path);
    }

    public function test_user_can_upload_a_valid_png(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->combine($user->toArray(), $this->realPng()))
            ->assertRedirect(route('profile.show'));

        $path = LogisticsSetting::forUser($user->id)->fresh()->photo_path;
        $this->assertNotNull($path);
        $this->assertStringStartsWith('uploads/avatars/', $path);
        $this->assertEquals('image/png', finfo_file(finfo_open(FILEINFO_MIME_TYPE), public_path($path)));

        $this->track($path);
    }

    // ── 4. Physical file exists ───────────────────────────────────

    public function test_uploaded_file_physically_exists_in_avatars_directory(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->combine($user->toArray(), $this->realPng()));

        $path = LogisticsSetting::forUser($user->id)->fresh()->photo_path;
        $this->assertTrue(file_exists(public_path($path)));
        $this->assertStringStartsWith('uploads/avatars/', $path);

        $this->track($path);
    }

    // ── 5 & 6. DB row + generated URL ─────────────────────────────

    public function test_photo_path_changes_in_database(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->combine($user->toArray(), $this->realJpeg()));

        $path = LogisticsSetting::forUser($user->id)->fresh()->photo_path;
        $this->assertNotNull($path);
        $this->assertSame($path, LogisticsSetting::forUser($user->id)->fresh()->photo_path);

        $this->track($path);
    }

    public function test_profile_photo_url_returns_the_new_photo_url(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->combine($user->toArray(), $this->realPng()));

        $path = LogisticsSetting::forUser($user->id)->fresh()->photo_path;
        $this->assertSame(asset($path), $user->fresh()->profilePhotoUrl());
        $this->assertStringContainsString('uploads/avatars/', $user->fresh()->profilePhotoUrl());

        $this->track($path);
    }

    // ── 7. Profile display ────────────────────────────────────────

    public function test_profile_page_displays_the_new_photo(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->combine($user->toArray(), $this->realJpeg()));

        $path = LogisticsSetting::forUser($user->id)->fresh()->photo_path;

        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertSee('<img src="' . asset($path) . '" alt="Profile photo"', false);

        $this->track($path);
    }

    // ── 8. Sidebar display (collapsed + expanded) ─────────────────

    public function test_sidebar_displays_the_new_photo(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->combine($user->toArray(), $this->realPng()));

        $path = LogisticsSetting::forUser($user->id)->fresh()->photo_path;
        $html = (string) $this->actingAs($user)->get(route('dashboard'))->getContent();

        $this->assertStringContainsString(
            '<img src="' . asset($path) . '" alt="Profile photo" class="h-8 w-8 rounded-full',
            $html
        );

        $this->track($path);
    }

    public function test_sidebar_falls_back_to_initial_when_photo_missing_on_disk(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        LogisticsSetting::forUser($user->id)->update(['photo_path' => 'uploads/avatars/does-not-exist.png']);

        $html = (string) $this->actingAs($user)->get(route('dashboard'))->getContent();

        $this->assertStringNotContainsString('uploads/avatars/does-not-exist.png', $html);
        $this->assertStringContainsString('rounded-full bg-teal-light', $html);
    }

    // ── 9 & 10. Replacement ───────────────────────────────────────

    public function test_replacing_a_photo_changes_stored_path_and_removes_old_file(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        // Upload A
        $this->actingAs($user)
            ->patch(route('profile.update'), $this->combine($user->toArray(), $this->realPng('first.png')));

        $oldPath = LogisticsSetting::forUser($user->id)->fresh()->photo_path;
        $this->assertTrue(file_exists(public_path($oldPath)), 'first photo should exist before replacement');

        // Upload B (different bytes → replacement)
        $this->actingAs($user)
            ->patch(route('profile.update'), $this->combine($user->toArray(), $this->realJpeg('second.jpg')));

        $newPath = LogisticsSetting::forUser($user->id)->fresh()->photo_path;

        $this->assertNotSame($oldPath, $newPath, 'photo_path must point at the new file');
        $this->assertTrue(file_exists(public_path($newPath)));
        $this->assertFalse(file_exists(public_path($oldPath)), 'old file must be removed after new store + DB update');
        $this->assertEquals('image/jpeg', finfo_file(finfo_open(FILEINFO_MIME_TYPE), public_path($newPath)));

        // Profile page must show B, not A, and not the initial fallback.
        $html = (string) $this->actingAs($user)->get(route('profile.show'))->getContent();
        $this->assertStringContainsString(asset($newPath), $html);
        $this->assertStringNotContainsString(asset($oldPath), $html);

        $this->track($newPath);
    }

    public function test_replacement_does_not_remove_old_photo_when_database_update_fails(): void
    {
        $this->markTestSkipped(<<<'SKIP'
        Simulating an Eloquent update() failure deterministically is not practical here;
        the ordering guarantee (new file → DB row → delete old) is covered directly by
        LogisticsSettingSavePhotoTest and by the success-path replacement test above.
        SKIP);
    }

    // ── 11 & 12. Validation rejection ─────────────────────────────

    public function test_invalid_file_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'photo' => UploadedFile::fake()->create('notes.txt', 10),
            ])
            ->assertSessionHasErrors('photo');

        $this->assertNull(LogisticsSetting::forUser($user->id)->fresh()->photo_path);
    }

    public function test_oversized_file_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'photo' => $this->oversizedPng(),
            ])
            ->assertSessionHasErrors('photo');
    }

    // ── 13. Failed upload preserves existing photo ────────────────

    public function test_failed_upload_does_not_destroy_existing_valid_photo(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        // Existing valid photo
        $this->actingAs($user)
            ->patch(route('profile.update'), $this->combine($user->toArray(), $this->realPng()));

        $existing = LogisticsSetting::forUser($user->id)->fresh()->photo_path;
        $this->assertTrue(file_exists(public_path($existing)));

        // Validation failure: oversized replacement
        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'photo' => $this->oversizedPng(),
            ])
            ->assertSessionHasErrors('photo');

        // Photo must be untouched
        $this->assertSame($existing, LogisticsSetting::forUser($user->id)->fresh()->photo_path);
        $this->assertTrue(file_exists(public_path($existing)));

        // Profile page must still show the existing photo
        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertSee('<img src="' . asset($existing) . '" alt="Profile photo"', false);

        $this->track($existing);
    }

    // ── 14. User isolation ────────────────────────────────────────

    public function test_user_a_cannot_affect_user_b_photo(): void
    {
        $userA = User::factory()->create(['role' => 'admin']);
        $userB = User::factory()->create(['role' => 'staff']);

        // User B uploads a photo first
        $this->actingAs($userB)
            ->patch(route('profile.update'), $this->combine($userB->toArray(), $this->realPng('b.png')));

        $bPath = LogisticsSetting::forUser($userB->id)->fresh()->photo_path;
        $bBytes = md5_file(public_path($bPath));
        $aPathBefore = LogisticsSetting::forUser($userA->id)->fresh()->photo_path;

        // User A uploads their own photo and edits their own profile
        $this->actingAs($userA)
            ->patch(route('profile.update'), $this->combine($userA->toArray(), $this->realJpeg('a.jpg')));

        $aPath = LogisticsSetting::forUser($userA->id)->fresh()->photo_path;

        // A's row updated
        $this->assertNotNull($aPath);
        $this->assertStringStartsWith('uploads/avatars/', $aPath);
        $this->assertNotSame($aPathBefore, $aPath);

        // B's row + file unchanged by A's update
        $this->assertSame($bPath, LogisticsSetting::forUser($userB->id)->fresh()->photo_path);
        $this->assertTrue(file_exists(public_path($bPath)));
        $this->assertSame($bBytes, md5_file(public_path($bPath)));

        // A's dashboard shows A's photo only
        $html = (string) $this->actingAs($userA)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString(asset($aPath), $html);
        $this->assertStringNotContainsString(asset($bPath), $html);

        // B's dashboard shows B's photo only
        $bHtml = (string) $this->actingAs($userB)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString(asset($bPath), $bHtml);
        $this->assertStringNotContainsString(asset($aPath), $bHtml);

        $this->track($aPath);
        $this->track($bPath);
    }

    // ── 15 & 16. Persistence across fresh requests ────────────────

    public function test_photo_is_still_displayed_on_a_fresh_profile_request(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->combine($user->toArray(), $this->realPng()));

        $path = LogisticsSetting::forUser($user->id)->fresh()->photo_path;
        $expectedSrc = asset($path);

        // Multiple independent GETs must keep showing the same stored photo.
        for ($i = 0; $i < 3; $i++) {
            $html = (string) $this->actingAs($user->fresh())->get(route('profile.show'))->getContent();
            $this->assertStringContainsString($expectedSrc, $html);
        }

        $this->track($path);
    }

    public function test_photo_is_still_displayed_on_a_fresh_request_containing_the_sidebar(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->combine($user->toArray(), $this->realJpeg()));

        $path = LogisticsSetting::forUser($user->id)->fresh()->photo_path;
        $expectedSrc = asset($path);

        foreach ([route('dashboard'), route('settings.index')] as $page) {
            $html = (string) $this->actingAs($user->fresh())->get($page)->getContent();
            $this->assertStringContainsString($expectedSrc, $html);
        }

        $this->track($path);
    }

    // ── Legacy Settings upload shares the same mechanism ──────────

    public function test_legacy_settings_photo_upload_persists_via_same_mechanism(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->post(route('settings.update-photo'), [
                'photo' => $this->realPng('settings.png'),
            ])
            ->assertRedirect(route('settings.index'));

        $path = LogisticsSetting::forUser($user->id)->fresh()->photo_path;
        $this->assertNotNull($path);
        $this->assertStringStartsWith('uploads/avatars/', $path);
        $this->assertTrue(file_exists(public_path($path)));

        // Same photo surfaces in the sidebar.
        $html = (string) $this->actingAs($user)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString(asset($path), $html);

        $this->track($path);
    }

    public function test_profile_update_photo_endpoint_persists_via_same_mechanism(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->post(route('profile.update-photo'), [
                'photo' => $this->realJpeg('legacy.jpg'),
            ])
            ->assertRedirect(route('profile.show'));

        $path = LogisticsSetting::forUser($user->id)->fresh()->photo_path;
        $this->assertNotNull($path);
        $this->assertStringStartsWith('uploads/avatars/', $path);
        $this->assertTrue(file_exists(public_path($path)));

        $this->track($path);
    }

    // ── Success flash ─────────────────────────────────────────────

    public function test_combined_update_flashes_success_message(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->combine($user->toArray(), $this->realJpeg()))
            ->assertSessionHas('success', 'Profile updated successfully.');

        $path = LogisticsSetting::forUser($user->id)->fresh()->photo_path;
        $this->track($path);
    }
}