<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Returned = 'returned';
    case Void = 'void';
}
