<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\LogisticsCenter;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Notification;
use App\Models\Rider;
use App\Models\RiderNotification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Messaging audit: the existing Logistics ↔ Rider conversation system.
 *
 * Covers authorization (own-conversation only), send validation and
 * persistence, read/unread behavior, edit/delete ownership, attachment
 * rules, and notification integration — without changing any of it.
 */
class MessagingAuditTest extends TestCase
{
    private function admin(): User
    {
        return User::create([
            'name' => 'Msg Admin ' . uniqid(),
            'first_name' => 'Msg',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'msg-admin-' . uniqid() . '@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000001',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => 'admin',
            'status' => 'active',
            'center_id' => null,
            'email_verified_at' => now(),
        ]);
    }

    private function riderWithUser(string $tag): array
    {
        $rider = Rider::create([
            'name' => 'Msg Rider ' . $tag . ' ' . uniqid(),
            'email' => 'msg-rider-' . strtolower($tag) . '-' . uniqid() . '@test.com',
            'phone' => '09000000002',
            'vehicle_type' => 'motorcycle',
            'license_plate' => 'MSG ' . $tag,
            'status' => 'available',
            'is_online' => true,
            'vehicle_verification' => 'verified',
            'approved_at' => now()->subDay(),
        ]);

        $user = User::create([
            'name' => $rider->name,
            'first_name' => 'Msg',
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

        return ['rider' => $rider, 'user' => $user];
    }

    private function riderConversation(User $user): Conversation
    {
        return Conversation::firstOrCreate(
            ['participant_type' => 'rider', 'participant_id' => $user->id],
            [
                'participant_name' => $user->name,
                'subject' => 'Rider Support',
                'last_message_at' => now(),
                'rider_id' => $user->rider?->id,
            ],
        );
    }


    private function jpg(string $name = 'note.jpg'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 100, 'image/jpeg');
    }

    // ── Authorization ────────────────────────────────────────────

    public function test_rider_api_cannot_open_another_riders_conversation(): void
    {
        $riderA = $this->riderWithUser('A');
        $riderB = $this->riderWithUser('B');
        $conversationA = $this->riderConversation($riderA['user']);

        $this->actingAs($riderB['user'], 'sanctum')
            ->getJson("/api/rider/conversations/{$conversationA->id}")
            ->assertForbidden();

        $this->actingAs($riderB['user'], 'sanctum')
            ->postJson("/api/rider/conversations/{$conversationA->id}/messages", [
                'body' => 'Sneaky message',
            ])->assertForbidden();

        $this->assertSame(0, Message::where('body', 'Sneaky message')->count());
    }

    public function test_rider_web_channel_is_pinned_to_own_conversation(): void
    {
        $riderA = $this->riderWithUser('A');
        $riderB = $this->riderWithUser('B');
        $conversationA = $this->riderConversation($riderA['user']);

        $this->actingAs($riderB['user'])
            ->post(route('rider.messages.send'), ['body' => 'Hello from B'])
            ->assertRedirect(route('rider.messages'));

        // B's message landed in B's own thread, never in A's.
        $conversationB = $this->riderConversation($riderB['user']);
        $this->assertSame(1, $conversationB->messages()->where('body', 'Hello from B')->count());
        $this->assertSame(0, $conversationA->messages()->where('body', 'Hello from B')->count());
    }

    public function test_guest_messaging_is_blocked(): void
    {
        $this->get(route('messages.index'))->assertRedirect(route('login'));
        $this->post(route('messages.store'), ['conversation_id' => 1, 'body' => 'x'])
            ->assertRedirect(route('login'));
        $this->get(route('rider.messages'))->assertRedirect(route('login'));

        // Existing conversation: binding resolves, then auth rejects.
        $rider = $this->riderWithUser('G');
        $conversation = $this->riderConversation($rider['user']);
        $this->getJson("/api/rider/conversations/{$conversation->id}")->assertUnauthorized();
        $this->getJson('/api/rider/notifications')->assertUnauthorized();
    }

    // ── Send validation + persistence ────────────────────────────

    public function test_empty_and_whitespace_bodies_are_rejected(): void
    {
        $rider = $this->riderWithUser('A');
        $conversation = $this->riderConversation($rider['user']);

        $this->actingAs($rider['user'])
            ->post(route('rider.messages.send'), ['body' => ''])
            ->assertSessionHasErrors('body');

        $this->actingAs($rider['user'])
            ->post(route('rider.messages.send'), ['body' => '   '])
            ->assertSessionHasErrors('body');

        $this->actingAs($rider['user'], 'sanctum')
            ->postJson('/api/rider/conversations/' . $conversation->id . '/messages', [
                'body' => '   ',
            ])->assertStatus(422);

        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_message_persists_with_sender_ordering_and_timestamps(): void
    {
        $admin = $this->admin();
        $rider = $this->riderWithUser('A');
        $conversation = $this->riderConversation($rider['user']);

        $this->actingAs($rider['user'])
            ->post(route('rider.messages.send'), ['body' => 'First from rider'])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('messages.store'), [
                'conversation_id' => $conversation->id,
                'body' => 'Reply from logistics',
            ])->assertRedirect();

        $messages = $conversation->messages()->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame(['First from rider', 'Reply from logistics'], $messages->pluck('body')->all());
        $this->assertSame('rider', $messages[0]->sender_type);
        $this->assertSame($rider['user']->id, (int) $messages[0]->sender_id);
        $this->assertSame('logistics', $messages[1]->sender_type);
        $this->assertSame($admin->id, (int) $messages[1]->sender_id);
        $this->assertNotNull($messages[0]->created_at);
        $this->assertSame('Reply from logistics', $conversation->fresh()->last_message_preview);
    }

    public function test_ajax_send_returns_json_without_server_error(): void
    {
        // The Alpine composer posts with Accept: application/json; the
        // action must honor its own JSON branch instead of 500ing on the
        // declared RedirectResponse return type.
        $admin = $this->admin();
        $rider = $this->riderWithUser('A');
        $conversation = $this->riderConversation($rider['user']);

        $this->actingAs($admin)
            ->postJson(route('messages.store'), [
                'conversation_id' => $conversation->id,
                'body' => 'JSON composer message',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(1, $conversation->messages()->where('body', 'JSON composer message')->count());
    }

    public function test_single_post_creates_single_message(): void
    {
        $rider = $this->riderWithUser('A');
        $conversation = $this->riderConversation($rider['user']);

        $this->actingAs($rider['user'])
            ->post(route('rider.messages.send'), ['body' => 'Only once'])
            ->assertRedirect();

        $this->assertSame(1, $conversation->messages()->where('body', 'Only once')->count());
    }

    // ── Read / unread ────────────────────────────────────────────

    public function test_rider_send_raises_staff_unread_and_opening_clears_it(): void
    {
        $admin = $this->admin();
        $rider = $this->riderWithUser('A');
        $conversation = $this->riderConversation($rider['user']);
        $this->assertSame(0, (int) $conversation->unread_count);

        $this->actingAs($rider['user'])
            ->post(route('rider.messages.send'), ['body' => 'Need help'])
            ->assertRedirect();

        $this->assertSame(1, (int) $conversation->fresh()->unread_count);

        $response = $this->actingAs($admin)->get(route('messages.show', $conversation));
        $response->assertOk()->assertJsonPath('messages.0.body', 'Need help');

        $this->assertSame(0, (int) $conversation->fresh()->unread_count);
        $this->assertTrue((bool) $conversation->messages()->first()->is_read);
    }

    public function test_logistics_send_does_not_inflate_staff_unread(): void
    {
        $admin = $this->admin();
        $rider = $this->riderWithUser('A');
        $conversation = $this->riderConversation($rider['user']);

        $this->actingAs($admin)
            ->post(route('messages.store'), [
                'conversation_id' => $conversation->id,
                'body' => 'Hello rider',
            ])->assertRedirect();

        $this->assertSame(0, (int) $conversation->fresh()->unread_count);
    }

    public function test_rider_poll_returns_new_messages_and_marks_them_read(): void
    {
        $admin = $this->admin();
        $rider = $this->riderWithUser('A');
        $conversation = $this->riderConversation($rider['user']);

        $this->actingAs($admin)
            ->post(route('messages.store'), [
                'conversation_id' => $conversation->id,
                'body' => 'Ping rider',
            ])->assertRedirect();

        $poll = $this->actingAs($rider['user'])
            ->getJson(route('rider.messages.poll'))
            ->assertOk()
            ->json();

        $this->assertSame('Ping rider', $poll['messages'][0]['body']);

        // Polling marks incoming messages read; a second poll shows them read.
        $again = $this->actingAs($rider['user'])
            ->getJson(route('rider.messages.poll'))
            ->assertOk()
            ->json();
        $this->assertTrue((bool) $again['messages'][0]['is_read']);
    }

    public function test_unread_filter_lists_only_unread_conversations(): void
    {
        $admin = $this->admin();
        $riderA = $this->riderWithUser('A');
        $riderB = $this->riderWithUser('B');
        $conversationA = $this->riderConversation($riderA['user']);
        $this->riderConversation($riderB['user']);

        $this->actingAs($riderA['user'])
            ->post(route('rider.messages.send'), ['body' => 'Needs eyes'])
            ->assertRedirect();

        $filtered = $this->actingAs($admin)
            ->get(route('messages.index', ['filter' => 'unread']))
            ->assertOk()
            ->viewData('conversations');

        $this->assertSame([$conversationA->id], $filtered->pluck('id')->all());
    }

    public function test_explicit_mark_read_endpoint(): void
    {
        $admin = $this->admin();
        $rider = $this->riderWithUser('A');
        $conversation = $this->riderConversation($rider['user']);

        $this->actingAs($rider['user'])
            ->post(route('rider.messages.send'), ['body' => 'Unread ping'])
            ->assertRedirect();
        $this->assertSame(1, (int) $conversation->fresh()->unread_count);

        $this->actingAs($admin)
            ->patch(route('messages.mark-read', $conversation))
            ->assertOk()
            ->assertJsonPath('total_unread', 0);

        $this->assertSame(0, (int) $conversation->fresh()->unread_count);
    }

    // ── Notifications ────────────────────────────────────────────

    public function test_logistics_send_notifies_rider_once_with_working_link(): void
    {
        $admin = $this->admin();
        $rider = $this->riderWithUser('A');
        $conversation = $this->riderConversation($rider['user']);

        $this->actingAs($admin)
            ->post(route('messages.store'), [
                'conversation_id' => $conversation->id,
                'body' => 'Your parcel is ready',
            ])->assertRedirect();

        // Global staff bell row with a working conversation link.
        $this->assertDatabaseHas('notifications', ['type' => 'new_message']);
        $link = Notification::where('type', 'new_message')->latest('id')->first()->link;
        $this->actingAs($admin)->get($link)->assertOk();

        // Rider-side bell row, exactly once even after a repeated send.
        $rows = RiderNotification::where('rider_id', $rider['rider']->id)->where('type', 'new_message');
        $this->assertSame(1, $rows->count());
        $this->assertSame($conversation->id, $rows->first()->data['conversation_id']);

        $this->actingAs($admin)
            ->post(route('messages.store'), [
                'conversation_id' => $conversation->id,
                'body' => 'Second ping',
            ])->assertRedirect();

        $this->assertSame(1, RiderNotification::where('rider_id', $rider['rider']->id)->where('type', 'new_message')->count());
        $this->assertSame(
            1,
            RiderNotification::where('rider_id', $rider['rider']->id)->where('is_read', false)->count()
        );
    }

    public function test_rider_send_creates_no_rider_notification_for_self(): void
    {
        $rider = $this->riderWithUser('A');
        $this->riderConversation($rider['user']);

        $this->actingAs($rider['user'])
            ->post(route('rider.messages.send'), ['body' => 'My own message'])
            ->assertRedirect();

        $this->assertSame(0, RiderNotification::where('rider_id', $rider['rider']->id)->count());
        $this->assertSame(0, Notification::count());
    }

    public function test_rider_notification_bell_counts_and_clears(): void
    {
        $admin = $this->admin();
        $rider = $this->riderWithUser('A');
        $conversation = $this->riderConversation($rider['user']);

        $this->actingAs($admin)
            ->post(route('messages.store'), [
                'conversation_id' => $conversation->id,
                'body' => 'Bell check',
            ])->assertRedirect();

        $index = $this->actingAs($rider['user'], 'sanctum')
            ->getJson('/api/rider/notifications')->assertOk();
        $this->assertSame(1, $index->json('unread_count'));

        $notificationId = $index->json('notifications.0.id');
        $this->actingAs($rider['user'], 'sanctum')
            ->patchJson("/api/rider/notifications/{$notificationId}/read", [])
            ->assertOk();

        $this->assertSame(
            0,
            $this->actingAs($rider['user'], 'sanctum')
                ->getJson('/api/rider/notifications')->json('unread_count')
        );
    }

    // ── Edit / delete ────────────────────────────────────────────

    public function test_sender_can_edit_own_message_and_it_persists(): void
    {
        $rider = $this->riderWithUser('A');
        $this->riderConversation($rider['user']);

        $this->actingAs($rider['user'])
            ->post(route('rider.messages.send'), ['body' => 'Typo here'])
            ->assertRedirect();

        $message = Message::where('body', 'Typo here')->firstOrFail();

        $this->actingAs($rider['user'])
            ->patchJson(route('rider.messages.update', $message), [
                'body' => 'Fixed text',
            ])->assertOk();

        $fresh = $message->fresh();
        $this->assertSame('Fixed text', $fresh->body);
        $this->assertNotNull($fresh->edited_at);
    }

    public function test_users_cannot_edit_or_delete_others_messages(): void
    {
        $riderA = $this->riderWithUser('A');
        $riderB = $this->riderWithUser('B');
        $this->riderConversation($riderA['user']);
        $this->riderConversation($riderB['user']);

        $this->actingAs($riderA['user'])
            ->post(route('rider.messages.send'), ['body' => 'A private note'])
            ->assertRedirect();
        $message = Message::where('body', 'A private note')->firstOrFail();

        $this->actingAs($riderB['user'])
            ->patchJson(route('rider.messages.update', $message), [
                'body' => 'Hijacked',
            ])->assertForbidden();

        $this->actingAs($riderB['user'])
            ->deleteJson(route('rider.messages.destroy', $message))
            ->assertForbidden();

        $this->assertSame('A private note', $message->fresh()->body);
        $this->assertNull($message->fresh()->deleted_at);
    }

    public function test_sender_can_delete_own_message_and_body_is_hidden(): void
    {
        $rider = $this->riderWithUser('A');
        $conversation = $this->riderConversation($rider['user']);

        $this->actingAs($rider['user'])
            ->post(route('rider.messages.send'), ['body' => 'Remove me'])
            ->assertRedirect();
        $message = Message::where('body', 'Remove me')->firstOrFail();

        $this->actingAs($rider['user'])
            ->deleteJson(route('rider.messages.destroy', $message))
            ->assertOk();

        $this->assertNotNull($message->fresh()->deleted_at);

        // Deleted bodies stay hidden on retrieval.
        $payload = $this->actingAs($rider['user'])
            ->getJson(route('rider.messages.poll'))
            ->assertOk()
            ->json('messages');
        $this->assertSame('', collect($payload)->firstWhere('id', $message->id)['body']);
    }

    public function test_logistics_staff_can_edit_own_but_not_rider_messages(): void
    {
        $admin = $this->admin();
        $otherAdmin = User::create([
            'name' => 'Other Admin', 'first_name' => 'Other', 'last_name' => 'Admin',
            'sex' => 'male', 'email' => 'other-admin-' . uniqid() . '@logistics.com',
            'password' => bcrypt('password'), 'phone' => '09000000009',
            'birthday' => '1990-01-01', 'age' => 35, 'role' => 'admin',
            'status' => 'active', 'email_verified_at' => now(),
        ]);
        $rider = $this->riderWithUser('A');
        $conversation = $this->riderConversation($rider['user']);

        $this->actingAs($rider['user'])
            ->post(route('rider.messages.send'), ['body' => 'Rider words'])
            ->assertRedirect();
        $riderMessage = Message::where('body', 'Rider words')->firstOrFail();

        // Staff cannot edit the rider's message.
        $this->actingAs($admin)
            ->patch(route('messages.update', $riderMessage), ['body' => 'Edited by staff'])
            ->assertForbidden();

        // Staff can edit their own message.
        $this->actingAs($admin)
            ->post(route('messages.store'), [
                'conversation_id' => $conversation->id,
                'body' => 'Staff words',
            ])->assertRedirect();
        $staffMessage = Message::where('body', 'Staff words')->firstOrFail();

        $this->actingAs($otherAdmin)
            ->patch(route('messages.update', $staffMessage), ['body' => 'Hijacked by peer'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->patch(route('messages.update', $staffMessage), ['body' => 'Staff words v2'])
            ->assertOk();

        $this->assertSame('Staff words v2', $staffMessage->fresh()->body);
    }

    // ── Attachments ──────────────────────────────────────────────

    public function test_attachment_upload_view_download_round_trip(): void
    {
        Storage::fake('local');
        $rider = $this->riderWithUser('A');
        $conversation = $this->riderConversation($rider['user']);

        $this->actingAs($rider['user'])
            ->post(route('rider.messages.send'), [
                'body' => 'See attached',
                'attachment' => $this->jpg(),
            ])->assertRedirect(route('rider.messages'));

        $attachment = MessageAttachment::latest('id')->first();
        $this->assertNotNull($attachment);
        $this->assertSame('note.jpg', $attachment->original_filename);
        Storage::disk('local')->assertExists($attachment->stored_path);

        // Logistics can view and download.
        $admin = $this->admin();
        $this->actingAs($admin)
            ->get(route('messages.attachments.view', $attachment))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($admin)
            ->get(route('messages.attachments.download', $attachment))
            ->assertOk();

        // The owning rider can view through the rider routes.
        $this->actingAs($rider['user'])
            ->get(route('rider.messages.attachments.view', $attachment))
            ->assertOk();
    }

    public function test_attachment_rejects_bad_mime_and_oversize(): void
    {
        Storage::fake('local');
        $rider = $this->riderWithUser('A');

        $this->actingAs($rider['user'])
            ->post(route('rider.messages.send'), [
                'body' => 'Bad file',
                'attachment' => UploadedFile::fake()->create('evil.exe', 100, 'application/x-msdownload'),
            ])->assertSessionHasErrors('attachment');

        $this->actingAs($rider['user'])
            ->post(route('rider.messages.send'), [
                'body' => 'Huge file',
                'attachment' => UploadedFile::fake()->create('huge.jpg', 6000, 'image/jpeg'),
            ])->assertSessionHasErrors('attachment');

        $this->assertSame(0, MessageAttachment::count());
    }

    public function test_attachment_access_is_authorized_and_missing_file_404s(): void
    {
        Storage::fake('local');
        $riderA = $this->riderWithUser('A');
        $riderB = $this->riderWithUser('B');
        $this->riderConversation($riderB['user']);

        $this->actingAs($riderA['user'])
            ->post(route('rider.messages.send'), [
                'body' => 'Private file',
                'attachment' => $this->jpg('private.jpg'),
            ])->assertRedirect();
        $attachment = MessageAttachment::latest('id')->firstOrFail();

        // Another rider cannot reach it through either surface.
        $this->actingAs($riderB['user'])
            ->get(route('rider.messages.attachments.view', $attachment))
            ->assertForbidden();

        // A row pointing at a missing file is a clean 404, not a 500.
        Storage::disk('local')->delete($attachment->stored_path);
        $this->actingAs($this->admin())
            ->get(route('messages.attachments.view', $attachment))
            ->assertNotFound();
    }

    // ── Logistics ↔ Rider round trip ─────────────────────────────

    public function test_logistics_and_rider_conversation_round_trip(): void
    {
        $admin = $this->admin();
        $rider = $this->riderWithUser('A');
        $conversation = $this->riderConversation($rider['user']);

        $this->actingAs($rider['user'])
            ->post(route('rider.messages.send'), ['body' => 'Hello logistics'])
            ->assertRedirect();

        $show = $this->actingAs($admin)
            ->get(route('messages.show', $conversation))
            ->assertOk()
            ->json();
        $this->assertSame('Hello logistics', $show['messages'][0]['body']);

        $this->actingAs($admin)
            ->post(route('messages.store'), [
                'conversation_id' => $conversation->id,
                'body' => 'Hello rider, noted',
            ])->assertRedirect();

        $poll = $this->actingAs($rider['user'])
            ->getJson(route('rider.messages.poll'))
            ->assertOk()
            ->json();
        $bodies = array_column($poll['messages'], 'body');
        $this->assertContains('Hello logistics', $bodies);
        $this->assertContains('Hello rider, noted', $bodies);
    }
}

