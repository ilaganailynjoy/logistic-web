<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Seeder;

class MessageSeeder extends Seeder
{
    public function run(): void
    {
        $conversations = [
            [
                'order_id' => 1025,
                'participant_type' => 'rider',
                'participant_id' => 1,
                'participant_name' => 'Carlo Reyes',
                'subject' => 'Package not ready',
                'messages' => [
                    ['sender_type' => 'rider', 'sender_id' => 1, 'body' => 'The seller hasn\'t prepared the package yet. I\'ve been waiting for 15 minutes already.', 'created_at' => now()->subMinutes(32)],
                    ['sender_type' => 'logistics', 'sender_id' => 1, 'body' => 'Please wait 10 minutes. We\'re contacting the seller right now.', 'created_at' => now()->subMinutes(28)],
                    ['sender_type' => 'rider', 'sender_id' => 1, 'body' => 'Okay, thank you. I\'ll wait.', 'created_at' => now()->subMinutes(25)],
                    ['sender_type' => 'rider', 'sender_id' => 1, 'body' => 'Still not ready. It\'s been almost 25 minutes now.', 'created_at' => now()->subMinutes(5)],
                    ['sender_type' => 'logistics', 'sender_id' => 1, 'body' => 'We\'re sorry for the delay. The seller confirmed it will be ready in 5 minutes. Please stand by.', 'created_at' => now()->subMinutes(3)],
                ],
                'last_message_at' => now()->subMinutes(3),
                'unread_count' => 0,
            ],
            [
                'order_id' => 1030,
                'participant_type' => 'seller',
                'participant_id' => 2,
                'participant_name' => 'ABC Store',
                'subject' => 'Ready for pickup',
                'messages' => [
                    ['sender_type' => 'seller', 'sender_id' => 2, 'body' => 'Order #1030 is ready for pickup. Please send a rider.', 'created_at' => now()->subHours(2)],
                    ['sender_type' => 'logistics', 'sender_id' => 1, 'body' => 'Noted. A rider has been assigned and is on the way to your location.', 'created_at' => now()->subHours(1)->subMinutes(50)],
                    ['sender_type' => 'seller', 'sender_id' => 2, 'body' => 'Thank you! We\'ll have it at the front counter.', 'created_at' => now()->subHours(1)->subMinutes(45)],
                ],
                'last_message_at' => now()->subHours(1)->subMinutes(45),
                'unread_count' => 0,
            ],
            [
                'order_id' => 1042,
                'participant_type' => 'rider',
                'participant_id' => 3,
                'participant_name' => 'Juan Dela Cruz',
                'subject' => 'Wrong address',
                'messages' => [
                    ['sender_type' => 'rider', 'sender_id' => 3, 'body' => 'Hi, the delivery address for Order #1042 seems incorrect. The customer says they moved.', 'created_at' => now()->subMinutes(45)],
                    ['sender_type' => 'logistics', 'sender_id' => 1, 'body' => 'Thanks for letting us know. Can you confirm the current address the customer provided?', 'created_at' => now()->subMinutes(40)],
                    ['sender_type' => 'rider', 'sender_id' => 3, 'body' => 'The customer says the new address is 456 Oak Street, Unit 3B.', 'created_at' => now()->subMinutes(35)],
                    ['sender_type' => 'logistics', 'sender_id' => 1, 'body' => 'Got it. Please proceed to the new address. We\'ll update the order records.', 'created_at' => now()->subMinutes(30)],
                ],
                'last_message_at' => now()->subMinutes(30),
                'unread_count' => 0,
            ],
            [
                'order_id' => 1055,
                'participant_type' => 'seller',
                'participant_id' => 4,
                'participant_name' => 'FreshMart',
                'subject' => 'Delivery delay concern',
                'messages' => [
                    ['sender_type' => 'seller', 'sender_id' => 4, 'body' => 'Our customer is complaining about the delivery time for Order #1055. It\'s been over 2 hours.', 'created_at' => now()->subMinutes(15)],
                    ['sender_type' => 'logistics', 'sender_id' => 1, 'body' => 'We apologize for the inconvenience. Let us check the status with the assigned rider.', 'created_at' => now()->subMinutes(12)],
                    ['sender_type' => 'seller', 'sender_id' => 4, 'body' => 'Please do. The customer is getting frustrated and might cancel.', 'created_at' => now()->subMinutes(10)],
                    ['sender_type' => 'logistics', 'sender_id' => 1, 'body' => 'The rider is currently on another delivery nearby. He will proceed to your customer\'s location within 20 minutes. We\'ll keep you posted.', 'created_at' => now()->subMinutes(8)],
                ],
                'last_message_at' => now()->subMinutes(8),
                'unread_count' => 2,
            ],
            [
                'order_id' => 1068,
                'participant_type' => 'rider',
                'participant_id' => 5,
                'participant_name' => 'Mark Santos',
                'subject' => 'Weather delay',
                'messages' => [
                    ['sender_type' => 'rider', 'sender_id' => 5, 'body' => 'Heavy rain in the area. I need to take shelter for a bit. Deliveries will be delayed.', 'created_at' => now()->subMinutes(50)],
                    ['sender_type' => 'logistics', 'sender_id' => 1, 'body' => 'Safety first, Mark. Take your time. We\'ll notify the affected customers.', 'created_at' => now()->subMinutes(48)],
                ],
                'last_message_at' => now()->subMinutes(48),
                'unread_count' => 0,
            ],
        ];

        foreach ($conversations as $convData) {
            $messages = $convData['messages'];
            unset($convData['messages']);

            $conversation = Conversation::create($convData);

            foreach ($messages as $msgData) {
                $msgData['conversation_id'] = $conversation->id;
                Message::create($msgData);
            }
        }
    }
}
