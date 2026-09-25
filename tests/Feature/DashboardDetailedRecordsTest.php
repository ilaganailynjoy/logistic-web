<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\User;
use Tests\TestCase;

/**
 * Dashboard Detailed Records honesty and scope.
 *
 * The section intentionally renders only the 50 most recently updated
 * deliveries with frontend-only search/sort/pagination (the full
 * Deliveries page stays authoritative). These tests lock that contract:
 * bounded record set, newest-first ordering, honest scope wording with a
 * visible path to the full page, correct empty states, and status pills
 * matching the canonical badge hues.
 */
class DashboardDetailedRecordsTest extends TestCase
{
    private function admin(): User
    {
        return User::create([
            'name' => 'Records Admin ' . uniqid(),
            'first_name' => 'Records',
            'last_name' => 'Admin',
            'sex' => 'male',
            'email' => 'records-admin-' . uniqid() . '@logistics.com',
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

    /**
     * @return Delivery[] newest first
     */
    private function seedDeliveries(int $count): array
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $delivery = Delivery::create([
                'sender_name' => 'Shop',
                'sender_phone' => '09170000001',
                'sender_address' => 'Shop St',
                'recipient_name' => 'Records Cust ' . $i,
                'recipient_phone' => '09170000002',
                'recipient_address' => 'Cust Ave',
                'status' => 'waiting_for_rider',
            ]);
            $delivery->forceFill(['updated_at' => now()->subMinutes($i)])->save();
            $rows[] = $delivery->fresh();
        }

        return $rows;
    }

    public function test_records_are_bounded_to_the_50_most_recent(): void
    {
        $rows = $this->seedDeliveries(55);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        // Newest 50 embedded; the 5 oldest fall outside the window.
        $this->assertStringContainsString($rows[0]->tracking_number, $html);
        $this->assertStringContainsString($rows[49]->tracking_number, $html);
        $this->assertStringNotContainsString($rows[50]->tracking_number, $html);
        $this->assertStringNotContainsString($rows[54]->tracking_number, $html);
    }

    public function test_records_order_newest_first(): void
    {
        $rows = $this->seedDeliveries(3);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $positions = array_map(
            fn (Delivery $d) => strpos($html, $d->tracking_number),
            $rows
        );

        $this->assertNotFalse($positions[0]);
        $this->assertLessThan($positions[1], $positions[0]);
        $this->assertLessThan($positions[2], $positions[1]);
    }

    public function test_scope_wording_and_full_page_path_are_visible(): void
    {
        $this->seedDeliveries(2);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        // Honest scope: recent records only, never full-database wording.
        $this->assertStringContainsString('recent deliveries', $html);
        $this->assertStringContainsString('Frontend-only filtering', $html);
        $this->assertStringContainsString('Full view', $html);
        $this->assertStringContainsString(route('deliveries.index'), $html);

        // The search box must not promise rider search: rows carry no rider.
        $this->assertStringNotContainsString('recipient, rider', $html);
    }

    public function test_empty_state_for_zero_records(): void
    {
        $html = (string) $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No delivery records yet', $html);
        $this->assertStringContainsString('Full view', $html);
    }

    public function test_rows_render_tracking_recipient_center_and_status(): void
    {
        $rows = $this->seedDeliveries(1);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($rows[0]->tracking_number, $html);
        $this->assertStringContainsString('Records Cust 0', $html);
        $this->assertStringContainsString('Waiting for Rider', $html);
    }

    public function test_status_pills_cover_all_delivery_statuses(): void
    {
        $html = (string) $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        // Extended mini-pill map mirrors the canonical status badge hues.
        foreach (['bg-amber-100', 'bg-blue-100', 'bg-cyan-100', 'bg-sky-100', 'bg-indigo-100', 'bg-purple-100', 'bg-violet-100', 'bg-emerald-100', 'bg-red-100'] as $class) {
            $this->assertStringContainsString($class, $html);
        }
    }
}
