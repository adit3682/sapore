<?php

return [
    // Persen dari subtotal. Ubah lewat .env: SAPORE_TAX_RATE=11
    'tax_rate' => (float) env('SAPORE_TAX_RATE', 10),
    'service_rate' => (float) env('SAPORE_SERVICE_RATE', 5),
    'food_cost_target' => (float) env('SAPORE_FOOD_COST_TARGET', 35),
];