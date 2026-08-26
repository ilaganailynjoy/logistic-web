<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Development seed data for Logistics Messages.
 *
 * Creates a small set of realistic rider ↔ logistics conversations using
 * EXISTING user accounts (no fake roles). Safe to re-run: conversations are
 * created with firstOrCreate and messages are only inserted when the
 * conversation is still empty, so real user messages are never duplicated
 * or overwritten.
 */
class LogisticsMessageSeeder extends Seeder
{
    public function run(): void
    {
        $logisticsAdmin = User::where('role', 'admin')->orderBy('id')->first();
        $riders = User::where('role', 'rider')->orderBy('id')->get()->keyBy('id');

        if (!$logisticsAdmin || $riders->count() < 3) {
            $this->command->warn('LogisticsMessageSeeder skipped: required rider/admin users not found.');

            return;
        }

        $ahmad = $riders->first();
        $siti = $riders->skip(1)->first();
        $ali = $riders->skip(2)->first();

        $this->seedPickupConversation($ahmad, $logisticsAdmin);
        $this->seedDeliveryIssueConversation($siti);
        $this->seedDamagedPackageConversation($ali, $logisticsAdmin);
    }

    /**
     * Conversation 1 — pickup workflow, all messages read, one edited reply.
     */
    private function seedPickupConversation(User $rider, User $logisticsAdmin): void
    {
        $conversation = $this->firstConversation($rider);
        if ($conversation->messages()->exists()) {
            return;
        }

        $base = now()->subHours(3);

        $this->addMessage($conversation, 'rider', $rider->id,
            "I'm at the pickup location for delivery #{$this->trackingRef($conversation)}.", $base, isRead: true);

        $logisticsReply = $this->addMessage($conversation, 'logistics', $logisticsAdmin->id,
            'Okay, please proceed with the pickup and update the delivery status once collected.',
            $base->copy()->addMinutes(6), isRead: true);

        // Demonstrate the edited-message indicator.
        $logisticsReply->update([
            'body' => 'Okay, please proceed with the pickup and update the delivery status once collected. Ping dispatch if the sender is unreachable.',
            'edited_at' => $base->copy()->addMinutes(8),
        ]);

        $this->addMessage($conversation, 'rider', $rider->id,
            'The package has been picked up. On my way to the drop-off area.',
            $base->copy()->addMinutes(14), isRead: true);

        $this->finishConversation($conversation, 'The package has been picked up. On my way to the drop-off area.', $base->copy()->addMinutes(14));
    }

    /**
     * Conversation 2 — delivery issue. Latest rider message is intentionally
     * left UNREAD so the Logistics unread badge can be demonstrated.
     */
    private function seedDeliveryIssueConversation(User $rider): void
    {
        $conversation = $this->firstConversation($rider);
        if ($conversation->messages()->exists()) {
            return;
        }

        $base = now()->subHours(2);

        $this->addMessage($conversation, 'rider', $rider->id,
            'The customer was not available at the delivery address.', $base, isRead: true);

        $this->addMessage($conversation, 'logistics', 1,
            'Please contact the customer and wait a few minutes before attempting delivery again.',
            $base->copy()->addMinutes(5), isRead: true);

        // Latest rider message: unread by Logistics.
        $this->addMessage($conversation, 'rider', $rider->id,
            'I contacted the customer. They will be available shortly.',
            $base->copy()->addMinutes(12), isRead: false);

        $this->finishConversation($conversation, 'I contacted the customer. They will be available shortly.', $base->copy()->addMinutes(12), unread: 1);
    }

    /**
     * Conversation 3 — damaged package with a real image attachment and a
     * soft-deleted rider message.
     */
    private function seedDamagedPackageConversation(User $rider, User $logisticsAdmin): void
    {
        $conversation = $this->firstConversation($rider);
        if ($conversation->messages()->exists()) {
            return;
        }

        $base = now()->subMinutes(40);

        // A rider message that was deleted (soft delete) — shows the
        // "This message was deleted." placeholder in the UI.
        $this->addMessage($conversation, 'rider', $rider->id,
            'Wrong chat, sorry.', $base, isRead: true, deletedAt: $base->copy()->addMinutes(2));

        $this->addMessage($conversation, 'rider', $rider->id,
            'The package was damaged during pickup. Please see the attached photo.',
            $base->copy()->addMinutes(4), isRead: true);

        $damageMessage = $this->addMessage($conversation, 'rider', $rider->id,
            'The box was already dented when I collected it from the sender.',
            $base->copy()->addMinutes(6), isRead: true);

        $this->storeAttachment($damageMessage, 'package-damage-example.png', 'image/png');

        $this->addMessage($conversation, 'logistics', $logisticsAdmin->id,
            'Thanks for the photo. Please note the damage on the delivery record and continue — support will follow up with the sender.',
            $base->copy()->addMinutes(11), isRead: true);

        $this->finishConversation($conversation, 'Thanks for the photo. Please note the damage on the delivery record and continue — support will follow up with the sender.', $base->copy()->addMinutes(11));
    }

    // ------------------------------------------------------------------

    private function firstConversation(User $rider): Conversation
    {
        return Conversation::firstOrCreate(
            ['participant_type' => 'rider', 'participant_id' => $rider->id],
            [
                'participant_name' => $rider->name,
                'subject' => 'Rider Support',
                'last_message_at' => now(),
            ],
        );
    }

    private function addMessage(Conversation $conversation, string $senderType, int $senderId, string $body, $createdAt, bool $isRead = true, $deletedAt = null): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'body' => $body,
            'is_read' => $isRead,
            'read_at' => $isRead ? $createdAt->copy()->addMinutes(1) : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'deleted_at' => $deletedAt,
        ]);
    }

    private function storeAttachment(Message $message, string $originalFilename, string $mime): void
    {
        // A small, real, valid image so previews and downloads actually work.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAT0lEQVR42u3XMQ0AMAgEQXCf7l1gECjAApv1w1Xb7s65OQH/pXoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX+k+gF/pPoBf6T6AX8=');

        $storedPath = 'message-attachments/' . $message->conversation_id . '/' . \Illuminate\Support\Str::uuid() . '.png';
        Storage::disk('local')->put($storedPath, $png);

        MessageAttachment::create([
            'message_id' => $message->id,
            'original_filename' => $originalFilename,
            'stored_path' => $storedPath,
            'mime_type' => $mime,
            'file_size' => strlen($png),
        ]);
    }

    private function finishConversation(Conversation $conversation, string $preview, $lastMessageAt, int $unread = 0): void
    {
        $conversation->update([
            'last_message_preview' => \Illuminate\Support\Str::limit($preview, 80),
            'last_message_at' => $lastMessageAt,
            'unread_count' => $unread,
        ]);
    }

    private function trackingRef(Conversation $conversation): string
    {
        return $conversation->delivery?->tracking_number ?? 'TRK-' . now()->format('Ymd') . '-SEED';
    }
}
