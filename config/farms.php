<?php

return [
    'max_boundary_points' => 500,
    'max_boundary_bytes' => 65536,
    'creation_idempotency_hours' => 24,

    'classifications' => [
        'soil_type' => ['sandy', 'loamy', 'clay', 'silty', 'black', 'red', 'alluvial', 'other'],
        'land_type' => ['irrigated', 'rainfed', 'other'],
        'farmland_type' => ['arable', 'orchard', 'plantation', 'pasture', 'other'],
        'water_availability' => ['reliable', 'seasonal', 'scarce', 'none', 'other'],
        'irrigation_source' => ['well', 'borewell', 'canal', 'pond', 'river', 'rainfed', 'other'],
    ],
];
