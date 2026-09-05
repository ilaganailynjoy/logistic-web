<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\LogisticsCenter;
use App\Models\Rider;
use App\Models\ServiceArea;
use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    private function user(string $role = 'admin'): User
    {
        return User::create([
            'name' => 'Report '.$role.' '.uniqid(),
            'first_name' => 'Report',
            'last_name' => ucfirst($role),
            'sex' => 'male',
            'email' => 'report-'.$role.'-'.uniqid().'@logistics.com',
            'password' => bcrypt('password'),
            'phone' => '09000000000',
            'birthday' => '1990-01-01',
            'age' => 35,
            'role' => $role,
            'status' => 'active',
            'center_id' => null,
            'email_verified_at' => now(),
        ]);
    }

    private function center(): LogisticsCenter
    {
        return LogisticsCenter::create([
            'name' => 'Report Center '.uniqid(),
            'address' => 'Test St',
            'city' => 'Test City',
            'province' => 'Test',
            'is_active' => true,
        ]);
    }

    private function area(LogisticsCenter $center): ServiceArea
    {
        return ServiceArea::create([
            'logistics_center_id' => $center->id,
            'name' => 'Report Area '.uniqid(),
            'is_active' => true,
        ]);
    }

    public function test_reports_index_renders_for_staff(): void
    {
        $admin = $this->user();
        $this->center();

        $this->actingAs($admin)
            ->get(route('reports.index', ['tab' => 'delivery']))
            ->assertOk()
            ->assertSee('Download PDF');
    }

    public function test_report_export_is_staff_gated(): void
    {
        $plainUser = $this->user('rider');

        $this->actingAs($plainUser)
            ->get(route('reports.export', ['tab' => 'delivery']))
            ->assertStatus(302);
    }

    public function test_delivery_report_export_returns_pdf(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $rider = Rider::create([
            'name' => 'PDF Rider',
            'email' => 'pdf-rider-'.uniqid().'@test.com',
            'phone' => '09000000002',
            'vehicle_type' => 'Motorcycle',
            'license_plate' => 'PDF '.random_int(1, 999),
            'status' => 'available',
            'center_id' => $center->id,
            'approved_at' => now()->subDays(10),
            'vehicle_verification' => 'verified',
        ]);

        Delivery::create([
            'sender_name' => 'Shop',
            'sender_phone' => '09171234567',
            'sender_address' => '1 Shop St',
            'recipient_name' => 'Cust',
            'recipient_phone' => '09171234568',
            'recipient_address' => '2 Cust Ave',
            'status' => 'delivered',
            'delivery_fee' => 100.00,
            'center_id' => $center->id,
            'rider_id' => $rider->id,
        ]);

        $response = $this->actingAs($admin)->get(route('reports.export', ['tab' => 'delivery']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeaderContains('Content-Disposition', 'invoiz-logistics-delivery-report-');
        $response->assertHeaderContains('Content-Disposition', '.pdf');
        $response->assertSee('%PDF', false);
    }

    public function test_financial_report_export_returns_pdf(): void
    {
        $admin = $this->user();
        $center = $this->center();

        $delivery = Delivery::create([
            'sender_name' => 'Shop',
            'sender_phone' => '09171234567',
            'sender_address' => '1 Shop St',
            'recipient_name' => 'Cust',
            'recipient_phone' => '09171234568',
            'recipient_address' => '2 Cust Ave',
            'status' => 'delivered',
            'delivery_fee' => 100.00,
            'center_id' => $center->id,
        ]);

        Transaction::create([
            'logistics_center_id' => $center->id,
            'delivery_id' => $delivery->id,
            'tracking_number' => $delivery->tracking_number,
            'amount' => 250.00,
            'rider_fee' => 50.00,
            'admin_commission' => 25.00,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($admin)->get(route('reports.export', ['tab' => 'financial']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeaderContains('Content-Disposition', 'invoiz-logistics-financial-report-');
        $response->assertSee('%PDF', false);
    }

    public function test_area_report_export_filters_by_center(): void
    {
        $admin = $this->user();
        $center = $this->center();
        $this->area($center);

        $response = $this->actingAs($admin)
            ->get(route('reports.export', ['tab' => 'area', 'center_id' => $center->id]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertSee('%PDF', false);
    }

    public function test_reports_index_shows_all_reports_tab(): void
    {
        $admin = $this->user();
        $this->center();

        $this->actingAs($admin)
            ->get(route('reports.index', ['tab' => 'all']))
            ->assertOk()
            ->assertSee('All Reports')
            ->assertSee('Delivery Report')
            ->assertSee('Center Report')
            ->assertSee('Service Area Report')
            ->assertSee('Rider Report')
            ->assertSee('Financial Report');
    }

    private function renderPdfAll(array $stats = [], string $preparedBy = 'Logistics Manager'): string
    {
        return view('reports.pdf-all', [
            'title' => 'Consolidated Logistics Report',
            'dateFrom' => null,
            'dateTo' => null,
            'preparedBy' => $preparedBy,
            'generatedAt' => now()->format('Y-m-d H:i:s'),
            'centerName' => null,
            'stats' => array_merge([
                'delivery' => [],
                'center' => [],
                'area' => [],
                'rider' => [],
                'financial' => [],
            ], $stats),
        ])->render();
    }

    public function test_all_reports_export_returns_downloadable_pdf(): void
    {
        $admin = $this->user();
        $this->center();

        $response = $this->actingAs($admin)->get(route('reports.export', ['tab' => 'all']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeaderContains('Content-Disposition', 'invoiz-logistics-all-reports-');
        $response->assertHeaderContains('Content-Disposition', '.pdf');
        $response->assertSee('%PDF', false);
    }

    public function test_all_reports_pdf_view_contains_all_five_sections(): void
    {
        $html = $this->renderPdfAll();

        $this->assertStringContainsString('CONSOLIDATED LOGISTICS REPORT', $html);
        $this->assertStringContainsString('1. Delivery Report', $html);
        $this->assertStringContainsString('2. Logistics Center Report', $html);
        $this->assertStringContainsString('3. Service Area Report', $html);
        $this->assertStringContainsString('4. Rider Report', $html);
        $this->assertStringContainsString('5. Financial Report', $html);
    }

    public function test_all_reports_export_is_staff_gated(): void
    {
        $plainUser = $this->user('rider');

        $this->actingAs($plainUser)
            ->get(route('reports.export', ['tab' => 'all']))
            ->assertStatus(302);
    }

    public function test_prepared_by_shows_logistics_manager_for_admin(): void
    {
        $admin = $this->user('admin');
        $this->assertSame('Logistics Manager', $admin->roleLabel());

        $html = view('reports.pdf', [
            'title' => 'Delivery Report',
            'dateFrom' => null,
            'dateTo' => null,
            'preparedBy' => $admin->roleLabel(),
            'generatedAt' => now()->format('Y-m-d H:i:s'),
            'centerName' => null,
            'tab' => 'delivery',
            'stats' => [],
        ])->render();

        $this->assertStringContainsString('Prepared by', $html);
        $this->assertStringContainsString('Logistics Manager', $html);
        $this->assertStringNotContainsString('Admin Logistics', $html);
    }
}