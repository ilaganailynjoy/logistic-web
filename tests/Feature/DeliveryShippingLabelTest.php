<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\LogisticsCenter;
use App\Models\Order;
use App\Models\Rider;
use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unified INVOIZ shipping waybill on the delivery detail page: seller, order
 * and customer data merged with the logistics operations (tracking, Code128
 * barcode, QR verification payload, rider, centers, parcel, status, COD and
 * print-only CSS). The waybill uses only real data with "—" fallbacks and
 * never creates or modifies tracking numbers.
 */
class DeliveryShippingLabelTest extends TestCase
{
    private function admin(): User
    {
        return User::create([
            'name' => 'Label Admin ' . uniqid(),
            'first_name' => 'Label',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'label-admin-' . uniqid() . '@logistics.com',
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

    private function delivery(array $overrides = []): Delivery
    {
        return Delivery::create(array_merge([
            'sender_name' => 'Label Sender ' . uniqid(),
            'sender_phone' => '09170000001',
            'sender_address' => '1 Sender St, Manila',
            'recipient_name' => 'Label Receiver ' . uniqid(),
            'recipient_phone' => '09170000002',
            'recipient_address' => '2 Receiver Ave, Quezon City',
            'status' => 'waiting_for_rider',
        ], $overrides));
    }

    public function test_label_shows_real_tracking_sender_and_receiver(): void
    {
        $delivery = $this->delivery();

        $html = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('INVOIZ LOGISTICS', $html);
        $this->assertStringContainsString('SHIPPING WAYBILL', $html);
        $this->assertStringContainsString('src="' . asset('images/logo-nobg.png') . '"', $html);
        $this->assertStringContainsString('alt="INVOIZ logo"', $html);
        $this->assertStringContainsString('WAYBILL / TRACKING', $html);
        $this->assertStringContainsString($delivery->tracking_number, $html);
        $this->assertStringContainsString($delivery->sender_name, $html);
        $this->assertStringContainsString($delivery->sender_phone, $html);
        $this->assertStringContainsString($delivery->sender_address, $html);
        $this->assertStringContainsString($delivery->recipient_name, $html);
        $this->assertStringContainsString($delivery->recipient_phone, $html);
        $this->assertStringContainsString($delivery->recipient_address, $html);
        $this->assertStringContainsString('Print Shipping Label', $html);
    }

    public function test_label_renders_qr_and_barcode_hooks_for_tracking_number(): void
    {
        $delivery = $this->delivery();

        $html = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->getContent();

        // QR keeps the existing tracking-number payload and library.
        $this->assertStringContainsString('id="parcel-qr"', $html);
        $this->assertStringContainsString('qrcode.min.js', $html);
        // Code128 barcode is generated from the real tracking number.
        $this->assertStringContainsString('id="parcel-barcode"', $html);
        $this->assertStringContainsString('JsBarcode.all.min.js', $html);
        $this->assertStringContainsString('CODE128', $html);
    }

    public function test_label_shows_cod_only_when_amount_to_collect(): void
    {
        $cod = $this->delivery(['amount_to_collect' => 1250.00]);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $cod))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('₱1,250.00', $html);
        $this->assertStringContainsString('CASH ON DELIVERY', $html);

        $plain = $this->delivery();

        $plainHtml = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $plain))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('CASH ON DELIVERY', $plainHtml);
        $this->assertStringContainsString('NO AMOUNT TO COLLECT', $plainHtml);
    }

    public function test_label_lists_items_order_and_parcel_details_when_present(): void
    {
        // NOTE: order_id has a foreign key to the orders table, so the
        // ORDER block is verified by absence here; order-linked deliveries
        // render it from the real order_id when present.
        $delivery = $this->delivery([
            'package_type' => 'Parcel',
            'package_description' => 'Books bundle',
            'weight' => 2.50,
        ]);
        DeliveryItem::create([
            'delivery_id' => $delivery->id,
            'name' => 'Textbook Set',
            'quantity' => 3,
        ]);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('ORDER #:', $html);
        $this->assertStringContainsString('ORDER ITEMS', $html);
        $this->assertStringContainsString('Textbook Set', $html);
        $this->assertStringContainsString('<td class="py-0.5 px-1 text-center">3</td>', $html);
        $this->assertStringContainsString('Books bundle', $html);
        $this->assertStringContainsString('2.5 kg', $html);
    }

    public function test_label_shows_destination_center_when_set(): void
    {
        $center = LogisticsCenter::create([
            'name' => 'Label Destination ' . uniqid(), 'address' => 'D St',
            'city' => 'D City', 'province' => 'D', 'is_active' => true,
        ]);
        $delivery = $this->delivery(['destination_center_id' => $center->id]);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('DESTINATION: ' . strtoupper($center->name), $html);
    }

    public function test_label_is_hidden_for_archived_deliveries(): void
    {
        $delivery = $this->delivery(['archived_at' => now()]);

        $this->actingAs($this->admin())
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertDontSee('WAYBILL / TRACKING')
            ->assertDontSee('Print Shipping Label');
    }

    public function test_print_css_limits_output_to_the_label(): void
    {
        $delivery = $this->delivery();

        $html = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('@media print', $html);
        $this->assertStringContainsString('#shipping-label', $html);
        $this->assertStringContainsString('no-print', $html);
    }

    public function test_guest_cannot_view_delivery_label(): void
    {
        $delivery = $this->delivery();

        $this->get(route('deliveries.show', $delivery))->assertRedirect(route('login'));
    }

    private function person(string $role, string $first, string $last, string $phone): User
    {
        return User::create([
            'name' => $first . ' ' . $last,
            'first_name' => $first,
            'last_name' => $last,
            'sex' => 'male',
            'email' => strtolower($first . $last) . substr(uniqid(), -6) . '@waybill.test',
            'password' => bcrypt('password'),
            'phone' => $phone,
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => $role,
            'status' => 'active',
            'center_id' => null,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Build a full seller → order → logistics chain with real rows only.
     * Returns seller, buyer, order, items, delivery and related records.
     */
    private function orderChain(array $opts = []): array
    {
        $seller = $this->person('seller', 'Way', 'Seller', '09170000101');
        $storeName = 'Waybill Test Store ' . uniqid();
        DB::table('sellers')->insert([
            'user_id' => $seller->id,
            'business_name' => $storeName,
            'line_of_business' => 'Apparel',
        ]);

        $buyer = $this->person('buyer', 'Way', 'Buyer', '09170000102');
        $addressId = DB::table('addresses')->insertGetId([
            'buyer_id' => $buyer->id,
            'recipient_name' => 'Waybill Buyer',
            'phone' => '09170000103',
            'address_line' => '123 Buyer St',
            'barangay' => 'Barangay Uno',
            'city' => 'Calamba',
            'province' => 'Laguna',
            'postal_code' => '4027',
        ]);

        $categoryId = DB::table('categories')->insertGetId(['name' => 'Waybill Cat ' . uniqid()]);

        $items = $opts['items'] ?? [
            ['product_name' => 'Classic White T-Shirt', 'variant_label' => 'Size: L', 'quantity' => 1, 'price' => 199.00],
            ['product_name' => 'Blue Cap', 'variant_label' => null, 'quantity' => 2, 'price' => 150.00],
        ];
        $productIds = [];
        foreach ($items as $item) {
            $productIds[] = DB::table('products')->insertGetId([
                'seller_id' => $seller->id,
                'category_id' => $categoryId,
                'name' => $item['product_name'],
                'price' => $item['price'],
            ]);
        }

        $order = Order::create([
            'buyer_id' => $buyer->id,
            'address_id' => $addressId,
            'total_amount' => $opts['total'] ?? 529.00,
            'status' => $opts['order_status'] ?? 'processing',
            'notes' => $opts['order_notes'] ?? null,
        ]);

        foreach ($items as $i => $item) {
            DB::table('order_items')->insert([
                'order_id' => $order->id,
                'product_id' => $productIds[$i],
                'seller_id' => $seller->id,
                'product_name' => $item['product_name'],
                'variant_label' => $item['variant_label'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
            ]);
        }

        DB::table('payments')->insert([
            'order_id' => $order->id,
            'method' => $opts['payment_method'] ?? 'gcash',
            'status' => $opts['payment_status'] ?? 'paid',
            'amount' => $opts['total'] ?? 529.00,
            'reference_number' => 'WBREF123',
        ]);

        if (! empty($opts['discount'])) {
            $voucherId = DB::table('vouchers')->insertGetId([
                'code' => 'WB' . strtoupper(substr(uniqid(), -6)),
                'name' => 'Waybill Discount',
                'discount_value' => $opts['discount'],
            ]);
            DB::table('order_vouchers')->insert([
                'order_id' => $order->id,
                'voucher_id' => $voucherId,
                'discount_amount' => $opts['discount'],
            ]);
        }

        $origin = LogisticsCenter::create([
            'name' => 'Waybill Origin ' . uniqid(), 'address' => 'O St',
            'city' => 'O City', 'province' => 'O', 'is_active' => true,
        ]);
        $destination = LogisticsCenter::create([
            'name' => 'Waybill Destination ' . uniqid(), 'address' => 'D St',
            'city' => 'D City', 'province' => 'D', 'is_active' => true,
        ]);
        $area = ServiceArea::create([
            'logistics_center_id' => $destination->id,
            'name' => 'Waybill Area ' . uniqid(),
            'is_active' => true,
        ]);

        $rider = null;
        if (! ($opts['no_rider'] ?? false)) {
            $rider = Rider::create([
                'name' => 'Waybill Rider ' . uniqid(),
                'email' => 'waybill-rider-' . uniqid() . '@test.com',
                'phone' => '09170000104',
                'vehicle_type' => 'Motorcycle',
                'license_plate' => 'WB 1234',
                'status' => 'available',
                'center_id' => $destination->id,
                'approved_at' => now()->subDays(10),
                'vehicle_verification' => 'verified',
            ]);
        }

        $delivery = Delivery::create(array_merge([
            'order_id' => $order->id,
            'rider_id' => $rider?->id,
            'center_id' => $origin->id,
            'destination_center_id' => $destination->id,
            'service_area_id' => $area->id,
            'sender_name' => 'Fallback Sender',
            'sender_phone' => '09170000105',
            'sender_address' => '9 Fallback St',
            'recipient_name' => 'Fallback Receiver',
            'recipient_phone' => '09170000106',
            'recipient_address' => '8 Fallback Ave',
            'status' => 'out_for_delivery',
            'picked_up_at' => now()->subDay(),
            'weight' => 1.50,
            'package_type' => 'Parcel',
            'package_description' => 'Apparel bundle',
            'priority' => 'high',
            'delivery_notes' => 'Leave at gate',
            'delivery_fee' => 50.00,
            'amount_to_collect' => $opts['collect'] ?? 0,
        ], $opts['delivery'] ?? []));

        return compact('seller', 'storeName', 'buyer', 'order', 'items', 'rider', 'origin', 'destination', 'area', 'delivery');
    }

    public function test_waybill_shows_unified_order_and_logistics_content(): void
    {
        $chain = $this->orderChain(['discount' => 20.00]);
        $order = $chain['order'];
        $delivery = $chain['delivery'];

        $html = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->getContent();

        // Section structure of the merged courier document.
        foreach (['SHIPPED BY / SELLER', 'DELIVER TO / BUYER', 'DELIVERY / LOGISTICS', 'PAYMENT', 'ORDER ITEMS', 'PARCEL INFORMATION', 'QR VERIFICATION', 'SCAN TO VERIFY PARCEL'] as $heading) {
            $this->assertStringContainsString($heading, $html);
        }

        // Order identity: number and date. The waybill carries no status so a
        // single print stays valid through every courier stage.
        $this->assertStringContainsString('ORDER #: ' . $order->id, $html);
        $this->assertStringContainsString($order->created_at->format('F d, Y'), $html);
        $this->assertStringNotContainsString('ORDER: PROCESSING', $html);
        $this->assertStringNotContainsString('Status:</dt>', $html);

        // Tracking stays the single identifier everywhere.
        $this->assertStringContainsString($delivery->tracking_number, $html);
        $this->assertStringContainsString('aria-label="Barcode for ' . $delivery->tracking_number . '"', $html);
        $this->assertStringContainsString('aria-label="QR code for parcel ' . $delivery->tracking_number . '"', $html);

        // Seller / store side from the real seller record.
        $this->assertStringContainsString($chain['storeName'], $html);
        $this->assertStringContainsString('09170000101', $html);

        // Buyer side from the authoritative order address.
        foreach (['Waybill Buyer', '123 Buyer St', 'Barangay Uno', 'Calamba', 'Laguna', '4027', '09170000103'] as $token) {
            $this->assertStringContainsString($token, $html);
        }

        // Multiple items with quantities, prices and subtotals.
        $this->assertStringContainsString('Classic White T-Shirt', $html);
        $this->assertStringContainsString('Size: L', $html);
        $this->assertStringContainsString('Blue Cap', $html);
        $this->assertStringContainsString('<td class="py-0.5 px-1 text-center">1</td>', $html);
        $this->assertStringContainsString('<td class="py-0.5 px-1 text-center">2</td>', $html);
        $this->assertStringContainsString('₱199.00', $html);
        $this->assertStringContainsString('₱300.00', $html);
        $this->assertStringContainsString('₱499.00', $html);
        $this->assertStringContainsString('₱50.00', $html);
        $this->assertStringContainsString('−₱20.00', $html);
        $this->assertStringContainsString('₱529.00', $html);

        // Payment method from the payment record + reference.
        $this->assertStringContainsString('GCASH', $html);
        $this->assertStringContainsString('WBREF123', $html);
        $this->assertStringContainsString('NO AMOUNT TO COLLECT', $html);

        // Logistics facts: rider, pickup, centers, area, parcel.
        $this->assertStringContainsString($chain['rider']->name, $html);
        $this->assertStringContainsString('Completed ·', $html);
        $this->assertStringContainsString($chain['origin']->name, $html);
        $this->assertStringContainsString($chain['destination']->name, $html);
        $this->assertStringContainsString($chain['area']->name, $html);
        $this->assertStringContainsString('Apparel bundle', $html);
        $this->assertStringContainsString('Leave at gate', $html);
        $this->assertStringContainsString('Thank you for shopping with INVOIZ.', $html);
    }

    public function test_waybill_collects_cod_amount_from_payment_record(): void
    {
        $chain = $this->orderChain([
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'collect' => 529.00,
        ]);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $chain['delivery']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('₱529.00', $html);
        $this->assertStringContainsString('CASH ON DELIVERY', $html);
        $this->assertStringContainsString('COLLECT ON DELIVERY', $html);
        $this->assertStringNotContainsString('NO AMOUNT TO COLLECT', $html);
    }

    public function test_waybill_handles_unassigned_rider_and_missing_logistics_safely(): void
    {
        $chain = $this->orderChain(['no_rider' => true, 'delivery' => [
            'center_id' => null,
            'destination_center_id' => null,
            'service_area_id' => null,
            'picked_up_at' => null,
            'status' => 'waiting_for_rider',
        ]]);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $chain['delivery']))
            ->assertOk()
            ->getContent();

        foreach (['Courier:', 'Pickup:', 'Origin:', 'Destination:', 'Service Area:'] as $row) {
            $this->assertStringContainsString($row, $html);
        }
        // Status-free waybill: nothing to reprint as the parcel moves.
        // (The screen page header still shows the live status outside the label.)
        $this->assertStringNotContainsString('Status:</dt>', $html);
    }

    public function test_waybill_falls_back_to_sender_recipient_without_order(): void
    {
        $delivery = $this->delivery();

        $html = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('SHIPPED BY / SELLER', $html);
        $this->assertStringContainsString('DELIVER TO / BUYER', $html);
        $this->assertStringContainsString($delivery->sender_name, $html);
        $this->assertStringContainsString($delivery->recipient_name, $html);
        $this->assertStringNotContainsString('ORDER #:', $html);
    }

    public function test_printing_does_not_modify_tracking_number(): void
    {
        $chain = $this->orderChain();
        $delivery = $chain['delivery'];
        $before = $delivery->tracking_number;

        $first = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->getContent();
        $second = (string) $this->actingAs($this->admin())
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($before, $first);
        $this->assertStringContainsString($before, $second);
        $this->assertSame($before, $delivery->fresh()->tracking_number);
    }

    public function test_qr_verification_still_verifies_waybill_parcel(): void
    {
        $chain = $this->orderChain(['delivery' => ['parcel_status' => 'received']]);
        $delivery = $chain['delivery'];

        $this->actingAs($this->admin())
            ->postJson(route('deliveries.scan-verify'), ['tracking_number' => $delivery->tracking_number])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('delivery.tracking_number', $delivery->tracking_number)
            ->assertJsonPath('delivery.parcel_status', 'scanned');

        $this->assertSame($delivery->tracking_number, $delivery->fresh()->tracking_number);
    }
}
