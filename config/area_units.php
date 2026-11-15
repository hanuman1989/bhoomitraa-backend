<?php

return [
    'default_unit' => 'hectare',

    /** Confirmed application standards, not universal Indian Bigha definitions. */
    'units' => [
        'bigha_kaccha' => [
            'label' => 'Kaccha Bigha',
            'label_hi' => 'कच्चा बीघा',
            'square_meter_factor' => '1618.74256896',
            'definition' => '17424 square feet',
        ],
        'bigha_pakka' => [
            'label' => 'Pakka Bigha',
            'label_hi' => 'पक्का बीघा',
            'square_meter_factor' => '2529.285264',
            'definition' => '27225 square feet',
        ],
        'hectare' => [
            'label' => 'Hectare',
            'label_hi' => 'हेक्टेयर',
            'square_meter_factor' => '10000',
        ],
        'acre' => [
            'label' => 'Acre',
            'label_hi' => 'एकड़',
            'square_meter_factor' => '4046.8564224',
        ],
        'sqm' => [
            'label' => 'Square Metre',
            'label_hi' => 'वर्ग मीटर',
            'square_meter_factor' => '1',
        ],
        'sqft' => [
            'label' => 'Square Foot',
            'label_hi' => 'वर्ग फुट',
            'square_meter_factor' => '0.09290304',
        ],
        'sqyd' => [
            'label' => 'Square Yard',
            'label_hi' => 'वर्ग गज',
            'square_meter_factor' => '0.83612736',
        ],
        'guntha' => [
            'label' => 'Guntha',
            'label_hi' => 'गुंठा',
            'square_meter_factor' => '101.17141056',
        ],
    ],

    'legacy_aliases' => [
        'ha' => 'hectare',
    ],

];
