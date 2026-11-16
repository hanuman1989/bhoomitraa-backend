<?php

return [
    'seed_sources' => ['catalogue', 'farm_saved', 'local', 'other', 'unknown'],
    'boundary_sources' => ['farm_boundary', 'manual', 'gps', 'manual_area'],
    'soil_assessment' => [
        'fertile_capacity' => ['very_good', 'good', 'average', 'poor', 'unknown'],
        'soil_moisture' => ['dry', 'normal', 'wet', 'unknown'],
        'drainage_condition' => ['good', 'average', 'poor', 'unknown'],
        'water_availability' => ['sufficient', 'moderate', 'limited', 'unknown'],
    ],
    'soil_measurement_units' => [
        'soil_ph' => ['pH'], 'electrical_conductivity' => ['dS/m', 'mS/cm'], 'organic_carbon' => ['percent', 'g/kg'],
        'nitrogen' => ['kg/ha', 'mg/kg', 'ppm', 'percent'], 'phosphorus' => ['kg/ha', 'mg/kg', 'ppm', 'percent'],
        'potassium' => ['kg/ha', 'mg/kg', 'ppm', 'percent'], 'sulphur' => ['kg/ha', 'mg/kg', 'ppm'],
        'zinc' => ['mg/kg', 'ppm'], 'iron' => ['mg/kg', 'ppm'], 'copper' => ['mg/kg', 'ppm'],
        'manganese' => ['mg/kg', 'ppm'], 'boron' => ['mg/kg', 'ppm'],
    ],
    'soil_reports_disk' => 'private',
    'soil_report_max_kb' => 10240,
    'quantity_units' => [
        'g' => ['dimension' => 'mass', 'factor' => '0.001'], 'kg' => ['dimension' => 'mass', 'factor' => '1'],
        'quintal' => ['dimension' => 'mass', 'factor' => '100'],
        'ml' => ['dimension' => 'volume', 'factor' => '0.001'], 'l' => ['dimension' => 'volume', 'factor' => '1'],
        'piece' => ['dimension' => 'piece', 'factor' => '1'], 'plant' => ['dimension' => 'plant', 'factor' => '1'],
    ],
];
