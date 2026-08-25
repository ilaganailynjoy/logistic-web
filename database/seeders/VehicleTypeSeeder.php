<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VehicleTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'motorcycle', 'label' => 'Motorcycle', 'capacity_kg' => 30, 'sort_order' => 1],
            ['name' => 'car', 'label' => 'Car/Sedan', 'capacity_kg' => 100, 'sort_order' => 2],
            ['name' => 'van', 'label' => 'Van', 'capacity_kg' => 300, 'sort_order' => 3],
            ['name' => 'truck', 'label' => 'Truck', 'capacity_kg' => 500, 'sort_order' => 4],
        ];

        foreach ($types as $type) {
            DB::table('vehicle_types')->updateOrInsert(
                ['name' => $type['name']],
                array_merge($type, ['is_active' => true])
            );
        }
    }
}
