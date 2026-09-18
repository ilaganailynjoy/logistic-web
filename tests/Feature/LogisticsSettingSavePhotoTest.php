<?php

namespace Tests\Feature;

use App\Models\LogisticsSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Model-level proof of the savePhoto() ordering guarantee:
 *
 *   new file stored → photo_path updated → old file removed (last)
 *
 * so a failed store never destroys the user's existing photo.
 */
class LogisticsSettingSavePhotoTest extends TestCase
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

    private function realPng(string $name = 'photo.png'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'invoiz_png_');
        file_put_contents(
            $tmp,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')
        );

        return new UploadedFile($tmp, $name, 'image/png', null, true);
    }

    public function test_save_photo_returns_reference_to_newly_stored_file(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $settings = LogisticsSetting::forUser($user->id);

        $path = $settings->savePhoto($this->realPng());

        $this->assertStringStartsWith('uploads/avatars/', $path);
        $this->assertTrue(file_exists(public_path($path)));
        $this->assertSame($path, $settings->fresh()->photo_path);

        $this->track($path);
    }

    public function test_second_save_keeps_only_new_file_and_clears_old_one(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $settings = LogisticsSetting::forUser($user->id);

        $first = $settings->savePhoto($this->realPng('first.png'));
        $this->assertTrue(file_exists(public_path($first)));

        $second = $settings->savePhoto($this->realPng('second.png'));

        $this->assertNotSame($first, $second);
        $this->assertTrue(file_exists(public_path($second)));
        $this->assertFalse(file_exists(public_path($first)), 'old file must be removed only after the new one is stored');
        $this->assertSame($second, $settings->fresh()->photo_path);

        $this->track($second);
    }

    public function test_failed_store_never_touches_existing_photo(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $settings = LogisticsSetting::forUser($user->id);

        $existing = $settings->savePhoto($this->realPng('existing.png'));
        $bytesBefore = md5_file(public_path($existing));

        // UploadedFile whose backing temp file is removed right before savePhoto(),
        // so move() fails after construction succeeded → storage failure mid-flight.
        $tmp = tempnam(sys_get_temp_dir(), 'invoiz_broken_');
        file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $broken = new UploadedFile($tmp, 'broken.png', 'image/png', null, true);
        @unlink($tmp);

        try {
            $settings->savePhoto($broken);
            $this->fail('savePhoto() should have thrown for a non-existent source file.');
        } catch (\Throwable) {
            // expected: storage failure
        }

        $this->assertSame($existing, $settings->fresh()->photo_path, 'DB photo_path must be untouched');
        $this->assertSame($bytesBefore, md5_file(public_path($existing)), 'existing file must be untouched on disk');

        $this->track($existing);
    }
}