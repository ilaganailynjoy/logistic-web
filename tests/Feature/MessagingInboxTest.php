<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Delivery;
use App\Models\LogisticsCenter;
use App\Models\Message;
use App\Models\Rider;
use App\Models\RiderNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * E-commerce messaging inbox: automatic conversation discovery, tracking
 * references, unread filtering, authorization, read state, and sending.
 *
 * No contact picker exists anywhere: threads appear from real
 * rider/order/seller/buyer relationships, and every access path is
 * server-authorized (including revocation on reassignment).
 */
class MessagingInboxTest extends TestCase
{
    private function user(string $role, string $tag, array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Msg ' . $tag . ' ' . uniqid(),
            'first_name' => 'Msg',
            'last_name' => $tag,
            'sex' => 'male',
            'email' => 'msg-' . strtolower($tag) . '-' . uniqid() . '@test.com',
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

    private function riderAccount(Rider $rider): User
    {
        $user = $this->user('rider', 'Rider', [
            'name' => $rider->name,
            'email' => $rider->email,
            'phone' => $rider->phone,
        ]);
        $rider->update(['user_id' => $user->id]);

        return $user;
    }

    private function makeRider(LogisticsCenter $center, string $tag): array
    {
        $rider = Rider::create([
            'name' => 'Msg Rider ' . $tag . ' ' . uniqid(),
            'email' => 'msg-rider-' . strtolower($tag) . '-' . uniqid() . '@test.com',
            'phone' => '09000000002',
            'vehicle_type' => 'Motorcycle',
            'license_plate' => 'MSG ' . $tag,
            'status' => 'available',
            'center_id' => $center->id,
        ]);
        $user = $this->riderAccount($rider);

        return [$rider, $user];
    }

    /**
     * Real order graph in the shared tables: seller (+store), buyer,
     * address, category/product, order + item, and a delivery linking
     * the order to the rider.
     *
     * @return array{order_id:int, delivery:Delivery}
     */
    private function orderDelivery(LogisticsCenter $center, Rider $rider, User $seller, User $buyer): array
    {
        $addressId = DB::table('addresses')->insertGetId([
            'buyer_id' => $buyer->id,
            'recipient_name' => $buyer->name,
            'phone' => '09170000009',
            'address_line' => '9 Buyer St',
            'barangay' => 'Brgy',
            'city' => 'Calamba',
            'province' => 'Laguna',
            'postal_code' => '4027',
        ]);
        $categoryId = DB::table('categories')->insertGetId(['name' => 'MsgCat ' . uniqid()]);
        $productId = DB::table('products')->insertGetId([
            'seller_id' => $seller->id,
            'category_id' => $categoryId,
            'name' => 'Msg Widget',
            'price' => 100.00,
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'buyer_id' => $buyer->id,
            'address_id' => $addressId,
            'total_amount' => 100.00,
            'status' => 'processing',
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $productId,
            'seller_id' => $seller->id,
            'product_name' => 'Msg Widget',
            'quantity' => 1,
            'price' => 100.00,
        ]);

        $delivery = Delivery::create([
            'order_id' => $orderId,
            'rider_id' => $rider->id,
            'sender_name' => 'Msg Store',
            'sender_phone' => '09170000001',
            'sender_address' => 'Shop St',
            'recipient_name' => $buyer->name,
            'recipient_phone' => '09170000002',
            'recipient_address' => '9 Buyer St',
            'status' => 'assigned',
            'center_id' => $center->id,
            'destination_center_id' => $center->id,
        ]);

        return [$orderId, $delivery];
    }

    private function center(): LogisticsCenter
    {
        return LogisticsCenter::create([
            'name' => 'Msg Center ' . uniqid(), 'address' => 'M St',
            'city' => 'M City', 'province' => 'M', 'is_active' => true,
        ]);
    }

    public function test_rider_index_auto_discovers_support_and_order_threads(): void
    {
        $center = $this->center();
        [$rider, $user] = $this->makeRider($center, 'A');
        $seller = $this->user('seller', 'Seller');
        DB::table('sellers')->insert([
            'user_id' => $seller->id, 'business_name' => 'Msg Store ' . uniqid(), 'line_of_business' => 'Goods',
        ]);
        $buyer = $this->user('buyer', 'Buyer');
        [$orderId, $delivery] = $this->orderDelivery($center, $rider, $seller, $buyer);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/rider/conversations')
            ->assertOk();

        $threads = collect($response->json('conversations'));
        $this->assertGreaterThanOrEqual(3, $threads->count());

        // Logistics support thread (no order).
        $support = $threads->firstWhere('type', 'rider');
        $this->assertNotNull($support);

        // Buyer thread from the real buyer relationship.
        $buyerThread = $threads->firstWhere('type', 'buyer');
        $this->assertNotNull($buyerThread);
        $this->assertSame($buyer->name, $buyerThread['name']);
        $this->assertSame($orderId, $buyerThread['order_id']);
        $this->assertSame($delivery->tracking_number, $buyerThread['tracking']);

        // Seller thread carries the store name + delivery tracking.
        $sellerThread = $threads->firstWhere('type', 'seller');
        $this->assertNotNull($sellerThread);
        $this->assertStringContainsString('Msg Store', $sellerThread['name']);
        $this->assertSame($delivery->tracking_number, $sellerThread['tracking']);
    }

    public function test_staff_inbox_shows_tracking_and_search_finds_it(): void
    {
        $center = $this->center();
        [$rider, $user] = $this->makeRider($center, 'B');
        $seller = $this->user('seller', 'Seller');
        $buyer = $this->user('buyer', 'Buyer');
        [$orderId, $delivery] = $this->orderDelivery($center, $rider, $seller, $buyer);

        // Generate threads through the real discovery path.
        $this->actingAs($user, 'sanctum')->getJson('/api/rider/conversations')->assertOk();

        $html = (string) $this->actingAs($this->user('admin', 'Admin'))
            ->get(route('messages.index'))
            ->assertOk()
            ->getContent();

        // Tracking + order refs ride in the server-provided thread payload
        // (the visible row text itself is Alpine-rendered client-side, and
        // @js escapes quotes as \u0022 in the embedded JSON).
        $this->assertStringContainsString($delivery->tracking_number, $html);
        $this->assertStringContainsString('\u0022order_id\u0022:' . $orderId, $html);
        $this->assertStringContainsString('\u0022tracking\u0022:\u0022' . $delivery->tracking_number . '\u0022', $html);

        // Tracking search resolves through the fixed order link.
        $searchHtml = (string) $this->actingAs($this->user('staff', 'Staff'))
            ->get(route('messages.index', ['search' => $delivery->tracking_number]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($delivery->tracking_number, $searchHtml);
    }

    public function test_unread_filter_shows_only_unread_threads(): void
    {
        $staff = $this->user('staff', 'Staff');
        Conversation::create([
            'participant_type' => 'rider', 'participant_id' => 1,
            'participant_name' => 'Unread Rider', 'subject' => 'S',
            'last_message_at' => now(), 'unread_count' => 3,
        ]);
        Conversation::create([
            'participant_type' => 'rider', 'participant_id' => 2,
            'participant_name' => 'Read Rider', 'subject' => 'S',
            'last_message_at' => now()->subHour(), 'unread_count' => 0,
        ]);

        $html = (string) $this->actingAs($staff)
            ->get(route('messages.index', ['filter' => 'unread']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Unread Rider', $html);
        $this->assertStringNotContainsString('Read Rider', $html);

        $all = (string) $this->actingAs($staff)
            ->get(route('messages.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Read Rider', $all);
    }

    public function test_reassignment_revokes_previous_rider_thread_access(): void
    {
        $center = $this->center();
        [$riderA, $userA] = $this->makeRider($center, 'A');
        [$riderB, $userB] = $this->makeRider($center, 'B');
        $seller = $this->user('seller', 'Seller');
        $buyer = $this->user('buyer', 'Buyer');
        [$orderId, $delivery] = $this->orderDelivery($center, $riderA, $seller, $buyer);

        $threads = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/rider/conversations')
            ->assertOk()
            ->json('conversations');
        $buyerThreadId = collect($threads)->firstWhere('type', 'buyer')['id'];

        // Still assigned: access granted.
        $this->actingAs($userA, 'sanctum')
            ->getJson("/api/rider/conversations/{$buyerThreadId}")
            ->assertOk();

        // Reassign the delivery away from A.
        $delivery->update(['rider_id' => $riderB->id]);

        // A's access is revoked; B gains it through discovery.
        $this->actingAs($userA, 'sanctum')
            ->getJson("/api/rider/conversations/{$buyerThreadId}")
            ->assertForbidden();

        $bThreads = $this->actingAs($userB, 'sanctum')
            ->getJson('/api/rider/conversations')
            ->assertOk()
            ->json('conversations');
        $this->assertNotNull(collect($bThreads)->firstWhere('type', 'buyer'));
    }

    public function test_rider_cannot_open_another_riders_thread(): void
    {
        $center = $this->center();
        [$riderA, $userA] = $this->makeRider($center, 'A');
        [, $userB] = $this->makeRider($center, 'B');

        $threadId = Conversation::create([
            'participant_type' => 'rider', 'participant_id' => $userA->id,
            'participant_name' => 'A', 'subject' => 'Rider Support',
            'last_message_at' => now(), 'rider_id' => $riderA->id,
        ])->id;

        $this->actingAs($userB, 'sanctum')
            ->getJson("/api/rider/conversations/{$threadId}")
            ->assertForbidden();

        $this->actingAs($userA, 'sanctum')
            ->getJson("/api/rider/conversations/{$threadId}")
            ->assertOk();
    }

    public function test_staff_opens_any_thread_and_marks_it_read(): void
    {
        $thread = Conversation::create([
            'participant_type' => 'rider', 'participant_id' => 9,
            'participant_name' => 'Some Rider', 'subject' => 'S',
            'last_message_at' => now(), 'unread_count' => 2,
        ]);
        Message::create([
            'conversation_id' => $thread->id, 'sender_type' => 'rider',
            'sender_id' => 9, 'body' => 'Hello logistics',
        ]);

        $this->actingAs($this->user('staff', 'Staff'))
            ->getJson(route('messages.show', $thread))
            ->assertOk()
            ->assertJsonPath('active.id', $thread->id);

        $this->assertSame(0, $thread->fresh()->unread_count);
    }

    public function test_staff_send_appends_bumps_and_notifies_rider(): void
    {
        $center = $this->center();
        [$rider, $user] = $this->makeRider($center, 'C');

        $thread = Conversation::create([
            'participant_type' => 'rider', 'participant_id' => $user->id,
            'participant_name' => $rider->name, 'subject' => 'Rider Support',
            'last_message_at' => now()->subHour(), 'rider_id' => $rider->id,
        ]);

        $this->actingAs($this->user('staff', 'Staff'))
            ->post(route('messages.store'), [
                'conversation_id' => $thread->id,
                'body' => 'On your way?',
            ])
            ->assertRedirect();

        $thread->refresh();
        $this->assertSame('On your way?', $thread->last_message_preview);
        $this->assertTrue($thread->last_message_at->greaterThan(now()->subMinute()));

        // The participating rider (not staff) gets the existing
        // rider-side new_message notice with the message text.
        $this->assertDatabaseHas('rider_notifications', [
            'rider_id' => $rider->id,
            'type' => 'new_message',
            'title' => 'New Message',
        ]);
    }

    public function test_guest_cannot_open_staff_inbox(): void
    {
        $this->get(route('messages.index'))->assertRedirect(route('login'));
    }
}
