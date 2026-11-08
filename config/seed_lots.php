<?php

return [
    'expiring_soon_days' => max(0, (int) env('SEED_LOT_EXPIRING_SOON_DAYS', 30)),
];
