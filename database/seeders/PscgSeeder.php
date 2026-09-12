<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\Province;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the PSGC Province → City/Municipality → Barangay hierarchy from data
 * mirrored from the official PSA source (https://psgc.gitlab.io) into
 * database/seeders/data. Idempotent: rebuilds the three reference tables from
 * scratch, preserving row identity across re-seeds.
 */
class PscgSeeder extends Seeder
{
    public function run(): void
    {
        $provinces = require database_path('seeders/data/psgc_provinces.php');
        $municipalities = require database_path('seeders/data/psgc_municipalities.php');
        $barangays = require database_path('seeders/data/psgc_barangays.php');

        DB::transaction(function () use ($provinces, $municipalities, $barangays) {
            Barangay::query()->delete();
            Municipality::query()->delete();
            Province::query()->delete();

            $provinceIdByCode = [];
            foreach ($provinces as [$code, $name, $regionCode]) {
                $provinceIdByCode[$code] = Province::create([
                    'code' => $code,
                    'name' => $name,
                    'region_code' => $regionCode,
                ])->id;
            }

            $municipalityIdByCode = [];
            foreach ($municipalities as [$code, $name, $provinceCode, $regionCode]) {
                if (!isset($provinceIdByCode[$provinceCode])) {
                    continue;
                }
                $municipalityIdByCode[$code] = Municipality::create([
                    'code' => $code,
                    'name' => $name,
                    'province_id' => $provinceIdByCode[$provinceCode],
                    'region_code' => $regionCode,
                ])->id;
            }

            $chunks = array_chunk($barangays, 2000);
            foreach ($chunks as $chunk) {
                $rows = [];
                foreach ($chunk as [$code, $name, $municipalityCode]) {
                    if (!isset($municipalityIdByCode[$municipalityCode])) {
                        continue;
                    }
                    $rows[] = [
                        'code' => $code,
                        'name' => $name,
                        'municipality_id' => $municipalityIdByCode[$municipalityCode],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                Barangay::query()->insert($rows);
            }
        });
    }
}