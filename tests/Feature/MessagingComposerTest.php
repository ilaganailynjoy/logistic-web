<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Rider;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Messaging composer behaviors: rider thread edit/delete with ownership
 * rules, upload validation, uuid-safe storage paths, and attachment
 * IDOR across riders, staff, and guests. Server-side enforcement only —
 * the UI layers are verified separately.
 */
class MessagingComposerTest extends TestCase
{
    private function user(string $role, string $tag, array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Composer ' . $tag . ' ' . uniqid(),
            'first_name' => 'Composer',
            'last_name' => $tag,
            'sex' => 'male',
            'email' => 'composer-' . strtolower($tag) . '-' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
            'phone' => '09000000001',
            'birthday' => '1990-01-01',
            'age' => 30,
            'role' => $role,
            'status' => 'active',
            'center_id' => null,
            'email_verified_at' => now(),
        ], $overrides));
    }

    private function staff(): User
    {
        return $this->user('staff', 'Staff');
    }

    private function riderPair(string $tag): array
    {
        $rider = Rider::create([
            'name' => 'Composer Rider ' . $tag . ' ' . uniqid(),
            'email' => 'composer-rider-' . strtolower($tag) . '-' . uniqid() . '@test.com',
            'phone' => '09000000002',
            'vehicle_type' => 'Motorcycle',
            'license_plate' => 'CMP ' . $tag,
            'status' => 'available',
        ]);
        $user = User::create([
            'name' => $rider->name,
            'first_name' => 'Composer',
            'last_name' => 'Rider',
            'sex' => 'male',
            'email' => $rider->email,
            'password' => bcrypt('password'),
            'phone' => $rider->phone,
            'birthday' => '1995-01-01',
            'age' => 30,
            'role' => 'rider',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $rider->update(['user_id' => $user->id]);

        return [$rider, $user];
    }

    private function thread(User $user, Rider $rider, string $type = 'rider'): Conversation
    {
        return Conversation::create([
            'participant_type' => $type,
            'participant_id' => $user->id,
            'participant_name' => $user->name,
            'subject' => 'Thread',
            'last_message_at' => now(),
            'rider_id' => $rider->id,
        ]);
    }

    private function message(Conversation $thread, User $sender, string $senderType, string $body = 'Hello'): Message
    {
        return Message::create([
            'conversation_id' => $thread->id,
            'sender_type' => $senderType,
            'sender_id' => $sender->id,
            'body' => $body,
        ]);
    }

    private function attachment(Message $message, string $original = 'proof.jpg'): MessageAttachment
    {
        Storage::disk('local')->put("message-attachments/{$message->conversation_id}/stored.jpg", 'binary-content');

        try {
            return MessageAttachment::create([
                'message_id' => $message->id,
                'original_filename' => $original,
                'stored_path' => "message-attachments/{$message->conversation_id}/stored.jpg",
                'mime_type' => 'image/jpeg',
                'file_size' => 14,
                'disk' => 'local',
            ]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete("message-attachments/{$message->conversation_id}/stored.jpg");

            throw $e;
        }
    }

    private function deleteAttachmentFile(MessageAttachment $attachment): void
    {
        Storage::disk('local')->delete($attachment->stored_path);
    }

    public function test_rider_edits_own_thread_message(): void
    {
        [$rider, $user] = $this->riderPair('A');
        $thread = $this->thread($user, $rider);
        $message = $this->message($thread, $user, 'rider', 'Typo here');

        $this->actingAs($user, 'sanctum')
            ->patchJson(
                "/api/rider/conversations/{$thread->id}/messages/{$message->id}",
                ['body' => 'Fixed text']
            )
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame('Fixed text', $message->fresh()->body);
        $this->assertNotNull($message->fresh()->edited_at);
        $this->assertSame('Fixed text', $thread->fresh()->last_message_preview);
    }

    public function test_rider_cannot_edit_staff_message_in_same_thread(): void
    {
        [$rider, $user] = $this->riderPair('B');
        $staff = $this->staff();
        $thread = $this->thread($user, $rider);
        $message = $this->message($thread, $staff, 'logistics', 'Staff note');

        $this->actingAs($user, 'sanctum')
            ->patchJson(
                "/api/rider/conversations/{$thread->id}/messages/{$message->id}",
                ['body' => 'Hijacked']
            )
            ->assertForbidden();

        $this->assertSame('Staff note', $message->fresh()->body);
    }

    public function test_rider_cannot_edit_message_from_another_thread(): void
    {
        [$rider, $user] = $this->riderPair('C');
        $thread = $this->thread($user, $rider);
        $other = Conversation::create([
            'participant_type' => 'seller', 'participant_id' => 999,
            'participant_name' => 'Shop', 'subject' => 'S',
            'last_message_at' => now(), 'rider_id' => $rider->id,
        ]);
        $message = $this->message($other, $user, 'rider', 'Mine elsewhere');

        // Tampered message id: the message is not in this thread.
        $this->actingAs($user, 'sanctum')
            ->patchJson(
                "/api/rider/conversations/{$thread->id}/messages/{$message->id}",
                ['body' => 'Hijacked']
            )
            ->assertNotFound();

        $this->assertSame('Mine elsewhere', $message->fresh()->body);
    }

    public function test_rider_cannot_edit_deleted_message(): void
    {
        [$rider, $user] = $this->riderPair('D');
        $thread = $this->thread($user, $rider);
        $message = $this->message($thread, $user, 'rider', 'Oops');
        $message->update(['deleted_at' => now()]);

        $this->actingAs($user, 'sanctum')
            ->patchJson(
                "/api/rider/conversations/{$thread->id}/messages/{$message->id}",
                ['body' => 'Resurrected']
            )
            ->assertForbidden();
    }

    public function test_rider_deletes_own_message_with_placeholder(): void
    {
        [$rider, $user] = $this->riderPair('E');
        $thread = $this->thread($user, $rider);
        $message = $this->message($thread, $user, 'rider', 'Remove me');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/rider/conversations/{$thread->id}/messages/{$message->id}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $fresh = $message->fresh();
        $this->assertTrue($fresh->isDeleted());
        $this->assertSame('Remove me', $fresh->getAttributes()['body']);
    }

    public function test_rider_cannot_delete_staff_message(): void
    {
        [$rider, $user] = $this->riderPair('F');
        $staff = $this->staff();
        $thread = $this->thread($user, $rider);
        $message = $this->message($thread, $staff, 'logistics', 'Keep me');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/rider/conversations/{$thread->id}/messages/{$message->id}")
            ->assertForbidden();

        $this->assertFalse($message->fresh()->isDeleted());
    }

    public function test_guest_cannot_edit_or_delete_thread_messages(): void
    {
        [$rider, $user] = $this->riderPair('G');
        $thread = $this->thread($user, $rider);
        $message = $this->message($thread, $user, 'rider', 'Hello');

        $this->patchJson(
            "/api/rider/conversations/{$thread->id}/messages/{$message->id}",
            ['body' => 'X']
        )->assertUnauthorized();

        $this->deleteJson("/api/rider/conversations/{$thread->id}/messages/{$message->id}")
            ->assertUnauthorized();
    }

    public function test_upload_rejects_executable_files(): void
    {
        [$rider, $user] = $this->riderPair('H');
        $thread = $this->thread($user, $rider);

        $file = UploadedFile::fake()->create('evil.exe', 10, 'application/x-msdownload');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/rider/conversations/{$thread->id}/messages", [
                'body' => 'Try exe',
            ])
            ->assertCreated(); // text-only control passes

        $this->actingAs($user, 'sanctum')
            ->post("/api/rider/conversations/{$thread->id}/messages", [
                'body' => 'Try exe',
                'attachment' => $file,
            ])
            ->assertStatus(422);

        $this->assertSame(0, MessageAttachment::count());
    }

    public function test_upload_rejects_oversized_files(): void
    {
        [$rider, $user] = $this->riderPair('I');
        $thread = $this->thread($user, $rider);

        $file = UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf');

        $this->actingAs($user, 'sanctum')
            ->post("/api/rider/conversations/{$thread->id}/messages", [
                'body' => 'Too big',
                'attachment' => $file,
            ])
            ->assertStatus(422);

        $this->assertSame(0, MessageAttachment::count());
    }

    public function test_stored_path_never_uses_client_filename(): void
    {
        [$rider, $user] = $this->riderPair('J');
        $thread = $this->thread($user, $rider);

        $path = tempnam(sys_get_temp_dir(), 'trav') . '.jpg';
        file_put_contents($path, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00" . str_repeat("\x00", 100));
        $file = new UploadedFile($path, '../../evil.jpg', 'image/jpeg', null, true);

        try {
            $this->actingAs($user, 'sanctum')
                ->post("/api/rider/conversations/{$thread->id}/messages", [
                    'body' => 'Traversal name',
                    'attachment' => $file,
                ])
                ->assertCreated();
        } finally {
            @unlink($path);
        }

        $attachment = MessageAttachment::firstOrFail();
        $this->assertMatchesRegularExpression(
            '#^message-attachments/\d+/[0-9a-f-]{36}\.jpg$#',
            $attachment->stored_path
        );
        $this->assertStringNotContainsString('..', $attachment->stored_path);
        // The framework also basenames the display name; traversal dies here too.
        $this->assertSame('evil.jpg', $attachment->original_filename);
    }

    public function test_rider_cannot_view_or_download_another_riders_attachment(): void
    {
        [$riderA] = $this->riderPair('K');
        [$riderB, $userB] = $this->riderPair('L');
        $threadA = $this->thread($this->user('rider', 'Owner'), $riderA);
        $message = $this->message($threadA, $this->user('staff', 'Staff'), 'logistics', 'File');
        $attachment = $this->attachment($message);

        try {
            $this->actingAs($userB, 'web')
                ->get(route('rider.messages.attachments.view', $attachment))
                ->assertForbidden();

            $this->actingAs($userB, 'web')
                ->get(route('rider.messages.attachments.download', $attachment))
                ->assertForbidden();
        } finally {
            $this->deleteAttachmentFile($attachment);
        }

        $this->assertTrue($attachment->fresh()->exists);
    }

    public function test_rider_views_own_attachment_and_staff_views_any(): void
    {
        [$rider, $user] = $this->riderPair('M');
        $thread = $this->thread($user, $rider);
        $message = $this->message($thread, $user, 'rider', 'Mine file');
        $attachment = $this->attachment($message);

        try {
            $this->actingAs($user, 'web')
                ->get(route('rider.messages.attachments.view', $attachment))
                ->assertOk();

            $this->actingAs($this->staff(), 'web')
                ->get(route('messages.attachments.view', $attachment))
                ->assertOk();
        } finally {
            $this->deleteAttachmentFile($attachment);
        }
    }
}
