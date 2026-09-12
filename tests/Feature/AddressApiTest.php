<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\Province;
use Tests\TestCase;

/**
 * Philippine Standard Geographic Code (PSGC) cascading address API:
 * GET /api/address/provinces, /provinces/{province}/municipalities,
 * /municipalities/{municipality}/barangays. Data comes straight from the
 * seeded provinces/municipalities/barangays tables.
 */
class AddressApiTest extends TestCase
{
    private Province $province;
    private Province $otherProvince;
    private Municipality $municipality;
    private Municipality $otherMunicipality;

    protected function setUp(): void
    {
        parent::setUp();

        $this->province = Province::create([
            'code' => '012800000',
            'name' => 'Ilocos Norte',
            'region_code' => '010000000',
        ]);
        $this->otherProvince = Province::create([
            'code' => '130000000',
            'name' => 'Metro Manila',
            'region_code' => '130000000',
        ]);

        $this->municipality = Municipality::create([
            'code' => '012801000',
            'name' => 'Laoag City',
            'province_id' => $this->province->id,
            'region_code' => '010000000',
        ]);
        $this->otherMunicipality = Municipality::create([
            'code' => '133900000',
            'name' => 'Manila',
            'province_id' => $this->otherProvince->id,
            'region_code' => '130000000',
        ]);

        Barangay::create([
            'code' => '012801001',
            'name' => 'Barangay 1',
            'municipality_id' => $this->municipality->id,
        ]);
        Barangay::create([
            'code' => '012801002',
            'name' => 'Barangay 2',
            'municipality_id' => $this->municipality->id,
        ]);
    }

    public function test_lists_provinces(): void
    {
        $res = $this->getJson('/api/address/provinces');

        $res->assertOk()->assertJsonCount(2, 'provinces');
        $res->assertJsonFragment(['name' => 'Ilocos Norte']);
        $res->assertJsonFragment(['name' => 'Metro Manila']);
    }

    public function test_lists_municipalities_of_a_province(): void
    {
        $res = $this->getJson("/api/address/provinces/{$this->province->id}/municipalities");

        $res->assertOk()->assertJsonCount(1, 'municipalities');
        $res->assertJsonFragment(['name' => 'Laoag City']);
        $res->assertJsonMissing(['name' => 'Manila']);
    }

    public function test_lists_barangays_of_a_municipality(): void
    {
        $res = $this->getJson("/api/address/municipalities/{$this->municipality->id}/barangays");

        $res->assertOk()->assertJsonCount(2, 'barangays');
        $res->assertJsonFragment(['name' => 'Barangay 1']);
        $res->assertJsonFragment(['name' => 'Barangay 2']);
    }

    public function test_unknown_province_returns_404(): void
    {
        $this->getJson('/api/address/provinces/999999/municipalities')->assertNotFound();
    }

    public function test_unknown_municipality_returns_404(): void
    {
        $this->getJson('/api/address/municipalities/999999/barangays')->assertNotFound();
    }
}