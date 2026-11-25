<?php

/*
 * Farmer Crop Timeline presentation settings.
 *
 * Icon values are identifiers exported by the frontend's src/icons (the same list as
 * ActivityCategory::SUPPORTED_ICONS). Tones are fixed UI colour keys: green, amber, blue, red, violet.
 */
return [
    // "Today" for due/late states and days-to/after-sowing. The application timezone stays UTC.
    'timezone' => env('CROP_TIMELINE_TIMEZONE', 'Asia/Kolkata'),

    'default_tone' => 'green',

    // Activity categories store an icon key but no colour, so the tone follows the icon.
    'icon_tones' => [
        'DropletsIcon' => 'blue', 'WaterIcon' => 'blue', 'WavesIcon' => 'blue', 'DrainageIcon' => 'blue',
        'CloudSunIcon' => 'blue', 'WeatherIcon' => 'blue', 'CalenderIcon' => 'blue', 'TimeIcon' => 'blue',
        'FlaskConicalIcon' => 'amber', 'FertilizerIcon' => 'amber', 'ShovelIcon' => 'amber', 'TractorIcon' => 'amber',
        'ScissorsIcon' => 'amber', 'HarvestingIcon' => 'amber', 'HarvestingSickleIcon' => 'amber', 'GroupIcon' => 'amber',
        'BugIcon' => 'red', 'ShieldCheckIcon' => 'red',
        'ChartNoAxesColumnIncreasingIcon' => 'violet', 'YieldAssessmentIcon' => 'violet', 'WarehouseIcon' => 'violet',
        'PostHarvestIcon' => 'violet', 'TruckIcon' => 'violet', 'ShoppingCartIcon' => 'violet', 'WalletIcon' => 'violet',
        'BotIcon' => 'violet', 'UserRoundCheckIcon' => 'violet', 'KnowledgeSourcesIcon' => 'violet',
    ],

    // Keys match config('crop_activity_templates.smart_actions'). Labels live in lang/*/crop_timeline.php.
    'smart_actions' => [
        'crop_details' => ['icon' => 'SproutIcon', 'tone' => 'green'],
        'crop_planning' => ['icon' => 'CalenderIcon', 'tone' => 'green'],
        'field_layout_spacing' => ['icon' => 'ListIcon', 'tone' => 'green'],
        'sowing_planting_method' => ['icon' => 'SeedIcon', 'tone' => 'green'],
        'soil_assessment' => ['icon' => 'ShovelIcon', 'tone' => 'amber'],
        'soil_test' => ['icon' => 'FlaskConicalIcon', 'tone' => 'amber'],
        'seed_calculator' => ['icon' => 'SeedIcon', 'tone' => 'amber'],
        'seed_record' => ['icon' => 'SeedIcon', 'tone' => 'amber'],
        'sowing_record' => ['icon' => 'ClipboardListIcon', 'tone' => 'amber'],
        'choose_seed' => ['icon' => 'SeedIcon', 'tone' => 'amber'],
        'fertilizer_recommendation' => ['icon' => 'FertilizerIcon', 'tone' => 'blue'],
        'fertilizer_record' => ['icon' => 'FertilizerIcon', 'tone' => 'blue'],
        'irrigation_recommendation' => ['icon' => 'DropletsIcon', 'tone' => 'blue'],
        'irrigation_record' => ['icon' => 'WaterIcon', 'tone' => 'blue'],
        'water_management' => ['icon' => 'WavesIcon', 'tone' => 'blue'],
        'weather_forecast' => ['icon' => 'CloudSunIcon', 'tone' => 'blue'],
        'weather_alerts' => ['icon' => 'WeatherIcon', 'tone' => 'blue'],
        'crop_observation' => ['icon' => 'ScanEyeIcon', 'tone' => 'green'],
        'growth_stage_tracking' => ['icon' => 'SproutIcon', 'tone' => 'green'],
        'ai_crop_doctor' => ['icon' => 'BotIcon', 'tone' => 'violet'],
        'ask_agricultural_expert' => ['icon' => 'UserRoundCheckIcon', 'tone' => 'violet'],
        'pest_monitoring' => ['icon' => 'BugIcon', 'tone' => 'red'],
        'disease_monitoring' => ['icon' => 'ShieldCheckIcon', 'tone' => 'red'],
        'yield_estimation' => ['icon' => 'YieldAssessmentIcon', 'tone' => 'violet'],
        'actual_yield_record' => ['icon' => 'ChartNoAxesColumnIncreasingIcon', 'tone' => 'violet'],
        'harvest_record' => ['icon' => 'HarvestingSickleIcon', 'tone' => 'amber'],
        'produce_quality' => ['icon' => 'ClipboardCheckIcon', 'tone' => 'violet'],
        'storage_record' => ['icon' => 'WarehouseIcon', 'tone' => 'violet'],
        'transport_record' => ['icon' => 'TruckIcon', 'tone' => 'violet'],
        'market_prices' => ['icon' => 'ShoppingCartIcon', 'tone' => 'blue'],
        'produce_sale' => ['icon' => 'WalletIcon', 'tone' => 'green'],
        'income_estimation' => ['icon' => 'WalletIcon', 'tone' => 'green'],
        'financial_summary' => ['icon' => 'WalletIcon', 'tone' => 'green'],
        'expense_summary' => ['icon' => 'WalletIcon', 'tone' => 'green'],
        'crop_profitability' => ['icon' => 'ChartNoAxesColumnIncreasingIcon', 'tone' => 'green'],
        'machinery_usage' => ['icon' => 'TractorIcon', 'tone' => 'amber'],
        'labour_assignment' => ['icon' => 'GroupIcon', 'tone' => 'amber'],
        'crop_completion' => ['icon' => 'ClipboardCheckIcon', 'tone' => 'green'],
        'knowledge_reference' => ['icon' => 'KnowledgeSourcesIcon', 'tone' => 'violet'],
    ],
];
