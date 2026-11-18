<?php

return [
    // Persen dari subtotal. Ubah lewat .env: SAPORE_TAX_RATE=11
    'tax_rate' => (float) env('SAPORE_TAX_RATE', 10),
    'service_rate' => (float) env('SAPORE_SERVICE_RATE', 5),
];
