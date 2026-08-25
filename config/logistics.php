<?php

return [
    'transitions' => [
        'waiting_for_rider' => ['assigned', 'cancelled'],
        'assigned'          => ['picked_up', 'cancelled'],
        'picked_up'         => ['out_for_delivery', 'failed'],
        'out_for_delivery'  => ['delivered', 'failed'],
        'delivered'         => [],
        'failed'            => [],
        'cancelled'         => [],
    ],

    'failure_reasons' => [
        'Recipient unavailable',
        'Incorrect address',
        'Recipient refused package',
        'Vehicle problem',
        'Rider issue',
        'Package damaged',
        'Weather/road condition',
        'Other',
    ],

    'cancellation_reasons' => [
        'Customer cancelled',
        'Incorrect delivery information',
        'Duplicate delivery',
        'Package unavailable',
        'Administrative cancellation',
        'Other',
    ],

    'vehicle_capacities' => [
        'motorcycle' => 30,
        'car'        => 100,
        'van'        => 300,
        'truck'      => 500,
    ],

    'vehicle_labels' => [
        'motorcycle' => 'Motorcycle',
        'car'        => 'Car/Sedan',
        'van'        => 'Van',
        'truck'      => 'Truck',
    ],
];
