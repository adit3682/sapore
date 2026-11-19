<?php

namespace App\Enums;

enum MovementType: string
{
    case In = 'in';
    case Out = 'out';
    case Adjustment = 'adjustment';
    case Waste = 'waste';
    case Transfer = 'transfer';
    case Return = 'return'; // bahan kembali karena retur penjualan (stok bertambah)
}
