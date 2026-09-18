<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\Province;
use Tests\TestCase;

/**
 * Public Philippine address cascade endpoints used by both the mobile app and
 * the web logistics-center application form.
 */
class AddressApiTest extends TestCase
{
    public function test_provinces_returns_ordered_list(): void
    {
        Province::create(['code' => '015500000', 'name' => 'Batanes', 'region_code' => '015500000']);
        Province::create(['code' => '014400000', 'name' => 'Abra', 'region_code' => '014400000']);

        $this->getJson('/api/address/provinces')
            ->assertOk()
            ->assertJsonCount(2, 'provinces')
            ->assertJsonPath('provinces.0.name', 'Abra')
            ->assertJsonPath('provinces.1.name', 'Batanes');
    }

    public function test_municipalities_returns_children_ordered_by_name(): void
    {
        $province = Province::create(['code' => '013900000', 'name' => 'Metro Manila', 'region_code' => '013900000']);
        Municipality::create(['code' => '137404000', 'name' => 'Pasig', 'province_id' => $province->id, 'region_code' => '013900000']);
        Municipality::create(['code' => '137501000', 'name' => 'Caloocan', 'province_id' => $province->id, 'region_code' => '013900000']);

        $this->getJson("/api/address/provinces/{$province->id}/municipalities")
            ->assertOk()
            ->assertJsonCount(2, 'municipalities')
            ->assertJsonPath('municipalities.0.name', 'Caloocan')
            ->assertJsonPath('municipalities.1.name', 'Pasig')
            ->assertJsonPath('province.name', 'Metro Manila');
    }

    public function test_barangays_returns_children_ordered_by_name(): void
    {
        $province = Province::create(['code' => '013900000', 'name' => 'Metro Manila', 'region_code' => '013900000']);
        $municipality = Municipality::create(['code' => '137404000', 'name' => 'Pasig', 'province_id' => $province->id, 'region_code' => '013900000']);
        Barangay::create(['code' => '137404022', 'name' => 'Zulu', 'municipality_id' => $municipality->id]);
        Barangay::create(['code' => '137404001', 'name' => 'Aguila', 'municipality_id' => $municipality->id]);

        $this->getJson("/api/address/municipalities/{$municipality->id}/barangays")
            ->assertOk()
            ->assertJsonCount(2, 'barangays')
            ->assertJsonPath('barangays.0.name', 'Aguila')
            ->assertJsonPath('barangays.1.name', 'Zulu');
    }

    public function test_municipalities_returns_404_for_missing_province(): void
    {
        $this->getJson('/api/address/provinces/999999/municipalities')->assertNotFound();
    }

    public function test_barangays_returns_404_for_missing_municipality(): void
    {
        $this->getJson('/api/address/municipalities/999999/barangays')->assertNotFound();
    }
}